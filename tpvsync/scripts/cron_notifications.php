<?php
declare(strict_types=1);

// SEGURIDAD (CRITICAL): bloqueo de invocación HTTP. Si Apache sirve este path,
// un atacante remoto podría dispararlo remotamente (DoS).
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') { http_response_code(403); exit('CLI only'); }
// SEGURIDAD: bloqueo de invocación HTTP — si Apache sirviera este path, un
// atacante podría disparar evaluaciones repetidas y saturar canales externos
// (slack/telegram rate limits). Solo CLI.

/**
 * Cron horario: evalúa las reglas de TpvSyncNotifications y dispara alertas
 * cuando se cumplen. Throttling interno por key (1×/hora).
 *
 * Uso:
 *   0 * * * *  php /ruta/a/tu/prestashop/modules/tpvsync/scripts/cron_notifications.php
 *
 * Idempotente: re-ejecutarlo varias veces dentro de la ventana de throttle
 * NO duplica alertas.
 */

require_once __DIR__ . '/../../../config/config.inc.php';
require_once _PS_MODULE_DIR_ . 'tpvsync/classes/TpvSyncSecrets.php';
require_once _PS_MODULE_DIR_ . 'tpvsync/classes/TpvSyncLog.php';
require_once _PS_MODULE_DIR_ . 'tpvsync/classes/TpvSyncNotifications.php';

$fired = TpvSyncNotifications::evaluateRules();
if (!empty($fired)) {
    fwrite(STDERR, "[tpvsync notifications] fired: " . implode(', ', $fired) . PHP_EOL);
}
exit(0);
