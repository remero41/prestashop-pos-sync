/**
 * tpvsync — JS del panel de configuración (back office).
 *
 * Dos responsabilidades:
 *   1) armButton(): feedback de spinner para enlaces .tpvsync-action legacy
 *      (botones que aún navegan con GET — recargan página entera).
 *   2) wizard AJAX de primera sincronización (PUSH/PULL): cuando el merchant
 *      pulsa "Enviar al TPV" o "Traer del TPV", abrimos el modal de progreso
 *      y llamamos al endpoint /module/tpvsync/syncbatch en bucle hasta que
 *      `done=true`. Cada lote es una request HTTP independiente, así
 *      max_execution_time se resetea entre lotes y catálogos de 4000+
 *      productos no revientan PHP.
 */
(function () {
    // ─── 1. Spinner para enlaces legacy ──────────────────────────────────
    function armButton(btn) {
        btn.addEventListener('click', function (ev) {
            if (btn.classList.contains('tpvsync-busy')) { ev.preventDefault(); return; }
            btn.classList.add('tpvsync-busy');
            var originalHtml = btn.innerHTML;
            btn.innerHTML = '<span class="tpvsync-spinner"></span>' + (btn.dataset.busyLabel || 'Procesando…');

            var status = document.createElement('span');
            status.className = 'tpvsync-status';
            btn.parentNode.insertBefore(status, btn.nextSibling);
            var start = Date.now();
            var tick = setInterval(function () {
                var s = Math.floor((Date.now() - start) / 1000);
                status.textContent = '(' + s + 's)';
            }, 500);

            setTimeout(function () {
                clearInterval(tick);
                btn.classList.remove('tpvsync-busy');
                btn.innerHTML = originalHtml;
                if (status.parentNode) { status.remove(); }
            }, 120000);
        });
    }

    // ─── 2. Wizard AJAX (PUSH/PULL con barra de progreso) ────────────────
    function formatEta(secondsLeft) {
        if (!isFinite(secondsLeft) || secondsLeft <= 0) { return ''; }
        if (secondsLeft < 60) { return Math.ceil(secondsLeft) + 's'; }
        var m = Math.floor(secondsLeft / 60);
        var s = Math.ceil(secondsLeft % 60);
        return m + 'm ' + (s < 10 ? '0' : '') + s + 's';
    }

    function fetchTpvCount(ajaxUrl, token) {
        // Carga asíncrona del conteo del TPV: el wizard ya está pintado con
        // "…" — esto solo lo reemplaza por el número real (o "—" + aviso si
        // la API tarda demasiado o falla). Se hace por AJAX para NO bloquear
        // el render del módulo: una llamada HTTP firmada con HMAC al TPV
        // puede tardar 20s+ y antes colgaba toda la página.
        var url = ajaxUrl
            + (ajaxUrl.indexOf('?') === -1 ? '?' : '&')
            + 'action=count&token=' + encodeURIComponent(token);
        var countEl = document.getElementById('tpvsync-count-tpv');
        var hintEl  = document.getElementById('tpvsync-count-hint');
        if (!countEl) { return; }
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j && typeof j.count === 'number') {
                    countEl.textContent = String(j.count);
                } else {
                    countEl.textContent = '—';
                    if (hintEl) {
                        hintEl.textContent = 'No se ha podido leer el catálogo del TPV. La sincronización en tiempo real seguirá funcionando, pero no podemos mostrar el conteo aquí.';
                        hintEl.style.display = '';
                    }
                }
            })
            .catch(function () {
                countEl.textContent = '—';
                if (hintEl) {
                    hintEl.textContent = 'No se ha podido leer el catálogo del TPV.';
                    hintEl.style.display = '';
                }
            });
    }

    /**
     * Wizard v2 — flujo de 3 pasos: decisión → sincronización → éxito.
     *
     * Cada paso es un <section data-step-panel="N">. Mostramos solo uno a la
     * vez aplicando la clase .is-active. La animación de viaje de paquetes y
     * la barra de progreso viven en el paso 2; el paso 3 es la pantalla de
     * éxito con bullets explicativos según el modo elegido.
     */
    function initWizardV2(wizard, ajaxUrl, token, reload) {
        var labels = {
            syncingTpv : wizard.getAttribute('data-l-syncing-tpv'),
            syncingPs  : wizard.getAttribute('data-l-syncing-ps'),
            successTpv : wizard.getAttribute('data-l-success-tpv'),
            successPs  : wizard.getAttribute('data-l-success-ps'),
            eta        : wizard.getAttribute('data-l-eta'),
            failed     : wizard.getAttribute('data-l-failed'),
            retry      : wizard.getAttribute('data-l-retry'),
        };

        var stepperItems = wizard.querySelectorAll('.tpvsync-stepper-item');
        var panels = wizard.querySelectorAll('[data-step-panel]');

        var syncTitleEl = wizard.querySelector('#tpvsync-syncing-title');
        var scene       = wizard.querySelector('#tpvsync-syncscene');
        // Cajas con posición FIJA: PS izquierda, TPV derecha. La dirección de
        // la animación cambia con la clase .is-reverse en .tpvsync-syncscene.
        var psCounter   = wizard.querySelector('#tpvsync-syncbox-ps-counter');
        var tpvCounter  = wizard.querySelector('#tpvsync-syncbox-tpv-counter');
        var barEl       = wizard.querySelector('#tpvsync-syncprogress-bar');
        var countsEl    = wizard.querySelector('#tpvsync-syncprogress-counts');
        var pctEl       = wizard.querySelector('#tpvsync-syncprogress-pct');
        var etaEl       = wizard.querySelector('#tpvsync-syncprogress-eta');
        var failBox     = wizard.querySelector('#tpvsync-syncfail');
        var failMsg     = wizard.querySelector('#tpvsync-syncfail-msg');
        var failBack    = wizard.querySelector('#tpvsync-syncfail-back');
        var failRetry   = wizard.querySelector('#tpvsync-syncfail-retry');
        var successLead    = wizard.querySelector('#tpvsync-success-lead');
        var successBullets = wizard.querySelector('#tpvsync-success-bullets');
        var successContinue= wizard.querySelector('#tpvsync-success-continue');

        var lastAction = null;
        var lastPrincipal = null;
        var cancelled = false;
        // Se marca true en cuanto el primer batch del wizard recibe respuesta
        // OK del backend (significa que el controller procesó &principal=...
        // y lo guardó en Configuration). A partir de ese momento, "Volver al
        // paso anterior" deja de ser una opción coherente — la decisión ya
        // está tomada y guardada.
        var principalPersisted = false;

        function goToStep(n) {
            for (var i = 0; i < panels.length; i++) {
                panels[i].classList.toggle('is-active',
                    parseInt(panels[i].getAttribute('data-step-panel'), 10) === n);
            }
            for (var j = 0; j < stepperItems.length; j++) {
                var idx = parseInt(stepperItems[j].getAttribute('data-step'), 10);
                stepperItems[j].classList.toggle('is-active', idx === n);
                stepperItems[j].classList.toggle('is-done', idx < n);
            }
            // Scroll suave al inicio del wizard para que el usuario vea el paso.
            try {
                wizard.scrollIntoView({ behavior: 'smooth', block: 'start' });
            } catch (_) { /* navegadores antiguos */ }
        }

        function configureScene(action) {
            // Las cajas NO se reordenan: PS siempre izquierda, TPV siempre derecha.
            // Lo que cambia es la dirección del flujo de paquetes:
            //   action='pull' → TPV manda  → flujo derecha→izquierda → is-reverse
            //   action='push' → PS manda   → flujo izquierda→derecha → sin clase
            if (action === 'pull') {
                scene.classList.add('is-reverse');
                syncTitleEl.textContent = labels.syncingTpv;
            } else {
                scene.classList.remove('is-reverse');
                syncTitleEl.textContent = labels.syncingPs;
            }
        }

        function resetProgress() {
            barEl.style.width = '0%';
            countsEl.textContent = 'Procesando primer lote…';
            pctEl.textContent = '';
            etaEl.innerHTML = '&nbsp;';
            if (psCounter)  psCounter.textContent = '';
            if (tpvCounter) tpvCounter.textContent = '';
            failBox.hidden = true;
        }

        function updateProgress(processed, total, action) {
            var pct = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 0;
            barEl.style.width = pct + '%';
            countsEl.textContent = processed + ' / ' + total;
            pctEl.textContent = pct + '%';
            // Origen muestra el total ('X productos'), destino muestra recibidos.
            if (action === 'pull') {
                // TPV → PS: TPV es origen, PS es destino.
                if (tpvCounter) tpvCounter.textContent = total + ' productos';
                if (psCounter)  psCounter.textContent  = processed + ' recibidos';
            } else {
                // PS → TPV: PS es origen, TPV es destino.
                if (psCounter)  psCounter.textContent  = total + ' productos';
                if (tpvCounter) tpvCounter.textContent = processed + ' recibidos';
            }
        }

        function humanizeError(raw) {
            if (!raw) return 'Error desconocido. Vuelve a intentarlo en unos minutos.';
            var s = String(raw).toLowerCase();
            // invalid_token aquí es del token interno del wizard, NO de las
            // credenciales del TPV. Pasa cuando la página del wizard se
            // renderizó hace mucho rato. La solución es recargar la página
            // del módulo, no "reconectar el TPV".
            if (s.indexOf('invalid_token') !== -1) {
                return 'La sesión del wizard ha expirado. Recarga la página y vuelve a empezar.';
            }
            if (s.indexOf('401') !== -1) {
                return 'Las credenciales del TPV ya no son válidas. Vuelve a conectar el TPV.';
            }
            if (s.indexOf('signature_invalid') !== -1) {
                return 'Las firmas de seguridad están desincronizadas. Reconecta el TPV.';
            }
            if (s.indexOf('circuit_open') !== -1) {
                return 'El TPV está rechazando peticiones (probablemente sobrecargado). Espera unos minutos y vuelve a intentarlo.';
            }
            if (s.indexOf('rate') !== -1 && s.indexOf('limit') !== -1) {
                return 'Has alcanzado el límite de peticiones por minuto. Espera 1 minuto y vuelve a intentarlo.';
            }
            if (s.indexOf('network') !== -1 || s.indexOf('failed to fetch') !== -1) {
                return 'No hay conexión con el servidor. Comprueba tu red y vuelve a intentarlo.';
            }
            if (s.indexOf('500') !== -1 || s.indexOf('exception') !== -1) {
                return 'El TPV ha tenido un error interno. Si persiste, contacta con soporte.';
            }
            return 'Error: ' + raw;
        }

        function showFail(errMsg) {
            failMsg.textContent = humanizeError(errMsg);
            failBox.hidden = false;
            etaEl.innerHTML = '&nbsp;';
            // Si el error es invalid_token (sesión del wizard caducada),
            // ofrecemos un botón "Recargar la página" en lugar de "Reintentar"
            // — reintentar volvería a fallar con el mismo token muerto. La
            // recarga regenera el token y arranca limpio.
            var isStale = errMsg && String(errMsg).toLowerCase().indexOf('invalid_token') !== -1;
            if (failRetry) {
                if (isStale) {
                    failRetry.textContent = 'Recargar la página';
                    failRetry.dataset.mode = 'reload';
                } else {
                    failRetry.textContent = labels.retry || 'Reintentar';
                    failRetry.dataset.mode = 'retry';
                }
            }
            // El botón "Volver al paso anterior" tiene sentido sólo si todavía
            // no se ha persistido la decisión (el primer batch del wizard
            // envía ?principal=tpv|ps en la URL; si llegó al servidor, ya
            // está guardado). En el caso del invalid_token, el primer batch
            // ni siquiera llegó a procesar la decisión — el token expiró
            // antes — así que volver al paso 1 sí tiene sentido. Pero si el
            // error es por otra causa post-persistencia, ocultamos "Volver"
            // para no dar la falsa impresión de que la decisión se puede
            // revertir. Heurística sencilla: el principal ya persistió si
            // el endpoint devolvió cualquier respuesta procesable distinta
            // de invalid_token. Lo señalizamos con una variable.
            if (failBack) {
                failBack.hidden = principalPersisted && !isStale;
            }
        }

        function buildSuccessBullets(action, finalStats) {
            // Resumen real del lote final (cuando done=true). El backend manda
            // los acumuladores cross-batch en distintos campos según action:
            //   pull (TPV→PS): created, updated, errors
            //   push (PS→TPV): sent, skipped, errors
            // Construimos un panel con tarjetas numéricas grandes.
            var statsHtml = '';
            if (finalStats) {
                if (action === 'pull') {
                    var created = finalStats.created || 0;
                    var updated = finalStats.updated || 0;
                    var errors  = finalStats.errors  || 0;
                    statsHtml = ''
                      + '<div class="tpvsync-success-stats">'
                      +   '<div class="tpvsync-success-stat">'
                      +     '<span class="tpvsync-success-stat-num">' + created + '</span>'
                      +     '<span class="tpvsync-success-stat-lbl">creados nuevos en PrestaShop</span>'
                      +   '</div>'
                      +   '<div class="tpvsync-success-stat">'
                      +     '<span class="tpvsync-success-stat-num">' + updated + '</span>'
                      +     '<span class="tpvsync-success-stat-lbl">actualizados (ya coincidían)</span>'
                      +   '</div>'
                      + (errors > 0
                          ? '<div class="tpvsync-success-stat is-warn">'
                          +    '<span class="tpvsync-success-stat-num">' + errors + '</span>'
                          +    '<span class="tpvsync-success-stat-lbl">con incidencias (revisar logs)</span>'
                          +  '</div>'
                          : '')
                      + '</div>';
                } else {
                    var sent    = finalStats.sent    || 0;
                    var skipped = finalStats.skipped || 0;
                    var errors2 = finalStats.errors  || 0;
                    statsHtml = ''
                      + '<div class="tpvsync-success-stats">'
                      +   '<div class="tpvsync-success-stat">'
                      +     '<span class="tpvsync-success-stat-num">' + sent + '</span>'
                      +     '<span class="tpvsync-success-stat-lbl">enviados al TPV</span>'
                      +   '</div>'
                      + (skipped > 0
                          ? '<div class="tpvsync-success-stat">'
                          +    '<span class="tpvsync-success-stat-num">' + skipped + '</span>'
                          +    '<span class="tpvsync-success-stat-lbl">saltados (sin referencia o no aplicables)</span>'
                          +  '</div>'
                          : '')
                      + (errors2 > 0
                          ? '<div class="tpvsync-success-stat is-warn">'
                          +    '<span class="tpvsync-success-stat-num">' + errors2 + '</span>'
                          +    '<span class="tpvsync-success-stat-lbl">con incidencias (revisar logs)</span>'
                          +  '</div>'
                          : '')
                      + '</div>';
                }
            }

            // Bullets explicativos del estado a partir de ahora.
            var bulletsHtml = '';
            if (action === 'pull') {
                bulletsHtml = ''
                  + '<li>El TPV es ahora la fuente de verdad: gestiona los productos desde ahí.</li>'
                  + '<li>Los cambios viajan al instante a PrestaShop (precio, descripción, alta/baja).</li>'
                  + '<li>El stock se sincroniza en ambos sentidos cada vez que vendes.</li>'
                  + '<li>Los productos que ya estaban en PrestaShop y no existen en el TPV quedan como "islas": se mantienen pero no se sincronizan.</li>';
            } else {
                bulletsHtml = ''
                  + '<li>PrestaShop es ahora la fuente de verdad: gestiona los productos desde aquí.</li>'
                  + '<li>Los cambios viajan al instante al TPV (precio, descripción, alta/baja).</li>'
                  + '<li>El stock se sincroniza en ambos sentidos cada vez que vendes.</li>'
                  + '<li>Los productos que ya estaban en el TPV y no existen en PrestaShop quedan como "islas": se mantienen pero no se sincronizan.</li>';
            }
            successBullets.innerHTML = bulletsHtml;
            successLead.textContent = (action === 'pull') ? labels.successTpv : labels.successPs;

            // Inyectamos el panel de stats antes de los bullets si lo tenemos.
            // Usamos un contenedor dedicado para no contaminar successBullets
            // (que es <ul>): lo metemos justo antes en el DOM.
            var statsContainer = wizard.querySelector('#tpvsync-success-stats');
            if (statsContainer) {
                statsContainer.innerHTML = statsHtml;
            }
        }

        function runSyncLoop(action, principal) {
            lastAction = action;
            lastPrincipal = principal;
            cancelled = false;
            resetProgress();
            configureScene(action);
            goToStep(2);

            var startTs = Date.now();
            var firstProcessed = null;
            var firstSent = false;
            var firstResponseSeen = false;

            var safetyTimer = setTimeout(function () {
                if (!firstResponseSeen && !cancelled) {
                    cancelled = true;
                    showFail('No hay respuesta del servidor (>20s). Comprueba la conexión.');
                }
            }, 20000);

            function tick() {
                var slowWarn = setTimeout(function () {
                    if (etaEl) {
                        etaEl.textContent = 'Este lote está tardando más de lo normal. Sigue procesando…';
                    }
                }, 15000);

                var url = ajaxUrl
                    + (ajaxUrl.indexOf('?') === -1 ? '?' : '&')
                    + 'action=' + encodeURIComponent(action)
                    + '&token=' + encodeURIComponent(token);
                if (!firstSent && principal) {
                    url += '&principal=' + encodeURIComponent(principal);
                    firstSent = true;
                }
                fetch(url, { credentials: 'same-origin' })
                    .then(function (resp) { return resp.json().then(function (j) { return { ok: resp.ok, body: j }; }); })
                    .then(function (r) {
                        firstResponseSeen = true;
                        clearTimeout(safetyTimer);
                        clearTimeout(slowWarn);
                        if (cancelled) { return; }
                        var b = r.body || {};
                        if (!r.ok || b.error) {
                            showFail(b.error || ('HTTP ' + (r.status || '')));
                            return;
                        }
                        // Primer batch OK = el controller leyó ?principal=...
                        // y lo persistió. La decisión ya está hecha en BD.
                        principalPersisted = true;
                        var processed = b.processed || 0;
                        var total     = b.total     || 0;
                        if (firstProcessed === null) { firstProcessed = processed; }
                        updateProgress(processed, total, action);

                        if (total > 0 && processed > firstProcessed) {
                            var elapsed = (Date.now() - startTs) / 1000;
                            var rate = (processed - firstProcessed) / elapsed;
                            var left = total - processed;
                            if (rate > 0) {
                                etaEl.textContent = labels.eta + ' ' + formatEta(left / rate);
                            }
                        }

                        if (b.done) {
                            updateProgress(total, total, action); // 100%
                            // Pequeña pausa para que el usuario vea el "100%"
                            // antes de saltar al paso 3 — sin esto se siente
                            // brusco y el merchant no procesa que terminó.
                            var finalStats = {
                                created: b.created, updated: b.updated,
                                sent: b.sent, skipped: b.skipped,
                                errors: b.errors
                            };
                            setTimeout(function () {
                                buildSuccessBullets(action, finalStats);
                                goToStep(3);
                            }, 700);
                        } else if (!cancelled) {
                            tick();
                        }
                    })
                    .catch(function (err) {
                        firstResponseSeen = true;
                        clearTimeout(safetyTimer);
                        clearTimeout(slowWarn);
                        if (!cancelled) {
                            showFail(err.message || 'network_error');
                        }
                    });
            }
            tick();
        }

        // ── Bind: las 2 cards de elección del paso 1 ─────────────────────
        wizard.querySelectorAll('[data-tpvsync-action]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var action  = btn.getAttribute('data-tpvsync-action');
                var principal = btn.getAttribute('data-tpvsync-principal') || '';
                var confirmMsg = btn.getAttribute('data-tpvsync-confirm');
                if (confirmMsg && !window.confirm(confirmMsg)) { return; }
                runSyncLoop(action, principal);
            });
        });

        // ── Bind: paso 2 — Volver y Reintentar ──────────────────────────
        if (failBack) {
            failBack.addEventListener('click', function () {
                cancelled = true;
                failBox.hidden = true;
                goToStep(1);
            });
        }
        if (failRetry) {
            failRetry.addEventListener('click', function () {
                if (failRetry.dataset.mode === 'reload') {
                    window.location.reload();
                    return;
                }
                if (lastAction) { runSyncLoop(lastAction, lastPrincipal); }
            });
        }

        // ── Bind: paso 3 — Continuar al panel ──────────────────────────
        if (successContinue) {
            successContinue.addEventListener('click', function () {
                if (reload) { window.location.href = reload; }
            });
        }
    }

    function initWizard(wizard) {
        var ajaxUrl = wizard.getAttribute('data-tpvsync-ajax');
        var token   = wizard.getAttribute('data-tpvsync-token');
        var reload  = wizard.getAttribute('data-tpvsync-reload');

        // Lanzar el peek del conteo TPV en cuanto se monte el wizard.
        fetchTpvCount(ajaxUrl, token);

        // Wizard v2 (3 pasos visuales): usa flujo independiente sin modal.
        // Si el DOM trae los paneles del wizard v2 los gestionamos aparte y
        // no enganchamos los handlers del modal antiguo (que ya no existe en
        // este flujo, sigue vivo solo para reconcile/divergencia).
        if (wizard.classList.contains('tpvsync-wizard-v2')) {
            initWizardV2(wizard, ajaxUrl, token, reload);
            return;
        }
        var labels = {
            pushTitle      : wizard.getAttribute('data-l-push-title'),
            pullTitle      : wizard.getAttribute('data-l-pull-title'),
            reconcileTitle : wizard.getAttribute('data-l-reconcile-title') || 'Reconciliando…',
            dontClose      : wizard.getAttribute('data-l-dont-close'),
            donePush       : wizard.getAttribute('data-l-done-push'),
            donePull       : wizard.getAttribute('data-l-done-pull'),
            doneReconcile  : wizard.getAttribute('data-l-done-reconcile') || 'Reconciliación completada.',
            closeBtn       : wizard.getAttribute('data-l-close-btn'),
            failed         : wizard.getAttribute('data-l-failed'),
            retry          : wizard.getAttribute('data-l-retry'),
            eta            : wizard.getAttribute('data-l-eta'),
        };

        var overlay   = document.getElementById('tpvsync-progress-overlay');
        var titleEl   = document.getElementById('tpvsync-progress-title');
        var barEl     = document.getElementById('tpvsync-progress-bar');
        var countsEl  = document.getElementById('tpvsync-progress-counts');
        var pctEl     = document.getElementById('tpvsync-progress-percent');
        var etaEl     = document.getElementById('tpvsync-progress-eta');
        var finalEl   = document.getElementById('tpvsync-progress-final');
        var finalMsg  = document.getElementById('tpvsync-progress-final-msg');
        var closeBtn  = document.getElementById('tpvsync-progress-close');
        var retryBtn  = document.getElementById('tpvsync-progress-retry');
        var xBtn      = document.getElementById('tpvsync-progress-x');

        if (!overlay) { return; }

        // Flag de cancelación: si el usuario pulsa X o Esc, dejamos de
        // disparar nuevos batches. El batch en vuelo se completa (no podemos
        // abortarlo desde el cliente) pero ya no se encadenan más.
        var cancelled = false;

        function showOverlay(action) {
            cancelled = false;
            var titleByAction = {
                push: labels.pushTitle,
                pull: labels.pullTitle,
                reconcile: labels.reconcileTitle,
                check: 'Comprobando divergencias…',
            };
            titleEl.textContent = titleByAction[action] || labels.pushTitle;
            // Mientras esperamos la primera respuesta (puede tardar 10-20s con
            // catálogos grandes — cada lote procesa 200 productos en serie),
            // mostramos una barra animada "indeterminada" en vez de "0%" fijo.
            // Sin esto, el merchant cree que la app se ha colgado.
            barEl.style.width = '100%';
            barEl.classList.add('tpvsync-progress-bar-indeterminate');
            countsEl.textContent = 'Procesando primer lote…';
            pctEl.textContent = '';
            etaEl.textContent = '';
            finalEl.hidden = true;
            retryBtn.hidden = true;
            overlay.hidden = false;
            document.body.classList.add('tpvsync-modal-open');
        }
        function hideOverlay() {
            cancelled = true;
            overlay.hidden = true;
            document.body.classList.remove('tpvsync-modal-open');
        }
        function update(processed, total, action) {
            // Primera respuesta: salimos del modo "indeterminado" (gradiente
            // animado) y volvemos a la barra normal con porcentaje real.
            barEl.classList.remove('tpvsync-progress-bar-indeterminate');
            var pct = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 0;
            barEl.style.width = pct + '%';
            countsEl.textContent = processed + ' / ' + total;
            pctEl.textContent = pct + '%';
        }
        function finishOk(action) {
            barEl.style.width = '100%';
            pctEl.textContent = '100%';
            etaEl.textContent = '';
            var msgByAction = {
                push: labels.donePush,
                pull: labels.donePull,
                reconcile: labels.doneReconcile,
            };
            finalMsg.textContent = msgByAction[action] || labels.donePush;
            finalMsg.className = 'tpvsync-progress-final-ok';
            closeBtn.textContent = labels.closeBtn;
            closeBtn.hidden = false;
            retryBtn.hidden = true;
            finalEl.hidden = false;

            // Tras push/pull (no reconcile, que ya hace su propio merge),
            // arrancamos análisis de divergencia: ¿hay productos en el otro
            // lado que no están en este? El merchant decide qué hacer.
            if (action === 'push' || action === 'pull') {
                runDivergenceAnalysis(action);
            }
        }
        // Mapa de errores técnicos a mensajes humanos. Lo que devuelve la API
        // es útil para depurar pero ininteligible para un merchant ("HTTP 401",
        // "circuit_open", "invalid_token"). Aquí los traducimos a algo
        // accionable; el código técnico queda en logs para soporte.
        function humanizeError(raw) {
            if (!raw) { return 'Error desconocido. Vuelve a intentarlo en unos minutos.'; }
            var s = String(raw).toLowerCase();
            if (s.indexOf('invalid_token') !== -1 || s.indexOf('401') !== -1) {
                return 'Tus credenciales han caducado. Pulsa "Conectar y sincronizar" para renovarlas.';
            }
            if (s.indexOf('signature_invalid') !== -1) {
                return 'Las firmas de seguridad están desincronizadas. Pulsa "Conectar y sincronizar" para regenerarlas.';
            }
            if (s.indexOf('circuit_open') !== -1) {
                return 'El TPV está rechazando peticiones (probablemente sobrecargado). Espera unos minutos y vuelve a intentarlo.';
            }
            if (s.indexOf('rate') !== -1 && s.indexOf('limit') !== -1) {
                return 'Has alcanzado el límite de peticiones por minuto. Espera 1 minuto y vuelve a intentarlo.';
            }
            if (s.indexOf('network') !== -1 || s.indexOf('failed to fetch') !== -1) {
                return 'No hay conexión con el servidor. Comprueba tu red y vuelve a intentarlo.';
            }
            if (s.indexOf('500') !== -1 || s.indexOf('exception') !== -1) {
                return 'El TPV ha tenido un error interno. Si persiste, avisa a soporte.';
            }
            if (s.indexOf('20s') !== -1 || s.indexOf('respuesta del servidor') !== -1) {
                return raw; // ya es legible
            }
            // Por defecto: enseñamos el raw pero advertimos.
            return 'Error: ' + raw + ' — si no entiendes el mensaje, contacta con soporte.';
        }

        function finishFail(errMsg, action) {
            etaEl.textContent = '';
            finalMsg.textContent = humanizeError(errMsg);
            finalMsg.className = 'tpvsync-progress-final-fail';
            closeBtn.textContent = labels.closeBtn;
            closeBtn.hidden = false;
            retryBtn.hidden = false;
            retryBtn.dataset.action = action;
            finalEl.hidden = false;
        }

        function runLoop(action, principalToPersist) {
            var startTs = Date.now();
            var firstProcessed = null;
            // principalToPersist se manda solo en el PRIMER lote del wizard.
            // El controller lo persiste en TPVSYNC_PRINCIPAL si llega; los
            // lotes siguientes ya no lo envían (firstSent=true).
            var firstSent = false;
            var firstResponseSeen = false;

            // Timeout de seguridad: si la PRIMERA respuesta no llega en 20s,
            // mostramos error con botón Cerrar/Reintentar — nunca dejamos al
            // usuario mirando una barra al 0% sin pista. Una vez llega la
            // primera respuesta cancelamos el timeout (el flujo normal ya
            // tiene su propio manejo de progreso por lote).
            var safetyTimer = setTimeout(function () {
                if (!firstResponseSeen && !cancelled) {
                    cancelled = true;
                    finishFail('No hay respuesta del servidor (>20s). Comprueba la conexión e inténtalo de nuevo.', action);
                }
            }, 20000);

            function tick() {
                var tickStart = Date.now();
                // Warning suave si un lote tarda >15s sin respuesta: el usuario
                // ve que "sigue trabajando" en lugar de pensar que se ha
                // quedado pillado. NO cierra ni cancela — solo informa.
                var slowWarn = setTimeout(function () {
                    if (etaEl) {
                        etaEl.textContent = 'Este lote está tardando más de lo normal. Sigue procesando…';
                    }
                }, 15000);

                var url = ajaxUrl
                    + (ajaxUrl.indexOf('?') === -1 ? '?' : '&')
                    + 'action=' + encodeURIComponent(action)
                    + '&token=' + encodeURIComponent(token);
                if (!firstSent && principalToPersist) {
                    url += '&principal=' + encodeURIComponent(principalToPersist);
                    firstSent = true;
                }
                fetch(url, { credentials: 'same-origin' })
                    .then(function (resp) { return resp.json().then(function (j) { return { ok: resp.ok, body: j }; }); })
                    .then(function (r) {
                        firstResponseSeen = true;
                        clearTimeout(safetyTimer);
                        clearTimeout(slowWarn);
                        var b = r.body || {};
                        if (!r.ok || b.error) {
                            finishFail(b.error || ('HTTP ' + (r.status || '')), action);
                            return;
                        }
                        var processed = b.processed || 0;
                        var total     = b.total     || 0;
                        if (firstProcessed === null) { firstProcessed = processed; }

                        update(processed, total, action);

                        // ETA: solo cuando ya tenemos progreso medible.
                        if (total > 0 && processed > firstProcessed) {
                            var elapsed = (Date.now() - startTs) / 1000;
                            var rate = (processed - firstProcessed) / elapsed; // items/s
                            var left = total - processed;
                            if (rate > 0) {
                                etaEl.textContent = labels.eta + ' ' + formatEta(left / rate);
                            }
                        }

                        if (b.done) {
                            finishOk(action);
                        } else if (!cancelled) {
                            // Lote siguiente. Sin sleep — el RTT es el throttle natural.
                            tick();
                        }
                    })
                    .catch(function (err) {
                        firstResponseSeen = true;
                        clearTimeout(safetyTimer);
                        clearTimeout(slowWarn);
                        if (!cancelled) {
                            finishFail(err.message || 'network_error', action);
                        }
                    });
            }
            showOverlay(action);
            tick();
        }

        // Bind: cada botón con data-tpvsync-action arranca el flujo.
        var actionButtons = wizard.querySelectorAll('[data-tpvsync-action]');
        if (window.console) console.log('[tpvsync] initWizard bound', actionButtons.length, 'action buttons');
        actionButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var action  = btn.getAttribute('data-tpvsync-action');
                var confirm_ = btn.getAttribute('data-tpvsync-confirm');
                var principal = btn.getAttribute('data-tpvsync-principal') || '';
                if (window.console) console.log('[tpvsync] click action=', action, 'principal=', principal);
                if (confirm_ && !window.confirm(confirm_)) { return; }
                // Acción nueva: check_sync arranca la pantalla unificada
                // "Estado de la sincronización" (4 números + botón arreglar).
                // Reemplaza al antiguo check_divergence — al merchant solo le
                // ofrecemos un único botón ahora.
                if (action === 'check_sync') {
                    runSyncStatusCheck();
                    return;
                }
                // Compat legacy: si por algún caché viejo aún hay botón con
                // check_divergence, lo redirigimos al flujo nuevo.
                if (action === 'check_divergence') {
                    runSyncStatusCheck();
                    return;
                }
                runLoop(action, principal);
            });
        });

        closeBtn.addEventListener('click', function () {
            hideOverlay();
            // Recargar para que la UI refleje INITIAL_SYNC_DONE=1 → wizard fuera.
            if (reload) { window.location.href = reload; }
        });
        // X superior derecha: cierre forzoso siempre disponible. NO recarga
        // (el merchant pulsa X para abandonar antes de tiempo, no para
        // confirmar éxito) — solo deja de encadenar batches y oculta el modal.
        if (xBtn) {
            xBtn.addEventListener('click', function () {
                hideOverlay();
            });
        }
        // Esc también cierra. Es la convención universal para cerrar modales.
        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape' && !overlay.hidden) {
                hideOverlay();
            }
        });
        // Click en el overlay (fuera del modal blanco) también cierra. Tres
        // vías de salida (X / Esc / click fuera) garantizan que el merchant
        // nunca quede atrapado, incluso si una falla por bug del navegador.
        overlay.addEventListener('click', function (ev) {
            if (ev.target === overlay) { hideOverlay(); }
        });
        retryBtn.addEventListener('click', function () {
            var action = retryBtn.dataset.action || 'push';
            finalEl.hidden = true;
            barEl.style.width = '0%';
            countsEl.textContent = '0 / ?';
            pctEl.textContent = '0%';
            runLoop(action);
        });

        // ── Comprobar sincronización (botón unificado del footer) ───────────
        // Muestra una pantalla "página completa" con 4 números:
        //   - Sincronizados (en ambos lados)
        //   - Islas en PS / WC (existen solo aquí)
        //   - Islas en TPV (existen solo allí)
        //   - Discrepancias (= islas TPV importables al PS)
        // Si hay discrepancias, el botón "Arreglar discrepancias" abre el
        // flujo de three-way merge ya existente (runDivergenceAnalysis).
        function runSyncStatusCheck() {
            // Reusamos el modal existente como contenedor "loading" mientras
            // pedimos los datos. Pasamos a una pantalla full-page después.
            showOverlay('check');
            titleEl.textContent = 'Comprobando estado de la sincronización…';
            countsEl.textContent = 'Analizando catálogos…';
            pctEl.textContent = '';
            etaEl.textContent = '';
            barEl.classList.add('tpvsync-progress-bar-indeterminate');
            barEl.style.width = '100%';
            finalEl.hidden = true;

            var url = ajaxUrl
                + (ajaxUrl.indexOf('?') === -1 ? '?' : '&')
                + 'action=check_sync&token=' + encodeURIComponent(token);
            fetch(url, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    hideOverlay();
                    if (!d || d.error) {
                        alert('No se pudo comprobar el estado: ' + ((d && d.error) || 'error desconocido'));
                        return;
                    }
                    renderSyncStatusPage(d);
                })
                .catch(function (err) {
                    hideOverlay();
                    alert('Error de red al comprobar el estado: ' + (err.message || err));
                });
        }

        // Renderiza la pantalla de "Estado de la sincronización" sustituyendo
        // el contenido principal de la página del módulo. Inspirado en cómo
        // hacíamos en renderDivergencePage: capturamos un nodo raíz y
        // reemplazamos su HTML.
        function renderSyncStatusPage(d) {
            var pageRoot = document.querySelector('.tpvsync-page');
            if (!pageRoot) {
                alert('No se encuentra el contenedor de la página.');
                return;
            }
            var synced       = d.synced       || 0;
            var islandsPs    = d.islands_ps   || 0;
            var islandsTpv   = d.islands_tpv  || 0;
            var divergences  = d.divergences  || 0;
            var unimportable = d.unimportable || 0;
            var psTotal      = d.ps_total     || 0;
            var tpvTotal     = d.tpv_total    || 0;
            var statusOk = divergences === 0;

            // Aviso "no importables": productos del TPV que NUNCA podrán
            // entrar en PS por datos rotos (precio<0, model vacío, model
            // duplicado en TPV, productos internos POS_DISCOUNT/POS_SERVICE).
            // Lo mostramos como info gris discreta para que el comerciante
            // sepa que el desequilibrio de totales tiene explicación, pero
            // sin pintarlo como alarma — no hay nada que él pueda arreglar.
            var unimportableHtml = unimportable > 0
              ? '<div class="tpvsync-syncstatus-info">'
              +   '<span class="tpvsync-syncstatus-info-icon">ⓘ</span>'
              +   '<span><strong>' + unimportable + ' productos del TPV</strong> no se pueden importar a PrestaShop (precio negativo, modelo vacío o duplicado, o productos internos del TPV como descuentos/servicios). Se ignoran automáticamente.</span>'
              + '</div>'
              : '';

            var html = ''
              + '<div class="tpvsync-card tpvsync-syncstatus-card">'
              +   '<div class="tpvsync-syncstatus-header ' + (statusOk ? 'is-ok' : 'is-warn') + '">'
              +     (statusOk
                      ? '<span class="tpvsync-syncstatus-icon">✓</span><h2>Todo en orden</h2><p>No hay discrepancias entre el TPV y tu tienda.</p>'
                      : '<span class="tpvsync-syncstatus-icon">⚠</span><h2>Hay discrepancias por revisar</h2><p>El TPV tiene ' + divergences + ' productos que no llegaron a tu tienda online.</p>')
              +   '</div>'
              +   '<div class="tpvsync-syncstatus-stats">'
              +     '<div class="tpvsync-syncstatus-stat"><span class="num">' + synced + '</span><span class="lbl">sincronizados</span><span class="hlp">existen en ambos lados</span></div>'
              +     '<div class="tpvsync-syncstatus-stat"><span class="num">' + islandsPs + '</span><span class="lbl">solo en PrestaShop</span><span class="hlp">islas que viven solo aquí</span></div>'
              +     '<div class="tpvsync-syncstatus-stat ' + (islandsTpv > 0 ? 'is-warn' : '') + '"><span class="num">' + islandsTpv + '</span><span class="lbl">solo en el TPV</span><span class="hlp">no han llegado a la tienda</span></div>'
              +     '<div class="tpvsync-syncstatus-stat is-summary"><span class="num">' + divergences + '</span><span class="lbl">discrepancias</span><span class="hlp">acción recomendada</span></div>'
              +   '</div>'
              +   unimportableHtml
              +   '<div class="tpvsync-syncstatus-context">'
              +     '<div><strong>PrestaShop</strong>: ' + psTotal + ' productos en total</div>'
              +     '<div><strong>TPV</strong>: ' + tpvTotal + ' productos en total</div>'
              +   '</div>'
              +   '<div class="tpvsync-syncstatus-actions">'
              +     '<button type="button" class="tpvsync-btn tpvsync-btn-secondary" id="tpvsync-syncstatus-back">Volver al panel</button>'
              +     (divergences > 0
                      ? '<button type="button" class="tpvsync-btn tpvsync-btn-primary" id="tpvsync-syncstatus-fix">Arreglar discrepancias →</button>'
                      : '')
              +   '</div>'
              + '</div>';

            // Capturamos el primer hijo después de los <style>, lo guardamos
            // para poder restaurar al "Volver al panel".
            var savedHtml = pageRoot.innerHTML;
            pageRoot.innerHTML = html;

            var backBtn = document.getElementById('tpvsync-syncstatus-back');
            if (backBtn) {
                backBtn.addEventListener('click', function () {
                    // Recargamos la página: simple, fiable y deja al usuario
                    // en el estado oficial del módulo (no en una vista parcial).
                    if (reload) { window.location.href = reload; }
                    else { window.location.reload(); }
                });
            }
            var fixBtn = document.getElementById('tpvsync-syncstatus-fix');
            if (fixBtn) {
                fixBtn.addEventListener('click', function () {
                    // Restauramos primero la página original (incluye modal,
                    // bindings, etc) y luego arrancamos el flujo de divergencia.
                    pageRoot.innerHTML = savedHtml;
                    // Re-bind: el wizard antiguo añade sus listeners en
                    // initWizard(); como acabamos de pisar el DOM, los
                    // reaplicamos.
                    document.querySelectorAll('.tpvsync-wizard[data-tpvsync-ajax]').forEach(initWizard);
                    // Pequeño delay para asegurar que showOverlay encuentra
                    // el modal recién recreado en el DOM.
                    setTimeout(function () {
                        showOverlay('check');
                        runDivergenceAnalysis('check');
                    }, 50);
                });
            }
            // Scroll arriba para que el usuario vea bien la pantalla.
            try { pageRoot.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (_) {}
        }

        // ── Análisis de divergencia post-push/pull ──────────────────────────
        // Tras un push/pull "exitoso", consultamos si hay productos en el
        // lado opuesto que no llegaron a este. Si los hay, mostramos un
        // dialog con 3 opciones (importar / desactivar / posponer) para que
        // el merchant decida qué hacer con esa divergencia.
        function runDivergenceAnalysis(prevAction) {
            // Cambiamos el modal a "analizando…" mientras consultamos.
            finalEl.hidden = true;
            countsEl.textContent = 'Analizando…';
            pctEl.textContent = '';
            etaEl.textContent = '';
            barEl.classList.add('tpvsync-progress-bar-indeterminate');
            barEl.style.width = '100%';
            titleEl.textContent = 'Comprobando si quedan productos por sincronizar…';

            var url = ajaxUrl
                + (ajaxUrl.indexOf('?') === -1 ? '?' : '&')
                + 'action=analyze_divergence&token=' + encodeURIComponent(token);
            fetch(url, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d || d.error) {
                        // Si falla el análisis, cerramos el modal sin bloquear:
                        // mejor no asustar al merchant por algo de telemetría.
                        return;
                    }
                    var onlyTpv = parseInt(d.only_in_tpv_count || 0, 10);
                    var onlyPs  = parseInt(d.only_in_ps_count  || 0, 10);
                    // Si no hay divergencia relevante, mantenemos el "completado".
                    if (onlyTpv === 0 && onlyPs === 0) {
                        finishOkClean();
                        return;
                    }
                    showDivergenceDialog(prevAction, d);
                })
                .catch(function () {
                    // Silencioso. El merchant ya tiene "completado" y puede usar
                    // el botón Reconciliar más tarde si lo necesita.
                });
        }

        function finishOkClean() {
            barEl.classList.remove('tpvsync-progress-bar-indeterminate');
            barEl.style.width = '100%';
            countsEl.textContent = '✓ Sin divergencias';
            pctEl.textContent = '100%';
            finalEl.hidden = false;
        }

        // Guardamos el contenedor (parent del wizard) ANTES de cualquier
        // innerHTML, porque tras el primer reemplazo wizard.parentElement
        // sería null (el wizard quedaría detached) y los siguientes pasos
        // no encontrarían dónde renderizar.
        var divPageRoot = null;

        function showDivergenceDialog(prevAction, d) {
            // Cierre del modal de progreso — la divergencia se renderiza
            // como página completa, no como modal flotante.
            hideOverlay();

            divPageRoot = wizard.parentElement; // capturado UNA vez
            if (!divPageRoot) { return; }

            var onlyTpv = parseInt(d.only_in_tpv_count || 0, 10);

            // Si no hay nada que resolver, mensaje breve y salir.
            if (onlyTpv === 0) {
                renderDivergencePage('success', {message: 'Tu tienda y el TPV están totalmente sincronizados.'});
                return;
            }

            // Step 1: presentar diagnóstico + 3 opciones.
            renderDivergenceStep1(d);
        }

        // ── PÁGINAS DEL WIZARD DE DIVERGENCIA ───────────────────────────────
        // Reemplazamos el contenido del módulo (no modal) por una página con
        // pasos claros: 1 = qué hacer, 2 = procesando, 3 = éxito o error.
        // Volver al módulo normal: window.location.href = reload.

        function renderDivergenceStep1(d) {
            var pageRoot = divPageRoot || wizard.parentElement;
            if (!pageRoot) { return; }
            var onlyTpv = parseInt(d.only_in_tpv_count || 0, 10);
            var sample = d.only_in_tpv || [];
            var sampleHtml = sample.slice(0, 8).map(function (p) {
                var name = (p.name || '').toString();
                if (name.length > 60) name = name.substring(0, 57) + '…';
                return '<li>' + escapeHtml(name)
                     + ' <span style="color:#888">(modelo ' + escapeHtml(p.model || '') + ')</span></li>';
            }).join('');
            var moreHtml = sample.length > 8
                ? '<li style="color:#888;font-style:italic">… y ' + (onlyTpv - 8) + ' productos más</li>'
                : '';

            var html =
                '<div class="tpvsync-div-page">'
                +   '<div class="tpvsync-div-steps">'
                +     '<div class="tpvsync-div-step active"><span class="tpvsync-div-step-num">1</span> Decidir</div>'
                +     '<div class="tpvsync-div-step"><span class="tpvsync-div-step-num">2</span> Procesar</div>'
                +     '<div class="tpvsync-div-step"><span class="tpvsync-div-step-num">3</span> Resumen</div>'
                +   '</div>'
                +   '<div class="tpvsync-card">'
                +     '<h2 style="margin:0 0 8px">Hay productos sin sincronizar</h2>'
                +     '<p style="font-size:14px;color:#374151;margin:0 0 18px;line-height:1.5">'
                +       'Detectamos <strong>' + onlyTpv + ' productos</strong> en el TPV que no están en tu tienda online. '
                +       'Probablemente son productos que vendes solo en tienda física, '
                +       'o que aún no habías subido a la tienda online.'
                +     '</p>'
                +     (sampleHtml
                        ? '<details style="margin:0 0 18px;background:#f8fafc;padding:10px 14px;border-radius:6px;">'
                          + '<summary style="cursor:pointer;color:#475569;font-size:13px;">Ver ejemplos (' + Math.min(8, onlyTpv) + ' de ' + onlyTpv + ')</summary>'
                          + '<ul style="margin:10px 0 0 20px;color:#475569;font-size:13px;line-height:1.6">' + sampleHtml + moreHtml + '</ul>'
                          + '</details>'
                        : '')
                +     '<h3 style="margin:18px 0 12px;font-size:15px">¿Qué prefieres?</h3>'
                +     '<div class="tpvsync-div-options">'
                +       '<button type="button" class="tpvsync-div-option tpvsync-div-option-primary" id="tpvsync-div-import">'
                +         '<span class="tpvsync-div-option-icon">⬇️</span>'
                +         '<span class="tpvsync-div-option-body">'
                +           '<strong>Traerlos a la tienda online</strong>'
                +           '<span class="tpvsync-div-option-hint">Recomendado si los vendes online. Crea los productos en tu tienda con sus precios y datos.</span>'
                +         '</span>'
                +       '</button>'
                +       '<button type="button" class="tpvsync-div-option tpvsync-div-option-warn" id="tpvsync-div-deactivate">'
                +         '<span class="tpvsync-div-option-icon">🚫</span>'
                +         '<span class="tpvsync-div-option-body">'
                +           '<strong>Despublicarlos del TPV</strong>'
                +           '<span class="tpvsync-div-option-hint">Si solo los vendes en presencial. Quedan inactivos en el TPV pero no se borran — los puedes reactivar luego.</span>'
                +         '</span>'
                +       '</button>'
                +       '<button type="button" class="tpvsync-div-option tpvsync-div-option-quiet" id="tpvsync-div-postpone">'
                +         '<span class="tpvsync-div-option-icon">⏰</span>'
                +         '<span class="tpvsync-div-option-body">'
                +           '<strong>Decidirlo después</strong>'
                +           '<span class="tpvsync-div-option-hint">Vuelvo a la página principal sin tocar nada. Puedo resolverlo más adelante desde el botón "Resolver divergencias".</span>'
                +         '</span>'
                +       '</button>'
                +     '</div>'
                +   '</div>'
                + '</div>';

            pageRoot.innerHTML = html;
            if (window.console) console.log('[tpvsync] step1 rendered');

            // addEventListener directo por id. Como pageRoot.innerHTML acaba
            // de regenerar el DOM, los nuevos botones existen ahora. Una
            // bandera guarda contra clicks múltiples si el merchant hace
            // doble-click rápido.
            var clicked = false;
            function bindDivBtn(id, fn) {
                var b = document.getElementById(id);
                if (!b) {
                    if (window.console) console.warn('[tpvsync] button missing:', id);
                    return;
                }
                b.addEventListener('click', function (ev) {
                    ev.preventDefault();
                    if (clicked) return;
                    clicked = true;
                    fn();
                });
            }
            bindDivBtn('tpvsync-div-import', function () {
                if (window.console) console.log('[tpvsync] click import');
                runDivergenceAction('import_tpv_orphans', d);
            });
            bindDivBtn('tpvsync-div-deactivate', function () {
                if (window.console) console.log('[tpvsync] click deactivate');
                if (!confirm('¿Despublicar ' + onlyTpv + ' productos del TPV? Quedan inactivos pero no se borran — los puedes reactivar luego.')) {
                    clicked = false;
                    return;
                }
                runDivergenceAction('deactivate_tpv_orphans', d);
            });
            bindDivBtn('tpvsync-div-postpone', function () {
                if (window.console) console.log('[tpvsync] click postpone');
                runDivergenceAction('postpone', d);
            });
        }

        function renderDivergenceStep2(actionLabel, totalToDo) {
            var pageRoot = divPageRoot || wizard.parentElement;
            if (window.console) console.log('[tpvsync] step2 pageRoot:', pageRoot, 'connected:', pageRoot && pageRoot.isConnected);
            if (!pageRoot) { return; }
            var html =
                '<div class="tpvsync-div-page">'
                +   '<div class="tpvsync-div-steps">'
                +     '<div class="tpvsync-div-step done"><span class="tpvsync-div-step-num">✓</span> Decidir</div>'
                +     '<div class="tpvsync-div-step active"><span class="tpvsync-div-step-num">2</span> Procesar</div>'
                +     '<div class="tpvsync-div-step"><span class="tpvsync-div-step-num">3</span> Resumen</div>'
                +   '</div>'
                +   '<div class="tpvsync-card" style="text-align:center">'
                +     '<h2 style="margin:0 0 18px">' + escapeHtml(actionLabel) + '</h2>'
                +     '<div class="tpvsync-progress-bar-wrap" style="max-width:400px;margin:0 auto 12px">'
                +       '<div class="tpvsync-progress-bar tpvsync-progress-bar-indeterminate" id="tpvsync-div-bar" style="width:100%"></div>'
                +     '</div>'
                +     '<p id="tpvsync-div-status" style="font-size:13px;color:#6b7280;margin:0">Procesando, no cierres la página…</p>'
                +   '</div>'
                + '</div>';
            pageRoot.innerHTML = html;
        }

        function renderDivergencePage(state, opts) {
            var pageRoot = divPageRoot || wizard.parentElement;
            if (!pageRoot) { return; }
            opts = opts || {};
            var stepClass = state === 'success' ? 'done' : 'fail';
            var icon = state === 'success' ? '✓' : '✕';
            var color = state === 'success' ? '#047857' : '#b91c1c';
            var bg = state === 'success' ? '#ecfdf5' : '#fef2f2';
            var border = state === 'success' ? '#a7f3d0' : '#fecaca';

            var html =
                '<div class="tpvsync-div-page">'
                +   '<div class="tpvsync-div-steps">'
                +     '<div class="tpvsync-div-step done"><span class="tpvsync-div-step-num">✓</span> Decidir</div>'
                +     '<div class="tpvsync-div-step done"><span class="tpvsync-div-step-num">✓</span> Procesar</div>'
                +     '<div class="tpvsync-div-step ' + stepClass + '"><span class="tpvsync-div-step-num">' + icon + '</span> Resumen</div>'
                +   '</div>'
                +   '<div class="tpvsync-card" style="text-align:center;background:' + bg + ';border:1px solid ' + border + '">'
                +     '<div style="font-size:48px;line-height:1;margin:0 0 12px;color:' + color + '">' + icon + '</div>'
                +     '<h2 style="margin:0 0 8px;color:' + color + '">' + (state === 'success' ? '¡Listo!' : 'No se pudo completar') + '</h2>'
                +     '<p style="font-size:14px;color:#374151;margin:0 0 22px;line-height:1.5">' + escapeHtml(opts.message || '') + '</p>'
                +     '<button type="button" class="tpvsync-btn tpvsync-btn-primary" onclick="window.location.href=\'' + (reload || '') + '\'">Volver al panel</button>'
                +   '</div>'
                + '</div>';
            pageRoot.innerHTML = html;
        }

        function runDivergenceAction(divAction, d) {
            if (window.console) console.log('[tpvsync] runDivergenceAction', divAction);
            var labelByAction = {
                import_tpv_orphans: 'Importando productos del TPV…',
                deactivate_tpv_orphans: 'Despublicando productos del TPV…',
                postpone: 'Guardando para más tarde…',
            };
            var totalToDo = parseInt(d.only_in_tpv_count || 0, 10);
            renderDivergenceStep2(labelByAction[divAction] || 'Procesando…', totalToDo);

            var verb = divAction === 'import_tpv_orphans'
                ? 'importados a tu tienda online'
                : 'despublicados del TPV';
            var keyDone = divAction === 'import_tpv_orphans' ? 'imported' : 'deactivated';

            // Postpone es one-shot, sin loop.
            if (divAction === 'postpone') {
                postOneBatch(divAction, [], function (res) {
                    if (res && res.error) {
                        renderDivergencePage('fail', {message: humanizeError(res.error)});
                        return;
                    }
                    renderDivergencePage('success', {
                        message: 'Pospuesto. Cuando quieras retomarlo, pulsa el botón "Resolver divergencias" en el panel.'
                    });
                });
                return;
            }

            // Loop: el endpoint procesa hasta 100 productos por llamada y
            // analyze_divergence devuelve los primeros 100 huérfanos. Tras
            // cada lote pedimos otra "foto fresca" del análisis y procesamos
            // los siguientes 100, hasta que no quedan huérfanos. La barra
            // de progreso se actualiza con el total real (totalToDo) menos
            // los que van quedando.
            var doneTotal = 0;
            var skippedTotal = 0;
            var lastBatchIds = (d.only_in_tpv || []).map(function (p) { return p.tpv_product_id; });

            function processBatch(ids) {
                postOneBatch(divAction, ids, function (res) {
                    if (!res || res.error) {
                        renderDivergencePage('fail', {message: humanizeError(res ? res.error : 'network_error')});
                        return;
                    }
                    doneTotal += parseInt(res[keyDone] || 0, 10);
                    skippedTotal += parseInt(res.skipped || 0, 10);
                    updateStep2Progress(doneTotal + skippedTotal, totalToDo);

                    // Tras procesar este lote, refrescamos el análisis
                    // y continuamos si quedan huérfanos. El backend
                    // mantiene un blocklist de IDs no procesables
                    // (precio negativo, etc) que analyze_divergence
                    // ya excluye, así que el bucle termina garantizado.
                    refreshDivergenceAndContinue();
                });
            }

            function refreshDivergenceAndContinue() {
                var url = ajaxUrl
                    + (ajaxUrl.indexOf('?') === -1 ? '?' : '&')
                    + 'action=analyze_divergence&token=' + encodeURIComponent(token);
                fetch(url, { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (fresh) {
                        if (!fresh || fresh.error) {
                            renderDivergencePage('fail', {message: humanizeError(fresh ? fresh.error : 'analyze_failed')});
                            return;
                        }
                        var freshIds = (fresh.only_in_tpv || []).map(function (p) { return p.tpv_product_id; });
                        var freshCount = parseInt(fresh.only_in_tpv_count || 0, 10);
                        if (freshCount === 0 || freshIds.length === 0) {
                            // Hemos terminado.
                            var msg = doneTotal + ' productos ' + verb + '.';
                            if (skippedTotal > 0) {
                                msg += ' (' + skippedTotal + ' productos no se pudieron importar por tener datos inválidos en el TPV — precio negativo o sin model. Los puedes corregir en el TPV y reintentar más tarde.)';
                            }
                            renderDivergencePage('success', {message: msg});
                            return;
                        }
                        // Continuar con los siguientes 100.
                        processBatch(freshIds);
                    })
                    .catch(function (err) {
                        renderDivergencePage('fail', {message: humanizeError(err && err.message ? err.message : 'network_error')});
                    });
            }

            processBatch(lastBatchIds);
        }

        function postOneBatch(divAction, ids, cb) {
            var url = ajaxUrl
                + (ajaxUrl.indexOf('?') === -1 ? '?' : '&')
                + 'action=divergence_action&token=' + encodeURIComponent(token);
            var formData = new FormData();
            formData.append('div_action', divAction);
            if (divAction !== 'postpone') {
                formData.append('tpv_ids', ids.join(','));
            }
            fetch(url, { credentials: 'same-origin', method: 'POST', body: formData })
                .then(function (r) { return r.json(); })
                .then(cb)
                .catch(function (err) {
                    cb({error: err && err.message ? err.message : 'network_error'});
                });
        }

        function updateStep2Progress(done, total) {
            var bar = document.getElementById('tpvsync-div-bar');
            var status = document.getElementById('tpvsync-div-status');
            if (!bar || !status) return;
            var pct = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0;
            bar.classList.remove('tpvsync-progress-bar-indeterminate');
            bar.style.width = pct + '%';
            status.textContent = done + ' / ' + total + ' procesados (' + pct + '%)';
        }

        function escapeHtml(s) {
            return String(s).replace(/[&<>"']/g, function (c) {
                return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
            });
        }
    }

    function init() {
        // Failsafe: si por cualquier razón al cargar la página queda un
        // overlay visible (HTML antiguo cacheado, JS interrumpido a media
        // ejecución, navegador con bug), nos aseguramos de cerrarlo. Mejor
        // empezar limpio que dejar al merchant atrapado en un modal sin
        // listeners enganchados.
        var overlay = document.getElementById('tpvsync-progress-overlay');
        if (overlay && !overlay.hidden) {
            overlay.hidden = true;
            document.body.classList.remove('tpvsync-modal-open');
        }
        // Doble seguro: vincular la X y Esc YA, antes de inicializar el
        // wizard. Si initWizard falla por cualquier razón, al menos el botón
        // de cierre seguirá funcionando.
        var earlyX = document.getElementById('tpvsync-progress-x');
        if (earlyX) {
            earlyX.addEventListener('click', function () {
                if (overlay) {
                    overlay.hidden = true;
                    document.body.classList.remove('tpvsync-modal-open');
                }
            });
        }
        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape' && overlay && !overlay.hidden) {
                overlay.hidden = true;
                document.body.classList.remove('tpvsync-modal-open');
            }
        });

        document.querySelectorAll('.tpvsync-action').forEach(armButton);
        document.querySelectorAll('.tpvsync-wizard[data-tpvsync-ajax]').forEach(initWizard);
    }

    if (document.readyState !== 'loading') {
        init();
    } else {
        document.addEventListener('DOMContentLoaded', init);
    }
})();
