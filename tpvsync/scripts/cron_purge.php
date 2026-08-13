<?php
declare(strict_types=1);

// SEGURIDAD (CRITICAL): bloqueo de invocación HTTP. Si Apache sirve este path,
// un atacante remoto podría dispararlo remotamente (DoS).
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') { http_response_code(403); exit('CLI only'); }
/**
 * Cron: purga entradas done/abandoned de la cola más antiguas de 30 días,
 * y rota el log (borra filas >90 días).
 *
 * Uso:
 *   0 3 * * * php /ruta/a/tu/prestashop/modules/tpvsync/scripts/cron_purge.php
 */

require_once __DIR__ . '/../../../config/config.inc.php';
require_once _PS_MODULE_DIR_ . 'tpvsync/tpvsync.php';

$module = new TpvSync();
$q = $module->queue()->purge(30);

Db::getInstance()->execute(
    'DELETE FROM ' . _DB_PREFIX_ . 'tpv_sync_log
     WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)'
);
$lrows = Db::getInstance()->Affected_Rows();

echo date('c') . " purge queue=$q log=$lrows" . PHP_EOL;
