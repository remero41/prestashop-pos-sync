# `TpvSyncOrder::onPsRefund()` — los 7 `return;` antes de cambiar la firma

Tabla levantada ANTES de tocar nada (Task 6 Step 1 del plan industrial rev.2). Existe
porque `void → bool` tiene una trampa: **un `return;` que se olvide devuelve `null`, que
es falsy**, y la cola lo leeria como fallo permanente — reintentando para siempre una
devolucion que si se aplico.

Fichero: `tpvsync/classes/TpvSyncOrder.php`, funcion desde la linea 414.

| # | Línea | Condición que lo alcanza | ¿Éxito o fallo? | Devuelve |
|---|---|---|---|---|
| 1 | 417 | `$GLOBALS['tpvsync_skip_refund_push']` — la devolucion viene DEL TPV, no hay que devolversela | **Éxito** (omisión deliberada) | `true` |
| 2 | 422 | El pedido de PS no tiene mapeo con ningun pedido del TPV | **Éxito terminal** — logueado como `skip` | `true` |
| 3 | 426 | El `OrderSlip` no carga (`Validate::isLoadedObject`) — id inexistente o corrupto | **Éxito terminal** — reintentar no lo arregla | `true` |
| 4 | 438 | El slip no tiene lineas (`!$rows`) | **Éxito terminal** — no hay nada que propagar | `true` |
| 5 | 486 | *(fuera de `onPsRefund`: es `updatePsStatus`)* | — | no se toca |
| 6 | 490 | *(idem `updatePsStatus`)* | — | no se toca |
| 7 | 494 | *(idem `updatePsStatus`)* | — | no se toca |

## Corrección: los `return;` NO son 7 en esta función, son 4

El plan cuenta 7 con `awk 'NR>=414 && NR<=520'`, pero **ese rango se pasa del final de la
función**: `onPsRefund()` acaba en la linea 476. Los tres ultimos (486, 490, 494) pertenecen
a `updatePsStatus()`, que es otra funcion, sigue siendo `void` y **no se toca** en este fix.

Dentro de `onPsRefund()` hay exactamente **4** `return;`, y los cuatro son de exito
terminal. Esto hace el cambio bastante mas seguro de lo que el plan temia — pero la tabla
sigue siendo necesaria, porque el riesgo del `null` es real para esos 4.

## Los dos caminos de salida por el final (sin `return` explícito)

| Camino | Línea | Condición | Devuelve |
|---|---|---|---|
| A | 468-472 | `$errors > 0`: alguna linea fallo ⇒ **ya reencola** `refund.send` | `false` |
| B | 473-475 | `$errors === 0`: todas las lineas propagadas | `true` |

## ⚠️ Trampa del camino A que el plan no menciona: doble reencolado

El camino A **ya llama a `enqueue('refund.send', ...)` por su cuenta**. Si ademas devuelve
`false`, `TpvSyncQueue::execute()` marcara la entrada como fallida y la reintentara — con
lo que la MISMA devolucion queda encolada dos veces.

Devolver `false` sigue siendo lo correcto (es un fallo real y la cola debe saberlo), pero
**el `enqueue()` interno tiene que desaparecer cuando la llamada viene de la cola**, o se
duplica. Se resuelve en el propio fix: `onPsRefund()` deja de reencolar y se limita a
informar; reencolar es responsabilidad de quien gestiona la cola.

El otro llamador (`tpvsync.php:3559`, el hook de PrestaShop) **ignora el retorno**, asi que
para el `void → bool` es retrocompatible — pero es quien pierde el reencolado automatico.
Por eso el `enqueue` no se borra: se mueve a ese llamador.
