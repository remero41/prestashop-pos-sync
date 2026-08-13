<?php
declare(strict_types=1);

// SEGURIDAD (CRITICAL): bloqueo de invocación HTTP. Si Apache sirve este path,
// un atacante remoto podría dispararlo remotamente (DoS).
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') { http_response_code(403); exit('CLI only'); }
/**
 * Cron / reimport manual: tras un csv.imported del TPV re-sincroniza el
 * catálogo completo a PS. Se ejecuta bajo demanda (el webhook lo lanza con
 * exec nohup) para no bloquear la respuesta HTTP.
 *
 * Uso manual:
 *   php /ruta/a/tu/prestashop/modules/tpvsync/scripts/cron_import.php
 */

$lockFile = sys_get_temp_dir() . '/tpvsync_import.lock';
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
$stats = $module->products()->importAll();
echo date('c') . ' import ' . json_encode($stats) . PHP_EOL;
