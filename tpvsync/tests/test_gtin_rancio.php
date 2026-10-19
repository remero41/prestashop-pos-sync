<?php
/**
 * EL GTIN RANCIO (spec varios códigos de barras del TPV, 2026-10-18; AUDIT_1 punto 14). Igual que en Woo.
 *
 * El ★ (model) de un producto en el TPV pasa de un EAN (8411300838279) a una referencia (CAM-BAS). El módulo
 * escribía la referencia nueva pero dejaba en PS el ean13 viejo; como buildPushPayload() prefiere el ean13, el
 * siguiente guardado en PS devolvía al TPV el EAN viejo (la API ya lo ignora, pero PS seguía enseñándolo y
 * mandándolo). Ahora el módulo recuerda el último ★ que escribió (tpv_sync_product_map.tpv_model) y borra el
 * ean13/upc que era ese ★; uno que el comerciante puso a mano se respeta.
 *
 * Corre en su PROPIO proceso (run.php lo lanza aparte): test_refund_paths.php sustituye TpvSyncProduct por un doble.
 * Uso: php tests/test_gtin_rancio.php
 */
declare(strict_types=1);

define('_PS_VERSION_', '8.1.0');
define('_DB_PREFIX_', 'ps_');
require dirname(__DIR__) . '/classes/TpvSyncApiClient.php';
require dirname(__DIR__) . '/classes/TpvSyncProduct.php';

$fallos = 0;
$pasan = 0;
function ok(bool $c, string $n): void
{
    global $fallos, $pasan;
    if ($c) { $pasan++; echo "  \033[32m✓\033[0m $n\n"; } else { $fallos++; echo "  \033[31m✗ $n\033[0m\n"; }
}
$g = fn(...$a) => TpvSyncProduct::gtinTrasModelo(...$a);

echo "GTIN rancio: cambiar el ★ del TPV no deja el EAN viejo en PrestaShop\n";
ok($g('8411300838279', '', '', '') === ['ean13' => '8411300838279', 'upc' => null], '★ EAN-13 → ean13 (como siempre)');
ok($g('123456789012', '', '', '') === ['ean13' => null, 'upc' => '123456789012'], '★ UPC-A → upc (como siempre)');
ok($g('12345678', '', '', '') === ['ean13' => '12345678', 'upc' => null], '★ EAN-8 → ean13 (como siempre)');
ok($g('CAM-BAS', '8411300838279', '', '8411300838279') === ['ean13' => '', 'upc' => null], '★ pasa a referencia: el ean13 que escribimos con el ★ anterior se BORRA');
ok($g('CAM-BAS', '', '123456789012', '123456789012') === ['ean13' => null, 'upc' => ''], 'igual con el upc');
ok($g('8400000000017', '', '123456789012', '123456789012') === ['ean13' => '8400000000017', 'upc' => ''], '★ UPC → ★ EAN: el upc viejo también se borra');
ok($g('CAM-BAS', '8400000000017', '', '8411300838279') === ['ean13' => null, 'upc' => null], 'un ean13 puesto a mano en PS (no es lo que escribimos) se respeta');
ok($g('CAM-BAS', '8400000000017', '', '') === ['ean13' => null, 'upc' => null], 'instalación de antes (sin tpv_model): no se toca nada');
ok($g('', '8411300838279', '', '8411300838279') === ['ean13' => null, 'upc' => null], 'sin ★ (dato raro): no se borra nada');

$src = file_get_contents(dirname(__DIR__) . '/classes/TpvSyncProduct.php');
ok((bool) preg_match('/self::gtinTrasModelo\(\$modelTpv, \(string\) \$product->ean13, \(string\) \$product->upc, \$modeloAnterior\)/', $src), 'upsert usa la regla con el ★ anterior');
ok((bool) preg_match('/\$this->guardarModeloTpv\(\$tpvId, \$modelTpv\)/', $src), 'y recuerda el ★ que acaba de escribir');
ok(str_contains($src, "ADD COLUMN tpv_model VARCHAR(100) NOT NULL DEFAULT ''"), 'la columna tpv_model se garantiza al usarla (como a_peso)');

echo "\n" . ($fallos === 0 ? "\033[32m✓ $pasan OK\033[0m" : "\033[31m✗ $fallos fallo(s), $pasan OK\033[0m") . "\n";
exit($fallos === 0 ? 0 : 1);
