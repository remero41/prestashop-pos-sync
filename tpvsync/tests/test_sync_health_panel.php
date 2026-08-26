<?php
declare(strict_types=1);
/**
 * F4 — panel de estado de sincronizacion: los semaforos.
 *
 * Mismos umbrales que el conector de WooCommerce a proposito: es el mismo
 * estandar y el mismo comerciante. Si divergen, el que atienda a las dos
 * tiendas tiene que aprender dos idiomas.
 *
 * Inventario previo (regla del plan: no afirmar ausencia sin contarla).
 * PrestaShop partia POR DELANTE de Woo:
 *   - cola     : ya tenia semaforo con los umbrales del estandar
 *                (pending > 50 => warn, abandoned > 0 => err), en el panel
 *                de 8 checks de getHealthChecks().
 *   - breaker  : ya se mostraba, con open/half_open/closed.
 *   - secrets, mappings huerfanos, tax mapping: tambien.
 *   - marca de ultima sync correcta : NO EXISTIA. La misma señal que
 *                faltaba en Woo, y la que el estandar pone primero.
 *
 * Diferencia con Woo que importa para el criterio: aqui la columna status
 * NO es texto libre. TpvSyncLog expone 4 metodos nombrados (ok/error/skip/
 * warn) y el barrido confirma que solo se usan esos cuatro. Aun asi el
 * criterio va por la lista de FALLOS, igual que en Woo: sobrevive a que
 * alguien añada un estado sano nuevo mañana.
 */

require_once dirname(__DIR__) . '/classes/TpvSyncHealth.php';

function run_sync_health_panel_tests(PrestaTestRunner $t)
{
    $t->suite('F4 — semaforo de la marca de ultima sincronizacion');

    $t->test('sync hace 1 minuto => ok', function ($t) {
        $t->assertEquals('ok', TpvSyncHealth::freshnessLevel(60));
    });
    $t->test('sync hace 14 minutos => ok (justo dentro)', function ($t) {
        $t->assertEquals('ok', TpvSyncHealth::freshnessLevel(14 * 60));
    });
    $t->test('sync hace 15 minutos => warn (el borde cuenta como fuera)', function ($t) {
        $t->assertEquals('warn', TpvSyncHealth::freshnessLevel(15 * 60));
    });
    $t->test('sync hace 1 hora => err', function ($t) {
        $t->assertEquals('err', TpvSyncHealth::freshnessLevel(3600));
    });
    $t->test('sin marca (null) => err, no una fecha absurda', function ($t) {
        $t->assertEquals('err', TpvSyncHealth::freshnessLevel(null));
    });

    $t->suite('F4 — semaforo de la cola');

    $t->test('cola vacia => ok', function ($t) {
        $t->assertEquals('ok', TpvSyncHealth::queueLevel(0, 0));
    });
    $t->test('49 pendientes => ok', function ($t) {
        $t->assertEquals('ok', TpvSyncHealth::queueLevel(49, 0));
    });
    $t->test('50 pendientes => warn (el umbral del estandar)', function ($t) {
        $t->assertEquals('warn', TpvSyncHealth::queueLevel(50, 0));
    });
    $t->test('1 abandonada con cola vacia => err', function ($t) {
        $t->assertEquals('err', TpvSyncHealth::queueLevel(0, 1));
    });
    $t->test('abandonadas mandan sobre pendientes', function ($t) {
        $t->assertEquals('err', TpvSyncHealth::queueLevel(500, 3));
    });

    $t->suite('F4 — el diagnostico combinado del estandar');

    $t->test('marca fresca + cola creciendo => la cola desborda al ejecutor', function ($t) {
        $d = TpvSyncHealth::diagnose(60, 300, 0);
        $t->assertEquals('backlog', $d['kind']);
    });
    $t->test('marca obsoleta => la sincronizacion esta rota', function ($t) {
        $d = TpvSyncHealth::diagnose(7200, 0, 0);
        $t->assertEquals('stalled', $d['kind']);
    });
    $t->test('marca fresca + cola vacia => sano', function ($t) {
        $d = TpvSyncHealth::diagnose(60, 0, 0);
        $t->assertEquals('healthy', $d['kind']);
    });
    $t->test('marca obsoleta Y cola creciendo => manda "rota"', function ($t) {
        $d = TpvSyncHealth::diagnose(7200, 300, 0);
        $t->assertEquals('stalled', $d['kind']);
    });
    $t->test('abandonadas => pide intervencion aunque lo demas este verde', function ($t) {
        $d = TpvSyncHealth::diagnose(60, 0, 2);
        $t->assertEquals('dropped', $d['kind']);
    });

    $t->suite('F4 — paridad de umbrales con el conector de WooCommerce');

    // Los dos plugins atienden al mismo comerciante contra el mismo TPV. Si
    // un panel dice ambar a los 50 pendientes y el otro a los 200, el que
    // lleva las dos tiendas no puede comparar. Se fija aqui.
    $t->test('los umbrales son los mismos que en el conector de Woo', function ($t) {
        $t->assertEquals(900,  TpvSyncHealth::FRESH_OK_SEC,   'verde por debajo de 15 min');
        $t->assertEquals(3600, TpvSyncHealth::FRESH_WARN_SEC, 'ambar por debajo de 1 h');
        $t->assertEquals(50,   TpvSyncHealth::QUEUE_WARN,     'ambar a partir de 50 pendientes');
    });

    $t->suite('F4 — el SQL que ejecuta contra PrestaShop');

    // Db::getRow() del core CONCATENA su propio ' LIMIT 1' (classes/db/Db.php
    // :650). Llevarlo ya escrito produce "LIMIT 1 LIMIT 1" y un error 1064 que
    // tumba TODO el health check con un 500 — la seccion Diagnostico del panel
    // se queda en "No se pudo obtener el diagnostico".
    //
    // Este test existe porque los tests de umbrales NO cazaron esto: probaban
    // la logica pura y nunca el SQL. Cazado mirando la pantalla con Playwright.
    $t->test('lastError() no escribe LIMIT: getRow ya lo añade', function ($t) {
        $src = (string) file_get_contents(dirname(__DIR__) . '/classes/TpvSyncHealth.php');
        preg_match('/function lastError\(\)(.*?)\n    }/s', $src, $m);
        $t->assert(!empty($m[1]), 'no se pudo aislar lastError()');
        // Quitar comentarios antes de buscar: el propio aviso que explica el
        // bug menciona LIMIT, y un stripos a secas se daba por satisfecho con
        // el comentario (mismo falso positivo que ya se colo dos veces).
        $cuerpo = preg_replace('~//[^\n]*~', '', $m[1]);
        $t->assert(stripos($cuerpo, 'LIMIT') === false,
            'lastError() usa getRow(), que ya concatena LIMIT 1: escribirlo ' .
            'produce "LIMIT 1 LIMIT 1" y un 1064 que tumba el health check');
    });

    // getValue() tambien lo añade (getRow por debajo), asi que la misma regla.
    $t->test('lastOkAge() no escribe LIMIT: getValue tambien lo añade', function ($t) {
        $src = (string) file_get_contents(dirname(__DIR__) . '/classes/TpvSyncHealth.php');
        preg_match('/function lastOkAge\(\)(.*?)\n    }/s', $src, $m);
        $t->assert(!empty($m[1]), 'no se pudo aislar lastOkAge()');
        $cuerpo = preg_replace('~//[^\n]*~', '', $m[1]);
        $t->assert(stripos($cuerpo, 'LIMIT') === false,
            'getValue() delega en getRow(), que ya concatena LIMIT 1');
    });

    $t->suite('F4 — el ultimo error se lee, no se vuelca');

    // Visto en pantalla con Playwright: el mensaje guardado incluye el cuerpo
    // problem+json entero, con las barras escapadas por json_encode. En el
    // panel salia asi, ocupando tres lineas:
    //
    //   Refund slip 1 prod 88888888: {"type":"https:\/\/catinfog.com\/api\/v1
    //   \/errors\/not_found","title":"Recurso no encontrado.","status":404,...
    //
    // Lo unico accionable ("Recurso no encontrado", 404) queda enterrado. El
    // panel existe para diagnosticar sin abrir ticket: un volcado JSON es lo
    // contrario de eso.
    $t->test('un problem+json se resume a title + status', function ($t) {
        $raw = 'Refund slip 1 prod 88888888: {"type":"https:\/\/catinfog.com\/api\/v1\/errors'
             . '\/not_found","title":"Recurso no encontrado.","status":404,"detail":"Recurso no'
             . ' encontrado.","instance":"\/api\/v1\/orders\/99999999\/returns"}';
        $out = TpvSyncHealth::humanizeError($raw);

        $t->assert(strpos($out, 'Recurso no encontrado') !== false,
            'debe conservar el title, que es lo unico legible');
        $t->assert(strpos($out, '404') !== false, 'debe conservar el status');
        $t->assert(strpos($out, 'Refund slip 1 prod 88888888') !== false,
            'debe conservar el contexto de quien fallo');
        $t->assert(strpos($out, '{"type"') === false, 'no debe quedar JSON crudo');
        $t->assert(strpos($out, '\\/') === false, 'no deben quedar barras escapadas');
    });

    $t->test('un mensaje normal se deja intacto', function ($t) {
        $raw = 'Timeout al contactar con el TPV tras 3 intentos';
        $t->assertEquals($raw, TpvSyncHealth::humanizeError($raw));
    });

    $t->test('un JSON sin title cae de pie: no revienta ni vacia el mensaje', function ($t) {
        $raw = 'algo raro: {"foo":"bar"}';
        $out = TpvSyncHealth::humanizeError($raw);
        $t->assert($out !== '', 'nunca debe devolver vacio');
        $t->assert(strpos($out, 'algo raro') !== false, 'conserva el prefijo');
    });

    $t->test('un mensaje larguisimo se recorta', function ($t) {
        $out = TpvSyncHealth::humanizeError(str_repeat('x', 500));
        // Medir en CARACTERES, no en bytes: el caracter de recorte es '…',
        // que ocupa 3 bytes en UTF-8. Con strlen() el limite de 200 se leia
        // como 202 y el test fallaba por una diferencia que no existe.
        $t->assert(mb_strlen($out, 'UTF-8') <= 200, 'longitud: ' . mb_strlen($out, 'UTF-8'));
        $t->assert(mb_substr($out, -1, 1, 'UTF-8') === '…', 'debe marcar el recorte');
    });

    $t->suite('F4 — que cuenta como sincronizacion correcta');

    $t->test('la marca se define por los fallos, no por status=ok', function ($t) {
        $src = (string) file_get_contents(dirname(__DIR__) . '/classes/TpvSyncHealth.php');

        $t->assert(strpos($src, 'FAILURE_STATUSES') !== false,
            'debe existir la lista cerrada de estados de fallo');
        $t->assert(strpos($src, "status = 'ok'") === false,
            "no filtrar por status = 'ok': deja fuera cualquier estado sano futuro");

        // Mirar SOLO la constante: el comentario de encima enumera los
        // estados para explicar el porque, y un strpos sobre todo el fuente
        // los encontraba ahi (falso positivo que ya se colo una vez).
        preg_match('/FAILURE_STATUSES\s*=\s*\[(.*?)\];/s', $src, $m);
        $t->assert(!empty($m[1]), 'no se pudo leer la constante FAILURE_STATUSES');
        $lista = $m[1];

        foreach (['skip', 'warn'] as $sano) {
            $t->assert(strpos($lista, "'" . $sano . "'") === false,
                "'$sano' NO es un fallo: skip es una decision, warn es un aviso");
        }
        $t->assert(strpos($lista, "'error'") !== false, "'error' debe contar como fallo");
    });
}
