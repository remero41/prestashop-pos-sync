<?php
/**
 * VENTA A PESO: lo que el TPV vende por kg NO se publica en PrestaShop (decisión del 02-10).
 *
 * El TPV vende a peso con la báscula: precio por kg/lb/100 g y stock con decimales
 * (4,25 kg). PrestaShop guarda unidades enteras (StockAvailable::setQuantity((int)…)) y
 * su precio sería «por unidad». La API lo avisa con `sold_by_weight` y el módulo:
 *
 *   - no lo crea; si ya estaba, lo DESACTIVA (no lo borra ni lo desenlaza) y lo marca
 *     en tpv_sync_product_map.a_peso;
 *   - si el TPV lo desmarca, el upsert normal lo vuelve a activar;
 *   - no copia su stock a PS (webhook, cron, reconciliación) ni empuja el entero de PS
 *     al TPV (la API respondería 409), tampoco desde la cola de reintentos.
 *
 * Corre en su PROPIO proceso (run.php lo lanza aparte): test_refund_paths.php sustituye
 * TpvSyncProduct por un doble y aquí se prueba la clase real.
 *
 * Uso: php tests/test_venta_a_peso.php
 */
declare(strict_types=1);

define('_PS_VERSION_', '8.1.0');
define('_DB_PREFIX_', 'ps_');

// ─── PrestaShop en memoria: solo lo que tocan los caminos de la venta a peso ─────────

/** La tabla tpv_sync_product_map y el stock; entiende las consultas del módulo. */
final class Db
{
    public static array $mapa = [];        // id_product => ['tpv' => id, 'a_peso' => 0|1]
    public static bool $columna = true;    // ¿existe tpv_sync_product_map.a_peso?
    public static array $sql = [];
    public static function getInstance(): self { return new self(); }

    public function getRow($q)
    {
        self::$sql[] = $q;
        if (preg_match('/SELECT id_product FROM ps_tpv_sync_product_map WHERE tpv_product_id = (\d+)/', $q, $m)) {
            foreach (self::$mapa as $ps => $f) { if ($f['tpv'] === (int) $m[1]) { return ['id_product' => $ps]; } }
            return false;
        }
        if (preg_match('/SELECT tpv_product_id FROM ps_tpv_sync_product_map WHERE id_product = (\d+)/', $q, $m)) {
            return isset(self::$mapa[(int) $m[1]]) ? ['tpv_product_id' => self::$mapa[(int) $m[1]]['tpv']] : false;
        }
        return false;
    }

    public function getValue($q)
    {
        self::$sql[] = $q;
        if (preg_match('/SELECT a_peso FROM ps_tpv_sync_product_map WHERE (id_product|tpv_product_id) = (\d+)/', $q, $m)) {
            if (!self::$columna) { return false; }
            foreach (self::$mapa as $ps => $f) {
                if (($m[1] === 'id_product' ? $ps : $f['tpv']) === (int) $m[2]) { return (string) $f['a_peso']; }
            }
            return false;
        }
        return false;
    }

    public function executeS($q)
    {
        self::$sql[] = $q;
        if (stripos($q, "SHOW COLUMNS FROM ps_tpv_sync_product_map LIKE 'a_peso'") !== false) {
            return self::$columna ? [['Field' => 'a_peso']] : [];
        }
        return [];
    }

    public function execute($q)
    {
        self::$sql[] = $q;
        if (stripos($q, 'ALTER TABLE ps_tpv_sync_product_map ADD COLUMN') !== false) { self::$columna = true; return true; }
        if (preg_match('/UPDATE ps_tpv_sync_product_map SET a_peso = (\d) WHERE id_product = (\d+)/', $q, $m)) {
            if (!self::$columna) { throw new RuntimeException('Unknown column a_peso'); }
            if (isset(self::$mapa[(int) $m[2]])) { self::$mapa[(int) $m[2]]['a_peso'] = (int) $m[1]; }
            return true;
        }
        return true;
    }
}

final class Product
{
    public static array $activos = [];     // id => 0|1
    public static array $creados = [];
    public $id; public $active;
    public function __construct($id = null) { $this->id = $id; $this->active = self::$activos[(int) $id] ?? null; }
    public function update() { self::$activos[(int) $this->id] = (int) $this->active; return true; }
    public function add() { self::$creados[] = $this; return true; }
}
final class Validate { public static function isLoadedObject($o) { return isset(Product::$activos[(int) $o->id]); } }
final class StockAvailable
{
    public static array $fijados = [];
    public static function setQuantity($id, $attr, $qty, $shop = null) { self::$fijados[] = [(int) $id, $qty]; }
    public static function getQuantityAvailableByProduct($id, $attr = 0) { return 5; }
}
final class TpvSyncLog
{
    public static array $entradas = [];
    public static function __callStatic($n, $a) { self::$entradas[] = [$n, $a[2] ?? '']; }
}

require dirname(__DIR__) . '/classes/TpvSyncApiClient.php';
require dirname(__DIR__) . '/classes/TpvSyncProduct.php';
require dirname(__DIR__) . '/classes/TpvSyncQueue.php';

/** API falsa: registra cada llamada; el detalle de producto sale de $productos. */
final class ApiPesoFalsa extends TpvSyncApiClient
{
    public array $llamadas = [];
    public array $productos = [];
    public function get(string $path, array $params = []): array
    {
        $this->llamadas[] = "GET $path";
        if ($path === '/products') { return ['data' => array_values($this->productos)]; }
        if (preg_match('#^/products/(\d+)/stock$#', $path, $m)) { return ['data' => ['quantity' => 4.25]]; }
        return ['data' => []];
    }
    public function getAll(string $path, array $params = []): array { return array_values($this->productos); }
    public function patch(string $path, array $body = []): array { $this->llamadas[] = "PATCH $path"; return ['data' => []]; }
    public function batch(array $ops): array
    {
        $r = [];
        foreach ($ops as $i => $op) {
            $id = (int) basename((string) $op['path']);
            $r[] = ['index' => $i, 'status' => 200, 'body' => ['data' => $this->productos[$id] ?? []]];
        }
        return ['results' => $r];
    }
}
function api(array $productos = []): ApiPesoFalsa
{
    $a = (new ReflectionClass(ApiPesoFalsa::class))->newInstanceWithoutConstructor();
    $a->llamadas = []; $a->productos = $productos;
    return $a;
}
function tpv(int $id, array $extra): array
{
    return $extra + ['product_id' => $id, 'name' => "TPV $id", 'model' => "M$id", 'sku' => '',
        'price' => 3.0, 'special_price' => null, 'quantity' => 4.25, 'status' => 1, 'tax_class_id' => 0];
}
/** Tienda: el producto PS 31 enlazado con el 502 del TPV, activo; el 32 con el 900. */
function tienda(bool $columna = true): void
{
    Db::$mapa = [31 => ['tpv' => 502, 'a_peso' => 0], 32 => ['tpv' => 900, 'a_peso' => 0]];
    Db::$columna = $columna; Db::$sql = [];
    Product::$activos = [31 => 1, 32 => 1]; Product::$creados = [];
    StockAvailable::$fijados = []; TpvSyncLog::$entradas = [];
    // La comprobación de la columna se recuerda por petición: cada caso es una petición nueva.
    $r = new ReflectionProperty(TpvSyncProduct::class, 'hayColumnaAPeso');
    $r->setAccessible(true);
    $r->setValue(null, null);
}

$fallos = 0; $pasan = 0;
function ok(bool $c, string $m): void { global $fallos, $pasan; if ($c) { $pasan++; echo "  \033[32m✓\033[0m $m\n"; } else { $fallos++; echo "  \033[31m✗\033[0m $m\n"; } }

echo "\n\033[1;34m══ Venta a peso: no se publica en PrestaShop ══\033[0m\n";

tienda();
$r = (new TpvSyncProduct(api()))->upsert(tpv(502, ['sold_by_weight' => true]));
ok(Product::$activos[31] === 0, 'producto a peso YA publicado ⇒ se desactiva');
ok($r === 'a_peso' && Db::$mapa[31]['a_peso'] === 1, 'devuelve a_peso y queda marcado en el mapa');
ok(StockAvailable::$fijados === [], 'su stock (4,25 kg) no se copia a PS como 4');
ok(isset(Db::$mapa[31]), 'no se desenlaza');

tienda();
$r = (new TpvSyncProduct(api()))->upsert(tpv(777, ['sold_by_weight' => true]));
ok(Product::$creados === [] && $r === 'a_peso', 'producto a peso que PS no tiene ⇒ no se crea');

tienda(false);
(new TpvSyncProduct(api()))->upsert(tpv(502, ['sold_by_weight' => true]));
ok(Db::$columna && Db::$mapa[31]['a_peso'] === 1, 'tienda sin la columna a_peso (módulo sin actualizar) ⇒ se crea y se marca');

tienda();
Db::$mapa[31]['a_peso'] = 1;
// El TPV lo desmarca: se limpia la marca y sigue el upsert de siempre, que le pone el
// estado del TPV (active=1). Ese resto es núcleo de PrestaShop (Context, ObjectModel…)
// que este arnés no carga: aquí se comprueba la marca, que es lo que libera el stock.
try { (new TpvSyncProduct(api()))->upsert(tpv(502, ['sold_by_weight' => false])); } catch (Throwable $e) {}
ok(Db::$mapa[31]['a_peso'] === 0, 'el TPV lo desmarca ⇒ se limpia la marca (vuelve a sincronizar stock)');

tienda();
Db::$mapa[31]['a_peso'] = 1;
(new TpvSyncProduct(api()))->updateStock(502, 3.75);
ok(StockAvailable::$fijados === [], 'stock.adjusted de un producto a peso no se copia a PS');
(new TpvSyncProduct(api()))->updateStock(900, 3.0);
ok(StockAvailable::$fijados === [[32, 3]], 'el de un producto por unidades sí');

tienda();
Db::$mapa[31]['a_peso'] = 1;
$a = api();
(new TpvSyncProduct($a))->pushStockChange(31, 0, 4);
ok($a->llamadas === [], 'editar el stock en PS de un producto a peso no lo empuja al TPV (pisaría 4,25 kg con 4)');

tienda();
Db::$mapa[31]['a_peso'] = 1;
$a = api();
$disp = new ReflectionMethod(TpvSyncQueue::class, 'dispatch');
$disp->setAccessible(true);
$listo = $disp->invoke(new TpvSyncQueue($a), 'stock.push', ['tpv_product_id' => 502, 'absolute_target' => 4]);
ok($listo === true && $a->llamadas === [], 'la cola de reintentos descarta el stock.push de un producto a peso (sin bucle de 409)');

tienda();
$a = api([502 => tpv(502, ['sold_by_weight' => true])]);
$st = (new TpvSyncProduct($a))->reconcile(100);
ok(StockAvailable::$fijados === [] && ($st['fixed'] ?? -1) === 0, 'cron: el producto a peso se salta, no se «corrige»');

echo "\n" . ($fallos === 0 ? "\033[32m✓ $pasan OK\033[0m" : "\033[31m✗ $fallos fallo(s), $pasan OK\033[0m") . "\n";
exit($fallos === 0 ? 0 : 1);
