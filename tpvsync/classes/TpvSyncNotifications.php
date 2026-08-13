<?php
/**
 * Notificaciones al admin cuando algo requiere atención.
 *
 * Canales:
 *   - email    (TPVSYNC_NOTIFY_EMAIL, default Configuration::PS_SHOP_EMAIL)
 *   - slack    (TPVSYNC_NOTIFY_SLACK_WEBHOOK)
 *   - telegram (TPVSYNC_NOTIFY_TELEGRAM_BOT + _CHAT_ID)
 *
 * Reglas pre-definidas (evaluadas en cron horario):
 *   - queue_abandoned: ≥1 entrada abandoned en la última hora
 *   - queue_growing:   >50 items pending
 *   - breaker_open:    circuit breaker en OPEN
 *   - tpv_unreachable: /health falla
 *   - secret_decrypt_failed: TpvSyncSecrets marcó descifrado roto
 *
 * Throttling: misma alerta (mismo key) solo 1×/hora vía Configuration con TS.
 *
 * Uso:
 *   TpvSyncNotifications::alert('queue_abandoned', 'msg', ['count' => 3]);
 *   TpvSyncNotifications::evaluateRules();    // llamado por cron horario
 */
declare(strict_types=1);

if (!defined('_PS_VERSION_')) exit;

class TpvSyncNotifications
{
    public const THROTTLE_SEC = 3600;
    public const ALERT_KEYS = [
        'queue_abandoned',
        'queue_growing',
        'breaker_open',
        'tpv_unreachable',
        'secret_decrypt_failed',
    ];

    /**
     * Emite una alerta en todos los canales configurados (salvo throttled).
     */
    public static function alert(string $key, string $message, array $context = []): void
    {
        // Throttle: misma key solo una vez por hora
        $throttleKey = 'TPVSYNC_NOTIF_TS_' . strtoupper(preg_replace('/[^a-z0-9_]/i', '_', $key));
        $lastAt = (int) Configuration::get($throttleKey);
        if ($lastAt > 0 && (time() - $lastAt) < self::THROTTLE_SEC) return;
        Configuration::updateValue($throttleKey, time());

        $shop    = (string) Configuration::get('PS_SHOP_NAME');
        $subject = "[TPV Sync · $shop] $key";
        $body    = "$message\n\n" . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        self::sendEmail($subject, $body);
        self::sendSlack($subject, $message, $context);
        self::sendTelegram($subject, $message, $context);

        // event_type='notify' permite filtrar fácil estas entradas en el log.
        TpvSyncLog::write('warn', 'notify', 0, "$key — $message", 'notify');
    }

    private static function sendEmail(string $subject, string $body): void
    {
        $to = (string) Configuration::get('TPVSYNC_NOTIFY_EMAIL');
        if ($to === '') return;

        // En PS usamos mail() directamente. Mail::Send() requiere plantillas
        // .html/.txt en mails/ — overkill para alertas técnicas.
        // Silenciamos errores: si el servidor no tiene sendmail, no debe
        // hacer ruido en logs ni romper el cron de notificaciones.
        $headers = [
            'From: noreply@' . (parse_url((string) Configuration::get('PS_SHOP_DOMAIN'), PHP_URL_HOST) ?: 'localhost'),
            'Content-Type: text/plain; charset=utf-8',
        ];
        @mail($to, $subject, $body, implode("\r\n", $headers));
    }

    private static function sendSlack(string $title, string $message, array $ctx): void
    {
        $url = (string) Configuration::get('TPVSYNC_NOTIFY_SLACK_WEBHOOK');
        if ($url === '') return;
        $text = "*$title*\n$message\n```" . json_encode($ctx, JSON_UNESCAPED_UNICODE) . '```';
        self::httpPost($url, json_encode(['text' => $text]), ['Content-Type: application/json']);
    }

    private static function sendTelegram(string $title, string $message, array $ctx): void
    {
        $bot  = (string) Configuration::get('TPVSYNC_NOTIFY_TELEGRAM_BOT');
        $chat = (string) Configuration::get('TPVSYNC_NOTIFY_TELEGRAM_CHAT_ID');
        if ($bot === '' || $chat === '') return;
        $url = "https://api.telegram.org/bot$bot/sendMessage";
        $payload = http_build_query([
            'chat_id'    => $chat,
            'text'       => "*$title*\n$message",
            'parse_mode' => 'Markdown',
        ]);
        self::httpPost($url, $payload, ['Content-Type: application/x-www-form-urlencoded']);
    }

    /**
     * Helper HTTP fire-and-forget. Sin reintentos: una notificación perdida
     * es preferible a un cron bloqueado por slack o telegram caídos.
     */
    private static function httpPost(string $url, string $body, array $headers): void
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    /**
     * Reglas pre-armadas: se evalúan en cron horario. Si se cumple alguna,
     * dispara alert() (con throttling).
     *
     * Devuelve un array con las reglas que SÍ dispararon alerta — útil para
     * tests y para mostrarlo en el panel de salud.
     */
    public static function evaluateRules(): array
    {
        $fired = [];

        // 1) queue_abandoned
        $abandoned = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'tpv_sync_queue
             WHERE status = "abandoned"
               AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)'
        );
        if ($abandoned > 0) {
            self::alert('queue_abandoned',
                "$abandoned entradas abandonadas en la última hora. Revisar tpv_sync_queue.",
                ['abandoned_1h' => $abandoned]);
            $fired[] = 'queue_abandoned';
        }

        // 2) queue_growing
        $pending = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'tpv_sync_queue WHERE status = "pending"'
        );
        if ($pending > 50) {
            self::alert('queue_growing',
                "Queue creciendo: $pending items pending. Posible TPV caído/lento.",
                ['pending' => $pending]);
            $fired[] = 'queue_growing';
        }

        // 3) breaker_open
        $breakerState = (string) Configuration::get('TPVSYNC_CB_STATE');
        if ($breakerState === 'open') {
            self::alert('breaker_open',
                'Circuit breaker OPEN — la API TPV lleva fallando varias requests seguidas.',
                [
                    'state' => $breakerState,
                    'fails' => (int) Configuration::get('TPVSYNC_CB_FAILS'),
                    'open_until' => (int) Configuration::get('TPVSYNC_CB_OPEN_UNTIL'),
                ]);
            $fired[] = 'breaker_open';
        }

        // 4) tpv_unreachable — solo si última check guardada está stale o KO
        $healthOk = (int) Configuration::get('TPVSYNC_HEALTH_OK');
        $healthAt = (int) Configuration::get('TPVSYNC_HEALTH_CHECKED_AT');
        if ($healthAt > 0 && (time() - $healthAt) < 7200 && $healthOk === 0) {
            // Solo alertamos si la última check explícita fue KO. Stale check
            // no genera ruido — el cron principal del módulo refresca el flag.
            self::alert('tpv_unreachable',
                'La API TPV no responde OK al /health.',
                ['last_check_at' => $healthAt]);
            $fired[] = 'tpv_unreachable';
        }

        // 5) secret_decrypt_failed
        $decryptFlag = (string) Configuration::get('TPVSYNC_SECRET_DECRYPT_FAILED');
        if ($decryptFlag !== '') {
            self::alert('secret_decrypt_failed',
                'Las credenciales no se pueden descifrar. Probable rotación de claves PS o restauración de backup. Pega de nuevo el Client Secret.',
                ['detail' => json_decode($decryptFlag, true) ?: []]);
            $fired[] = 'secret_decrypt_failed';
        }

        return $fired;
    }

    /**
     * Limpia el throttle de una alerta (forzar reenvío). Usado por la UI
     * cuando el admin pulsa "reenviar" o resuelve manualmente la causa.
     */
    public static function resetThrottle(string $key): void
    {
        $throttleKey = 'TPVSYNC_NOTIF_TS_' . strtoupper(preg_replace('/[^a-z0-9_]/i', '_', $key));
        Configuration::deleteByName($throttleKey);
    }
}
