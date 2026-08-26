<?php
declare(strict_types=1);
/**
 * Catinfog Conector para PrestaShop
 *
 * Conecta una tienda PrestaShop con el TPV Catinfog vía la API v1.
 * Sincroniza productos, stock, pedidos y devoluciones en ambas direcciones.
 *
 * Equivalente al plugin de WooCommerce (woocomerce-pos-sync) pero adaptado a
 * PrestaShop 9.x: usa hooks, ObjectModels, Controllers y CronJobs de PS en
 * vez de hooks de WordPress.
 *
 * Flujos soportados:
 *   PS → TPV  productos (alta/edit/baja), stock, pedidos, reembolsos
 *   TPV → PS  productos, stock, cambios de estado, devoluciones
 *
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'tpvsync/classes/TpvSyncSecrets.php';
require_once _PS_MODULE_DIR_ . 'tpvsync/classes/TpvSyncNotifications.php';
require_once _PS_MODULE_DIR_ . 'tpvsync/classes/TpvSyncApiClient.php';
require_once _PS_MODULE_DIR_ . 'tpvsync/classes/TpvSyncCircuitBreaker.php';
require_once _PS_MODULE_DIR_ . 'tpvsync/classes/TpvSyncLog.php';
require_once _PS_MODULE_DIR_ . 'tpvsync/classes/TpvSyncProduct.php';
require_once _PS_MODULE_DIR_ . 'tpvsync/classes/TpvSyncOrder.php';
require_once _PS_MODULE_DIR_ . 'tpvsync/classes/TpvSyncQueue.php';
require_once _PS_MODULE_DIR_ . 'tpvsync/classes/TpvSyncWebhook.php';
require_once _PS_MODULE_DIR_ . 'tpvsync/classes/TpvSyncCustomer.php';

class TpvSync extends Module
{
    public const VERSION = '1.0.2';

    /**
     * Hooks de PrestaShop a los que se suscribe el módulo.
     * Escogidos para tener paridad funcional con el plugin de WooCommerce:
     *   - actionProductUpdate/Add/Delete        → push catálogo PS→TPV
     *   - actionUpdateQuantity                  → push stock PS→TPV
     *   - actionValidateOrder                   → crea pedido en TPV al pagar
     *   - actionOrderStatusPostUpdate           → propaga cambios de estado
     *   - displayBackOfficeHeader               → recursos CSS/JS del admin
     *   - actionObjectOrderSlipAddAfter         → reembolsos (devoluciones)
     */
    public const HOOKS = [
        // PS 9: actionProductSave dispara tras create Y tras update (cubre ambos).
        // actionProductAdd sigue existiendo pero SOLO en algunos flujos legacy;
        // actionProductSave es el canónico. Nos suscribimos a ambos por seguridad
        // y evitamos doble-push con el guard $GLOBALS['tpvsync_pushed_in_request'].
        'actionProductSave',
        'actionProductAdd',
        'actionProductUpdate',
        'actionProductDelete',
        'actionUpdateQuantity',
        'actionValidateOrderBefore',
        'actionValidateOrder',
        'actionOrderStatusPostUpdate',
        'actionObjectOrderSlipAddAfter',
        // Customer sync (paridad con plugin WC):
        // actionObjectCustomerAddAfter dispara tras Customer::add() (front signup,
        // admin nuevo, REST). actionObjectCustomerUpdateAfter cubre ediciones.
        // actionObjectAddressAddAfter/UpdateAfter capturan los cambios de la
        // dirección (que en PS van por su propia tabla, no en customer).
        'actionObjectCustomerAddAfter',
        'actionObjectCustomerUpdateAfter',
        'actionObjectCustomerDeleteBefore',
        'actionObjectAddressAddAfter',
        'actionObjectAddressUpdateAfter',
        'displayBackOfficeHeader',
    ];

    public function __construct()
    {
        $this->name = 'tpvsync';
        $this->tab = 'administration';
        $this->version = self::VERSION;
        $this->author = 'Catinfog';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.7', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Catinfog Conector (TPV)');
        $this->description = $this->l(
            'Conecta tu PrestaShop con el TPV Catinfog. Sincroniza productos, stock, ventas y devoluciones.'
        );
        $this->confirmUninstall = $this->l(
            '¿Seguro? Se desconectará del TPV y se borrarán las credenciales y la cola de reintentos.'
        );
    }

    // ─── Install / Uninstall ─────────────────────────────────────────────────

    public function install(): bool
    {
        if (!parent::install()) {
            return false;
        }

        foreach (self::HOOKS as $hook) {
            if (!$this->registerHook($hook)) {
                return false;
            }
        }

        if (!$this->installDb()) {
            return false;
        }

        // Config por defecto
        Configuration::updateValue('TPVSYNC_API_URL', 'https://tu-tpv.ejemplo.com/api/v1');
        Configuration::updateValue('TPVSYNC_CLIENT_ID', 'prestashop');
        Configuration::updateValue('TPVSYNC_CLIENT_SECRET', '');
        Configuration::updateValue('TPVSYNC_WEBHOOK_SECRET', '');
        Configuration::updateValue('TPVSYNC_WEBHOOK_ID', 0);
        Configuration::updateValue('TPVSYNC_MODULE_CATALOG', 1);
        Configuration::updateValue('TPVSYNC_MODULE_ORDERS', 0);
        Configuration::updateValue('TPVSYNC_CB_STATE', 'closed');
        Configuration::updateValue('TPVSYNC_CB_FAILS', 0);
        Configuration::updateValue('TPVSYNC_CB_OPEN_UNTIL', 0);

        return true;
    }

    public function uninstall(): bool
    {
        // Intento de desregistro limpio del webhook en el TPV. Si falla (TPV caído,
        // credenciales rotadas, etc.) seguimos con la desinstalación — el webhook
        // huérfano en el TPV es preferible a bloquear al admin.
        try {
            $existingId = (int) Configuration::get('TPVSYNC_WEBHOOK_ID');
            if ($existingId > 0) {
                (new TpvSyncApiClient())->delete('/webhooks/' . $existingId);
            }
        } catch (\Throwable $e) {
            // no-op — no queremos abortar el uninstall por esto
        }

        Configuration::deleteByName('TPVSYNC_API_URL');
        Configuration::deleteByName('TPVSYNC_CLIENT_ID');
        Configuration::deleteByName('TPVSYNC_CLIENT_SECRET');
        Configuration::deleteByName('TPVSYNC_SHARED_SECRET');
        Configuration::deleteByName('TPVSYNC_WEBHOOK_SECRET');
        Configuration::deleteByName('TPVSYNC_WEBHOOK_ID');
        Configuration::deleteByName('TPVSYNC_TOKEN_CACHE');
        Configuration::deleteByName('TPVSYNC_MODULE_CATALOG');
        Configuration::deleteByName('TPVSYNC_MODULE_ORDERS');
        Configuration::deleteByName('TPVSYNC_CB_STATE');
        Configuration::deleteByName('TPVSYNC_CB_FAILS');
        Configuration::deleteByName('TPVSYNC_CB_OPEN_UNTIL');

        $this->uninstallDb();

        return parent::uninstall();
    }

    private function installDb(): bool
    {
        $sqlFile = _PS_MODULE_DIR_ . 'tpvsync/sql/install.sql';
        if (!is_readable($sqlFile)) {
            return true;
        }
        $sql = file_get_contents($sqlFile);
        $sql = str_replace(
            ['PREFIX_', 'ENGINE_TYPE', 'CHARSET_TYPE'],
            [_DB_PREFIX_, _MYSQL_ENGINE_, 'utf8mb4'],
            $sql
        );
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if (!Db::getInstance()->execute($stmt)) {
                return false;
            }
        }
        return true;
    }

    private function uninstallDb(): bool
    {
        $sqlFile = _PS_MODULE_DIR_ . 'tpvsync/sql/uninstall.sql';
        if (!is_readable($sqlFile)) {
            return true;
        }
        $sql = str_replace('PREFIX_', _DB_PREFIX_, file_get_contents($sqlFile));
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            Db::getInstance()->execute($stmt);
        }
        return true;
    }

    // ─── Factorías de servicios ──────────────────────────────────────────────
    //
    // Cada llamada construye instancias nuevas para evitar estado compartido
    // entre requests (PHP-FPM comparte opcode cache pero no memoria de objetos
    // entre requests distintos). El coste es despreciable y el aislamiento
    // hace el módulo más predecible.

    public function api(): TpvSyncApiClient
    {
        return new TpvSyncApiClient();
    }

    public function products(): TpvSyncProduct
    {
        return new TpvSyncProduct($this->api());
    }

    public function orders(): TpvSyncOrder
    {
        return new TpvSyncOrder($this->api());
    }

    public function queue(): TpvSyncQueue
    {
        return new TpvSyncQueue($this->api());
    }

    // ─── Página de configuración en admin ────────────────────────────────────

    public function getContent(): string
    {
        // ── AJAX endpoints para el panel de mapeo de impuestos ────────────
        // Antes de cualquier render: si llega una llamada AJAX, respondemos
        // JSON puro y salimos. PS no tiene un AJAX controller "limpio" desde
        // un módulo sin controllers/admin/, así que reutilizamos esta misma
        // ruta: el JS hace POST a la URL del módulo con `tpvsync_ajax=...`.
        $ajax = (string) Tools::getValue('tpvsync_ajax', '');
        if ($ajax !== '') {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            try {
                if ($ajax === 'tax_map_load')      { echo json_encode($this->ajaxTaxMapLoad()); }
                elseif ($ajax === 'tax_map_save') { echo json_encode($this->ajaxTaxMapSave()); }
                elseif ($ajax === 'bulk_customers') { echo json_encode($this->ajaxBulkCustomers()); }
                elseif ($ajax === 'health_check')   { echo json_encode($this->ajaxHealthCheck()); }
                else { http_response_code(400); echo json_encode(['error' => 'unknown_ajax']); }
            } catch (\Throwable $e) {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
            exit;
        }

        $output = '';

        // ── Auto-clear cache al detectar versión nueva del módulo ──────────
        // Si el merchant subió una versión nueva por FTP / git pull (típico
        // en updates), PrestaShop NO regenera class_index.php automáticamente
        // hasta que alguien pulse "Borrar caché" en Rendimiento. Eso provoca
        // 404 en front controllers nuevos (ej. /module/tpvsync/syncbatch) y
        // el merchant ve un AJAX que "no hace nada" sin pista alguna. Aquí
        // detectamos cambio de versión y limpiamos el class_index nosotros.
        $lastSeen = (string) Configuration::get('TPVSYNC_INSTALLED_VERSION');
        if ($lastSeen !== self::VERSION) {
            $cacheClass = _PS_CACHE_DIR_ . 'class_index.php';
            if (file_exists($cacheClass) && is_writable($cacheClass)) {
                @unlink($cacheClass);
            }
            // Borramos también los FrontContainer compilados — contienen el
            // mapa de routing que resuelve /module/tpvsync/<controller>.
            foreach (glob(_PS_CACHE_DIR_ . 'FrontContainer*.php*') ?: [] as $f) {
                if (is_writable($f)) { @unlink($f); }
            }
            Configuration::updateValue('TPVSYNC_INSTALLED_VERSION', self::VERSION);
            // Aprovechamos la actualización para migrar secrets en plaintext
            // (instalaciones legacy previas al cifrado) a ciphertext. Idempotente.
            TpvSyncSecrets::migratePlaintext();
        }

        // ── Detección de sync inicial inacabado ─────────────────────────────
        // Si hay un offset PUSH/PULL > 0 con timestamp viejo (>1h), significa
        // que el merchant cerró la pestaña a media import (timeout, sin querer,
        // bloqueo del navegador, etc.). Banner + dos botones: Reanudar (sigue
        // por donde iba) o Empezar de cero (resetea offsets).
        $stalePushOffset = (int) Configuration::get('TPVSYNC_PUSH_OFFSET');
        $stalePullOffset = (int) Configuration::get('TPVSYNC_IMPORT_OFFSET');
        $stalePushAt     = (int) Configuration::get('TPVSYNC_PUSH_LAST_AT');
        $stalePullAt     = (int) Configuration::get('TPVSYNC_PULL_LAST_AT');
        $oneHourAgo      = time() - 3600;
        $orphanPush      = $stalePushOffset > 0 && $stalePushAt > 0 && $stalePushAt < $oneHourAgo;
        $orphanPull      = $stalePullOffset > 0 && $stalePullAt > 0 && $stalePullAt < $oneHourAgo;

        if (Tools::isSubmit('tpvsync_sync_reset')) {
            // Botón "Empezar de cero": limpiamos offsets y acumuladores.
            foreach ([
                'TPVSYNC_PUSH_OFFSET','TPVSYNC_PUSH_LAST_AT',
                'TPVSYNC_PUSH_SENT_TOTAL','TPVSYNC_PUSH_SKIPPED_TOTAL','TPVSYNC_PUSH_ERRORS_TOTAL',
                'TPVSYNC_IMPORT_OFFSET','TPVSYNC_PULL_LAST_AT',
                'TPVSYNC_PULL_CREATED_TOTAL','TPVSYNC_PULL_UPDATED_TOTAL','TPVSYNC_PULL_ERRORS_TOTAL',
            ] as $k) {
                Configuration::deleteByName($k);
            }
            $cleanUrl = AdminController::$currentIndex . '&configure=' . $this->name
                      . '&token=' . Tools::getAdminTokenLite('AdminModules');
            Tools::redirectAdmin($cleanUrl);
            return '';
        }

        // Banner: si TpvSyncSecrets ha registrado fallo de descifrado, lo
        // exponemos al admin con instrucciones accionables. Sin esto, el
        // cliente solo ve "no se puede conectar" sin pista de la causa.
        $decryptFlag = (string) Configuration::get('TPVSYNC_SECRET_DECRYPT_FAILED');
        if ($decryptFlag !== '') {
            $info = json_decode($decryptFlag, true) ?: [];
            $output .= '<div class="tpvsync-page"><div class="tpvsync-card" '
                . 'style="background:#fee2e2;border-color:#fca5a5">'
                . '<strong>' . htmlspecialchars($this->l('Credenciales no descifrables'), ENT_QUOTES) . '</strong><br>'
                . htmlspecialchars($this->l('Las credenciales guardadas no se pueden descifrar. Esto pasa cuando la instalación se restaura desde un backup, se migra a otro servidor o se regeneran las claves de PrestaShop. Pega de nuevo el Client Secret abajo y se reinicializará.'), ENT_QUOTES)
                . ' <small>(' . htmlspecialchars((string) ($info['reason'] ?? 'unknown'), ENT_QUOTES) . ')</small>'
                . '</div></div>';
        }

        if ($orphanPush || $orphanPull) {
            $which     = $orphanPush ? 'Push (PrestaShop → TPV)' : 'Pull (TPV → PrestaShop)';
            $offsetVal = $orphanPush ? $stalePushOffset : $stalePullOffset;
            $resetUrl  = AdminController::$currentIndex . '&configure=' . $this->name
                       . '&token=' . Tools::getAdminTokenLite('AdminModules')
                       . '&tpvsync_sync_reset=1';
            $confirmReset = htmlspecialchars(
                $this->l('¿Empezar de cero? Se perderá el progreso actual.'),
                ENT_QUOTES
            );
            $output .= '<div class="tpvsync-page"><div class="tpvsync-card" '
                . 'style="background:#fef3c7;border-color:#fcd34d">'
                . '<strong>' . htmlspecialchars($this->l('Sincronización inicial inacabada'), ENT_QUOTES) . '</strong><br>'
                . sprintf(
                    htmlspecialchars($this->l('Detectamos una sincronización %s que quedó a medias hace más de 1 hora (en el producto %d). Puedes reanudarla pulsando el mismo botón del wizard, o empezar de cero.'), ENT_QUOTES),
                    htmlspecialchars($which, ENT_QUOTES),
                    $offsetVal
                )
                . '<div style="margin-top:10px"><a class="tpvsync-btn tpvsync-btn-secondary" '
                . 'onclick="return confirm(\'' . $confirmReset . '\');" '
                . 'href="' . htmlspecialchars($resetUrl, ENT_QUOTES) . '">'
                . htmlspecialchars($this->l('Empezar de cero'), ENT_QUOTES) . '</a></div>'
                . '</div></div>';
        }

        if (Tools::isSubmit('submit' . $this->name)) {
            $url = trim((string) Tools::getValue('TPVSYNC_API_URL'));
            $cid = trim((string) Tools::getValue('TPVSYNC_CLIENT_ID'));
            $sec = trim((string) Tools::getValue('TPVSYNC_CLIENT_SECRET'));

            if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
                $output .= $this->displayError($this->l('URL de la API inválida.'));
            } else {
                Configuration::updateValue('TPVSYNC_API_URL', rtrim($url, '/'));
                Configuration::updateValue('TPVSYNC_CLIENT_ID', $cid);
                if ($sec !== '') {
                    TpvSyncSecrets::set('TPVSYNC_CLIENT_SECRET', $sec);
                }
                Configuration::updateValue('TPVSYNC_MODULE_CATALOG', (int) Tools::getValue('TPVSYNC_MODULE_CATALOG'));
                Configuration::updateValue('TPVSYNC_MODULE_ORDERS', (int) Tools::getValue('TPVSYNC_MODULE_ORDERS'));
                $output .= $this->displayConfirmation($this->l('Configuración guardada.'));
            }
        }

        // ── PRG (Post-Redirect-Get) para acciones mutadoras ──────────────
        // Las acciones que cambian estado se ejecutan UNA vez y redirigen a
        // la URL limpia (sin el query param). Sin esto, F5 reenviaba la
        // acción (síntoma observado: refrescar tras pulsar Pausar volvía a
        // pausar el cliente, generando un loop con el TPV).
        //
        // Tras la mutación pasamos el resultado al GET via `?tpvsync_msg=<code>`
        // (códigos cortos: paused_ok, paused_fail, etc.) y al recargar mostramos
        // la confirmación/error correspondiente al inicio del output.
        $mutatingActions = [
            'tpvsync_activate'          => ['renderActivateSync',    'activated'],
            'tpvsync_pause'             => ['renderPauseSync',       'paused'],
            'tpvsync_disconnect'        => ['renderPauseSync',       'paused'],   // alias legacy
            'tpvsync_resume'            => ['renderResumeSync',      'resumed'],
            'tpvsync_delete_connection' => ['renderDeleteConnection', 'deleted'],
            'tpvsync_test'              => ['renderTestConnection',  'tested'],
            'tpvsync_import'            => ['renderImportAll',       'imported'],
            'tpvsync_process_queue'     => ['renderProcessQueue',    'queue_ok'],
            'tpvsync_reconcile'         => ['renderReconcile',       'reconciled'],
        ];
        foreach ($mutatingActions as $param => [$method, $msgCode]) {
            if (Tools::isSubmit($param)) {
                // Ejecutar (devuelve HTML que normalmente persistiríamos —
                // aquí lo descartamos porque sólo nos interesa el efecto).
                $this->{$method}();
                // Verificación post-acción: para `tpvsync_activate` no podemos
                // confiar ciegamente en que el render haya completado el flujo
                // (el registro del webhook puede fallar dejando WEBHOOK_ID=0).
                // Si tras la acción el estado real no refleja éxito, redirigimos
                // con un código de error para que el usuario vea un banner
                // honesto en lugar de un falso "Conexión activa".
                $effectiveCode = $msgCode;
                if ($param === 'tpvsync_activate') {
                    $whId = (int) Configuration::get('TPVSYNC_WEBHOOK_ID');
                    if ($whId <= 0) {
                        $effectiveCode = 'activate_failed';
                    }
                }
                // Redirect limpio. PrestaShop sanitiza el querystring; usamos
                // un código corto que mapeamos a HTML al recargar.
                $cleanUrl = AdminController::$currentIndex . '&configure=' . $this->name
                          . '&token=' . Tools::getAdminTokenLite('AdminModules')
                          . '&tpvsync_msg=' . urlencode($effectiveCode);
                Tools::redirectAdmin($cleanUrl);
                return ''; // unreachable, defensivo.
            }
        }
        // Tras el redirect, traducir el código a banner.
        $msgCode = (string) Tools::getValue('tpvsync_msg', '');
        if ($msgCode !== '') {
            $msgMap = [
                'paused'     => ['type' => 'warn',    'text' => $this->l('Conexión pausada. Las credenciales se mantienen — pulsa "Reanudar" para volver a sincronizar.')],
                'resumed'    => ['type' => 'ok',      'text' => $this->l('Conexión reanudada. Tu tienda y el TPV están sincronizados de nuevo.')],
                'deleted'    => ['type' => 'warn',    'text' => $this->l('Conexión eliminada. Para volver a conectar, genera nuevas credenciales en el TPV y pégalas aquí.')],
                'activated'  => ['type' => 'ok',      'text' => $this->l('Conexión activa. Tu tienda y el TPV están sincronizados.')],
                'activate_failed' => ['type' => 'warn', 'text' => $this->l('No se pudo completar la conexión. Revisa que la URL del TPV, el Client ID y el Client Secret son correctos, e inténtalo de nuevo.')],
                'reconciled' => ['type' => 'ok',      'text' => $this->l('Reconciliación completada.')],
                'tested'     => ['type' => 'ok',      'text' => $this->l('Conexión probada correctamente.')],
                'imported'   => ['type' => 'ok',      'text' => $this->l('Importación completada.')],
                'queue_ok'   => ['type' => 'ok',      'text' => $this->l('Cola procesada.')],
            ];
            if (isset($msgMap[$msgCode])) {
                $m = $msgMap[$msgCode];
                $output .= ($m['type'] === 'ok')
                    ? $this->displayConfirmation($m['text'])
                    : $this->displayWarning($m['text']);
            }
        }
        // Wizard de sync inicial: una vez conectado, el comerciante elige
        // quién manda (TPV o PrestaShop). Esa decisión arranca un push del
        // catálogo del Principal hacia el Secundario. No se puede saltar:
        // sin esta decisión la sincronización en tiempo real no tiene
        // dirección clara y se rompe en silencio.
        if (Tools::isSubmit('tpvsync_initial_pull')) {
            $output .= $this->renderInitialSyncPull();
        }
        if (Tools::isSubmit('tpvsync_initial_push')) {
            $output .= $this->renderInitialSyncPush();
        }

        // Cargamos el CSS inline directamente en getContent() porque en PS9
        // el hook displayBackOfficeHeader puede no llegar a tiempo dentro de
        // AdminModulesManageController (depende del orden de hooks/controller
        // y del modo de renderizado del BO). Inline garantiza 100% que el CSS
        // siempre aplica, sin depender de addCSS() ni del hook header.
        $cssPath = __DIR__ . '/views/css/admin.css';
        $css = is_readable($cssPath) ? (string) file_get_contents($cssPath) : '';
        $styleTag = $css !== '' ? '<style id="tpvsync-admin-css">' . $css . '</style>' : '';

        // Envolvemos toda la salida en un wrapper namespaced (.tpvsync-page)
        // para que el CSS del módulo NO afecte a otros componentes del admin
        // (sidebar, breadcrumbs, modales globales) que comparten clases bootstrap
        // como .panel, .btn, .form-group.
        // Detectar si toca mostrar el wizard de sync inicial obligatorio:
        // hay webhook activo (== conectado) pero el flag de sync inicial
        // todavía no está marcado. En ese caso reemplazamos las cards
        // normales por el wizard, que el usuario debe completar antes de
        // poder usar el plugin con normalidad.
        $webhookActive = ((int) Configuration::get('TPVSYNC_WEBHOOK_ID')) > 0
                      && (string) Configuration::get('TPVSYNC_WEBHOOK_SECRET') !== '';
        $needsInitialSync = $webhookActive
                         && !((int) Configuration::get('TPVSYNC_INITIAL_SYNC_DONE'));

        $bodyHtml = $output;
        if ($needsInitialSync) {
            $bodyHtml .= $this->renderInitialSyncWizard();
        } else {
            $bodyHtml .= $this->renderForm()
                       . $this->renderActions()
                       . $this->renderHealthPanel()
                       . $this->renderTaxMappingPanel()
                       . $this->renderBulkCustomersPanel()
                       . $this->renderQueueTable()
                       . $this->renderReconcileFooter();
        }
        // El modal de progreso se renderiza SIEMPRE (oculto por defecto). Antes
        // solo aparecía dentro del wizard inicial — eso hacía que el botón
        // "Reconciliar" de renderReconcileFooter fallara silenciosamente porque
        // el JS no encontraba #tpvsync-progress-overlay en el DOM.
        $bodyHtml .= $this->renderProgressModal();

        return $styleTag
             . '<div class="tpvsync-page">'
             . $this->renderHero()
             . $this->renderStatusBanner()
             . $bodyHtml
             . '</div>';
    }

    /**
     * Hero superior — replica el patrón visual del plugin WC: dos badges
     * (PrestaShop ↔ Catinfog) + título + subtítulo. Comunica de un vistazo
     * "qué hace este módulo" sin abrumar al usuario poco experto.
     */
    private function renderHero(): string
    {
        $title = htmlspecialchars($this->l('Catinfog Conector'), ENT_QUOTES);
        $subtitle = htmlspecialchars(
            $this->l('Sincroniza tu tienda PrestaShop con el TPV en tiempo real.'),
            ENT_QUOTES
        );
        $version = isset($this->version) ? htmlspecialchars((string) $this->version, ENT_QUOTES) : '';
        $versionTag = $version !== '' ? '<span class="tpvsync-hero-version">v' . $version . '</span>' : '';

        return <<<HTML
<div class="tpvsync-hero">
    <div class="tpvsync-hero-logos">
        <div class="tpvsync-logo tpvsync-logo-ps">PS</div>
        <span class="tpvsync-arrows">⇄</span>
        <div class="tpvsync-logo tpvsync-logo-cat">Catinfog</div>
    </div>
    <h1>{$title}</h1>
    <p>{$subtitle}</p>
    {$versionTag}
</div>
HTML;
    }

    /**
     * Indicador de estado superior con 4 estados:
     *   - 🟢 ok       : webhook registrado, TPV responde Y catálogos unificados.
     *   - 🟡 pending  : webhook registrado, TPV responde, PERO el merchant no
     *                   ha completado el wizard de unificación inicial. La
     *                   sincro en tiempo real funciona pero los catálogos
     *                   pueden estar descoordinados.
     *   - 🔴 down     : webhook registrado pero el TPV no responde (caído o
     *                   credenciales inválidas) — la sync está en pausa de facto.
     *   - ⚪ off      : webhook no registrado (nunca conectado, o desconectado
     *                   manualmente con el botón "Parar conexión").
     *
     * El ping al /health se cachea 30s en una opción de Configuration para
     * no martillear la API en cada recarga del BO.
     */
    private function renderStatusBanner(): string
    {
        $hasWebhook = ((int) Configuration::get('TPVSYNC_WEBHOOK_ID')) > 0
                   && (string) Configuration::get('TPVSYNC_WEBHOOK_SECRET') !== '';

        if (!$hasWebhook) {
            $msg = htmlspecialchars($this->l('Sin conectar'), ENT_QUOTES);
            return '<div class="tpvsync-pulse-status tpvsync-pulse-status-off">'
                 . '<span class="tpvsync-pulse-dot"></span>'
                 . '<span class="tpvsync-pulse-text">' . $msg . '</span>'
                 . '</div>';
        }

        $apiUp = $this->isApiReachableCached();
        if (!$apiUp) {
            $msg = htmlspecialchars(
                $this->l('Sin conexión con el TPV. Comprueba que el TPV está accesible.'),
                ENT_QUOTES
            );
            return '<div class="tpvsync-pulse-status tpvsync-pulse-status-down">'
                 . '<span class="tpvsync-pulse-dot"></span>'
                 . '<span class="tpvsync-pulse-text">' . $msg . '</span>'
                 . '</div>';
        }

        // Distinguimos entre "conectado y unificado" (verde sólido) y "conectado
        // pero falta unificar catálogos" (amarillo). Sin esta distinción, el
        // banner verde aparecía a la vez que el wizard de unificación, lo que
        // confundía: ¿está sincronizado o no? El estado correcto cuando sale
        // el wizard es "casi listo, te falta el último paso".
        $initialDone = (bool) Configuration::get('TPVSYNC_INITIAL_SYNC_DONE');
        if (!$initialDone) {
            $msg = htmlspecialchars(
                $this->l('Conectado con el TPV — falta unificar los catálogos'),
                ENT_QUOTES
            );
            return '<div class="tpvsync-pulse-status tpvsync-pulse-status-pending">'
                 . '<span class="tpvsync-pulse-dot"></span>'
                 . '<span class="tpvsync-pulse-text">' . $msg . '</span>'
                 . '</div>';
        }

        $msg = htmlspecialchars(
            $this->l('Conectado y sincronizado con tu TPV Catinfog'),
            ENT_QUOTES
        );
        return '<div class="tpvsync-pulse-status tpvsync-pulse-status-ok">'
             . '<span class="tpvsync-pulse-dot"></span>'
             . '<span class="tpvsync-pulse-text">' . $msg . '</span>'
             . '</div>';
    }

    /**
     * Comprueba si el TPV responde a /health. Cachea el resultado 30s en
     * Configuration para evitar que cada render del BO haga un round-trip.
     * El TTL corto garantiza que un fallo de red se refleje rápido en la UI.
     */
    private function isApiReachableCached(): bool
    {
        $now = time();
        $cachedAt = (int) Configuration::get('TPVSYNC_HEALTH_CHECKED_AT');
        if ($cachedAt > 0 && ($now - $cachedAt) < 30) {
            return (bool) (int) Configuration::get('TPVSYNC_HEALTH_OK');
        }
        // Probe FIRMADO: POST /auth/verify devuelve 401 si HMAC no coincide,
        // 200 si OK. Detecta secrets desincronizados (cosa que GET /health
        // no puede ver). Es lo que diferencia un chip verde mentiroso de un
        // chip rojo accionable.
        $ok = false;
        try {
            $api = $this->api();
            if ($api->isConfigured()) {
                $r = $api->post('/auth/verify', []);
                // BUG-A — EL PEOR DE LOS SIETE. Este es el semaforo que el
                // comerciante mira para saber si sincroniza. Decidia salud con
                // "el cuerpo no trae la clave errors", y como problem+json nunca
                // la trae, daba VERDE ante credenciales revocadas, un 500 del
                // servidor o un 502 del proxy: mentia justo cuando importaba.
                $ok = TpvSyncApiClient::fueBien($r);
            }
        } catch (\Throwable $e) {
            $ok = false;
        }
        Configuration::updateValue('TPVSYNC_HEALTH_OK', $ok ? 1 : 0);
        Configuration::updateValue('TPVSYNC_HEALTH_CHECKED_AT', $now);
        return $ok;
    }

    /**
     * Render del formulario principal en HTML propio (sin HelperForm).
     *
     * Por qué abandonamos HelperForm:
     *   - Inserta switches con `<a class="slide-button btn">` que entran en
     *     conflicto con cualquier estilo de `.btn` que pongamos en la página.
     *   - El layout 4-col del HelperForm queda demasiado denso y poco legible.
     *
     * Replicamos el patrón del plugin WC equivalente: cards-step con inputs
     * stacked, descripciones claras, toggles propios CSS-only (input checkbox
     * + label + slider).
     */
    private function renderForm(): string
    {
        $apiUrl    = (string) Configuration::get('TPVSYNC_API_URL');
        $clientId  = (string) Configuration::get('TPVSYNC_CLIENT_ID');
        $hasSecret = (string) Configuration::get('TPVSYNC_CLIENT_SECRET') !== '';
        $modCat    = (int) Configuration::get('TPVSYNC_MODULE_CATALOG');
        $modOrd    = (int) Configuration::get('TPVSYNC_MODULE_ORDERS');

        $submitName = 'submit' . $this->name;

        // Etiquetas i18n
        $L = [
            'title'       => $this->l('Conexión con la API TPV'),
            'url'         => $this->l('URL de la API'),
            'url_desc'    => $this->l('Ej: https://tu-tpv.ejemplo.com/api/v1'),
            'cid'         => $this->l('Client ID'),
            'sec'         => $this->l('Client Secret'),
            'sec_desc'    => $this->l('Dejar vacío para no cambiar.'),
            'sec_saved'   => $this->l('(guardada — déjalo vacío para no cambiarla)'),
            'sec_paste'   => $this->l('Pega la contraseña del TPV'),
            'mod_cat'     => $this->l('Módulo Catálogo'),
            'mod_cat_d'   => $this->l('Al activar, cada alta/edición en PS se empuja al TPV.'),
            'mod_ord'     => $this->l('Módulo Pedidos'),
            'mod_ord_d'   => $this->l('Al activar, los pedidos PS se crean como pedidos nativos en el TPV.'),
            'save'        => $this->l('Guardar'),
        ];
        foreach ($L as $k => $v) {
            $L[$k] = htmlspecialchars($v, ENT_QUOTES);
        }

        $secPlaceholder = $hasSecret ? $L['sec_saved'] : $L['sec_paste'];
        $apiUrlEsc      = htmlspecialchars($apiUrl, ENT_QUOTES);
        $clientIdEsc    = htmlspecialchars($clientId, ENT_QUOTES);
        $catChecked     = $modCat ? 'checked' : '';
        $ordChecked     = $modOrd ? 'checked' : '';

        // Lógica de "step done": el header del card muestra check verde si los
        // campos requeridos del paso están rellenos. Es feedback visual barato
        // pero efectivo para guiar a usuarios poco expertos.
        $credsOk = ($apiUrl !== '' && $clientId !== '' && $hasSecret);
        $step1HeaderClass = $credsOk ? 'tpvsync-card-header is-done' : 'tpvsync-card-header';
        $step1BadgeHtml   = $credsOk
            ? '<span class="tpvsync-card-badge ok">✓ ' . htmlspecialchars($this->l('Listo'), ENT_QUOTES) . '</span>'
            : '<span class="tpvsync-card-badge warn">' . htmlspecialchars($this->l('Pendiente'), ENT_QUOTES) . '</span>';

        $modulesOn = ($modCat || $modOrd);
        $step2HeaderClass = $modulesOn ? 'tpvsync-card-header is-done' : 'tpvsync-card-header';
        $step2BadgeHtml   = $modulesOn
            ? '<span class="tpvsync-card-badge ok">✓ ' . htmlspecialchars($this->l('Activo'), ENT_QUOTES) . '</span>'
            : '<span class="tpvsync-card-badge warn">' . htmlspecialchars($this->l('Sin módulos'), ENT_QUOTES) . '</span>';

        $L['step1_title'] = htmlspecialchars($this->l('Conectar con el TPV'), ENT_QUOTES);
        $L['step2_title'] = htmlspecialchars($this->l('Elegir qué sincronizar'), ENT_QUOTES);

        return <<<HTML
<form method="post">
    <!-- Paso 1: credenciales API -->
    <div class="tpvsync-card">
        <div class="{$step1HeaderClass}">
            <div class="tpvsync-step-number">1</div>
            <h3 class="tpvsync-card-title">{$L['step1_title']}</h3>
            {$step1BadgeHtml}
        </div>

        <div class="tpvsync-field">
            <label for="TPVSYNC_API_URL">{$L['url']} <span class="tpvsync-req">*</span></label>
            <input type="url" id="TPVSYNC_API_URL" name="TPVSYNC_API_URL"
                   value="{$apiUrlEsc}" placeholder="https://tpv.midominio.com/api/v1"
                   autocomplete="off" spellcheck="false" required>
            <small class="tpvsync-hint">{$L['url_desc']}</small>
        </div>

        <div class="tpvsync-field">
            <label for="TPVSYNC_CLIENT_ID">{$L['cid']}</label>
            <input type="text" id="TPVSYNC_CLIENT_ID" name="TPVSYNC_CLIENT_ID"
                   value="{$clientIdEsc}" autocomplete="off" spellcheck="false">
        </div>

        <div class="tpvsync-field">
            <label for="TPVSYNC_CLIENT_SECRET">{$L['sec']}</label>
            <input type="password" id="TPVSYNC_CLIENT_SECRET" name="TPVSYNC_CLIENT_SECRET"
                   value="" placeholder="{$secPlaceholder}" autocomplete="new-password">
            <small class="tpvsync-hint">{$L['sec_desc']}</small>
        </div>
    </div>

    <!-- Paso 2: módulos -->
    <div class="tpvsync-card">
        <div class="{$step2HeaderClass}">
            <div class="tpvsync-step-number">2</div>
            <h3 class="tpvsync-card-title">{$L['step2_title']}</h3>
            {$step2BadgeHtml}
        </div>

        <div class="tpvsync-module">
            <label class="tpvsync-toggle">
                <input type="hidden"   name="TPVSYNC_MODULE_CATALOG" value="0">
                <input type="checkbox" name="TPVSYNC_MODULE_CATALOG" value="1" {$catChecked}>
                <span class="tpvsync-slider"></span>
            </label>
            <div class="tpvsync-module-text">
                <strong>{$L['mod_cat']}</strong>
                <p>{$L['mod_cat_d']}</p>
            </div>
        </div>

        <div class="tpvsync-module">
            <label class="tpvsync-toggle">
                <input type="hidden"   name="TPVSYNC_MODULE_ORDERS" value="0">
                <input type="checkbox" name="TPVSYNC_MODULE_ORDERS" value="1" {$ordChecked}>
                <span class="tpvsync-slider"></span>
            </label>
            <div class="tpvsync-module-text">
                <strong>{$L['mod_ord']}</strong>
                <p>{$L['mod_ord_d']}</p>
            </div>
        </div>

        <div class="tpvsync-actions">
            <button type="submit" name="{$submitName}" class="tpvsync-btn tpvsync-btn-primary">
                {$L['save']}
            </button>
        </div>
    </div>
</form>
HTML;
    }

    /**
     * Card principal "Conexión TPV ↔ Tienda" con tres estados visualmente
     * distintos:
     *
     *   1. SIN CONEXIÓN (primera vez o tras "Eliminar conexión"): un único
     *      botón grande "Conectar y sincronizar" que activa webhook +
     *      reconciliación inicial bidireccional en una sola acción.
     *
     *   2. CONECTADO: chip verde "Conectado" con el pulse, y JUSTO debajo
     *      el botón "Pausar conexión". Al fondo de la página un único
     *      botón "Reconciliar" (que reemplaza a Importar/Reconectar).
     *
     *   3. PAUSADO: banner amarillo "Conexión pausada", con dos botones
     *      grandes "Reanudar" y "Eliminar conexión".
     */
    private function renderActions(): string
    {
        $url = AdminController::$currentIndex . '&configure=' . $this->name
             . '&token=' . Tools::getAdminTokenLite('AdminModules');

        $webhookId      = (int) Configuration::get('TPVSYNC_WEBHOOK_ID');
        $webhookActive  = $webhookId > 0 && (string) Configuration::get('TPVSYNC_WEBHOOK_SECRET') !== '';
        $isPaused       = (bool) Configuration::get('TPVSYNC_PAUSED');
        $hasCredentials = (string) Configuration::get('TPVSYNC_CLIENT_ID') !== ''
                       && (string) Configuration::get('TPVSYNC_CLIENT_SECRET') !== ''
                       && (string) Configuration::get('TPVSYNC_API_URL') !== '';

        // ── Estado 3: PAUSADO ────────────────────────────────────────────
        if ($isPaused) {
            $titleP   = htmlspecialchars($this->l('Conexión pausada'), ENT_QUOTES);
            $msgP     = htmlspecialchars(
                $this->l('La sincronización está parada. Reanuda para volver a conectar, o elimina la conexión por completo.'),
                ENT_QUOTES
            );
            $resumeL  = htmlspecialchars($this->l('▶ Reanudar conexión'), ENT_QUOTES);
            $deleteL  = htmlspecialchars($this->l('🗑 Eliminar conexión con TPV'), ENT_QUOTES);
            $resumeB  = htmlspecialchars($this->l('Reanudando…'), ENT_QUOTES);
            $deleteB  = htmlspecialchars($this->l('Eliminando…'), ENT_QUOTES);
            $delConfirm = htmlspecialchars(
                $this->l('¿Eliminar la conexión? Las credenciales se borrarán en TPV y aquí. Para volver a conectar tendrás que generar nuevas credenciales.'),
                ENT_QUOTES
            );
            return <<<HTML
<div class="tpvsync-card">
    <div class="tpvsync-card-header">
        <div class="tpvsync-step-number">3</div>
        <h3 class="tpvsync-card-title">{$titleP}</h3>
        <span class="tpvsync-badge tpvsync-badge-warn">●&nbsp;{$titleP}</span>
    </div>
    <p class="tpvsync-hint" style="margin: 0 0 16px;">{$msgP}</p>
    <div class="tpvsync-actions">
        <a class="tpvsync-btn tpvsync-btn-primary tpvsync-action"
           data-busy-label="{$resumeB}"
           href="{$url}&tpvsync_resume=1">{$resumeL}</a>
        <a class="tpvsync-btn tpvsync-btn-danger tpvsync-action"
           data-busy-label="{$deleteB}"
           onclick="return confirm('{$delConfirm}');"
           href="{$url}&tpvsync_delete_connection=1">{$deleteL}</a>
    </div>
</div>
HTML;
        }

        // ── Estado 1: SIN CONEXIÓN ───────────────────────────────────────
        if (!$webhookActive) {
            $title1   = htmlspecialchars($this->l('Conectar tu tienda con el TPV'), ENT_QUOTES);
            $msg1     = htmlspecialchars(
                $hasCredentials
                    ? $this->l('Las credenciales están guardadas. Pulsa para conectar y sincronizar catálogos en ambos sentidos.')
                    : $this->l('Primero rellena la URL del TPV, Client ID y Client Secret arriba y pulsa Guardar.'),
                ENT_QUOTES
            );
            $btnLabel = htmlspecialchars($this->l('🔗 Conectar y sincronizar ahora'), ENT_QUOTES);
            $btnBusy  = htmlspecialchars($this->l('Conectando y sincronizando…'), ENT_QUOTES);
            $disabled = $hasCredentials ? '' : 'tpvsync-btn-disabled';
            $statusBadge = '<span class="tpvsync-badge tpvsync-badge-warn">●&nbsp;'
                . htmlspecialchars($this->l('Sin conectar'), ENT_QUOTES) . '</span>';
            return <<<HTML
<div class="tpvsync-card">
    <div class="tpvsync-card-header">
        <div class="tpvsync-step-number">3</div>
        <h3 class="tpvsync-card-title">{$title1}</h3>
        {$statusBadge}
    </div>
    <p class="tpvsync-hint" style="margin: 0 0 16px;">{$msg1}</p>
    <div class="tpvsync-actions">
        <a class="tpvsync-btn tpvsync-btn-primary tpvsync-action {$disabled}"
           data-busy-label="{$btnBusy}"
           href="{$url}&tpvsync_activate=1">{$btnLabel}</a>
    </div>
</div>
HTML;
        }

        // ── Estado 2: CONECTADO ──────────────────────────────────────────
        // Pulse verde en la cabecera + botón pausar JUSTO debajo (no al
        // final como antes). El botón "Reconciliar" único se renderiza
        // aparte al fondo (renderReconcileFooter).
        $titleC   = htmlspecialchars($this->l('Conectado con el TPV'), ENT_QUOTES);
        $msgC     = htmlspecialchars(
            $this->l('Tu tienda y el TPV están sincronizados. Los cambios viajan solos en ambas direcciones.'),
            ENT_QUOTES
        );
        $statusBadge = '<span class="tpvsync-badge tpvsync-badge-ok">●&nbsp;'
            . htmlspecialchars($this->l('Conectado'), ENT_QUOTES) . '</span>';
        $pauseL  = htmlspecialchars($this->l('⏸ Pausar conexión'), ENT_QUOTES);
        $pauseB  = htmlspecialchars($this->l('Pausando…'), ENT_QUOTES);
        $queueL  = htmlspecialchars($this->l('Reintentar pendientes'), ENT_QUOTES);
        $queueB  = htmlspecialchars($this->l('Reintentando…'), ENT_QUOTES);
        $pauseConfirm = htmlspecialchars(
            $this->l('¿Pausar la sincronización? Las credenciales se mantienen, podrás reanudar cuando quieras.'),
            ENT_QUOTES
        );
        return <<<HTML
<div class="tpvsync-card">
    <div class="tpvsync-card-header is-done">
        <div class="tpvsync-step-number">3</div>
        <h3 class="tpvsync-card-title">{$titleC}</h3>
        {$statusBadge}
    </div>
    <p class="tpvsync-hint" style="margin: 0 0 16px;">{$msgC}</p>
    <div class="tpvsync-actions">
        <a class="tpvsync-btn tpvsync-btn-secondary tpvsync-action"
           data-busy-label="{$pauseB}"
           onclick="return confirm('{$pauseConfirm}');"
           href="{$url}&tpvsync_pause=1">{$pauseL}</a>
        <a class="tpvsync-btn tpvsync-btn-secondary tpvsync-action"
           data-busy-label="{$queueB}"
           href="{$url}&tpvsync_process_queue=1">{$queueL}</a>
    </div>
</div>
HTML;
    }

    /**
     * Botón único "Reconciliar TPV ↔ Tienda" que se pinta al final de la
     * página, separado de la card principal de estado. Reemplaza a los
     * antiguos "Importar" y "Reconectar".
     *
     * Solo visible en estado conectado.
     */
    private function renderReconcileFooter(): string
    {
        $url = AdminController::$currentIndex . '&configure=' . $this->name
             . '&token=' . Tools::getAdminTokenLite('AdminModules');
        $webhookId = (int) Configuration::get('TPVSYNC_WEBHOOK_ID');
        $webhookActive = $webhookId > 0 && (string) Configuration::get('TPVSYNC_WEBHOOK_SECRET') !== '';
        $isPaused = (bool) Configuration::get('TPVSYNC_PAUSED');
        if (!$webhookActive || $isPaused) {
            return '';
        }
        // Botón unificado "Comprobar sincronización": ejecuta reconcile +
        // analyze_divergence en una sola pasada y muestra al usuario una
        // pantalla con 4 números (sincronizados, islas PS, islas TPV,
        // discrepancias). Si hay discrepancias, ofrece "Arreglar".
        // Reemplaza a los antiguos "Reconciliar" + "Resolver divergencias",
        // que para el comerciante eran indistinguibles.
        $title = htmlspecialchars($this->l('Estado de la sincronización'), ENT_QUOTES);
        $hint  = htmlspecialchars(
            $this->l('Compara los catálogos del TPV y de tu tienda online y te dice cuántos productos están sincronizados, cuántos viven solo en un lado (islas) y si hay discrepancias por resolver.'),
            ENT_QUOTES
        );
        $btn   = htmlspecialchars($this->l('Comprobar sincronización'), ENT_QUOTES);

        // Token AJAX y URL del endpoint syncbatch — los reusa el JS para
        // arrancar el bucle de lotes con el modal de progreso. Reutilizamos
        // la infraestructura del wizard (PUSH/PULL) pero con action=reconcile.
        $batchToken = TpvSyncSecrets::get('TPVSYNC_BATCH_TOKEN');
        if ($batchToken === '') {
            $batchToken = bin2hex(random_bytes(16));
            TpvSyncSecrets::set('TPVSYNC_BATCH_TOKEN', $batchToken);
        }
        $ajaxUrl = $this->context->link->getModuleLink(
            'tpvsync',
            'syncbatch',
            [],
            (bool) Configuration::get('PS_SSL_ENABLED')
        );
        $reloadUrl = $url; // tras terminar, recargar a la página actual.

        $tokenJson = htmlspecialchars($batchToken, ENT_QUOTES);
        $ajaxJson  = htmlspecialchars($ajaxUrl, ENT_QUOTES);
        $reloadJson = htmlspecialchars($reloadUrl, ENT_QUOTES);
        $reconcileTitle = htmlspecialchars($this->l('Reconciliando catálogos…'), ENT_QUOTES);
        $dontClose = htmlspecialchars($this->l('No cierres esta ventana hasta que termine.'), ENT_QUOTES);
        $doneLabel = htmlspecialchars($this->l('Reconciliación completada.'), ENT_QUOTES);
        $closeBtn = htmlspecialchars($this->l('Cerrar y continuar'), ENT_QUOTES);
        $failed = htmlspecialchars($this->l('La reconciliación ha fallado'), ENT_QUOTES);
        $retry = htmlspecialchars($this->l('Reintentar'), ENT_QUOTES);
        $eta = htmlspecialchars($this->l('Tiempo restante aprox.'), ENT_QUOTES);

        return <<<HTML
<div class="tpvsync-card tpvsync-wizard"
     data-tpvsync-ajax="{$ajaxJson}"
     data-tpvsync-token="{$tokenJson}"
     data-tpvsync-reload="{$reloadJson}"
     data-l-push-title="{$reconcileTitle}"
     data-l-pull-title="{$reconcileTitle}"
     data-l-reconcile-title="{$reconcileTitle}"
     data-l-dont-close="{$dontClose}"
     data-l-done-push="{$doneLabel}"
     data-l-done-pull="{$doneLabel}"
     data-l-done-reconcile="{$doneLabel}"
     data-l-close-btn="{$closeBtn}"
     data-l-failed="{$failed}"
     data-l-retry="{$retry}"
     data-l-eta="{$eta}">
    <div class="tpvsync-card-header">
        <h3 class="tpvsync-card-title">{$title}</h3>
    </div>
    <p class="tpvsync-hint" style="margin: 0 0 16px;">{$hint}</p>
    <div class="tpvsync-actions">
        <button type="button"
                class="tpvsync-btn tpvsync-btn-primary"
                data-tpvsync-action="check_sync">{$btn}</button>
    </div>
</div>
HTML;
    }

    /**
     * Activa la sincronización en un solo paso: valida las credenciales,
     * registra el webhook en el TPV (que genera y devuelve el secret) y
     * persiste webhook_id + secret en Configuration.
     *
     * El secret nunca se muestra al admin — el patrón es el mismo que usa
     * el plugin WooCommerce equivalente (ajax_register_webhook).
     */
    private function renderActivateSync(): string
    {
        try {
            $api = $this->api();
            if (!$api->isConfigured()) {
                return $this->displayError(
                    $this->l('Faltan datos de conexión. Rellena la URL del TPV, Client ID y Client Secret y pulsa Guardar.')
                );
            }

            $webhookUrl = Context::getContext()->link->getModuleLink(
                'tpvsync',
                'webhook',
                [],
                Configuration::get('PS_SSL_ENABLED') ? true : null
            );

            // Self-healing: limpiamos LOCAL siempre. Si la operación falla,
            // el módulo queda "Sin conectar" coherente, no en limbo.
            // Tampoco hacemos DELETE remoto (firmado con el HMAC que puede
            // estar roto): el POST /webhooks de la API hace dedup automático.
            Configuration::updateValue('TPVSYNC_WEBHOOK_ID', 0);
            Configuration::updateValue('TPVSYNC_WEBHOOK_SECRET', '');
            Configuration::updateValue('TPVSYNC_HEALTH_OK', 0);
            Configuration::updateValue('TPVSYNC_HEALTH_CHECKED_AT', 0);

            // Pre-acordamos el secret HMAC: lo generamos local y lo enviamos.
            // Elimina el race "TPV genera → respuesta perdida → cliente con
            // secret huérfano".
            $preAgreedSecret = bin2hex(random_bytes(32));

            // Sólo nos suscribimos a los eventos de los módulos activos. Evita
            // ruido en el TPV dispatcher (suscribir a order.* sin el módulo
            // Pedidos activo sería spam).
            // Eventos válidos según api/v1/controllers/WebhookController.php::VALID_EVENTS.
            // No incluimos `variant.stock_adjusted` ni `order.status_changed` porque
            // la API no los acepta — el TPV solo emite `stock.adjusted` (cubre tanto
            // producto simple como variante) y los pagos vía `order.payment_changed`.
            $catalogOn = (bool) Configuration::get('TPVSYNC_MODULE_CATALOG');
            $ordersOn = (bool) Configuration::get('TPVSYNC_MODULE_ORDERS');
            $events = array_values(array_filter([
                $catalogOn ? 'product.created' : null,
                $catalogOn ? 'product.updated' : null,
                $catalogOn ? 'product.deleted' : null,
                $catalogOn ? 'stock.adjusted' : null,
                $catalogOn ? 'special.created' : null,
                $catalogOn ? 'special.deleted' : null,
                $catalogOn ? 'variant.created' : null,
                $catalogOn ? 'variants.updated' : null,
                $catalogOn ? 'option.created' : null,
                $catalogOn ? 'option.deleted' : null,
                $catalogOn ? 'csv.imported' : null,
                $ordersOn ? 'order.created' : null,
                $ordersOn ? 'order.payment_changed' : null,
                $ordersOn ? 'return.created' : null,
                $ordersOn ? 'return.deleted' : null,
            ]));

            if (empty($events)) {
                return $this->displayWarning(
                    $this->l('Activa al menos uno de los módulos (Catálogo o Pedidos) en el paso 2 antes de conectar.')
                );
            }

            $r = $api->post('/webhooks', [
                'url'    => $webhookUrl,
                'secret' => $preAgreedSecret,
                'events' => $events,
            ]);

            if (!empty($r['data']['webhook_id'])) {
                Configuration::updateValue('TPVSYNC_WEBHOOK_ID', (int) $r['data']['webhook_id']);
                $finalSecret = (string)($r['data']['secret'] ?? $preAgreedSecret);
                TpvSyncSecrets::set('TPVSYNC_WEBHOOK_SECRET', $finalSecret);

                // Si veníamos de un "Parar conexión" reciente (>5min), marcamos
                // un flag para que el merchant pueda disparar la reconciliación
                // bidireccional desde un botón dedicado. NO la ejecutamos aquí
                // de forma síncrona: con catálogos grandes tarda minutos y
                // agota el max_execution_time de PHP, dejando al usuario en una
                // pantalla blanca de error tras un timeout. La reconciliación
                // tiene su propio render con feedback de progreso por lotes.
                $disconnectedAt = (int) Configuration::get('TPVSYNC_DISCONNECTED_AT');
                $msgExtra = '';
                if ($disconnectedAt > 0 && (time() - $disconnectedAt) > 300) {
                    Configuration::updateValue('TPVSYNC_RECONCILE_PENDING', 1);
                    $msgExtra = ' ' . $this->l('Como la conexión llevaba un rato pausada, te recomendamos lanzar una reconciliación para alinear los cambios pendientes — encontrarás el botón en el panel del módulo.');
                }
                Configuration::updateValue('TPVSYNC_DISCONNECTED_AT', 0);

                // En primera conexión (sin sync inicial hecho) volvemos al
                // wizard: el merchant tiene que decidir la dirección del
                // primer volcado (PULL/PUSH/SKIP). Si ya estaba hecho, mensaje
                // de éxito normal.
                $initialDone = (bool) Configuration::get('TPVSYNC_INITIAL_SYNC_DONE');
                if (!$initialDone) {
                    return $this->displayConfirmation(
                        $this->l('Conectado correctamente. Antes de empezar a sincronizar en tiempo real, elige cómo unificar los catálogos en el wizard que aparece debajo.')
                    );
                }
                return $this->displayConfirmation(
                    $this->l('Conectado correctamente. Tu tienda y el TPV ya están sincronizados.') . $msgExtra
                );
            }

            // Mensaje técnico que devuelve la API → traducimos a algo entendible.
            // Guardamos el técnico en log/comentario para que un técnico pueda diagnosticar
            // si fuese necesario, pero al usuario le mostramos algo accionable.
            $technical = $r['errors'][0]['message'] ?? $r['error'] ?? '';
            $hint = $this->l('No se pudo conectar con el TPV. Comprueba que la URL, el Client ID y el Client Secret son correctos y vuelve a intentarlo.');
            return $this->displayError($hint);
        } catch (\Throwable $e) {
            return $this->displayError($e->getMessage());
        }
    }

    /**
     * Detiene la sincronización: borra el webhook en el TPV (best-effort) y
     * limpia las opciones locales. La configuración (URL, client id/secret)
     * se mantiene para que el usuario pueda volver a conectar con un click.
     */
    /**
     * Pausa la conexión:
     *   1. Llama POST /auth/pause en el TPV → status=0 + borra webhooks remotos.
     *   2. Limpia el webhook local (id + secret).
     *   3. Marca TPVSYNC_PAUSED=1 para que la UI muestre el banner "Pausada".
     *
     * Las credenciales (client_id + client_secret) se mantienen. Para reanudar
     * basta con pulsar "Reanudar". Para borrarlas del todo, "Eliminar conexión".
     */
    private function renderPauseSync(): string
    {
        try {
            $api = $this->api();
            if ($api->isConfigured()) {
                try {
                    $api->post('/auth/pause', []);
                } catch (\Throwable $e) {
                    // Best-effort: si el TPV no responde, seguimos limpiando local.
                    // El TPV detectará la pausa cuando el plugin no envíe más
                    // requests; el usuario puede reanudar y el estado se realinea.
                    TpvSyncLog::write('warn', 'pause', 0, 'auth/pause: ' . $e->getMessage());
                }
            }

            Configuration::updateValue('TPVSYNC_WEBHOOK_ID', 0);
            Configuration::updateValue('TPVSYNC_WEBHOOK_SECRET', '');
            Configuration::updateValue('TPVSYNC_PAUSED', 1);
            // Timestamp de desconexión: al reanudar, si pasaron >5 min,
            // disparamos reconciliación bidireccional silenciosa.
            Configuration::updateValue('TPVSYNC_DISCONNECTED_AT', time());

            return $this->displayConfirmation(
                $this->l('Conexión pausada. Tu tienda y el TPV están desincronizados temporalmente. Puedes reanudar cuando quieras o eliminar la conexión por completo.')
            );
        } catch (\Throwable $e) {
            return $this->displayError(
                $this->l('No se pudo pausar la conexión limpiamente. Vuelve a intentarlo.')
            );
        }
    }

    /**
     * Reanuda la conexión tras una pausa: llama /auth/resume y re-registra el
     * webhook con un secret nuevo. Equivalente al `renderActivateSync` pero
     * limpia el flag PAUSED.
     */
    private function renderResumeSync(): string
    {
        try {
            $api = $this->api();
            if (!$api->isConfigured()) {
                return $this->displayError(
                    $this->l('Faltan credenciales. Pulsa "Eliminar conexión" y vuelve a empezar.')
                );
            }
            // Limpiar cache de tokens local (la pausa los revocó en el TPV).
            Configuration::updateValue('TPVSYNC_TOKEN_CACHE', '');

            // Llamar /auth/resume con HTTP Basic (caso especial: el bearer
            // normal no funciona cuando status=0 — /auth/token también lo
            // bloquea). resumeWithBasicAuth implementa este path.
            $r = $api->resumeWithBasicAuth();
            if (empty($r['data']['resumed']) && empty($r['resumed'])) {
                $err = $r['errors'][0]['message'] ?? ($r['error'] ?? 'unknown');
                return $this->displayError(
                    $this->l('No se pudo reanudar la conexión: ') . htmlspecialchars((string) $err)
                );
            }

            // Status ya está =1 en el TPV. Limpiamos PAUSED local y re-registramos
            // webhook usando el path normal (que ya pide token nuevo).
            Configuration::updateValue('TPVSYNC_PAUSED', 0);
            return $this->renderActivateSync();
        } catch (\Throwable $e) {
            return $this->displayError(
                $this->l('No se pudo reanudar la conexión. Vuelve a intentarlo.')
            );
        }
    }

    /**
     * Elimina la conexión por completo.
     *   1. POST /auth/disconnect → DELETE api_client + webhooks + revoke tokens.
     *   2. Borra credenciales y webhook locales.
     *   3. Resetea flags PAUSED, INITIAL_SYNC_DONE.
     *
     * Tras esto el plugin queda como recién instalado: hay que generar
     * credenciales nuevas en el TPV y pegarlas aquí para volver a conectar.
     */
    private function renderDeleteConnection(): string
    {
        try {
            $api = $this->api();

            // 1) Borrar webhook remoto explícitamente (mejor que confiar en
            //    /auth/disconnect, que puede estar deprecated o no borrar
            //    webhooks de otras tiendas).
            $whId = (int) Configuration::get('TPVSYNC_WEBHOOK_ID');
            if ($api->isConfigured() && $whId > 0) {
                try {
                    $api->delete('/webhooks/' . $whId);
                } catch (\Throwable $e) {
                    TpvSyncLog::write('warn', 'disconnect', 0, "DELETE /webhooks/$whId: " . $e->getMessage());
                }
            }

            // 2) Revocar credenciales en TPV (borra api_client server-side).
            if ($api->isConfigured()) {
                try {
                    $api->post('/auth/disconnect', []);
                } catch (\Throwable $e) {
                    TpvSyncLog::write('warn', 'disconnect', 0, 'auth/disconnect: ' . $e->getMessage());
                }
            }

            // 3) Borrado total local. Usamos deleteByName en vez de
            //    updateValue('') para evitar el bug "filas fantasma" en
            //    {prefix}configuration (PS no garantiza unicidad por
            //    name+shop si una fila tiene value=NULL — Configuration::get
            //    devuelve la primera que encuentra y puede ser la stale).
            //    deleteByName borra TODAS las filas con ese name, garantizando
            //    estado limpio. El siguiente updateValue creará una fila nueva.
            $keysToWipe = [
                'TPVSYNC_CLIENT_ID', 'TPVSYNC_CLIENT_SECRET',
                'TPVSYNC_WEBHOOK_ID', 'TPVSYNC_WEBHOOK_SECRET',
                'TPVSYNC_PAUSED', 'TPVSYNC_INITIAL_SYNC_DONE',
                'TPVSYNC_PRINCIPAL', 'TPVSYNC_HEALTH_OK',
                'TPVSYNC_HEALTH_CHECKED_AT', 'TPVSYNC_RECONCILE_PENDING',
                'TPVSYNC_DISCONNECTED_AT', 'TPVSYNC_TOKEN_CACHE',
                'TPVSYNC_RECONCILE_PHASE', 'TPVSYNC_RECONCILE_PS_OFFSET',
                'TPVSYNC_PUSH_OFFSET', 'TPVSYNC_IMPORT_OFFSET',
            ];
            foreach ($keysToWipe as $k) {
                Configuration::deleteByName($k);
            }

            // 4) Red de seguridad: barrer cualquier fila huérfana con name
            //    TPVSYNC_* y value=NULL/'' que haya quedado de instalaciones
            //    previas o de bugs antiguos del plugin. Sin esto, el usuario
            //    pega credenciales nuevas y Configuration::get sigue devolviendo
            //    la fila NULL stale → "guardé pero no se aplicó".
            Db::getInstance()->execute(
                "DELETE FROM " . _DB_PREFIX_ . "configuration "
                . "WHERE name LIKE 'TPVSYNC_%' AND (value IS NULL OR value = '')"
            );

            return $this->displayConfirmation(
                $this->l('Conexión eliminada. Para volver a conectar, genera nuevas credenciales en el TPV (Configuración → Tienda online) y pégalas aquí.')
            );
        } catch (\Throwable $e) {
            TpvSyncLog::write('error', 'disconnect', 0, 'renderDeleteConnection fatal: ' . $e->getMessage());
            return $this->displayError(
                $this->l('No se pudo eliminar la conexión limpiamente. Comprueba la conexión y vuelve a intentarlo.')
            );
        }
    }

    /**
     * Reconciliación bidireccional manual (botón único del fondo de la UI).
     *
     * Reemplaza a "Importar productos del TPV" y "Reconectar":
     *   - Si el webhook está roto, lo re-registra.
     *   - Sincroniza catálogo TPV ↔ PS aplicando deltas en ambos lados.
     *   - Reporta estadísticas.
     */
    private function renderReconcile(): string
    {
        // El handler legacy hacía reconciliación síncrona y reventaba PHP con
        // catálogos grandes (>3000 productos). Ahora la reconciliación corre
        // por AJAX en lotes con barra de progreso — este método solo prepara
        // el flag y devuelve el HTML del trigger; el trabajo lo hace
        // processReconcileBatch() llamado desde el endpoint front controller.
        $api = $this->api();
        if (!$api->isConfigured()) {
            return $this->displayError(
                $this->l('Faltan credenciales. No se puede reconciliar.')
            );
        }
        $whId = (int) Configuration::get('TPVSYNC_WEBHOOK_ID');
        if ($whId === 0) {
            return $this->displayWarning(
                $this->l('No hay webhook activo. Conecta primero antes de reconciliar.')
            );
        }
        // Resetear estado previo si existía (por si quedó a media).
        Configuration::deleteByName('TPVSYNC_RECONCILE_PHASE');
        Configuration::deleteByName('TPVSYNC_RECONCILE_PS_OFFSET');

        return $this->displayConfirmation(
            $this->l('Reconciliación lista para iniciar. Pulsa el botón en el banner para arrancar.')
        );
    }

    /**
     * Reconciliación bidireccional silenciosa.
     *
     * Cuándo se llama: tras un "Parar → Reconectar" donde la pausa fue >5 min.
     * Qué hace: compara catálogos de TPV y PS y arregla solo las discrepancias.
     * Política de conflicto: gana el lado con `updated_at` más reciente.
     *
     * Es best-effort: si falla, log + retornar stats parciales — NUNCA
     * propagar la excepción al flujo de "Reconectar". El usuario ya tiene
     * el webhook activo; los cambios futuros viajarán solos.
     */
    private function reconcileSilently(): array
    {
        $stats = ['synced' => 0, 'fixed' => 0, 'errors' => 0];
        try {
            $products = $this->products();
            // El reconcileBidirectional vive en TpvSyncProduct y procesa por
            // lotes internamente. No bloqueamos la UI más de lo necesario.
            if (method_exists($products, 'reconcileBidirectional')) {
                $r = $products->reconcileBidirectional();
                $stats['synced'] = (int)($r['synced'] ?? 0);
                $stats['fixed']  = (int)($r['fixed']  ?? 0);
                $stats['errors'] = (int)($r['errors'] ?? 0);
            }
        } catch (\Throwable $e) {
            TpvSyncLog::error('reconcile', 0, 'silent recon: ' . $e->getMessage());
            $stats['errors']++;
        }
        return $stats;
    }

    private function renderTestConnection(): string
    {
        try {
            $api = $this->api();
            if (!$api->isConfigured()) {
                return $this->displayError(
                    $this->l('Faltan datos de conexión. Rellena la URL del TPV, Client ID y Client Secret y pulsa Guardar.')
                );
            }
            $r = $api->get('/health');
            if (isset($r['error'])) {
                return $this->displayError(
                    $this->l('No se pudo conectar con el TPV. Comprueba que la URL y las credenciales son correctas.')
                );
            }
            return $this->displayConfirmation($this->l('La conexión con el TPV funciona correctamente.'));
        } catch (\Throwable $e) {
            return $this->displayError(
                $this->l('No se pudo conectar con el TPV. Comprueba que la URL y las credenciales son correctas.')
            );
        }
    }

    /**
     * Import iterativo — procesa en lotes de 200 para evitar el timeout del BO.
     * Recuerda el offset en Configuration y renderiza un botón "Seguir" si
     * queda trabajo. Paridad con el plugin WC (que encolaba por AJAX).
     */
    private function renderImportAll(): string
    {
        try {
            $offset = (int) Tools::getValue('tpvsync_import_offset', 0);
            $batchSize = 200;
            $stats = $this->products()->importAll(['offset' => $offset, 'limit' => $batchSize]);

            $next = (int) ($stats['next_offset'] ?? 0);
            $total = (int) ($stats['total_seen'] ?? 0);
            $processed = $offset + (int) ($stats['processed'] ?? 0);

            $created = (int) ($stats['created'] ?? 0);
            $updated = (int) ($stats['updated'] ?? 0);
            $errors  = (int) ($stats['errors']  ?? 0);

            $msg = sprintf(
                $this->l('%d productos nuevos · %d actualizados · %d con errores. Progreso: %d de %d.'),
                $created, $updated, $errors, $processed, $total
            );

            if ($next > 0) {
                $url = AdminController::$currentIndex . '&configure=' . $this->name
                    . '&token=' . Tools::getAdminTokenLite('AdminModules')
                    . '&tpvsync_import=1&tpvsync_import_offset=' . $next;
                $msg .= '<br><br><a class="tpvsync-btn tpvsync-btn-primary" href="' . $url . '">'
                    . $this->l('Continuar con el siguiente lote')
                    . '</a>';
            } else {
                $msg .= '<br><br><b>' . $this->l('Importación completada.') . '</b>';
            }

            return $this->displayConfirmation($msg);
        } catch (\Throwable $e) {
            return $this->displayError(
                $this->l('No se pudo importar el catálogo. Comprueba la conexión con el TPV e inténtalo de nuevo.')
            );
        }
    }

    private function renderProcessQueue(): string
    {
        $stats = $this->queue()->process(50);
        $ok   = (int) ($stats['ok']      ?? 0);
        $fail = (int) ($stats['fail']    ?? 0);
        $skip = (int) ($stats['skipped'] ?? 0);
        return $this->displayConfirmation(sprintf(
            $this->l('%d cambios sincronizados, %d con error, %d omitidos.'),
            $ok, $fail, $skip
        ));
    }

    private function renderQueueTable(): string
    {
        $rows = Db::getInstance()->executeS(
            'SELECT id, job_type, status, attempts, last_error, created_at, scheduled_at
             FROM ' . _DB_PREFIX_ . "tpv_sync_queue
             WHERE status IN ('pending','failed')
             ORDER BY id DESC LIMIT 20"
        );
        $titleLabel = htmlspecialchars($this->l('Diagnóstico avanzado'), ENT_QUOTES);
        $intro      = htmlspecialchars(
            $this->l('Cuando algún cambio no se puede enviar al TPV (por ejemplo, si la conexión cae un momento), queda pendiente aquí para reintentarse automáticamente. En funcionamiento normal no necesitas mirar esto.'),
            ENT_QUOTES
        );
        $emptyLabel = htmlspecialchars($this->l('sin pendientes'), ENT_QUOTES);

        if (!$rows) {
            return <<<HTML
<details class="tpvsync-collapsible">
    <summary>{$titleLabel} <span class="tpvsync-collapsible-meta">— {$emptyLabel}</span></summary>
    <div class="tpvsync-collapsible-body">
        <p class="tpvsync-hint" style="padding-top:14px;margin:0;">{$intro}</p>
    </div>
</details>
HTML;
        }
        $countLabel = htmlspecialchars(
            sprintf($this->l('%d cambios pendientes'), count($rows)),
            ENT_QUOTES
        );
        $html = '<details class="tpvsync-collapsible">'
              . '<summary>' . $titleLabel . ' <span class="tpvsync-collapsible-meta">— ' . $countLabel . '</span></summary>'
              . '<div class="tpvsync-collapsible-body">'
              . '<p class="tpvsync-hint" style="padding-top:14px;margin:0 0 14px;">' . $intro . '</p>'
              . '<table class="tpvsync-table"><thead><tr>'
              . '<th>' . htmlspecialchars($this->l('Cambio'), ENT_QUOTES) . '</th>'
              . '<th>' . htmlspecialchars($this->l('Estado'), ENT_QUOTES) . '</th>'
              . '<th>' . htmlspecialchars($this->l('Intentos'), ENT_QUOTES) . '</th>'
              . '<th>' . htmlspecialchars($this->l('Próximo reintento'), ENT_QUOTES) . '</th>'
              . '</tr></thead><tbody>';

        // Traducción de job_type técnico → texto humano. Cubre los tipos que
        // realmente encola el queue.php; cualquier otro cae al fallback.
        $jobLabels = [
            'product.push'      => $this->l('Producto enviado al TPV'),
            'product.delete'    => $this->l('Producto borrado'),
            'stock.push'        => $this->l('Stock actualizado'),
            'order.push'        => $this->l('Pedido enviado al TPV'),
            'order.refund'      => $this->l('Devolución'),
            'webhook.dispatch'  => $this->l('Cambio recibido del TPV'),
        ];
        $statusLabels = [
            'pending' => $this->l('en espera'),
            'failed'  => $this->l('falló'),
            'done'    => $this->l('hecho'),
        ];

        foreach ($rows as $r) {
            $jobHuman = $jobLabels[$r['job_type']] ?? $r['job_type'];
            $statusHuman = $statusLabels[$r['status']] ?? $r['status'];
            $html .= '<tr>'
                . '<td>' . htmlspecialchars($jobHuman) . '</td>'
                . '<td><span class="tpvsync-pill tpvsync-pill-' . htmlspecialchars($r['status']) . '">'
                .   htmlspecialchars($statusHuman) . '</span></td>'
                . '<td>' . (int) $r['attempts'] . '</td>'
                . '<td><small>' . htmlspecialchars($r['scheduled_at']) . '</small></td>'
                . '</tr>';
        }
        return $html . '</tbody></table></div></details>';
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  Wizard de sync inicial: pregunta única "¿Quién manda?"
    // ═══════════════════════════════════════════════════════════════════════════
    //
    // El comerciante elige cuál de los dos sistemas es el principal (la fuente
    // de verdad). A partir de esa decisión, el plugin sube todo el catálogo
    // del Principal al Secundario. Lo que ya existía en el Secundario y no
    // matchea queda como "isla": vive su vida sin sincronizar.
    //
    // Dos botones, una sola decisión:
    //   - TPV manda  → push de PS hacia el TPV es inverso al nombre histórico
    //                  del action: en realidad es "TPV → PS" (pull del TPV).
    //                  El JS llama a action=pull pero conceptualmente es
    //                  "TPV es el Principal y empuja al Secundario PS".
    //   - PS manda   → push de PS hacia el TPV (action=push).
    //
    // Una vez completado uno de los dos, se marca:
    //   - TPVSYNC_INITIAL_SYNC_DONE=1
    //   - TPVSYNC_PRINCIPAL='tpv' o 'ps' (la decisión de autoridad)
    //
    // No hay "saltar". La decisión es inevitable: si vas a usar el plugin,
    // tienes que decidir quién manda. Saltarse esto lleva a inconsistencias
    // silenciosas que descubres meses después con stock corrupto.

    private function renderInitialSyncWizard(): string
    {
        // Conteo de PS: directo en la BD local — barato, instantáneo.
        $psCount = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'product WHERE state = 1'
        );
        // Conteo del TPV: NO lo pedimos aquí. Antes hacíamos GET /products?count=1
        // síncrono, pero con HMAC + retries puede tardar 20-40s y eso colgaba
        // toda la página del módulo (PHP es síncrono — el wizard no se renderiza
        // hasta que vuelva la respuesta). Cada F5 → otro cuelgue. Ahora lo
        // cargamos por AJAX desde el JS después del paint inicial: la página
        // se pinta al instante con "…" y el número aparece cuando llega.
        $tpvCount = null; // marcador → render mostrará "…" y JS lo rellenará

        $url = AdminController::$currentIndex . '&configure=' . $this->name
             . '&token=' . Tools::getAdminTokenLite('AdminModules');

        $L = [
            'title'       => htmlspecialchars($this->l('¿Cuál es tu sistema principal de inventario?'), ENT_QUOTES),
            'intro'       => htmlspecialchars(
                $this->l('Esta decisión define quién manda: el sistema principal es la fuente de verdad. El otro lado refleja sus cambios. Los productos que ya existen solo en el otro lado no se mezclarán: viven aparte como "islas" que puedes sincronizar más adelante si lo deseas.'),
                ENT_QUOTES
            ),
            'pull_title'  => htmlspecialchars($this->l('Manda el TPV'), ENT_QUOTES),
            'pull_desc'   => htmlspecialchars(
                $this->l('Recomendado si vendes principalmente en tienda física. El TPV es la fuente de verdad: existencia, precio y datos de los productos. Su catálogo se sube a PrestaShop. Los productos extra de PrestaShop quedan como islas.'),
                ENT_QUOTES
            ),
            'pull_btn'    => htmlspecialchars($this->l('Elegir TPV como principal'), ENT_QUOTES),
            'push_title'  => htmlspecialchars($this->l('Manda PrestaShop'), ENT_QUOTES),
            'push_desc'   => htmlspecialchars(
                $this->l('Recomendado si vendes principalmente online. PrestaShop es la fuente de verdad. Su catálogo se sube al TPV. Los productos extra del TPV quedan como islas.'),
                ENT_QUOTES
            ),
            'push_btn'    => htmlspecialchars($this->l('Elegir PrestaShop como principal'), ENT_QUOTES),
            'count_label' => htmlspecialchars($this->l('Productos detectados'), ENT_QUOTES),
            'ps_label'    => htmlspecialchars($this->l('PrestaShop'), ENT_QUOTES),
            'tpv_label'   => htmlspecialchars($this->l('TPV'), ENT_QUOTES),
            'unit_label'  => htmlspecialchars($this->l('productos'), ENT_QUOTES),
            'match_note'  => htmlspecialchars(
                $this->l('Los productos que existan en ambos lados con la misma referencia o EAN se identifican automáticamente: no se duplicarán, se sincronizarán como uno solo.'),
                ENT_QUOTES
            ),
            'warning'     => htmlspecialchars(
                $this->l('Esta decisión solo se toma UNA vez. Después la sincronización en tiempo real se encarga del resto.'),
                ENT_QUOTES
            ),
            // Strings que el JS necesitará en runtime: pasamos por data-attrs.
            'progress_push_title' => htmlspecialchars($this->l('Enviando productos al TPV…'), ENT_QUOTES),
            'progress_pull_title' => htmlspecialchars($this->l('Trayendo productos del TPV…'), ENT_QUOTES),
            'progress_dont_close' => htmlspecialchars($this->l('No cierres esta ventana hasta que termine.'), ENT_QUOTES),
            'progress_done_push'  => htmlspecialchars($this->l('Envío completado.'), ENT_QUOTES),
            'progress_done_pull'  => htmlspecialchars($this->l('Importación completada.'), ENT_QUOTES),
            'progress_close_btn'  => htmlspecialchars($this->l('Cerrar y continuar'), ENT_QUOTES),
            'progress_failed'     => htmlspecialchars($this->l('La sincronización ha fallado'), ENT_QUOTES),
            'progress_retry'      => htmlspecialchars($this->l('Reintentar'), ENT_QUOTES),
            'progress_eta'        => htmlspecialchars($this->l('Tiempo restante aprox.'), ENT_QUOTES),
        ];
        // El conteo del TPV se rellena por AJAX. Hasta que llegue, "…".
        // Si el AJAX falla, el JS pondrá "—" y un mensaje de error.
        $tpvCountStr   = $tpvCount === null
            ? '…'
            : htmlspecialchars((string) $tpvCount, ENT_QUOTES);
        $tpvUnitHidden = false; // siempre mostramos "productos" (vendrá el número)
        $tpvHint       = '';
        $confirmPush = htmlspecialchars(
            $this->l('Vas a establecer PrestaShop como sistema principal. Todos sus productos se subirán al TPV. Esta decisión define quién manda en la sincronización. ¿Continuar?'),
            ENT_QUOTES
        );
        $confirmPull = htmlspecialchars(
            $this->l('Vas a establecer el TPV como sistema principal. Todos sus productos se subirán a PrestaShop. Esta decisión define quién manda en la sincronización. ¿Continuar?'),
            ENT_QUOTES
        );

        $tpvUnitHtml = $tpvUnitHidden
            ? ''
            : '<span class="tpvsync-count-unit">' . $L['unit_label'] . '</span>';
        $tpvHintHtml = $tpvHint !== ''
            ? '<p class="tpvsync-hint tpvsync-hint-warn" style="margin: 8px 0 0; text-align: center; font-size: 12px;">' . $tpvHint . '</p>'
            : '';

        // Token corto para autenticar las llamadas AJAX al endpoint
        // controllers/front/syncbatch.php. NO usamos el admin token de PS
        // porque vive en el front controller (rama pública).
        //
        // REUTILIZAMOS el token si ya existe — antes lo regenerábamos en cada
        // render con el argumento "vida corta = menos superficie", pero eso
        // creaba un bug grave: si la página del módulo se renderizaba dos
        // veces antes de que el usuario pulsara el botón (cambio de pestaña,
        // F5, navegación admin), el token del DOM cargado en el primer render
        // ya no coincidía con el de Configuration y el primer batch fallaba
        // con 403 invalid_token. El usuario veía "credenciales caducadas"
        // sin haber tocado nada.
        // El token vive sólo durante la inicialización (se borra al terminar
        // el wizard), está vinculado al admin que lo originó por la propia
        // sesión PS, y ya no es un "secreto largo" que justifique rotación
        // agresiva — basta con que cambie entre instalaciones distintas.
        $batchToken = TpvSyncSecrets::get('TPVSYNC_BATCH_TOKEN');
        if ($batchToken === '') {
            $batchToken = bin2hex(random_bytes(16));
            TpvSyncSecrets::set('TPVSYNC_BATCH_TOKEN', $batchToken);
        }

        // URL pública del endpoint AJAX. getModuleLink resuelve a la URL
        // correcta según el shop y la config de SSL.
        $ajaxUrl = $this->context->link->getModuleLink(
            'tpvsync',
            'syncbatch',
            [],
            (bool) Configuration::get('PS_SSL_ENABLED')
        );

        // Ya no existe "saltar este paso": la decisión de autoridad es
        // inevitable. Si el comerciante quiere usar el plugin, elige uno
        // de los dos botones.

        $tokenJson  = htmlspecialchars($batchToken, ENT_QUOTES);
        $ajaxJson   = htmlspecialchars($ajaxUrl, ENT_QUOTES);
        $reloadUrl  = htmlspecialchars($url, ENT_QUOTES);
        // URL pública del logo PrestaShop (mascota oficial). Usamos cache-buster
        // por mtime para que se refresque tras cualquier reemplazo del asset.
        $psLogoFile = __DIR__ . '/views/img/prestashop.png';
        $psLogoVer  = file_exists($psLogoFile) ? (int) filemtime($psLogoFile) : self::VERSION;
        $psLogoUrl  = htmlspecialchars($this->_path . 'views/img/prestashop.png?v=' . $psLogoVer, ENT_QUOTES);
        // Logo Catinfog (TPV) — horizontal "Cat" + "infog" en rojo.
        $catLogoFile = __DIR__ . '/views/img/catinfog.png';
        $catLogoVer  = file_exists($catLogoFile) ? (int) filemtime($catLogoFile) : self::VERSION;
        $catLogoUrl  = htmlspecialchars($this->_path . 'views/img/catinfog.png?v=' . $catLogoVer, ENT_QUOTES);

        // Strings adicionales para los pasos 2 y 3 del wizard.
        $stepLabels = [
            'step1' => htmlspecialchars($this->l('Decidir'), ENT_QUOTES),
            'step2' => htmlspecialchars($this->l('Sincronizar'), ENT_QUOTES),
            'step3' => htmlspecialchars($this->l('Listo'), ENT_QUOTES),
            'tpv_choice_title' => htmlspecialchars($this->l('Manda el TPV'), ENT_QUOTES),
            'tpv_choice_lead'  => htmlspecialchars($this->l('Vendes principalmente en tienda física'), ENT_QUOTES),
            'tpv_choice_desc1' => htmlspecialchars(
                $this->l('Se mandará todo el catálogo de productos desde el TPV a PrestaShop.'),
                ENT_QUOTES
            ),
            'tpv_choice_desc2' => htmlspecialchars(
                $this->l('A partir de ahí esos productos quedarán sincronizados.'),
                ENT_QUOTES
            ),
            'tpv_choice_desc3' => htmlspecialchars(
                $this->l('Cada producto que crees nuevo desde el TPV se enviará a PrestaShop.'),
                ENT_QUOTES
            ),
            'ps_choice_title'  => htmlspecialchars($this->l('Manda PrestaShop'), ENT_QUOTES),
            'ps_choice_lead'   => htmlspecialchars($this->l('Vendes principalmente online'), ENT_QUOTES),
            'ps_choice_desc1'  => htmlspecialchars(
                $this->l('Se mandará todo el catálogo de productos de PrestaShop al TPV.'),
                ENT_QUOTES
            ),
            'ps_choice_desc2'  => htmlspecialchars(
                $this->l('A partir de ahí esos productos quedarán sincronizados.'),
                ENT_QUOTES
            ),
            'ps_choice_desc3'  => htmlspecialchars(
                $this->l('Cada producto que crees nuevo desde PrestaShop se enviará al TPV.'),
                ENT_QUOTES
            ),
            'choose_btn'  => htmlspecialchars($this->l('Elegir esta opción'), ENT_QUOTES),
            'syncing_tpv_title' => htmlspecialchars($this->l('Subiendo el catálogo del TPV a PrestaShop…'), ENT_QUOTES),
            'syncing_ps_title'  => htmlspecialchars($this->l('Subiendo el catálogo de PrestaShop al TPV…'), ENT_QUOTES),
            'syncing_lead' => htmlspecialchars(
                $this->l('No cierres esta ventana. Esta es la única vez que tienes que esperar — luego todo va automático.'),
                ENT_QUOTES
            ),
            'success_title' => htmlspecialchars($this->l('¡Listo! Ya están sincronizados.'), ENT_QUOTES),
            'success_lead_tpv' => htmlspecialchars(
                $this->l('Manda el TPV: las modificaciones de productos se hacen desde ahí. Los cambios viajan solos a PrestaShop. El stock siempre se actualiza en ambos lados al vender.'),
                ENT_QUOTES
            ),
            'success_lead_ps' => htmlspecialchars(
                $this->l('Manda PrestaShop: las modificaciones de productos se hacen desde aquí. Los cambios viajan solos al TPV. El stock siempre se actualiza en ambos lados al vender.'),
                ENT_QUOTES
            ),
            'success_btn' => htmlspecialchars($this->l('Continuar al panel'), ENT_QUOTES),
            'eta_label' => htmlspecialchars($this->l('Tiempo restante aprox.'), ENT_QUOTES),
            'failed_title' => htmlspecialchars($this->l('Algo ha ido mal'), ENT_QUOTES),
            'retry_btn' => htmlspecialchars($this->l('Reintentar'), ENT_QUOTES),
            'back_btn'  => htmlspecialchars($this->l('Volver al paso anterior'), ENT_QUOTES),
        ];

        return <<<HTML
<div class="tpvsync-card tpvsync-wizard tpvsync-wizard-v2"
     id="tpvsync-wizard"
     data-tpvsync-ajax="{$ajaxJson}"
     data-tpvsync-token="{$tokenJson}"
     data-tpvsync-reload="{$reloadUrl}"
     data-l-syncing-tpv="{$stepLabels['syncing_tpv_title']}"
     data-l-syncing-ps="{$stepLabels['syncing_ps_title']}"
     data-l-success-tpv="{$stepLabels['success_lead_tpv']}"
     data-l-success-ps="{$stepLabels['success_lead_ps']}"
     data-l-eta="{$stepLabels['eta_label']}"
     data-l-failed="{$stepLabels['failed_title']}"
     data-l-retry="{$stepLabels['retry_btn']}">

    <!-- Stepper visual: 3 pasos -->
    <ol class="tpvsync-stepper" aria-label="Pasos">
        <li class="tpvsync-stepper-item is-active" data-step="1">
            <span class="tpvsync-stepper-num">1</span>
            <span class="tpvsync-stepper-label">{$stepLabels['step1']}</span>
        </li>
        <li class="tpvsync-stepper-line"></li>
        <li class="tpvsync-stepper-item" data-step="2">
            <span class="tpvsync-stepper-num">2</span>
            <span class="tpvsync-stepper-label">{$stepLabels['step2']}</span>
        </li>
        <li class="tpvsync-stepper-line"></li>
        <li class="tpvsync-stepper-item" data-step="3">
            <span class="tpvsync-stepper-num">3</span>
            <span class="tpvsync-stepper-label">{$stepLabels['step3']}</span>
        </li>
    </ol>

    <!-- ──────────── PASO 1: DECISIÓN ──────────── -->
    <section class="tpvsync-wstep is-active" data-step-panel="1">
        <h2 class="tpvsync-wstep-title">{$L['title']}</h2>
        <p class="tpvsync-wstep-lead">{$L['intro']}</p>

        <div class="tpvsync-counts">
            <div class="tpvsync-count-box">
                <span class="tpvsync-count-label">{$L['ps_label']}</span>
                <span class="tpvsync-count-value">{$psCount}</span>
                <span class="tpvsync-count-unit">{$L['unit_label']}</span>
            </div>
            <div class="tpvsync-count-arrow">⇄</div>
            <div class="tpvsync-count-box">
                <span class="tpvsync-count-label">{$L['tpv_label']}</span>
                <span class="tpvsync-count-value" id="tpvsync-count-tpv">{$tpvCountStr}</span>
                {$tpvUnitHtml}
            </div>
        </div>

        <p class="tpvsync-match-note">
            <span class="tpvsync-match-note-icon" aria-hidden="true">ℹ</span>
            <span>{$L['match_note']}</span>
        </p>

        <div class="tpvsync-bigchoice">
            <!-- PrestaShop a la IZQUIERDA -->
            <button type="button"
                    class="tpvsync-bigchoice-card tpvsync-bigchoice-card-ps"
                    data-tpvsync-action="push"
                    data-tpvsync-principal="ps"
                    data-tpvsync-confirm="{$confirmPush}">
                <div class="tpvsync-bigchoice-icon tpvsync-bigchoice-icon-ps" aria-hidden="true">
                    <img src="{$psLogoUrl}" alt="" width="56" height="56" loading="lazy">
                </div>
                <div class="tpvsync-bigchoice-body">
                    <strong>{$stepLabels['ps_choice_title']}</strong>
                    <span class="tpvsync-bigchoice-lead">{$stepLabels['ps_choice_lead']}</span>
                    <ol class="tpvsync-bigchoice-steps">
                        <li>{$stepLabels['ps_choice_desc1']}</li>
                        <li>{$stepLabels['ps_choice_desc2']}</li>
                        <li>{$stepLabels['ps_choice_desc3']}</li>
                    </ol>
                </div>
                <span class="tpvsync-bigchoice-cta">{$stepLabels['choose_btn']} →</span>
            </button>

            <!-- TPV a la DERECHA -->
            <button type="button"
                    class="tpvsync-bigchoice-card tpvsync-bigchoice-card-tpv"
                    data-tpvsync-action="pull"
                    data-tpvsync-principal="tpv"
                    data-tpvsync-confirm="{$confirmPull}">
                <div class="tpvsync-bigchoice-icon tpvsync-bigchoice-icon-tpv tpvsync-bigchoice-icon-wide" aria-hidden="true">
                    <img src="{$catLogoUrl}" alt="Catinfog" loading="lazy">
                </div>
                <div class="tpvsync-bigchoice-body">
                    <strong>{$stepLabels['tpv_choice_title']}</strong>
                    <span class="tpvsync-bigchoice-lead">{$stepLabels['tpv_choice_lead']}</span>
                    <ol class="tpvsync-bigchoice-steps">
                        <li>{$stepLabels['tpv_choice_desc1']}</li>
                        <li>{$stepLabels['tpv_choice_desc2']}</li>
                        <li>{$stepLabels['tpv_choice_desc3']}</li>
                    </ol>
                </div>
                <span class="tpvsync-bigchoice-cta">{$stepLabels['choose_btn']} →</span>
            </button>
        </div>

        <p class="tpvsync-hint tpvsync-hint-warn" id="tpvsync-count-hint" style="margin: 12px 0 0; text-align: center; font-size: 12px; display: none;"></p>
        <p class="tpvsync-hint" style="margin: 14px 0 0; font-size: 12.5px;">{$L['warning']}</p>
    </section>

    <!-- ──────────── PASO 2: SINCRONIZACIÓN ──────────── -->
    <section class="tpvsync-wstep" data-step-panel="2">
        <h2 class="tpvsync-wstep-title" id="tpvsync-syncing-title">{$stepLabels['syncing_tpv_title']}</h2>
        <p class="tpvsync-wstep-lead">{$stepLabels['syncing_lead']}</p>

        <!-- Animación: PrestaShop SIEMPRE a la izquierda, TPV SIEMPRE a la
             derecha. La dirección de los paquetes cambia según quién manda:
             si manda PS, viajan de izquierda→derecha (PS empuja al TPV).
             Si manda TPV, viajan de derecha→izquierda (TPV empuja a PS). -->
        <div class="tpvsync-syncscene" id="tpvsync-syncscene">
            <div class="tpvsync-syncbox tpvsync-syncbox-ps" id="tpvsync-syncbox-ps">
                <div class="tpvsync-syncbox-icon" aria-label="PrestaShop">
                    <img src="{$psLogoUrl}" alt="" width="48" height="48" loading="lazy">
                </div>
                <div class="tpvsync-syncbox-name">PrestaShop</div>
                <div class="tpvsync-syncbox-counter" id="tpvsync-syncbox-ps-counter"></div>
            </div>
            <div class="tpvsync-syncpipe" aria-hidden="true">
                <span class="tpvsync-syncparcel tpvsync-syncparcel-1"></span>
                <span class="tpvsync-syncparcel tpvsync-syncparcel-2"></span>
                <span class="tpvsync-syncparcel tpvsync-syncparcel-3"></span>
            </div>
            <div class="tpvsync-syncbox tpvsync-syncbox-tpv" id="tpvsync-syncbox-tpv">
                <div class="tpvsync-syncbox-icon tpvsync-syncbox-icon-wide" aria-label="Catinfog">
                    <img src="{$catLogoUrl}" alt="Catinfog" loading="lazy">
                </div>
                <div class="tpvsync-syncbox-name">TPV</div>
                <div class="tpvsync-syncbox-counter" id="tpvsync-syncbox-tpv-counter"></div>
            </div>
        </div>

        <div class="tpvsync-syncprogress">
            <div class="tpvsync-syncprogress-bar-wrap">
                <div class="tpvsync-syncprogress-bar" id="tpvsync-syncprogress-bar"></div>
            </div>
            <div class="tpvsync-syncprogress-meta">
                <span id="tpvsync-syncprogress-counts">0 / 0</span>
                <span id="tpvsync-syncprogress-pct">0%</span>
            </div>
            <div class="tpvsync-syncprogress-eta" id="tpvsync-syncprogress-eta">&nbsp;</div>
        </div>

        <div class="tpvsync-syncfail" id="tpvsync-syncfail" hidden>
            <strong id="tpvsync-syncfail-title">{$stepLabels['failed_title']}</strong>
            <p id="tpvsync-syncfail-msg"></p>
            <div class="tpvsync-syncfail-actions">
                <button type="button" class="tpvsync-btn tpvsync-btn-secondary" id="tpvsync-syncfail-back">{$stepLabels['back_btn']}</button>
                <button type="button" class="tpvsync-btn tpvsync-btn-primary" id="tpvsync-syncfail-retry">{$stepLabels['retry_btn']}</button>
            </div>
        </div>
    </section>

    <!-- ──────────── PASO 3: ÉXITO ──────────── -->
    <section class="tpvsync-wstep" data-step-panel="3">
        <div class="tpvsync-success-circle" aria-hidden="true">
            <svg width="64" height="64" viewBox="0 0 64 64" fill="none">
                <circle cx="32" cy="32" r="30" stroke="#10b981" stroke-width="3" class="tpvsync-success-ring"></circle>
                <path d="M20 33 L29 42 L45 24" stroke="#10b981" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" fill="none" class="tpvsync-success-check"></path>
            </svg>
        </div>
        <h2 class="tpvsync-wstep-title tpvsync-wstep-title-success">{$stepLabels['success_title']}</h2>
        <p class="tpvsync-wstep-lead" id="tpvsync-success-lead">&nbsp;</p>

        <!-- Resumen numérico real (creados/actualizados/errores). Lo rellena
             buildSuccessBullets() con los acumuladores cross-batch del backend. -->
        <div id="tpvsync-success-stats"></div>

        <ul class="tpvsync-success-bullets" id="tpvsync-success-bullets"></ul>

        <button type="button" class="tpvsync-btn tpvsync-btn-primary tpvsync-btn-large" id="tpvsync-success-continue">
            {$stepLabels['success_btn']}
        </button>
    </section>
</div>

HTML;
    }

    /**
     * Modal de progreso compartido por wizard inicial y reconcile.
     *
     * Se renderiza SIEMPRE (oculto por defecto). Antes vivía dentro de
     * Panel "Mapeo de impuestos" — equivalencia entre tax_rules_groups PS y
     * tax_class_id del TPV. Sin esto, todos los productos van con el default
     * global (Configuration::TPVSYNC_DEFAULT_TAX_CLASS_ID), lo que es OK
     * para tiendas mono-IVA pero incorrecto si el cliente tiene productos
     * con IVAs distintos (libros 4%, alimentación 10%, normal 21%).
     *
     * El panel hace una sola llamada AJAX al cargar (carga PS classes + TPV
     * classes + mapeo actual) y otra al guardar. Sin recarga de página.
     */
    private function renderTaxMappingPanel(): string
    {
        $title  = htmlspecialchars($this->l('Mapeo de impuestos'), ENT_QUOTES);
        $intro  = htmlspecialchars($this->l('Asocia cada clase de impuesto de PrestaShop con la clase fiscal del TPV. Si no está mapeada, se usa la clase por defecto.'), ENT_QUOTES);
        $defLabel = htmlspecialchars($this->l('Clase por defecto (TPV)'), ENT_QUOTES);
        $loading = htmlspecialchars($this->l('Cargando…'), ENT_QUOTES);
        $errLoad = htmlspecialchars($this->l('No se pudo cargar el mapeo. Comprueba la conexión con el TPV.'), ENT_QUOTES);
        $saveLbl = htmlspecialchars($this->l('Guardar mapeo'), ENT_QUOTES);
        $saveOk  = htmlspecialchars($this->l('Mapeo guardado.'), ENT_QUOTES);
        $saveErr = htmlspecialchars($this->l('No se pudo guardar.'), ENT_QUOTES);
        $unmapped = htmlspecialchars($this->l('— Sin mapear —'), ENT_QUOTES);

        $ajaxUrl = AdminController::$currentIndex . '&configure=' . $this->name
                 . '&token=' . Tools::getAdminTokenLite('AdminModules');

        return <<<HTML
<div class="tpvsync-card" id="tpvsync-tax-map-card">
  <h3 style="margin-top:0">$title</h3>
  <p style="color:#6b7280;margin-top:4px">$intro</p>
  <div id="tpvsync-tax-map-status">$loading</div>
  <div id="tpvsync-tax-map-body" style="display:none">
    <div style="margin-bottom:14px">
      <label for="tpvsync-tax-default" style="font-weight:600">$defLabel</label>
      <select id="tpvsync-tax-default" style="margin-left:8px;min-width:240px"></select>
    </div>
    <table style="width:100%;border-collapse:collapse" class="tpvsync-tax-table">
      <thead>
        <tr style="background:#f3f4f6">
          <th style="padding:8px;text-align:left">PrestaShop</th>
          <th style="padding:8px;text-align:left">→ TPV</th>
        </tr>
      </thead>
      <tbody id="tpvsync-tax-tbody"></tbody>
    </table>
    <div style="margin-top:12px">
      <button type="button" id="tpvsync-tax-save" class="tpvsync-btn tpvsync-btn-primary">$saveLbl</button>
      <span id="tpvsync-tax-save-msg" style="margin-left:10px"></span>
    </div>
  </div>
</div>
<script>
(function(){
  var url = '$ajaxUrl';
  var tbody = document.getElementById('tpvsync-tax-tbody');
  var status = document.getElementById('tpvsync-tax-map-status');
  var body = document.getElementById('tpvsync-tax-map-body');
  var defaultSelect = document.getElementById('tpvsync-tax-default');
  var btnSave = document.getElementById('tpvsync-tax-save');
  var saveMsg = document.getElementById('tpvsync-tax-save-msg');
  var psClasses = [], tpvClasses = [], mapping = {}, defaultId = 0;

  function buildSelect(id, currentVal) {
    var html = '<option value="0">$unmapped</option>';
    for (var i = 0; i < tpvClasses.length; i++) {
      var c = tpvClasses[i];
      var sel = (parseInt(currentVal, 10) === c.tax_class_id) ? ' selected' : '';
      html += '<option value="' + c.tax_class_id + '"' + sel + '>'
            + escHtml(c.title) + ' (id ' + c.tax_class_id + ')</option>';
    }
    return html;
  }
  function escHtml(s) {
    return String(s).replace(/[&<>"']/g, function(c) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }
  function render() {
    // Default select
    defaultSelect.innerHTML = buildSelect('tpvsync-tax-default', defaultId);
    // Tabla por clase PS
    var html = '';
    for (var i = 0; i < psClasses.length; i++) {
      var ps = psClasses[i];
      var current = mapping[ps.id_tax_rules_group] || 0;
      html += '<tr>';
      html += '<td style="padding:8px;border-bottom:1px solid #e5e7eb">' + escHtml(ps.name) + ' <small style="color:#6b7280">(id ' + ps.id_tax_rules_group + ')</small></td>';
      html += '<td style="padding:8px;border-bottom:1px solid #e5e7eb">';
      html += '<select data-ps-id="' + ps.id_tax_rules_group + '" style="width:100%">';
      html += buildSelect(0, current);
      html += '</select></td>';
      html += '</tr>';
    }
    if (psClasses.length === 0) {
      html = '<tr><td colspan="2" style="padding:14px;color:#6b7280">No hay clases de impuestos en PrestaShop.</td></tr>';
    }
    tbody.innerHTML = html;
  }
  function load() {
    var fd = new FormData();
    fd.append('tpvsync_ajax', 'tax_map_load');
    fetch(url, {method:'POST', body:fd, credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(data) {
        if (!data || data.error) { status.textContent = '$errLoad'; return; }
        psClasses = data.ps_classes || [];
        tpvClasses = data.tpv_classes || [];
        mapping = data.mapping || {};
        defaultId = data.default_id || 0;
        if (tpvClasses.length === 0) {
          status.textContent = '$errLoad';
          return;
        }
        status.style.display = 'none';
        body.style.display = 'block';
        render();
      })
      .catch(function() { status.textContent = '$errLoad'; });
  }
  btnSave.addEventListener('click', function() {
    saveMsg.textContent = '';
    var newMap = {};
    var rows = tbody.querySelectorAll('select[data-ps-id]');
    rows.forEach(function(sel) {
      var psId = parseInt(sel.getAttribute('data-ps-id'), 10);
      var cid = parseInt(sel.value, 10);
      if (psId > 0 && cid > 0) newMap[psId] = cid;
    });
    var newDef = parseInt(defaultSelect.value, 10) || 0;
    var fd = new FormData();
    fd.append('tpvsync_ajax', 'tax_map_save');
    fd.append('mapping_json', JSON.stringify(newMap));
    fd.append('default_id', String(newDef));
    fetch(url, {method:'POST', body:fd, credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(data) {
        if (data && data.ok) {
          saveMsg.style.color = '#10b981';
          saveMsg.textContent = '$saveOk';
        } else {
          saveMsg.style.color = '#ef4444';
          saveMsg.textContent = '$saveErr';
        }
      })
      .catch(function() {
        saveMsg.style.color = '#ef4444';
        saveMsg.textContent = '$saveErr';
      });
  });
  load();
})();
</script>
HTML;
    }

    /**
     * Panel "Bulk push de clientes" — análogo al de WC. Ejecuta
     * `pushAllPsCustomers()` lote a lote y muestra progreso.
     */
    private function renderBulkCustomersPanel(): string
    {
        $title = htmlspecialchars($this->l('Sincronizar clientes'), ENT_QUOTES);
        $intro = htmlspecialchars($this->l('Envía al TPV todos los clientes registrados de PrestaShop. Idempotente: los ya sincronizados se vinculan por email sin duplicarse.'), ENT_QUOTES);
        $btnLbl = htmlspecialchars($this->l('Sincronizar clientes ahora'), ENT_QUOTES);
        $running = htmlspecialchars($this->l('Sincronizando…'), ENT_QUOTES);
        $errMsg = htmlspecialchars($this->l('Falló la sincronización. Mira los logs.'), ENT_QUOTES);

        $ajaxUrl = AdminController::$currentIndex . '&configure=' . $this->name
                 . '&token=' . Tools::getAdminTokenLite('AdminModules');

        return <<<HTML
<div class="tpvsync-card" id="tpvsync-bulk-customers-card">
  <h3 style="margin-top:0">$title</h3>
  <p style="color:#6b7280;margin-top:4px">$intro</p>
  <button type="button" id="tpvsync-bulk-customers-btn" class="tpvsync-btn tpvsync-btn-primary">$btnLbl</button>
  <div id="tpvsync-bulk-customers-out" style="margin-top:10px;font-family:monospace;color:#374151"></div>
</div>
<script>
(function(){
  var url = '$ajaxUrl';
  var btn = document.getElementById('tpvsync-bulk-customers-btn');
  var out = document.getElementById('tpvsync-bulk-customers-out');
  btn.addEventListener('click', function() {
    btn.disabled = true;
    out.textContent = '$running';
    var fd = new FormData();
    fd.append('tpvsync_ajax', 'bulk_customers');
    fetch(url, {method:'POST', body:fd, credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(data) {
        if (data && data.ok && data.stats) {
          out.style.color = '#10b981';
          out.textContent = 'OK · enviados: ' + (data.stats.sent || 0)
                          + ' · creados: ' + (data.stats.created || 0)
                          + ' · vinculados: ' + (data.stats.matched || 0)
                          + ' · errores: ' + (data.stats.errors || 0);
        } else {
          out.style.color = '#ef4444';
          out.textContent = '$errMsg';
        }
        btn.disabled = false;
      })
      .catch(function() {
        out.style.color = '#ef4444';
        out.textContent = '$errMsg';
        btn.disabled = false;
      });
  });
})();
</script>
HTML;
    }

    /**
     * Panel "Diagnóstico" — vista rápida del estado de salud del módulo.
     * Una sola llamada AJAX a /health_check devuelve un set de checks
     * (api, webhook, breaker, queue, secrets, mappings huérfanos) y los
     * mostramos como semáforo verde/ámbar/rojo + detalles.
     */
    private function renderHealthPanel(): string
    {
        $title  = htmlspecialchars($this->l('Diagnóstico'), ENT_QUOTES);
        $intro  = htmlspecialchars($this->l('Vista rápida del estado del módulo. Si algo está rojo o ámbar, el detalle te dice qué revisar.'), ENT_QUOTES);
        $loading = htmlspecialchars($this->l('Cargando…'), ENT_QUOTES);
        $refreshLbl = htmlspecialchars($this->l('Refrescar'), ENT_QUOTES);

        $ajaxUrl = AdminController::$currentIndex . '&configure=' . $this->name
                 . '&token=' . Tools::getAdminTokenLite('AdminModules');

        return <<<HTML
<div class="tpvsync-card" id="tpvsync-health-card">
  <h3 style="margin-top:0">$title</h3>
  <p style="color:#6b7280;margin-top:4px">$intro</p>
  <div id="tpvsync-health-content">$loading</div>
  <div style="margin-top:10px">
    <button type="button" id="tpvsync-health-refresh" class="tpvsync-btn tpvsync-btn-secondary">$refreshLbl</button>
  </div>
</div>
<script>
(function(){
  var url = '$ajaxUrl';
  var content = document.getElementById('tpvsync-health-content');
  var btn = document.getElementById('tpvsync-health-refresh');
  function colorFor(level) {
    return level === 'ok' ? '#10b981' : (level === 'warn' ? '#f59e0b' : '#ef4444');
  }
  function iconFor(level) {
    return level === 'ok' ? '✓' : (level === 'warn' ? '!' : '✗');
  }
  function escHtml(s) {
    return String(s).replace(/[&<>"']/g, function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});
  }
  function render(checks) {
    if (!checks || !Array.isArray(checks)) {
      content.innerHTML = '<span style="color:#ef4444">No se pudo obtener el diagnóstico.</span>';
      return;
    }
    var html = '<table style="width:100%;border-collapse:collapse">';
    checks.forEach(function(c) {
      var color = colorFor(c.level);
      html += '<tr>';
      html += '<td style="padding:6px 10px;border-bottom:1px solid #e5e7eb;width:30px;color:' + color + ';font-weight:bold;font-size:18px">' + iconFor(c.level) + '</td>';
      html += '<td style="padding:6px 10px;border-bottom:1px solid #e5e7eb;font-weight:600">' + escHtml(c.name) + '</td>';
      html += '<td style="padding:6px 10px;border-bottom:1px solid #e5e7eb;color:#374151">' + escHtml(c.detail) + '</td>';
      html += '</tr>';
    });
    html += '</table>';
    content.innerHTML = html;
  }
  function load() {
    content.innerHTML = '$loading';
    var fd = new FormData();
    fd.append('tpvsync_ajax', 'health_check');
    fetch(url, {method:'POST', body:fd, credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(data){ render(data.checks); })
      .catch(function(){ content.innerHTML = '<span style="color:#ef4444">Error de red.</span>'; });
  }
  btn.addEventListener('click', load);
  load();
})();
</script>
HTML;
    }

    /**
     * AJAX handler: devuelve un array `checks[]` con el estado actual del
     * módulo. Cada check tiene { name, level (ok|warn|err), detail }.
     */
    private function ajaxHealthCheck(): array
    {
        $checks = [];

        // 1) Configuración mínima
        $apiUrl  = (string) Configuration::get('TPVSYNC_API_URL');
        $cid     = (string) Configuration::get('TPVSYNC_CLIENT_ID');
        $secret  = TpvSyncSecrets::get('TPVSYNC_CLIENT_SECRET');
        $configured = $apiUrl !== '' && $cid !== '' && $secret !== '';
        $checks[] = [
            'name'   => 'Configuración',
            'level'  => $configured ? 'ok' : 'err',
            'detail' => $configured
                ? 'API URL + Client ID + Secret presentes'
                : 'Falta API URL, Client ID o Client Secret',
        ];

        // 2) Circuit breaker — leído ANTES de hacer la API call.
        //    Si lo leemos después, una API call exitosa transicionaría
        //    breaker a closed automáticamente y nunca veríamos los estados
        //    open/half-open en el panel. El admin debe ver el estado tal
        //    cual está cuando abre la pestaña.
        $breakerState = (string) Configuration::get('TPVSYNC_CB_STATE') ?: 'closed';
        $breakerFails = (int) Configuration::get('TPVSYNC_CB_FAILS');

        // 3) Conectividad API (/health). Si breaker está OPEN, la llamada
        //    fallará rápido (breaker corta antes de hacer red) — eso es lo
        //    que queremos: no añadir latencia al panel y mostrar el estado.
        if ($configured) {
            try {
                $r = $this->api()->get('/health');
                $apiOk = !empty($r['status']) && $r['status'] === 'ok';
                $checks[] = [
                    'name'   => 'API TPV',
                    'level'  => $apiOk ? 'ok' : 'err',
                    'detail' => $apiOk
                        ? 'GET /health → ' . ($r['service'] ?? 'ok')
                        : 'No responde OK al /health',
                ];
                Configuration::updateValue('TPVSYNC_HEALTH_OK', $apiOk ? 1 : 0);
                Configuration::updateValue('TPVSYNC_HEALTH_CHECKED_AT', time());
            } catch (\Throwable $e) {
                $checks[] = ['name' => 'API TPV', 'level' => 'err', 'detail' => 'Excepción: ' . $e->getMessage()];
                Configuration::updateValue('TPVSYNC_HEALTH_OK', 0);
                Configuration::updateValue('TPVSYNC_HEALTH_CHECKED_AT', time());
            }
        }

        // 4) Webhook activo
        $webhookId = (int) Configuration::get('TPVSYNC_WEBHOOK_ID');
        $webhookSec = TpvSyncSecrets::get('TPVSYNC_WEBHOOK_SECRET');
        $webhookOk = $webhookId > 0 && $webhookSec !== '';
        $checks[] = [
            'name'   => 'Webhook',
            'level'  => $webhookOk ? 'ok' : 'warn',
            'detail' => $webhookOk
                ? "ID $webhookId con secret HMAC"
                : 'No registrado — el TPV no podrá notificar cambios al plugin',
        ];

        // Y ahora añadimos el check del breaker (con el state ya capturado).
        $breakerLevel = $breakerState === 'open' ? 'err'
                     : ($breakerState === 'half_open' ? 'warn' : 'ok');
        $checks[] = [
            'name'   => 'Circuit breaker',
            'level'  => $breakerLevel,
            'detail' => "Estado: $breakerState · fallos: $breakerFails",
        ];

        // 5) Queue
        $pending = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'tpv_sync_queue WHERE status = "pending"'
        );
        $abandoned = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'tpv_sync_queue WHERE status = "abandoned"'
        );
        $queueLevel = $abandoned > 0 ? 'err' : ($pending > 50 ? 'warn' : 'ok');
        $checks[] = [
            'name'   => 'Queue',
            'level'  => $queueLevel,
            'detail' => "$pending pending · $abandoned abandoned",
        ];

        // 6) Secrets
        $decryptFlag = (string) Configuration::get('TPVSYNC_SECRET_DECRYPT_FAILED');
        $checks[] = [
            'name'   => 'Cifrado de secrets',
            'level'  => $decryptFlag === '' ? 'ok' : 'err',
            'detail' => $decryptFlag === ''
                ? 'OK · libsodium disponible: ' . (function_exists('sodium_crypto_secretbox') ? 'sí' : 'no (usando openssl)')
                : 'Las credenciales no se pueden descifrar — pega de nuevo el Client Secret',
        ];

        // 7) Mappings huérfanos: filas en el map cuyo producto PS ya no existe
        $orphanMaps = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'tpv_sync_product_map m
             LEFT JOIN ' . _DB_PREFIX_ . 'product p ON p.id_product = m.id_product
             WHERE p.id_product IS NULL'
        );
        $checks[] = [
            'name'   => 'Mappings huérfanos',
            'level'  => $orphanMaps === 0 ? 'ok' : 'warn',
            'detail' => $orphanMaps === 0
                ? 'Sin huérfanos'
                : "$orphanMaps mappings apuntan a productos PS borrados",
        ];

        // 8) Tax mapping
        $taxMapCount = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'tpv_sync_tax_map'
        );
        $defaultTax = (int) Configuration::get('TPVSYNC_DEFAULT_TAX_CLASS_ID');
        $checks[] = [
            'name'   => 'Mapeo de impuestos',
            'level'  => ($taxMapCount > 0 || $defaultTax > 0) ? 'ok' : 'warn',
            'detail' => $taxMapCount > 0
                ? "$taxMapCount clases mapeadas · default = $defaultTax"
                : ($defaultTax > 0 ? "Solo default = $defaultTax" : 'Sin mapeo y sin default — productos irán sin tax_class_id'),
        ];

        return ['checks' => $checks];
    }

    /**
     * renderInitialSyncWizard y eso hacía que el botón "Reconciliar" del
     * footer (que está fuera del wizard) fallara silenciosamente porque el
     * JS no encontraba #tpvsync-progress-overlay en el DOM y hacía return.
     */
    private function renderProgressModal(): string
    {
        $dontClose = htmlspecialchars($this->l('No cierres esta ventana hasta que termine.'), ENT_QUOTES);
        $closeBtn  = htmlspecialchars($this->l('Cerrar y continuar'), ENT_QUOTES);
        $retry     = htmlspecialchars($this->l('Reintentar'), ENT_QUOTES);

        return <<<HTML
<div class="tpvsync-progress-overlay" id="tpvsync-progress-overlay" hidden
     onclick="if(event.target===this){this.hidden=true;document.body.classList.remove('tpvsync-modal-open');}">
    <div class="tpvsync-progress-modal">
        <button type="button" class="tpvsync-progress-x" id="tpvsync-progress-x"
                aria-label="Cerrar" title="Cancelar y cerrar"
                onclick="document.getElementById('tpvsync-progress-overlay').hidden=true;document.body.classList.remove('tpvsync-modal-open');">&times;</button>
        <h3 class="tpvsync-progress-title" id="tpvsync-progress-title">…</h3>
        <p class="tpvsync-progress-warn">{$dontClose}</p>
        <div class="tpvsync-progress-bar-wrap">
            <div class="tpvsync-progress-bar" id="tpvsync-progress-bar" style="width:0%"></div>
        </div>
        <div class="tpvsync-progress-meta">
            <span id="tpvsync-progress-counts">0 / 0</span>
            <span id="tpvsync-progress-percent">0%</span>
        </div>
        <p class="tpvsync-progress-eta" id="tpvsync-progress-eta"></p>
        <div class="tpvsync-progress-final" id="tpvsync-progress-final" hidden>
            <p id="tpvsync-progress-final-msg"></p>
            <button type="button" class="tpvsync-btn tpvsync-btn-primary"
                    id="tpvsync-progress-close">{$closeBtn}</button>
            <button type="button" class="tpvsync-btn tpvsync-btn-secondary"
                    id="tpvsync-progress-retry" hidden>{$retry}</button>
        </div>
    </div>
</div>
HTML;
    }

    /**
     * Pull inicial: trae todos los productos del TPV a PrestaShop.
     * Reutiliza renderImportAll (que ya pagina por lotes de 200 y persiste el
     * offset). Tras la pasada completa, marca TPVSYNC_INITIAL_SYNC_DONE=1.
     */
    private function renderInitialSyncPull(): string
    {
        $out = $this->renderImportAll();
        // renderImportAll deja el offset persistido en TPVSYNC_IMPORT_OFFSET.
        // Si ese flag está a 0 (o no existe) significa que terminó.
        $remaining = (int) Configuration::get('TPVSYNC_IMPORT_OFFSET');
        if ($remaining === 0) {
            Configuration::updateValue('TPVSYNC_INITIAL_SYNC_DONE', 1);
        }
        return $out;
    }

    /**
     * Push inicial: envía todos los productos de PrestaShop al TPV.
     * Wrapper legacy que itera procesando lotes hasta terminar — solo se usa
     * como fallback si el AJAX está desactivado. Para uso normal ver
     * processPushBatch() llamado desde el front controller AJAX.
     */
    private function renderInitialSyncPush(): string
    {
        // Loop síncrono solo si JS está roto. La UI principal usa AJAX y nunca
        // entra aquí. Procesamos un batch y devolvemos página con auto-recarga.
        $r = $this->processPushBatch();
        if (!empty($r['error'])) {
            return $this->displayError($this->l('No se pudo completar el envío inicial. Comprueba la conexión con el TPV e inténtalo de nuevo.'));
        }
        if ($r['done']) {
            return $this->displayConfirmation(sprintf(
                $this->l('Catálogo enviado al TPV. %d productos enviados, %d omitidos, %d con errores. La sincronización en tiempo real ya está activa.'),
                $r['sent'], $r['skipped'], $r['errors']
            ));
        }
        // Página intermedia: auto-recarga vía meta refresh para que continúe
        // sin requerir click manual cuando JS no está disponible.
        $url = AdminController::$currentIndex . '&configure=' . $this->name
            . '&token=' . Tools::getAdminTokenLite('AdminModules')
            . '&tpvsync_initial_push=1';
        $msg = sprintf(
            $this->l('Lote enviado: %d productos. Progreso: %d / %d.'),
            $r['sent'], $r['processed'], $r['total']
        );
        return $this->displayConfirmation($msg)
            . '<meta http-equiv="refresh" content="1;url=' . htmlspecialchars($url, ENT_QUOTES) . '">';
    }

    /**
     * Análisis de divergencia post-sync: detecta productos que solo existen
     * en un lado tras una sincronización inicial (push o pull).
     *
     * El "Three Way Merge" que falta en muchos conectores: tras un PUSH,
     * pueden quedar productos en el TPV que el merchant nunca importó a PS,
     * y al revés. Esta función los identifica para que el merchant decida.
     *
     * Devuelve:
     *   - only_in_tpv: [{tpv_product_id, model, sku, name, price}] hasta 100
     *   - only_in_tpv_count: total exacto
     *   - only_in_ps_count: productos PS sin mapping al TPV
     *   - tpv_total, ps_total: contadores generales
     *
     * Estrategia: para PUSH (PS → TPV) el caso interesante es "qué hay en el
     * TPV que no está en PS". Para PULL (TPV → PS) al revés. Devolvemos
     * ambos para que la UI pueda elegir según contexto.
     */
    /**
     * Resumen unificado del estado de sincronización para el botón
     * "Comprobar sincronización". Devuelve:
     *   - synced:         productos en ambos lados (mapeados).
     *   - islands_ps:     productos solo en PrestaShop (sin mapping).
     *   - islands_tpv:    productos solo en el TPV (no han llegado a PS).
     *   - divergences:    suma de islands que NO están en blocklist
     *                     (es decir, las que el usuario podría arreglar).
     *   - ps_total / tpv_total: contadores totales para mostrar contexto.
     *
     * Internamente reusa analyzeDivergence(): es la misma fuente de verdad,
     * solo cambia la presentación.
     */
    public function runSyncStatusCheck(): array
    {
        $diag = $this->analyzeDivergence();
        if (!empty($diag['error'])) {
            return [
                'synced'       => 0,
                'islands_ps'   => 0,
                'islands_tpv'  => 0,
                'divergences'  => 0,
                'unimportable' => 0,
                'ps_total'     => 0,
                'tpv_total'    => 0,
                'error'        => $diag['error'],
            ];
        }
        $synced     = (int) ($diag['mapped'] ?? 0);
        $islandsPs  = (int) ($diag['only_in_ps_count'] ?? 0);
        $islandsTpv = (int) ($diag['only_in_tpv_count'] ?? 0);
        $unimportable = (int) ($diag['unimportable_count'] ?? 0);
        // "Discrepancias" son las islas TPV importables al PS. Los productos
        // "no importables" (precio negativo, model vacío, duplicados, POS
        // internos) se filtran ANTES en analyzeDivergence y NO cuentan como
        // discrepancia: son ruido inevitable del TPV que el comerciante no
        // puede resolver desde aquí.
        $divergences = $islandsTpv;
        return [
            'synced'           => $synced,
            'islands_ps'       => $islandsPs,
            'islands_tpv'      => $islandsTpv,
            'divergences'      => $divergences,
            'unimportable'     => $unimportable,
            'ps_total'         => (int) ($diag['ps_total']  ?? 0),
            'tpv_total'        => (int) ($diag['tpv_total'] ?? 0),
            'only_in_tpv_sample' => $diag['only_in_tpv'] ?? [],
            'error'            => null,
        ];
    }

    public function analyzeDivergence(): array
    {
        try {
            // Snapshot ligero del catálogo TPV (solo IDs y campos clave).
            // Usamos el mismo cache de IDs que processPullBatch para no
            // re-descargar si ya lo tenemos.
            $cacheJson = (string) Configuration::get('TPVSYNC_PULL_IDS_CACHE');
            $cacheTs   = (int) Configuration::get('TPVSYNC_PULL_IDS_CACHE_TS');
            $tpvProducts = [];

            // Si no tenemos cache fresca, descargamos snapshot completo del TPV.
            // Necesitamos model+sku+name+price para mostrar al merchant en la UI.
            $r = $this->api()->getAll('/products', [
                'fields' => 'product_id,model,sku,name,price',
            ]);
            foreach ($r as $row) {
                $tid = (int) ($row['product_id'] ?? 0);
                if ($tid <= 0) { continue; }
                $tpvProducts[$tid] = [
                    'tpv_product_id' => $tid,
                    'model'          => (string) ($row['model'] ?? ''),
                    'sku'            => (string) ($row['sku'] ?? ''),
                    'name'           => (string) ($row['name'] ?? ''),
                    'price'          => (float) ($row['price'] ?? 0),
                ];
            }
            $tpvTotal = count($tpvProducts);

            // Mapeos locales: tpv_id que están en PS.
            $mappedTpvIds = Db::getInstance()->executeS(
                'SELECT tpv_product_id FROM ' . _DB_PREFIX_ . 'tpv_sync_product_map'
            ) ?: [];
            $inPs = [];
            foreach ($mappedTpvIds as $m) {
                $inPs[(int) $m['tpv_product_id']] = true;
            }

            // Blocklist: productos TPV que NO se pueden importar (datos rotos
            // en el TPV — precio negativo, etc). Los excluimos para que la UI
            // no entre en bucle infinito intentando importar lo imposible.
            $blocklistRaw = (string) Configuration::get('TPVSYNC_DIVERGENCE_BLOCKLIST');
            $blocklist = [];
            if ($blocklistRaw !== '') {
                foreach (explode(',', $blocklistRaw) as $bid) {
                    $bid = (int) $bid;
                    if ($bid > 0) { $blocklist[$bid] = true; }
                }
            }

            // Auto-clasificación de productos NO IMPORTABLES.
            // Antes presentábamos al usuario "discrepancias" que en realidad
            // eran productos del TPV que NUNCA podrían pasar a PrestaShop:
            //   - precio negativo (PS rechaza con Product::isPrice())
            //   - model vacío (no hay identificador único cross-system)
            //   - model duplicado en el propio TPV (PS UNIQUE rechaza el 2º)
            //   - productos internos del POS (POS_DISCOUNT, POS_SERVICE...)
            //     que solo existen para flujo interno de caja.
            //
            // Los detectamos aquí y los marcamos como "no importables", se
            // filtran de las islas y se cuentan aparte. La UI puede mostrar
            // este número como contexto (sin alarmar) pero no como acción
            // pendiente. Si quisiéramos podríamos persistirlos en la
            // blocklist, pero como la detección es derivable, recalcularlos
            // cada vez es más simple y robusto que mantener BD.
            $modelCount = [];
            foreach ($tpvProducts as $p) {
                $m = trim((string) $p['model']);
                if ($m !== '') {
                    $modelCount[$m] = ($modelCount[$m] ?? 0) + 1;
                }
            }
            $internalPosModels = ['POS_DISCOUNT', 'POS_SERVICE', 'POS_TIP'];
            $isUnimportable = function (array $p) use ($modelCount, $internalPosModels): bool {
                if ((float) ($p['price'] ?? 0) < 0) return true;
                $m = trim((string) ($p['model'] ?? ''));
                if ($m === '') return true;
                if (in_array($m, $internalPosModels, true)) return true;
                if (($modelCount[$m] ?? 0) > 1) return true;
                return false;
            };

            // Productos solo en TPV: están en $tpvProducts pero no en $inPs
            // ni en el blocklist. Los "no importables" se cuentan aparte.
            $onlyInTpv = [];
            $unimportableCount = 0;
            foreach ($tpvProducts as $tid => $p) {
                if (isset($inPs[$tid]) || isset($blocklist[$tid])) continue;
                if ($isUnimportable($p)) {
                    $unimportableCount++;
                    continue;
                }
                $onlyInTpv[] = $p;
            }
            $onlyInTpvCount = count($onlyInTpv);
            // Devolvemos solo los primeros 100 para no saturar la UI; el
            // count total guía al merchant para decidir.
            $onlyInTpvSample = array_slice($onlyInTpv, 0, 100);

            // Productos PS sin mapping (solo en PS, no llegaron al TPV).
            $psTotal = (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'product WHERE state = 1'
            );
            $mappedPsCount = (int) Db::getInstance()->getValue(
                'SELECT COUNT(DISTINCT id_product) FROM ' . _DB_PREFIX_ . 'tpv_sync_product_map'
            );
            $onlyInPsCount = max(0, $psTotal - $mappedPsCount);

            return [
                'tpv_total'           => $tpvTotal,
                'ps_total'            => $psTotal,
                'mapped'              => $mappedPsCount,
                'only_in_tpv'         => $onlyInTpvSample,
                'only_in_tpv_count'   => $onlyInTpvCount,
                'only_in_ps_count'    => $onlyInPsCount,
                'unimportable_count'  => $unimportableCount,
                'error'               => null,
            ];
        } catch (\Throwable $e) {
            TpvSyncLog::error('divergence', 0, 'analyzeDivergence: ' . $e->getMessage());
            return [
                'tpv_total' => 0,
                'ps_total' => 0,
                'mapped' => 0,
                'only_in_tpv' => [],
                'only_in_tpv_count' => 0,
                'only_in_ps_count' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Procesa una acción de resolución de divergencia post-sync.
     *
     * Acciones soportadas:
     *   - 'import_tpv_orphans': pull al PS de productos que solo existen en TPV.
     *     Usa importAll() en modo selectivo (limitado a una lista de tpv_ids).
     *   - 'deactivate_tpv_orphans': desactiva en TPV (status=0) los productos
     *     que solo están allí. NO los borra — el merchant puede reactivarlos.
     *   - 'postpone': solo marca la divergencia como pospuesta. El merchant
     *     puede volver a abordarla desde el botón Reconciliar.
     */
    public function processDivergenceAction(): array
    {
        $action = (string) Tools::getValue('div_action', '');
        $tpvIdsRaw = Tools::getValue('tpv_ids', '');
        $tpvIds = is_string($tpvIdsRaw)
            ? array_filter(array_map('intval', explode(',', $tpvIdsRaw)))
            : (is_array($tpvIdsRaw) ? array_filter(array_map('intval', $tpvIdsRaw)) : []);

        if (!in_array($action, ['import_tpv_orphans', 'deactivate_tpv_orphans', 'postpone'], true)) {
            return ['error' => 'invalid_action'];
        }

        try {
            if ($action === 'postpone') {
                Configuration::updateValue('TPVSYNC_DIVERGENCE_POSTPONED', time());
                return ['done' => true, 'action' => 'postpone'];
            }

            if ($action === 'import_tpv_orphans') {
                // Importar a PS los productos huérfanos del TPV. Para no
                // saturar, procesamos hasta 100 por llamada. La UI puede
                // re-llamar si hay más.
                $batchIds = array_slice($tpvIds, 0, 100);
                $stats = ['imported' => 0, 'errors' => 0, 'skipped' => 0];
                // IDs no procesables (skipped/error) — los marcamos para que
                // analyze_divergence siguiente los excluya y la UI no entre
                // en bucle infinito tratando de importar productos imposibles
                // (ej: precio negativo, datos rotos en el TPV).
                $unprocessable = [];
                foreach ($batchIds as $tpvId) {
                    try {
                        $detail = $this->api()->get('/products/' . $tpvId);
                        if (!empty($detail['data'])) {
                            $r = $this->products()->upsert($detail['data']);
                            if ($r === 'created' || $r === 'updated') {
                                $stats['imported']++;
                            } else {
                                // 'skipped' u otro: contar y marcar como
                                // no procesable para que el bucle pare.
                                $stats['skipped']++;
                                $unprocessable[] = (int) $tpvId;
                            }
                        } else {
                            $stats['errors']++;
                            $unprocessable[] = (int) $tpvId;
                        }
                    } catch (\Throwable $e) {
                        $stats['errors']++;
                        $unprocessable[] = (int) $tpvId;
                        TpvSyncLog::error('divergence', $tpvId,
                            'import_tpv_orphans: ' . $e->getMessage());
                    }
                }
                // Persistir los IDs unprocessable en una option de PS para
                // que analyze_divergence los excluya en futuras llamadas.
                if (!empty($unprocessable)) {
                    $prev = (string) Configuration::get('TPVSYNC_DIVERGENCE_BLOCKLIST');
                    $prevList = $prev !== '' ? array_map('intval', explode(',', $prev)) : [];
                    $merged = array_values(array_unique(array_merge($prevList, $unprocessable)));
                    Configuration::updateValue('TPVSYNC_DIVERGENCE_BLOCKLIST', implode(',', $merged));
                }
                $stats['remaining'] = max(0, count($tpvIds) - count($batchIds));
                $stats['done']     = $stats['remaining'] === 0;
                $stats['unprocessable'] = $unprocessable;
                return $stats;
            }

            if ($action === 'deactivate_tpv_orphans') {
                // PATCH /products/{id} con status=0 — soft delete reversible.
                $batchIds = array_slice($tpvIds, 0, 100);
                $stats = ['deactivated' => 0, 'errors' => 0];
                foreach ($batchIds as $tpvId) {
                    try {
                        $r = $this->api()->patch('/products/' . $tpvId, ['status' => 0]);
                        if (TpvSyncApiClient::fueBien($r)) {   // BUG-A: rama de EXITO
                            $stats['deactivated']++;
                        } else {
                            $stats['errors']++;
                        }
                    } catch (\Throwable $e) {
                        $stats['errors']++;
                        TpvSyncLog::error('divergence', $tpvId,
                            'deactivate_tpv_orphans: ' . $e->getMessage());
                    }
                }
                $stats['remaining'] = max(0, count($tpvIds) - count($batchIds));
                $stats['done']      = $stats['remaining'] === 0;
                return $stats;
            }

            return ['error' => 'unknown'];
        } catch (\Throwable $e) {
            TpvSyncLog::error('divergence', 0, 'processDivergenceAction: ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Pide el conteo de productos del TPV. Endpoint barato para el AJAX
     * del wizard: pintamos la página al instante con "…" y luego este
     * método rellena el número sin bloquear el render del módulo.
     *
     * Devuelve ['count' => int|null, 'error' => string|null].
     */
    public function fetchTpvCount(): array
    {
        try {
            $r = $this->api()->get('/products', ['per_page' => 1, 'count' => 1]);
            if (isset($r['meta']['total']) && $r['meta']['total'] !== null) {
                return ['count' => (int) $r['meta']['total'], 'error' => null];
            }
            if (isset($r['pagination']['total']) && $r['pagination']['total'] !== null) {
                return ['count' => (int) $r['pagination']['total'], 'error' => null];
            }
            $errMsg = $r['errors'][0]['message']
                   ?? $r['error']
                   ?? ('respuesta sin meta.total — keys=' . implode(',', array_keys($r ?: [])));
            TpvSyncLog::write('warn', 'wizard', 0,
                'peek /products: ' . substr((string) $errMsg, 0, 200), 'tpv_count');
            return ['count' => null, 'error' => 'no_total'];
        } catch (\Throwable $e) {
            TpvSyncLog::write('warn', 'wizard', 0,
                'peek /products excepción: ' . substr($e->getMessage(), 0, 200), 'tpv_count');
            return ['count' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Procesa un único lote de PUSH (PS → TPV). Pensado para llamarse desde
     * el endpoint AJAX en bucle hasta `done=true`. Cada llamada es una
     * request HTTP nueva → cada lote tiene su propio max_execution_time
     * fresco, eliminando el riesgo de timeout para catálogos grandes.
     *
     * Devuelve: ['processed', 'total', 'sent', 'skipped', 'errors',
     *            'done' (bool), 'error' (string|null)]
     */
    public function processPushBatch(): array
    {
        try {
            $offset = (int) Configuration::get('TPVSYNC_PUSH_OFFSET');
            // 100 por lote vía endpoint bulk: con ~5ms/producto efectivos
            // (vs 150ms del flujo singular), 100 productos entran en ~500ms
            // de procesamiento server-side. La precarga del catálogo TPV
            // sigue valiendo la pena para los productos con variantes que
            // caen al flujo singular (fallback).
            $batchSize = 100;

            $rows = Db::getInstance()->executeS(
                'SELECT id_product FROM ' . _DB_PREFIX_ . 'product
                 WHERE state = 1
                 ORDER BY id_product ASC
                 LIMIT ' . $batchSize . ' OFFSET ' . $offset
            ) ?: [];

            $sent = 0; $skipped = 0; $errors = 0;
            try {
                $bulkStats = $this->products()->pushProductsBulk(
                    array_map(static fn($r) => (int) $r['id_product'], $rows)
                );
                $sent = (int) ($bulkStats['sent'] ?? 0);
                $errors = (int) ($bulkStats['errors'] ?? 0);
                $skipped = max(0, count($rows) - $sent - $errors);
            } catch (\Throwable $e) {
                // Fallback total a singular si el bulk explota por algo
                // catastrófico (timeout, BD locked, etc). Garantizamos que
                // el lote progresa aunque sea más lento.
                TpvSyncLog::error('bulk', 0, 'pushProductsBulk excepción: ' . $e->getMessage());
                foreach ($rows as $r) {
                    $idProduct = (int) $r['id_product'];
                    try {
                        $ok = $this->products()->pushProductToTpv($idProduct);
                        if ($ok) { $sent++; } else { $skipped++; }
                    } catch (\Throwable $e2) {
                        $errors++;
                        TpvSyncLog::error('product', $idProduct, 'initial_push: ' . $e2->getMessage());
                    }
                }
            }

            $processed = $offset + count($rows);
            $total = (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'product WHERE state = 1'
            );

            // Acumuladores cross-batch para el resumen final del wizard.
            Configuration::updateValue('TPVSYNC_PUSH_SENT_TOTAL',
                ((int) Configuration::get('TPVSYNC_PUSH_SENT_TOTAL')) + $sent);
            Configuration::updateValue('TPVSYNC_PUSH_SKIPPED_TOTAL',
                ((int) Configuration::get('TPVSYNC_PUSH_SKIPPED_TOTAL')) + $skipped);
            Configuration::updateValue('TPVSYNC_PUSH_ERRORS_TOTAL',
                ((int) Configuration::get('TPVSYNC_PUSH_ERRORS_TOTAL')) + $errors);

            $done = count($rows) < $batchSize;
            if ($done) {
                Configuration::updateValue('TPVSYNC_PUSH_OFFSET', 0);
                Configuration::updateValue('TPVSYNC_INITIAL_SYNC_DONE', 1);
                $sentTotal    = (int) Configuration::get('TPVSYNC_PUSH_SENT_TOTAL');
                $skippedTotal = (int) Configuration::get('TPVSYNC_PUSH_SKIPPED_TOTAL');
                $errorsTotal  = (int) Configuration::get('TPVSYNC_PUSH_ERRORS_TOTAL');
                Configuration::updateValue('TPVSYNC_PUSH_SENT_TOTAL', 0);
                Configuration::updateValue('TPVSYNC_PUSH_SKIPPED_TOTAL', 0);
                Configuration::updateValue('TPVSYNC_PUSH_ERRORS_TOTAL', 0);
                return [
                    'processed' => $processed,
                    'total'     => $total,
                    'sent'      => $sentTotal,
                    'skipped'   => $skippedTotal,
                    'errors'    => $errorsTotal,
                    'done'      => true,
                    'error'     => null,
                ];
            }

            Configuration::updateValue('TPVSYNC_PUSH_OFFSET', $offset + $batchSize);
            Configuration::updateValue('TPVSYNC_PUSH_LAST_AT', time());
            return [
                'processed' => $processed,
                'total'     => $total,
                'sent'      => $sent,
                'skipped'   => $skipped,
                'errors'    => $errors,
                'done'      => false,
                'error'     => null,
            ];
        } catch (\Throwable $e) {
            return [
                'processed' => 0,
                'total'     => 0,
                'sent'      => 0,
                'skipped'   => 0,
                'errors'    => 0,
                'done'      => false,
                'error'     => $e->getMessage(),
            ];
        }
    }

    /**
     * Procesa un único lote de PULL (TPV → PS). Equivalente a processPushBatch
     * pero para la dirección inversa. Reutiliza importAll() del módulo de
     * productos, que ya pagina internamente y persiste el offset en
     * TPVSYNC_IMPORT_OFFSET.
     */
    public function processPullBatch(): array
    {
        try {
            $offset = (int) Configuration::get('TPVSYNC_IMPORT_OFFSET');
            // Mismo razonamiento que processPushBatch: 50 cabe en 30s con
            // margen para productos pesados.
            $batchSize = 50;

            $stats = $this->products()->importAll([
                'offset' => $offset,
                'limit'  => $batchSize,
            ]);

            $next      = (int) ($stats['next_offset'] ?? 0);
            $total     = (int) ($stats['total_seen']  ?? 0);
            $created   = (int) ($stats['created']     ?? 0);
            $updated   = (int) ($stats['updated']     ?? 0);
            $errors    = (int) ($stats['errors']      ?? 0);
            $processed = $offset + (int) ($stats['processed'] ?? 0);

            // Acumuladores cross-batch.
            Configuration::updateValue('TPVSYNC_PULL_CREATED_TOTAL',
                ((int) Configuration::get('TPVSYNC_PULL_CREATED_TOTAL')) + $created);
            Configuration::updateValue('TPVSYNC_PULL_UPDATED_TOTAL',
                ((int) Configuration::get('TPVSYNC_PULL_UPDATED_TOTAL')) + $updated);
            Configuration::updateValue('TPVSYNC_PULL_ERRORS_TOTAL',
                ((int) Configuration::get('TPVSYNC_PULL_ERRORS_TOTAL')) + $errors);

            $done = $next === 0;
            if ($done) {
                Configuration::updateValue('TPVSYNC_INITIAL_SYNC_DONE', 1);
                Configuration::updateValue('TPVSYNC_IMPORT_OFFSET', 0);
                $createdTotal = (int) Configuration::get('TPVSYNC_PULL_CREATED_TOTAL');
                $updatedTotal = (int) Configuration::get('TPVSYNC_PULL_UPDATED_TOTAL');
                $errorsTotal  = (int) Configuration::get('TPVSYNC_PULL_ERRORS_TOTAL');
                Configuration::updateValue('TPVSYNC_PULL_CREATED_TOTAL', 0);
                Configuration::updateValue('TPVSYNC_PULL_UPDATED_TOTAL', 0);
                Configuration::updateValue('TPVSYNC_PULL_ERRORS_TOTAL', 0);
                // Limpiar cache de IDs del pull (tras done, ya no hace falta).
                Configuration::deleteByName('TPVSYNC_PULL_IDS_CACHE');
                Configuration::deleteByName('TPVSYNC_PULL_IDS_CACHE_TS');
                return [
                    'processed' => $processed,
                    'total'     => $total,
                    'created'   => $createdTotal,
                    'updated'   => $updatedTotal,
                    'errors'    => $errorsTotal,
                    'done'      => true,
                    'error'     => null,
                ];
            }

            // PERSISTIR el offset para el siguiente batch — sin esto, el
            // siguiente lote vuelve a leer offset=0 y sincroniza una y otra
            // vez los mismos primeros 50 productos. La barra de progreso
            // se queda fija en 50/5185 (~1%) "para siempre" porque cada
            // tick reporta el mismo processed.
            Configuration::updateValue('TPVSYNC_IMPORT_OFFSET', $next);
            Configuration::updateValue('TPVSYNC_PULL_LAST_AT', time());
            return [
                'processed' => $processed,
                'total'     => $total,
                'created'   => $created,
                'updated'   => $updated,
                'errors'    => $errors,
                'done'      => false,
                'error'     => null,
            ];
        } catch (\Throwable $e) {
            return [
                'processed' => 0,
                'total'     => 0,
                'created'   => 0,
                'updated'   => 0,
                'errors'    => 0,
                'done'      => false,
                'error'     => $e->getMessage(),
            ];
        }
    }

    /**
     * Procesa un único lote de RECONCILIACIÓN PS ↔ TPV.
     *
     * Estrategia profesional, ligera y rápida:
     *   1. Snapshot ligero del TPV: en lugar de descargar TODO el catálogo
     *      (53 páginas paginadas con HMAC) lo guardamos en una tabla local
     *      `tpv_sync_reconcile_snapshot` durante el primer lote. Cada lote
     *      siguiente compara contra esa tabla — sin más HTTP al TPV salvo
     *      cuando hay que enviar/recibir un cambio concreto.
     *   2. Snapshot del PS también persiste: 100 productos por lote, offset
     *      en `TPVSYNC_RECONCILE_PS_OFFSET`. Cada producto compara contra
     *      su contraparte TPV (vía model) y aplica la regla "gana el más
     *      reciente". Si no tiene contraparte → push al TPV vía bulk.
     *   3. Tras procesar todos los productos PS, un lote final dedicado a
     *      productos que SOLO existen en TPV (huérfanos en sentido inverso)
     *      → pull a PS via importAll.
     *   4. Limpieza: borramos la tabla snapshot al terminar (flag _DONE).
     *
     * Devuelve ['phase', 'processed', 'total', 'synced', 'fixed', 'errors',
     *           'done', 'error'].
     */
    public function processReconcileBatch(): array
    {
        try {
            $batchSize = 100;
            $phase = (string) (Configuration::get('TPVSYNC_RECONCILE_PHASE') ?: 'snapshot');

            // ── FASE 1: snapshot del TPV en tabla local ────────────────
            // Solo se ejecuta una vez (primer lote). Si el catálogo es muy
            // grande lo paginamos pero TODO en este lote — acepta hasta 60s
            // (set_time_limit en el endpoint). Para 5000 productos son
            // ~25 páginas × 100ms = 2.5s.
            if ($phase === 'snapshot') {
                Db::getInstance()->execute(
                    'CREATE TABLE IF NOT EXISTS ' . _DB_PREFIX_ . 'tpv_sync_reconcile_snapshot (
                        tpv_product_id INT UNSIGNED NOT NULL PRIMARY KEY,
                        model VARCHAR(64) NOT NULL DEFAULT "",
                        sku VARCHAR(64) NOT NULL DEFAULT "",
                        price DECIMAL(15,4) NOT NULL DEFAULT 0,
                        quantity DECIMAL(15,4) NOT NULL DEFAULT 0,
                        date_modified DATETIME NULL,
                        INDEX idx_model (model)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
                );
                Db::getInstance()->execute(
                    'TRUNCATE ' . _DB_PREFIX_ . 'tpv_sync_reconcile_snapshot'
                );

                // Paginación con cursor; campos mínimos para no llenar RAM.
                $cursor = null;
                $totalLoaded = 0;
                $pageGuard = 0;
                do {
                    $params = [
                        'per_page' => 200,
                        'fields'   => 'product_id,model,sku,price,quantity,date_modified',
                    ];
                    if ($cursor !== null) { $params['cursor'] = $cursor; }
                    $r = $this->api()->get('/products', $params);
                    $items = $r['data'] ?? [];
                    if (empty($items)) { break; }

                    // Insert en bulk (1 query por página, multi-VALUES).
                    $values = [];
                    foreach ($items as $it) {
                        $tid = (int) ($it['product_id'] ?? 0);
                        if ($tid === 0) { continue; }
                        $values[] = "(" . (int) $tid
                            . ", '" . pSQL((string) ($it['model'] ?? '')) . "'"
                            . ", '" . pSQL((string) ($it['sku'] ?? '')) . "'"
                            . ", " . (float) ($it['price'] ?? 0)
                            . ", " . (float) ($it['quantity'] ?? 0)
                            . ", " . (!empty($it['date_modified'])
                                ? "'" . pSQL((string) $it['date_modified']) . "'"
                                : 'NULL')
                            . ")";
                    }
                    if (!empty($values)) {
                        // INSERT IGNORE: la paginación por cursor puede
                        // devolver un mismo product_id en dos páginas si el
                        // catálogo se modifica concurrentemente. Sin IGNORE,
                        // 1062 Duplicate aborta toda la reconciliación.
                        Db::getInstance()->execute(
                            'INSERT IGNORE INTO ' . _DB_PREFIX_ . 'tpv_sync_reconcile_snapshot
                             (tpv_product_id, model, sku, price, quantity, date_modified)
                             VALUES ' . implode(',', $values)
                        );
                        $totalLoaded += count($values);
                    }

                    $cursor = $r['meta']['cursor'] ?? null;
                    $pageGuard++;
                } while ($cursor !== null && $pageGuard < 200);

                $totalPs = (int) Db::getInstance()->getValue(
                    'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'product WHERE state = 1'
                );

                Configuration::updateValue('TPVSYNC_RECONCILE_PHASE', 'compare');
                Configuration::updateValue('TPVSYNC_RECONCILE_PS_OFFSET', 0);
                Configuration::updateValue('TPVSYNC_RECONCILE_TPV_TOTAL', $totalLoaded);
                Configuration::updateValue('TPVSYNC_RECONCILE_PS_TOTAL', $totalPs);
                Configuration::updateValue('TPVSYNC_RECONCILE_SYNCED', 0);
                Configuration::updateValue('TPVSYNC_RECONCILE_FIXED', 0);
                Configuration::updateValue('TPVSYNC_RECONCILE_ERRORS', 0);
                Configuration::updateValue('TPVSYNC_RECONCILE_LAST_AT', time());

                return [
                    'phase'     => 'snapshot',
                    'processed' => 0,
                    'total'     => $totalPs,
                    'synced'    => 0,
                    'fixed'     => 0,
                    'errors'    => 0,
                    'done'      => false,
                    'error'     => null,
                    'message'   => 'Snapshot del TPV cargado: ' . $totalLoaded . ' productos.',
                ];
            }

            // ── FASE 2: comparar lotes de PS contra el snapshot ────────
            if ($phase === 'compare') {
                $offset = (int) Configuration::get('TPVSYNC_RECONCILE_PS_OFFSET');
                $totalPs = (int) Configuration::get('TPVSYNC_RECONCILE_PS_TOTAL');

                $rows = Db::getInstance()->executeS(
                    'SELECT id_product, ean13, reference, date_upd
                     FROM ' . _DB_PREFIX_ . 'product
                     WHERE state = 1
                     ORDER BY id_product ASC
                     LIMIT ' . $batchSize . ' OFFSET ' . $offset
                ) ?: [];

                $synced = 0; $fixed = 0; $errors = 0;
                $orphansPush = []; // productos PS sin match → push bulk al final

                // Optimización: pre-cargamos los matches del snapshot con UNA
                // sola query IN para todo el lote (en vez de N queries con
                // model=...). Para 100 productos, eso pasa de 100 queries a 1.
                $allKeys = [];
                foreach ($rows as $row) {
                    $ean = trim((string) ($row['ean13'] ?? ''));
                    $ref = trim((string) ($row['reference'] ?? ''));
                    if ($ean !== '') $allKeys[] = $ean;
                    if ($ref !== '') $allKeys[] = $ref;
                }
                $allKeys = array_values(array_unique($allKeys));
                $matchByModel = [];
                if (!empty($allKeys)) {
                    $escaped = array_map(static fn($k) => '"' . pSQL($k) . '"', $allKeys);
                    $matches = Db::getInstance()->executeS(
                        'SELECT tpv_product_id, model, price, quantity, date_modified
                         FROM ' . _DB_PREFIX_ . 'tpv_sync_reconcile_snapshot
                         WHERE model IN (' . implode(',', $escaped) . ')'
                    ) ?: [];
                    foreach ($matches as $m) {
                        $matchByModel[(string) $m['model']] = $m;
                    }
                }

                // Pre-cargar mapeos existentes para todos los psIds en una query.
                $psIds = array_map(static fn($r) => (int) $r['id_product'], $rows);
                $existingMaps = [];
                if (!empty($psIds)) {
                    $rowsMap = Db::getInstance()->executeS(
                        'SELECT id_product, tpv_product_id FROM ' . _DB_PREFIX_ . 'tpv_sync_product_map
                         WHERE id_product IN (' . implode(',', $psIds) . ')'
                    ) ?: [];
                    foreach ($rowsMap as $m) {
                        $existingMaps[(int) $m['id_product']] = (int) $m['tpv_product_id'];
                    }
                }

                foreach ($rows as $row) {
                    $psId = (int) $row['id_product'];
                    try {
                        $ean = trim((string) ($row['ean13'] ?? ''));
                        $ref = trim((string) ($row['reference'] ?? ''));

                        // Lookup en RAM.
                        $tpvMatch = null;
                        if ($ean !== '' && isset($matchByModel[$ean])) {
                            $tpvMatch = $matchByModel[$ean];
                        } elseif ($ref !== '' && isset($matchByModel[$ref])) {
                            $tpvMatch = $matchByModel[$ref];
                        }

                        if (!$tpvMatch || empty($tpvMatch['tpv_product_id'])) {
                            // Sin match: lo encolamos para push bulk.
                            $orphansPush[] = $psId;
                            continue;
                        }

                        $tpvId = (int) $tpvMatch['tpv_product_id'];
                        // Asegurar mapeo local (sin query si ya está cacheado en $existingMaps).
                        if (($existingMaps[$psId] ?? 0) !== $tpvId) {
                            $this->products()->setMap($psId, $tpvId);
                            $synced++;
                        }

                        // Comparar fechas (gana el más reciente, margen 60s).
                        $tpvUpd = strtotime((string) ($tpvMatch['date_modified'] ?? '')) ?: 0;
                        $psUpd  = strtotime((string) ($row['date_upd'] ?? '')) ?: 0;
                        if ($psUpd > $tpvUpd + 60) {
                            // PS más reciente → push.
                            $orphansPush[] = $psId;
                        } elseif ($tpvUpd > $psUpd + 60) {
                            // TPV más reciente → pull singular.
                            try {
                                $detail = $this->api()->get('/products/' . $tpvId);
                                if (!empty($detail['data'])) {
                                    $this->products()->upsert($detail['data']);
                                    $fixed++;
                                }
                            } catch (\Throwable $e2) {
                                TpvSyncLog::error('reconcile', $tpvId,
                                    'pull singular: ' . $e2->getMessage());
                                $errors++;
                            }
                        }
                    } catch (\Throwable $e) {
                        TpvSyncLog::error('reconcile', $psId, 'compare: ' . $e->getMessage());
                        $errors++;
                    }
                }

                // Push bulk de los huérfanos / pendientes en este lote.
                if (!empty($orphansPush)) {
                    try {
                        $bulkStats = $this->products()->pushProductsBulk($orphansPush);
                        $fixed += (int) ($bulkStats['sent'] ?? 0);
                        $errors += (int) ($bulkStats['errors'] ?? 0);
                    } catch (\Throwable $e) {
                        TpvSyncLog::error('reconcile', 0, 'bulk push: ' . $e->getMessage());
                        $errors += count($orphansPush);
                    }
                }

                // Acumuladores.
                Configuration::updateValue('TPVSYNC_RECONCILE_SYNCED',
                    ((int) Configuration::get('TPVSYNC_RECONCILE_SYNCED')) + $synced);
                Configuration::updateValue('TPVSYNC_RECONCILE_FIXED',
                    ((int) Configuration::get('TPVSYNC_RECONCILE_FIXED')) + $fixed);
                Configuration::updateValue('TPVSYNC_RECONCILE_ERRORS',
                    ((int) Configuration::get('TPVSYNC_RECONCILE_ERRORS')) + $errors);
                Configuration::updateValue('TPVSYNC_RECONCILE_LAST_AT', time());

                $processed = $offset + count($rows);
                $isLast = count($rows) < $batchSize;

                if ($isLast) {
                    Configuration::updateValue('TPVSYNC_RECONCILE_PHASE', 'cleanup');
                    Configuration::updateValue('TPVSYNC_RECONCILE_PS_OFFSET', 0);
                } else {
                    Configuration::updateValue('TPVSYNC_RECONCILE_PS_OFFSET', $offset + $batchSize);
                }

                return [
                    'phase'     => 'compare',
                    'processed' => $processed,
                    'total'     => $totalPs,
                    'synced'    => $synced,
                    'fixed'     => $fixed,
                    'errors'    => $errors,
                    'done'      => false,
                    'error'     => null,
                ];
            }

            // ── FASE 3: cleanup + resumen final ────────────────────────
            if ($phase === 'cleanup') {
                Db::getInstance()->execute(
                    'DROP TABLE IF EXISTS ' . _DB_PREFIX_ . 'tpv_sync_reconcile_snapshot'
                );

                $totals = [
                    'synced' => (int) Configuration::get('TPVSYNC_RECONCILE_SYNCED'),
                    'fixed'  => (int) Configuration::get('TPVSYNC_RECONCILE_FIXED'),
                    'errors' => (int) Configuration::get('TPVSYNC_RECONCILE_ERRORS'),
                ];
                $totalPs = (int) Configuration::get('TPVSYNC_RECONCILE_PS_TOTAL');

                // Limpieza de flags.
                foreach ([
                    'TPVSYNC_RECONCILE_PHASE','TPVSYNC_RECONCILE_PS_OFFSET',
                    'TPVSYNC_RECONCILE_TPV_TOTAL','TPVSYNC_RECONCILE_PS_TOTAL',
                    'TPVSYNC_RECONCILE_SYNCED','TPVSYNC_RECONCILE_FIXED',
                    'TPVSYNC_RECONCILE_ERRORS','TPVSYNC_RECONCILE_LAST_AT',
                    'TPVSYNC_RECONCILE_PENDING',
                ] as $k) {
                    Configuration::deleteByName($k);
                }

                return [
                    'phase'     => 'cleanup',
                    'processed' => $totalPs,
                    'total'     => $totalPs,
                    'synced'    => $totals['synced'],
                    'fixed'     => $totals['fixed'],
                    'errors'    => $totals['errors'],
                    'done'      => true,
                    'error'     => null,
                ];
            }

            return [
                'phase'     => $phase,
                'processed' => 0,
                'total'     => 0,
                'synced'    => 0,
                'fixed'     => 0,
                'errors'    => 0,
                'done'      => false,
                'error'     => 'unknown_phase',
            ];
        } catch (\Throwable $e) {
            TpvSyncLog::error('reconcile', 0, 'processReconcileBatch: ' . $e->getMessage());
            return [
                'phase'     => 'error',
                'processed' => 0,
                'total'     => 0,
                'synced'    => 0,
                'fixed'     => 0,
                'errors'    => 1,
                'done'      => false,
                'error'     => $e->getMessage(),
            ];
        }
    }

    // ─── Hooks ───────────────────────────────────────────────────────────────
    //
    // Todos los handlers respetan:
    //   * guards anti-bucle mediante flags globales ($GLOBALS['tpvsync_skip_*'])
    //     activados cuando el cambio viene del webhook TPV→PS y no debe
    //     re-empujarse al TPV.
    //   * encolado en queue si la llamada falla, para reintentar con backoff.

    public function hookDisplayBackOfficeHeader(array $params): string
    {
        $controller = (string) Tools::getValue('controller');
        $module = (string) Tools::getValue('configure');
        $url = $_SERVER['REQUEST_URI'] ?? '';
        $isOurPage = ($module === $this->name)
            || ($controller === 'AdminModules' && $module === $this->name)
            || strpos($url, '/configure/' . $this->name) !== false;

        // En la página del módulo, cargamos assets completos (CSS + JS del wizard).
        if ($isOurPage) {
            $ctx = Context::getContext();
            if ($ctx && $ctx->controller && method_exists($ctx->controller, 'addCSS')) {
                // Cache-buster: usamos mtime de cada asset como query string para
                // que el navegador del merchant recargue la versión nueva tras un
                // update del módulo. Sin esto, JS/CSS antiguos pueden quedar
                // pegados días enteros — y un modal con bug viejo deja al
                // usuario atrapado sin que el F5 lo resuelva.
                $cssFile = __DIR__ . '/views/css/admin.css';
                $jsFile  = __DIR__ . '/views/js/admin.js';
                $cssVer  = file_exists($cssFile) ? (int) filemtime($cssFile) : self::VERSION;
                $jsVer   = file_exists($jsFile)  ? (int) filemtime($jsFile)  : self::VERSION;
                $ctx->controller->addCSS($this->_path . 'views/css/admin.css?v=' . $cssVer, 'all');
                $ctx->controller->addJS($this->_path . 'views/js/admin.js?v=' . $jsVer);
            }
            return '';
        }

        // Fuera del módulo: solo intervenimos en el editor de productos cuando
        // manda el TPV y el producto está sincronizado. Inyectamos un banner
        // arriba avisando al admin de que ese producto se gestiona desde el
        // TPV, y deshabilitamos los inputs principales (excepto stock).
        if ($this->getPrincipal() !== 'tpv') {
            return '';
        }
        $isProductEditor = ($controller === 'AdminProducts')
            && in_array((string) Tools::getValue('action', ''), ['', 'edit', 'index'], true);
        if (!$isProductEditor) {
            return '';
        }
        $idProduct = (int) Tools::getValue('id_product');
        if ($idProduct <= 0) {
            return '';
        }
        $tpvId = $this->products()->findTpvByPs($idProduct);
        if ($tpvId === 0) {
            return ''; // Producto isla: editable libremente.
        }

        $msg = htmlspecialchars(
            $this->l('Este producto se gestiona desde el TPV. Los cambios que hagas aquí en campos sincronizados (nombre, precio, descripción, estado…) se revertirán al estado del TPV. El stock sí es bidireccional.'),
            ENT_QUOTES
        );

        return <<<HTML
<style id="tpvsync-readonly-style">
  .tpvsync-managed-banner {
    background: #fff5e6; border: 1px solid #ffb84d; color: #663300;
    padding: 12px 16px; border-radius: 6px; margin: 12px 0;
    font-size: 13px; line-height: 1.5;
  }
  .tpvsync-managed-banner strong { color: #b35900; }
  body.tpvsync-product-managed input[name^="name"],
  body.tpvsync-product-managed input[name^="reference"],
  body.tpvsync-product-managed input[name^="ean13"],
  body.tpvsync-product-managed input[name^="upc"],
  body.tpvsync-product-managed input[name^="price"],
  body.tpvsync-product-managed textarea[name^="description"],
  body.tpvsync-product-managed input[name="active"],
  body.tpvsync-product-managed select[name^="id_tax_rules_group"] {
    pointer-events: none; opacity: 0.6; background: #f6f6f6 !important;
  }
</style>
<script>
(function () {
  document.addEventListener('DOMContentLoaded', function () {
    document.body.classList.add('tpvsync-product-managed');
    var banner = document.createElement('div');
    banner.className = 'tpvsync-managed-banner';
    banner.innerHTML = '<strong>⚙ Gestionado por el TPV.</strong> {$msg}';
    var anchor = document.querySelector('.product-page-header, #product, form#product_form, .form-wrapper');
    if (anchor && anchor.parentNode) {
      anchor.parentNode.insertBefore(banner, anchor);
    } else {
      document.body.insertBefore(banner, document.body.firstChild);
    }
  });
})();
</script>
HTML;
    }

    /**
     * Quién es la fuente de verdad ('tpv', 'ps' o '' = sin decidir).
     * Se establece en el wizard inicial. Mientras esté vacío, los hooks
     * mantienen el comportamiento legacy ('ps' manda — todo se empuja al TPV)
     * para no romper instalaciones antiguas que actualicen sin pasar por el
     * wizard.
     */
    private function getPrincipal(): string
    {
        $p = (string) Configuration::get('TPVSYNC_PRINCIPAL');
        return in_array($p, ['tpv', 'ps'], true) ? $p : '';
    }

    /**
     * Helper: aplica las reglas del modo "Principal" cuando el TPV manda.
     *
     * Si el producto PS está mapeado (sincronizado), un cambio iniciado en PS
     * sobre campos no-stock debe revertirse: pedimos el estado actual al TPV
     * y lo aplicamos sobre el producto PS (sobreescribe lo que el usuario
     * acababa de tocar). El stock NO entra aquí — bidireccional siempre.
     *
     * Si el producto PS NO está mapeado: es una "isla". El cambio se acepta
     * localmente y NO se propaga al TPV. El TPV no debe enterarse de cambios
     * en productos que no son suyos.
     *
     * Devuelve true si la regla aplicó (caller debe abortar el push normal).
     */
    private function applyPrincipalRulesOnPsProductChange(int $idProduct): bool
    {
        $principal = $this->getPrincipal();
        if ($principal !== 'tpv') {
            return false; // Modo legacy o "ps manda": no aplicamos reglas, push normal.
        }
        if ($idProduct <= 0) {
            return true;
        }
        $tpvId = $this->products()->findTpvByPs($idProduct);
        if ($tpvId === 0) {
            // Isla en PS: el cambio se queda local, no se propaga.
            return true;
        }
        // Producto sincronizado: el TPV manda. Revertimos el cambio
        // pidiendo el estado autoritativo al TPV. Activamos el guard
        // tpvsync_skip_product_push para que el upsert resultante no
        // re-empuje al TPV (sería bucle).
        $GLOBALS['tpvsync_skip_product_push'] = true;
        try {
            $this->products()->updateFromTpv($tpvId);
        } finally {
            $GLOBALS['tpvsync_skip_product_push'] = false;
        }
        return true;
    }

    /**
     * Helper: empuja un producto al TPV una sola vez por request HTTP aunque
     * varios hooks coincidan (actionProductSave + actionProductAdd + actionProductUpdate).
     */
    private function pushProductOnce(int $idProduct): void
    {
        if ($idProduct <= 0) {
            return;
        }
        if (!Configuration::get('TPVSYNC_MODULE_CATALOG')) {
            return;
        }
        if (!empty($GLOBALS['tpvsync_skip_product_push'])) {
            return;
        }
        if (!empty($GLOBALS['tpvsync_pushed_in_request'][$idProduct])) {
            return;
        }
        // Modo Principal: si manda el TPV, los cambios sobre productos
        // sincronizados se revierten y los cambios sobre islas no se propagan.
        if ($this->applyPrincipalRulesOnPsProductChange($idProduct)) {
            $GLOBALS['tpvsync_pushed_in_request'][$idProduct] = true;
            return;
        }
        $GLOBALS['tpvsync_pushed_in_request'][$idProduct] = true;
        $this->products()->pushProductToTpv($idProduct);
    }

    public function hookActionProductSave(array $params): void
    {
        $id = (int) ($params['id_product'] ?? 0);
        if ($id === 0 && !empty($params['product']) && $params['product'] instanceof Product) {
            $id = (int) $params['product']->id;
        }
        $this->pushProductOnce($id);
    }

    public function hookActionProductAdd(array $params): void
    {
        $product = $params['product'] ?? null;
        if ($product instanceof Product) {
            $this->pushProductOnce((int) $product->id);
        }
    }

    public function hookActionProductUpdate(array $params): void
    {
        $product = $params['product'] ?? null;
        if ($product instanceof Product) {
            $this->pushProductOnce((int) $product->id);
        }
    }

    public function hookActionProductDelete(array $params): void
    {
        if (!Configuration::get('TPVSYNC_MODULE_CATALOG')) {
            return;
        }
        if (!empty($GLOBALS['tpvsync_skip_product_push'])) {
            return;
        }
        $psId = (int) ($params['id_product'] ?? 0);
        if ($psId === 0) {
            return;
        }
        // Modo Principal: si manda el TPV, un delete en PS NO debe propagarse.
        // - Si el producto era una isla, su borrado en PS es asunto local.
        // - Si era sincronizado, el delete en PS es accidental: el TPV sigue
        //   teniendo el producto y un reconcile posterior lo recreará en PS.
        //   Idealmente la UI no permitirá llegar aquí (banner read-only +
        //   bloqueo de delete bulk), pero como cinturón no propagamos.
        if ($this->getPrincipal() === 'tpv') {
            return;
        }
        $this->products()->deleteProductInTpv($psId);
    }

    /**
     * Hook: actionUpdateQuantity.
     * Params típicos: ['id_product'=>N, 'id_product_attribute'=>N, 'quantity'=>N,
     *                  'delta_quantity'=>N] (el delta lo añadió PS 1.7.7+).
     *
     * Este hook dispara en *cualquier* cambio de stock de PS: admin, imports,
     * ventas (cuando se valida un pedido). Para evitar doble descuento cuando
     * la venta también se manda al TPV como pedido, miramos el flag
     * $GLOBALS['tpvsync_skip_stock_push'] que activa:
     *   - El webhook handler TPV→PS (el TPV ya sabe el valor, no reenviar).
     *   - El flow de pedido cuando se crea el pedido en el TPV (el TPV
     *     descuenta al registrar el pedido).
     */
    public function hookActionUpdateQuantity(array $params): void
    {
        if (!Configuration::get('TPVSYNC_MODULE_CATALOG')) {
            return;
        }
        if (!empty($GLOBALS['tpvsync_skip_stock_push'])) {
            return;
        }
        $psId = (int) ($params['id_product'] ?? 0);
        $psAttrId = (int) ($params['id_product_attribute'] ?? 0);
        $qty = (int) ($params['quantity'] ?? 0);
        if ($psId === 0) {
            return;
        }
        $this->products()->pushStockChange($psId, $psAttrId, $qty);
    }

    /**
     * Hook: actionValidateOrder — se dispara al confirmar el pago del pedido
     * en el frontend (PS llama al módulo de pago y este valida). Equivale al
     * woocommerce_payment_complete de WC.
     */
    /**
     * Hook: actionValidateOrderBefore — se dispara ANTES de que PS empiece a
     * decrementar stock por el pedido. Activamos el flag anti-push para que
     * los actionUpdateQuantity disparados durante la validación NO empujen
     * stock al TPV (sería doble descuento: uno por el stock + otro por el
     * POST /orders products[]).
     *
     * El flag se limpia en hookActionValidateOrder (que se dispara después).
     */
    public function hookActionValidateOrderBefore(array $params): void
    {
        if (!Configuration::get('TPVSYNC_MODULE_ORDERS')) {
            return;
        }
        $GLOBALS['tpvsync_skip_stock_push'] = true;
    }

    public function hookActionValidateOrder(array $params): void
    {
        $order = $params['order'] ?? null;
        if (!$order instanceof Order) {
            $GLOBALS['tpvsync_skip_stock_push'] = false;
            return;
        }
        // Primero enviamos el pedido (TPV descuenta stock al crear el order)
        $this->orders()->sendToTpv((int) $order->id);
        // Liberamos el flag: cambios posteriores de stock (edición manual, no
        // ligados a la venta) sí se propagan con normalidad.
        $GLOBALS['tpvsync_skip_stock_push'] = false;
    }

    public function hookActionOrderStatusPostUpdate(array $params): void
    {
        $idOrder = (int) ($params['id_order'] ?? 0);
        $newStatus = $params['newOrderStatus'] ?? null;
        if ($idOrder === 0 || !$newStatus instanceof OrderState) {
            return;
        }
        $this->orders()->onPsStatusChanged($idOrder, (int) $newStatus->id);
    }

    /**
     * Hook: actionObjectOrderSlipAddAfter — creación de una "nota de crédito"
     * en PS (el equivalente a un refund/devolución). OrderSlip contiene las
     * líneas devueltas y el importe.
     */
    public function hookActionObjectOrderSlipAddAfter(array $params): void
    {
        $slip = $params['object'] ?? null;
        if (!$slip instanceof OrderSlip) {
            return;
        }
        if (!empty($GLOBALS['tpvsync_skip_refund_push'])) {
            return;
        }
        // BUG-D: onPsRefund ya no reencola por su cuenta (lo hacia y ademas la cola
        // reintentaba, duplicando la entrada). Reencolar es de quien llama, y este
        // hook es el unico camino que no pasa por la cola: si falla aqui y nadie
        // encola, la devolucion se pierde en silencio.
        if (!$this->orders()->onPsRefund((int) $slip->id_order, (int) $slip->id)) {
            $this->queue()->enqueue('refund.send', [
                'id_order' => (int) $slip->id_order,
                'id_order_slip' => (int) $slip->id,
            ], 'refund push failed');
        }
    }

    // ─── Hooks de Customer ──────────────────────────────────────────────────

    public function hookActionObjectCustomerAddAfter(array $params): void
    {
        $customer = $params['object'] ?? null;
        if (!($customer instanceof Customer)) return;
        $this->customers()->pushPsCustomerToTpv((int) $customer->id);
    }

    public function hookActionObjectCustomerUpdateAfter(array $params): void
    {
        $customer = $params['object'] ?? null;
        if (!($customer instanceof Customer)) return;
        $this->customers()->pushPsCustomerToTpv((int) $customer->id);
    }

    public function hookActionObjectCustomerDeleteBefore(array $params): void
    {
        $customer = $params['object'] ?? null;
        if (!($customer instanceof Customer)) return;
        $this->customers()->pushPsCustomerDeleteToTpv((int) $customer->id);
    }

    /**
     * En PS las direcciones viven en su propia tabla (Address). Cuando un
     * cliente añade/edita su dirección desde "Mi cuenta", el customer en sí
     * no cambia, así que actionObjectCustomerUpdateAfter NO dispara. Esto
     * captura el cambio y re-empuja el customer entero (con el address
     * fresco) al TPV.
     */
    public function hookActionObjectAddressAddAfter(array $params): void
    {
        $address = $params['object'] ?? null;
        if (!($address instanceof Address)) return;
        if ((int) $address->id_customer === 0) return;
        $this->customers()->pushPsCustomerToTpv((int) $address->id_customer);
    }

    public function hookActionObjectAddressUpdateAfter(array $params): void
    {
        $address = $params['object'] ?? null;
        if (!($address instanceof Address)) return;
        if ((int) $address->id_customer === 0) return;
        $this->customers()->pushPsCustomerToTpv((int) $address->id_customer);
    }

    /**
     * Lazy-loader del servicio de customers. Reutiliza la instancia entre
     * varios hooks del mismo request.
     */
    public function customers(): TpvSyncCustomer
    {
        static $instance = null;
        if ($instance === null) {
            require_once _PS_MODULE_DIR_ . 'tpvsync/classes/TpvSyncCustomer.php';
            $instance = new TpvSyncCustomer($this->api());
        }
        return $instance;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AJAX handlers (mapeo de impuestos, bulk customers, ...)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * GET-like AJAX: devuelve clases de impuestos PS, clases TPV (vía API), y
     * el mapeo guardado actualmente en `palyp_tpv_sync_tax_map`.
     *
     * Estructura:
     *   {
     *     "ps_classes": [{"id_tax_rules_group": 1, "name": "IVA 21%"}, ...],
     *     "tpv_classes": [{"tax_class_id": 13, "title": "Tipo general 21%"}, ...],
     *     "mapping": {"1": 13, "2": 12, ...}
     *   }
     */
    private function ajaxTaxMapLoad(): array
    {
        // PS tax_rules_groups locales
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $rows = Db::getInstance()->executeS(
            'SELECT trg.id_tax_rules_group, trg.name
             FROM ' . _DB_PREFIX_ . 'tax_rules_group trg
             WHERE trg.active = 1 AND trg.deleted = 0
             ORDER BY trg.name ASC'
        ) ?: [];
        $psClasses = array_map(function ($r) {
            return [
                'id_tax_rules_group' => (int) $r['id_tax_rules_group'],
                'name'               => (string) $r['name'],
            ];
        }, $rows);

        // Clases TPV vía API
        $tpvClasses = [];
        try {
            $r = $this->api()->get('/tax-classes');
            if (!empty($r['data']) && is_array($r['data'])) {
                foreach ($r['data'] as $cls) {
                    if ((int) ($cls['tax_class_id'] ?? 0) <= 0) continue;
                    $tpvClasses[] = [
                        'tax_class_id' => (int) $cls['tax_class_id'],
                        'title'        => (string) ($cls['title'] ?? ''),
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Si TPV está caído, devolvemos array vacío y el JS muestra
            // mensaje accionable. NO bloqueamos el panel.
        }

        // Mapping actual
        $mapping = [];
        $rowsMap = Db::getInstance()->executeS(
            'SELECT id_tax_rules_group, tpv_tax_class_id FROM ' . _DB_PREFIX_ . 'tpv_sync_tax_map'
        ) ?: [];
        foreach ($rowsMap as $row) {
            $mapping[(int) $row['id_tax_rules_group']] = (int) $row['tpv_tax_class_id'];
        }

        return [
            'ps_classes'   => $psClasses,
            'tpv_classes'  => $tpvClasses,
            'mapping'      => $mapping,
            'default_id'   => (int) Configuration::get('TPVSYNC_DEFAULT_TAX_CLASS_ID'),
        ];
    }

    /**
     * POST AJAX: persiste el mapeo. Espera un JSON `mapping` con
     * `id_tax_rules_group → tpv_tax_class_id`.
     *
     *   {"mapping": {"1": 13, "2": 12}, "default_id": 13}
     *
     * Reemplaza por completo: filas no enviadas se eliminan. Idempotente.
     */
    private function ajaxTaxMapSave(): array
    {
        $rawJson = (string) Tools::getValue('mapping_json', '');
        $defaultId = (int) Tools::getValue('default_id', 0);

        $mapping = [];
        if ($rawJson !== '') {
            $decoded = json_decode($rawJson, true);
            if (is_array($decoded)) {
                foreach ($decoded as $k => $v) {
                    $rid = (int) $k; $cid = (int) $v;
                    if ($rid > 0 && $cid > 0) $mapping[$rid] = $cid;
                }
            }
        }

        // Reemplazar todo el mapeo en una transacción lógica
        $db = Db::getInstance();
        $db->execute('DELETE FROM ' . _DB_PREFIX_ . 'tpv_sync_tax_map');
        foreach ($mapping as $rid => $cid) {
            $db->insert('tpv_sync_tax_map', [
                'id_tax_rules_group' => (int) $rid,
                'tpv_tax_class_id'   => (int) $cid,
                'updated_at'         => date('Y-m-d H:i:s'),
            ]);
        }

        Configuration::updateValue('TPVSYNC_DEFAULT_TAX_CLASS_ID', $defaultId);
        TpvSyncProduct::clearTaxMapCache();

        return ['ok' => true, 'count' => count($mapping), 'default_id' => $defaultId];
    }

    /**
     * POST AJAX: dispara el bulk push de customers PS → TPV. Devuelve estado
     * del job (sent / errors). Procesado lote a lote para no agotar PHP
     * max_execution_time.
     */
    private function ajaxBulkCustomers(): array
    {
        require_once _PS_MODULE_DIR_ . 'tpvsync/classes/TpvSyncCustomer.php';
        $cust = new TpvSyncCustomer($this->api());
        $stats = $cust->pushAllPsCustomers();
        return ['ok' => true, 'stats' => $stats];
    }
}
