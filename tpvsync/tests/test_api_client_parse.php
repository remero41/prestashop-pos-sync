<?php
/**
 * BUG-A — el cliente decidia el exito sin mirar el status HTTP.
 *
 * Reproduce el bug sin PrestaShop y sin red. La cadena, verificada eslabon a
 * eslabon en la auditoria:
 *   1. api/v1/classes/Response.php:247-262 (problemJson) emite
 *      type/title/status/detail — SIN clave 'errors'.
 *   2. Este cliente NEGOCIA ese formato: 'Accept: application/problem+json'
 *      (TpvSyncApiClient.php:489).
 *   3. Siete puntos del modulo concluian exito con empty(errors) && empty(error).
 * ⇒ un 4xx en problem+json se marcaba como aplicado.
 *
 * decide() y fueBien() son static a proposito: se prueban sin construir el
 * cliente (el constructor necesita Configuration y TpvSyncSecrets).
 */

require_once dirname(__DIR__) . '/classes/TpvSyncApiClient.php';

function run_api_client_parse_tests(PrestaTestRunner $t)
{
    $t->suite('BUG-A — API client: el status manda');

    $problem404 = [
        'type' => 'https://api.tpv/errors/not_found',
        'title' => 'Not Found',
        'status' => 404,
        'detail' => 'product 99999999 no existe',
        'request_id' => 'c1d214f17a7a4558',
    ];

    $t->test('el cuerpo de un problem+json NO trae la clave errors (la premisa del bug)', function ($t) use ($problem404) {
        $t->assert(empty($problem404['errors']) && empty($problem404['error']),
            'Si este aserto cayera, BUG-A no existiria: el bug es justo que la clave no esta');
    });

    $t->test('404 problem+json ⇒ _ok=false y _status=404', function ($t) use ($problem404) {
        $r = TpvSyncApiClient::decide(404, $problem404);
        $t->assert(isset($r['_status']) && $r['_status'] === 404, '_status debe propagarse');
        $t->assert(isset($r['_ok']) && $r['_ok'] === false, '_ok debe ser false en 4xx');
        $t->assert(TpvSyncApiClient::fueBien($r) === false,
            'fueBien() debe decir NO ante un 404, aunque el cuerpo no traiga errors');
    });

    $t->test('502 de proxy (cuerpo HTML ⇒ json_decode da []) ⇒ fallo', function ($t) {
        $r = TpvSyncApiClient::decide(502, []);
        $t->assert(TpvSyncApiClient::fueBien($r) === false,
            'Un cuerpo vacio con status 502 es un fallo, no un exito silencioso');
    });

    $t->test('429 tras agotar reintentos ⇒ fallo', function ($t) {
        $r = TpvSyncApiClient::decide(429, ['title' => 'Too Many Requests', 'status' => 429]);
        $t->assert(TpvSyncApiClient::fueBien($r) === false, 'Un 429 no es un exito');
    });

    $t->test('207 parcial es DISTINGUIBLE de un 200 (BUG-B de rebote)', function ($t) {
        $r207 = TpvSyncApiClient::decide(207, ['results' => [['ok' => true], ['error' => 'x']]]);
        $r200 = TpvSyncApiClient::decide(200, ['results' => [['ok' => true], ['ok' => true]]]);
        $t->assert($r207['_status'] === 207 && $r200['_status'] === 200,
            'Sin _status ambos eran el mismo array y el 207 era indistinguible del 200');
    });

    $t->test('200 normal ⇒ exito y el payload intacto', function ($t) {
        $r = TpvSyncApiClient::decide(200, ['data' => ['product_id' => 42]]);
        $t->assert(TpvSyncApiClient::fueBien($r) === true, 'Un 200 con data es exito');
        $t->assert(isset($r['data']['product_id']) && $r['data']['product_id'] === 42,
            'decide() no debe pisar el payload: el guion bajo existe para eso');
    });

    $t->suite('BUG-A — fallback por cuerpo (respuestas que no pasaron por decide)');

    $t->test('el circuit breaker abierto se sigue detectando', function ($t) {
        // breakerResponse() no pasa por decide(): devuelve error+errors a pelo.
        // El fallback por cuerpo es lo que lo cubre.
        $breaker = ['error' => 'circuit_open', 'errors' => [['error' => 'circuit_open',
            'message' => 'Circuit breaker abierto — backend indispuesto']]];
        $t->assert(TpvSyncApiClient::fueBien($breaker) === false,
            'Con el circuito abierto no se ha aplicado nada: nunca es exito');
    });

    $t->test('sin _ok, un cuerpo con type sigue siendo fallo (antipatron hermano unificado)', function ($t) {
        $t->assert(TpvSyncApiClient::fueBien(['type' => 'https://api.tpv/errors/not_found']) === false,
            'Varios sitios miraban type y acertaban por accidente: el fallback los cubre');
    });

    $t->test('un no-array nunca es exito', function ($t) {
        $t->assert(TpvSyncApiClient::fueBien(null) === false, 'null no es exito');
    });

    $t->test('_ok=false MANDA aunque el cuerpo parezca limpio', function ($t) {
        $t->assert(TpvSyncApiClient::fueBien(['_ok' => false, '_status' => 500]) === false,
            'El status prevalece sobre la ausencia de senales en el cuerpo');
    });

    $t->suite('BUG-A — el health-check del modulo (tpvsync.php:606)');

    $t->test('NO da verde ante credenciales revocadas', function ($t) {
        $body401 = ['type' => 'https://api.tpv/errors/invalid_client', 'title' => 'Unauthorized', 'status' => 401];
        $criterioViejo = empty($body401['error']) && empty($body401['errors']);
        $t->assert($criterioViejo === true,
            'El criterio viejo daba VERDE con las credenciales revocadas (el peor de los 7 sitios)');
        $t->assert(TpvSyncApiClient::fueBien(TpvSyncApiClient::decide(401, $body401)) === false,
            'El nuevo debe dar ROJO: es justo cuando el semaforo tiene que avisar');
    });
}
