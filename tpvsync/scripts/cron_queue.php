<?php
declare(strict_types=1);

// SEGURIDAD (CRITICAL): bloqueo de invocación HTTP. Si Apache sirve este path,
// un atacante remoto podría dispararlo remotamente (DoS).
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') { http_response_code(403); exit('CLI only'); }
/**
 * Cron: procesa la cola de reintentos.
 *
 * Uso:
 *   <asterisco>/1 <asterisco> <asterisco> <asterisco> <asterisco> php /ruta/a/tu/prestashop/modules/tpvsync/scripts/cron_queue.php
 *   (donde <asterisco> es "*" — evitamos literal por parser PHP)
 *
 * Protegido por flock para evitar procesos solapados cuando un reintento
 * dura más de 1 minuto (raro pero posible si el TPV va lento).
 */

$lockFile = sys_get_temp_dir() . '/tpvsync_queue.lock';
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
$stats = $module->queue()->process(50);
echo date('c') . " queue " . json_encode($stats) . PHP_EOL;
