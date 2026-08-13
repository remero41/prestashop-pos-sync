<?php
declare(strict_types=1);

// SEGURIDAD (CRITICAL): bloqueo de invocación HTTP. Si Apache sirve este path,
// un atacante remoto podría dispararlo remotamente (DoS).
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') { http_response_code(403); exit('CLI only'); }
/**
 * Cron: reconciliación de stock semanal (o manual).
 *
 * Uso:
 *   0 4 * * 0  php /ruta/a/tu/prestashop/modules/tpvsync/scripts/cron_reconcile.php
 *
 * Corre sobre un lote limitado (200 productos/ejecución) para no bloquear
 * la tienda ni saturar el TPV. Si el catálogo es grande, conviene ejecutarlo
 * varias veces seguidas — cada pasada empieza desde el principio del catálogo
 * activo (la API ordena por product_id asc).
 */

$lockFile = sys_get_temp_dir() . '/tpvsync_reconcile.lock';
$lock = fopen($lockFile, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}
register_shutdown_function(function () use ($lock) {
    if ($lock) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
});

require_once __DIR__ . '/../../../config/config.inc.php';
require_once _PS_MODULE_DIR_ . 'tpvsync/tpvsync.php';

$module = new TpvSync();
$stats = $module->products()->reconcile(200);
echo date('c') . ' reconcile ' . json_encode($stats) . PHP_EOL;
