<?php
/**
 * BUG-D — `refund.send` devolvia true incondicionalmente. DINERO.
 *
 * TpvSyncQueue::execute() case 'refund.send' llamaba a onPsRefund() y hacia
 * `return true;` a pelo, bajo un comentario que describe una heuristica
 * ("si el breaker sigue closed y no hubo error en los ultimos 5s") que NO ESTA
 * IMPLEMENTADA: no se consulta el log, ni el breaker, ni el retorno. Una
 * devolucion fallida se marcaba como sincronizada y se borraba de la cola.
 *
 * Estos tests recorren cada camino de onPsRefund() con PrestaShop stubeado, y
 * comprueban el valor devuelto. Con la firma `void` original TODOS devuelven
 * null (falsy) — que es justo el riesgo del cambio: un `return;` que se olvide
 * convierte un camino de EXITO en fallo permanente en cola, reintentando para
 * siempre una devolucion que si se aplico.
 *
 * La tabla de caminos esta en docs/ONPSREFUND_CAMINOS.md, levantada ANTES de
 * tocar la firma.
 */

// ─── Stubs minimos de PrestaShop ─────────────────────────────────────────────
// Solo lo que onPsRefund() toca. Si hiciera falta mas, estariamos probando
// PrestaShop en vez del modulo.

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}

class TpvTestDb
{
    public static $rows = [];
    public static $value = 0;
    public function executeS($sql) { return self::$rows; }
    public function getValue($sql) { return self::$value; }
    public function execute($sql) { return true; }
    public function insert($t, $d) { return true; }
}

if (!class_exists('Db')) {
    class Db
    {
        public static function getInstance() { return new TpvTestDb(); }
    }
}

if (!class_exists('OrderSlip')) {
    class OrderSlip
    {
        public static $loaded = true;
        public $id;
        public $id_order = 0;
        public function __construct($id = null) { $this->id = $id; }
    }
}

if (!class_exists('Validate')) {
    class Validate
    {
        public static function isLoadedObject($o) { return OrderSlip::$loaded; }
    }
}

if (!class_exists('TpvSyncLog')) {
    class TpvSyncLog
    {
        public static $entries = [];
        public static function error($r, $id, $m) { self::$entries[] = ['error', $m]; }
        public static function ok($r, $id, $m)    { self::$entries[] = ['ok', $m]; }
        public static function skip($r, $id, $m)  { self::$entries[] = ['skip', $m]; }
        public static function warn($r, $id, $m)  { self::$entries[] = ['warn', $m]; }
    }
}

/**
 * Cliente API simulado: devuelve lo que le digas, sin red.
 *
 * EXTIENDE el cliente real porque TpvSyncOrder::__construct lo exige por tipo.
 * El constructor del padre NO se llama (necesitaria Configuration y
 * TpvSyncSecrets); se instancia con newInstanceWithoutConstructor(), ver
 * tpvTestApi() abajo.
 */
class TpvTestApi extends TpvSyncApiClient
{
    public $respuesta = [];
    public $llamadas  = 0;
    public $cuerpos   = [];
    // Firmas identicas a las del padre (TpvSyncApiClient), o PHP rechaza la clase.
    public function post(string $path, array $body = [], ?string $idempotencyKey = null): array
    {
        $this->llamadas++;
        $this->cuerpos[] = $body;

        return $this->respuesta;
    }

    public function get(string $path, array $params = []): array { return $this->respuesta; }

    public function patch(string $path, array $body = []): array { return $this->respuesta; }

    public function delete(string $path): array { return $this->respuesta; }
}

/** Crea un TpvTestApi sin pasar por el constructor del cliente real. */
function tpvTestApi(array $respuesta = [])
{
    $api = (new ReflectionClass('TpvTestApi'))->newInstanceWithoutConstructor();
    $api->respuesta = $respuesta;
    $api->llamadas  = 0;

    return $api;
}

/**
 * Cola simulada: registra si alguien reencola, sin tocar la BD.
 *
 * Se declara con el NOMBRE REAL (TpvSyncQueue) para que el `new TpvSyncQueue()`
 * que hay dentro de onPsRefund resuelva aqui y no arrastre PrestaShop.
 */
if (!class_exists('TpvSyncQueue')) {
    class TpvSyncQueue
    {
        public static $encolados = [];
        public function __construct($api = null) {}
        public function enqueue($op, $payload, $motivo = '') { self::$encolados[] = [$op, $motivo]; }
    }
}

/** Idem para TpvSyncProduct: onPsRefund solo le pide findTpvByPs(). */
if (!class_exists('TpvSyncProduct')) {
    class TpvSyncProduct
    {
        public static $mapa = 7;   // id de producto en el TPV; 0 = sin mapeo
        public function __construct($api = null) {}
        public function findTpvByPs($idProduct) { return self::$mapa; }
    }
}

function run_refund_paths_tests(PrestaTestRunner $t)
{
    $t->suite('BUG-D — onPsRefund(): valor devuelto por cada camino');

    $ref = new ReflectionMethod('TpvSyncOrder', 'onPsRefund');
    $tipo = $ref->getReturnType();
    $nombreTipo = $tipo ? $tipo->getName() : '(sin tipo)';

    $t->test('la firma declara bool, no void', function ($t) use ($nombreTipo) {
        $t->assert($nombreTipo === 'bool',
            "onPsRefund debe devolver bool para que la cola pueda propagar el resultado; declara: $nombreTipo");
    });

    // ── Camino 1 (linea 417): omision deliberada ──
    $t->test('C1 skip_refund_push ⇒ true (omision deliberada, NO es un fallo)', function ($t) {
        $GLOBALS['tpvsync_skip_refund_push'] = true;
        $r = (new TpvSyncOrder(tpvTestApi()))->onPsRefund(1, 1);
        unset($GLOBALS['tpvsync_skip_refund_push']);
        $t->assert($r === true,
            'La devolucion viene DEL TPV: no hay que devolversela. Si esto diera false, ' .
            'la entrada se reintentaria para siempre. Devolvio: ' . var_export($r, true));
    });

    // ── Camino 2 (linea 422): pedido sin mapeo ──
    $t->test('C2 pedido sin mapeo TPV ⇒ true (terminal: reintentar no lo arregla)', function ($t) {
        TpvTestDb::$value = 0;   // findTpvByPs devuelve 0
        $r = (new TpvSyncOrder(tpvTestApi()))->onPsRefund(1, 1);
        $t->assert($r === true,
            'Sin mapeo no hay nada que propagar nunca: es exito terminal, no fallo. Devolvio: ' . var_export($r, true));
    });

    // ── Camino 3 (linea 426): slip que no carga ──
    $t->test('C3 OrderSlip no carga ⇒ true (terminal)', function ($t) {
        TpvTestDb::$value = 555;      // hay mapeo
        OrderSlip::$loaded = false;   // pero el slip esta corrupto
        $r = (new TpvSyncOrder(tpvTestApi()))->onPsRefund(1, 1);
        OrderSlip::$loaded = true;
        $t->assert($r === true,
            'Un slip inexistente no se arregla reintentando. Devolvio: ' . var_export($r, true));
    });

    // ── Camino 4 (linea 438): slip sin lineas ──
    $t->test('C4 slip sin lineas ⇒ true (terminal: nada que propagar)', function ($t) {
        TpvTestDb::$value = 555;
        TpvTestDb::$rows  = [];
        $r = (new TpvSyncOrder(tpvTestApi()))->onPsRefund(1, 1);
        $t->assert($r === true, 'Devolvio: ' . var_export($r, true));
    });

    // ── Camino A (salida por el final con errores) ──
    $t->test('CA la API rechaza la linea ⇒ false (EL BUG: esto se daba por bueno)', function ($t) {
        TpvTestDb::$value = 555;
        TpvTestDb::$rows  = [[
            'id_order_detail' => 1, 'product_quantity' => 2,
            'amount_tax_incl' => 10.0, 'product_name' => 'Camiseta', 'product_id' => 7,
        ]];
        // 404 en problem+json: sin 'return_id' y sin 'errors'.
        $api = tpvTestApi(TpvSyncApiClient::decide(404, [
            'type' => 'https://api.tpv/errors/not_found', 'title' => 'Not Found', 'status' => 404,
        ]));
        $r = (new TpvSyncOrder($api))->onPsRefund(1, 1);
        $t->assert($r === false,
            'Una devolucion que la API rechazo NO puede marcarse como sincronizada: es dinero. ' .
            'Devolvio: ' . var_export($r, true));
    });

    // ── Camino B (salida por el final, todo bien) ──
    $t->test('CB todas las lineas propagadas ⇒ true', function ($t) {
        TpvTestDb::$value = 555;
        TpvTestDb::$rows  = [[
            'id_order_detail' => 1, 'product_quantity' => 2,
            'amount_tax_incl' => 10.0, 'product_name' => 'Camiseta', 'product_id' => 7,
        ]];
        $api = tpvTestApi(TpvSyncApiClient::decide(201, ['data' => ['return_id' => 99]]));
        $r = (new TpvSyncOrder($api))->onPsRefund(1, 1);
        $t->assert($r === true, 'La ruta feliz debe seguir devolviendo true. Devolvio: ' . var_export($r, true));
    });

    // ── La API rechaza return_status_id != 3 (api_tpv bb2b474, 22-08-2026) ──
    // El alta de devolución nace ejecutada (3) y cualquier otro valor es 422
    // invalid_return_status. El módulo mandaba 1: NINGÚN reembolso de
    // PrestaShop llegaba al TPV desde entonces (mismo fallo que en Woo).
    $t->test('el reembolso no manda un return_status_id que la API rechaza', function ($t) {
        TpvTestDb::$value = 555;
        TpvTestDb::$rows  = [[
            'id_order_detail' => 1, 'product_quantity' => 1,
            'amount_tax_incl' => 10.0, 'product_name' => 'Camiseta', 'product_id' => 7,
        ]];
        $api = tpvTestApi(TpvSyncApiClient::decide(201, ['data' => ['return_id' => 99]]));
        (new TpvSyncOrder($api))->onPsRefund(1, 1);
        $body = $api->cuerpos[0] ?? [];
        $t->assert($body !== [], 'no llegó a mandar la línea');
        $t->assert(!isset($body['return_status_id']) || (int) $body['return_status_id'] === 3,
            'return_status_id=' . var_export($body['return_status_id'] ?? null, true) . ' ⇒ 422 en la API real');
    });

    $t->suite('BUG-D — ningun camino devuelve null');

    $t->test('los 6 caminos devuelven un bool ESTRICTO (un return; olvidado daria null)', function ($t) {
        // Es el riesgo concreto del void → bool: null es falsy, asi que un camino
        // de exito olvidado se convertiria en fallo permanente en cola.
        $casos = [];

        $GLOBALS['tpvsync_skip_refund_push'] = true;
        $casos['C1'] = (new TpvSyncOrder(tpvTestApi()))->onPsRefund(1, 1);
        unset($GLOBALS['tpvsync_skip_refund_push']);

        TpvTestDb::$value = 0;
        $casos['C2'] = (new TpvSyncOrder(tpvTestApi()))->onPsRefund(1, 1);

        TpvTestDb::$value = 555;
        OrderSlip::$loaded = false;
        $casos['C3'] = (new TpvSyncOrder(tpvTestApi()))->onPsRefund(1, 1);
        OrderSlip::$loaded = true;

        TpvTestDb::$rows = [];
        $casos['C4'] = (new TpvSyncOrder(tpvTestApi()))->onPsRefund(1, 1);

        TpvTestDb::$rows = [[
            'id_order_detail' => 1, 'product_quantity' => 2,
            'amount_tax_incl' => 10.0, 'product_name' => 'X', 'product_id' => 7,
        ]];
        $apiKo = tpvTestApi(TpvSyncApiClient::decide(404, ['type' => 'x']));
        $casos['CA'] = (new TpvSyncOrder($apiKo))->onPsRefund(1, 1);

        $apiOk = tpvTestApi(TpvSyncApiClient::decide(201, ['data' => ['return_id' => 99]]));
        $casos['CB'] = (new TpvSyncOrder($apiOk))->onPsRefund(1, 1);

        foreach ($casos as $nombre => $v) {
            $t->assert(is_bool($v), "$nombre devolvio " . var_export($v, true) . ' en vez de un bool');
        }
    });

    $t->suite('BUG-D — onPsRefund no reencola por su cuenta (evita doble encolado)');

    $t->test('un fallo NO reencola desde dentro: eso es cosa de quien gestiona la cola', function ($t) {
        TpvSyncQueue::$encolados = [];
        TpvTestDb::$value = 555;
        TpvTestDb::$rows  = [[
            'id_order_detail' => 1, 'product_quantity' => 2,
            'amount_tax_incl' => 10.0, 'product_name' => 'X', 'product_id' => 7,
        ]];
        $api = tpvTestApi(TpvSyncApiClient::decide(500, []));
        (new TpvSyncOrder($api))->onPsRefund(1, 1);
        $t->assert(count(TpvSyncQueue::$encolados) === 0,
            'Si onPsRefund reencola Y ademas devuelve false, la cola reintenta la entrada ' .
            'y queda encolada DOS veces. Encolo: ' . count(TpvSyncQueue::$encolados));
    });
}
