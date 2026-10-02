<?php
declare(strict_types=1);
/**
 * Cola de reintentos persistente.
 *
 * Usa la tabla PREFIX_tpv_sync_queue. Cada job tiene job_type + payload JSON +
 * scheduled_at. `process()` coge los que ya toca ejecutar con backoff
 * exponencial (1 min, 5 min, 15 min, 1h, 6h, 24h).
 *
 * Job types soportados:
 *   order.send      — reintenta TpvSyncOrder::sendToTpv
 *   refund.send     — reintenta TpvSyncOrder::onPsRefund
 *   stock.push      — reintenta push absoluto de stock cuando pushStockChange
 *                     no pudo leer el stock TPV y encoló.
 *   product.push    — reintenta TpvSyncProduct::pushProductToTpv
 *
 * Todos se procesan en TpvSyncQueue::dispatch(). El hook dispatch -> método
 * del servicio correspondiente debe ser SÍNCRONO (bloquea hasta éxito/fallo)
 * y devolver bool para que la cola marque el job.
 */
class TpvSyncQueue
{
    /** Retrasos por intento, en segundos (exponencial con cap). */
    private const BACKOFF = [60, 300, 900, 3600, 21600, 86400];

    public const STATUS_PENDING = 'pending';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ABANDONED = 'abandoned';

    private TpvSyncApiClient $api;

    public function __construct(TpvSyncApiClient $api)
    {
        $this->api = $api;
    }

    public function enqueue(string $jobType, array $payload, string $lastError = ''): int
    {
        Db::getInstance()->insert('tpv_sync_queue', [
            'job_type' => pSQL($jobType),
            'payload' => pSQL(json_encode($payload)),
            'status' => self::STATUS_PENDING,
            'attempts' => 0,
            'last_error' => pSQL(Tools::substr($lastError, 0, 1000)),
            'scheduled_at' => date('Y-m-d H:i:s', time() + self::BACKOFF[0]),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return (int) Db::getInstance()->Insert_ID();
    }

    /**
     * Procesa hasta $limit jobs pendientes cuya scheduled_at ya llegó.
     * @return array stats ok/fail/skipped
     */
    public function process(int $limit = 20): array
    {
        $stats = ['ok' => 0, 'fail' => 0, 'skipped' => 0];

        // Mientras el circuit breaker esté abierto no gastamos retries.
        if (!$this->api->breaker()->allowRequest()) {
            return $stats + ['reason' => 'circuit_open'];
        }

        $rows = Db::getInstance()->executeS(
            'SELECT * FROM ' . _DB_PREFIX_ . "tpv_sync_queue
             WHERE status = '" . self::STATUS_PENDING . "'
               AND scheduled_at <= NOW()
             ORDER BY id ASC LIMIT " . (int) $limit
        );
        if (!$rows) {
            return $stats;
        }

        foreach ($rows as $row) {
            $attempts = (int) $row['attempts'] + 1;
            $payload = json_decode((string) $row['payload'], true) ?: [];
            try {
                $success = $this->dispatch((string) $row['job_type'], $payload);
            } catch (\Throwable $e) {
                $success = false;
                TpvSyncLog::error('queue', (int) $row['id'], 'dispatch: ' . $e->getMessage());
            }
            if ($success) {
                Db::getInstance()->update('tpv_sync_queue', [
                    'status' => self::STATUS_DONE,
                    'attempts' => $attempts,
                    'last_error' => '',
                    'updated_at' => date('Y-m-d H:i:s'),
                ], 'id = ' . (int) $row['id']);
                $stats['ok']++;
                continue;
            }
            // Fallo: programar siguiente intento, o abandonar si agotamos backoffs
            if ($attempts >= count(self::BACKOFF)) {
                Db::getInstance()->update('tpv_sync_queue', [
                    'status' => self::STATUS_ABANDONED,
                    'attempts' => $attempts,
                    'updated_at' => date('Y-m-d H:i:s'),
                ], 'id = ' . (int) $row['id']);
                TpvSyncLog::error('queue', (int) $row['id'], 'Abandonado tras ' . $attempts . ' intentos');
                $stats['fail']++;
                continue;
            }
            $next = date('Y-m-d H:i:s', time() + self::BACKOFF[$attempts]);
            Db::getInstance()->update('tpv_sync_queue', [
                'status' => self::STATUS_PENDING,
                'attempts' => $attempts,
                'scheduled_at' => $next,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = ' . (int) $row['id']);
            $stats['fail']++;
        }
        return $stats;
    }

    private function dispatch(string $jobType, array $payload): bool
    {
        switch ($jobType) {
            case 'order.send':
                $idOrder = (int) ($payload['id_order'] ?? 0);
                if ($idOrder === 0) {
                    return true; // nada que hacer
                }
                (new TpvSyncOrder($this->api))->sendToTpv($idOrder);
                $tpvId = (int) Db::getInstance()->getValue(
                    'SELECT tpv_order_id FROM ' . _DB_PREFIX_ . 'tpv_sync_order_map
                     WHERE id_order = ' . $idOrder
                );
                return $tpvId > 0;

            case 'refund.send':
                $idOrder = (int) ($payload['id_order'] ?? 0);
                $idSlip = (int) ($payload['id_order_slip'] ?? 0);
                if ($idOrder === 0 || $idSlip === 0) {
                    return true;
                }
                // BUG-D (auditoria 2026-08-26): aqui habia un `return true` a ciegas
                // bajo un comentario que describia una heuristica de breaker/log
                // NUNCA IMPLEMENTADA — no se consultaba el log, ni el breaker, ni
                // el retorno. Una devolucion que la API rechazaba se marcaba como
                // sincronizada y se borraba de la cola. Es dinero.
                return (new TpvSyncOrder($this->api))->onPsRefund($idOrder, $idSlip);

            case 'stock.push':
                $tpvId = (int) ($payload['tpv_product_id'] ?? 0);
                $target = (int) ($payload['absolute_target'] ?? 0);
                if ($tpvId === 0) {
                    return true;
                }
                // A peso: la API rechaza (409) el stock entero de PS; reintentar sería un bucle.
                if ((new TpvSyncProduct($this->api))->esAPeso($tpvId)) {
                    return true;
                }
                $current = $this->api->get("/products/$tpvId/stock");
                // BUG-A, misma clase distinta forma: miraba SOLO isset($current['error']).
                // Un 404/502 en problem+json no la trae, asi que seguia adelante y
                // calculaba el delta contra un $tpvQty inexistente.
                if (!TpvSyncApiClient::fueBien($current)) {
                    return false;
                }
                $tpvQty = null;
                if (isset($current['total']['quantity'])) {
                    $tpvQty = (float) $current['total']['quantity'];
                } elseif (isset($current['data']['quantity'])) {
                    $tpvQty = (float) $current['data']['quantity'];
                }
                if ($tpvQty === null) {
                    return false;
                }
                $delta = $target - $tpvQty;
                if (abs($delta) < 0.0001) {
                    return true;
                }
                $res = $this->api->patch("/products/$tpvId/stock", [
                    'quantity_change' => $delta,
                    'reason' => (string) ($payload['reason'] ?? 'ajuste_manual'),
                    'comment' => (string) ($payload['comment'] ?? ''),
                ]);

                // BUG-A: esto era `empty($res['error']) && empty($res['errors'])`.
                // Un 404/502/429 no trae esas claves, asi que el ajuste de stock se
                // marcaba aplicado y se BORRABA de la cola: PS y el TPV divergian
                // sin senal y sin nadie que reintentara.
                return TpvSyncApiClient::fueBien($res);

            case 'product.push':
                $psId = (int) ($payload['id_product'] ?? 0);
                if ($psId === 0) {
                    return true;
                }
                return (new TpvSyncProduct($this->api))->pushProductToTpv($psId);

            default:
                TpvSyncLog::skip('queue', 0, "job_type desconocido: $jobType");
                return true; // no reintentar un tipo que no entendemos
        }
    }

    /** Borra jobs done/abandoned más antiguos que $days días. Llamado por cron diario. */
    public function purge(int $days = 30): int
    {
        $sql = 'DELETE FROM ' . _DB_PREFIX_ . "tpv_sync_queue
                WHERE status IN ('" . self::STATUS_DONE . "','" . self::STATUS_ABANDONED . "')
                  AND updated_at < DATE_SUB(NOW(), INTERVAL " . (int) $days . ' DAY)';
        Db::getInstance()->execute($sql);
        return (int) Db::getInstance()->Affected_Rows();
    }
}
