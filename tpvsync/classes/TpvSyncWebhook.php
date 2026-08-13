<?php
declare(strict_types=1);
/**
 * Receptor de webhooks del TPV.
 *
 * El TPV emite eventos firmados HMAC-SHA256 a un endpoint público del cliente.
 * En este módulo el endpoint vive en el FrontController `tpvsyncwebhook`
 * (ver controllers/front/webhook.php). Esta clase encapsula la lógica: la
 * separamos del controller para poder testearla sin arrancar el front de PS.
 *
 * Eventos que procesamos (mismos que el plugin WC):
 *   product.created     → upsert
 *   product.updated     → upsert
 *   product.deleted     → soft delete (active=0) en PS
 *   stock.adjusted      → StockAvailable::setQuantity
 *   variant.stock_adjusted → idem por combination
 *   special.created/deleted
 *   variants.updated / variant.created / option.created / option.deleted
 *   order.created       → solo log (venta física TPV, no hace falta reflejar)
 *   order.status_changed → OrderHistory en PS
 *   return.created      → OrderSlip en PS
 *   category.* / customer.*
 *
 * Idempotencia: cache por `idempotency_key` en tabla idempotency_seen —
 * 24h de TTL (cubre los reintentos del dispatcher).
 *
 * Ordering guard de stock: usamos el `timestamp` del payload vs el último
 * aplicado para descartar eventos fuera de orden. Persistimos el último ts
 * por recurso en tabla PREFIX_tpv_sync_stock_ts.
 */
class TpvSyncWebhook
{
    /** Versiones del payload soportadas. Si el TPV emite otra → 426. */
    private const SUPPORTED_VERSIONS = ['1'];

    public function handle(): void
    {
        $raw = (string) file_get_contents('php://input');
        $signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
        $version = $_SERVER['HTTP_X_WEBHOOK_VERSION'] ?? '1';
        $secret = TpvSyncSecrets::get('TPVSYNC_WEBHOOK_SECRET');

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        if ($secret === '') {
            http_response_code(503);
            echo json_encode(['error' => 'Webhook not configured']);
            return;
        }
        // SEGURIDAD (HIGH): anti-replay con timestamp ±5 min. Sin esto un
        // payload+firma capturado se podría replicar tras la purga del cache
        // de idempotencia (24h). Periodo de gracia mientras el TPV todavía
        // emita firmas legacy: pasamos pero registramos.
        $ts = (int) ($_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? 0);
        if ($ts > 0 && abs(time() - $ts) > 300) {
            http_response_code(401);
            echo json_encode(['error' => 'Stale timestamp']);
            return;
        }
        if (!self::verifySignature($raw, (string) $signature, $secret, $ts)) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid signature']);
            return;
        }
        if (!in_array((string) $version, self::SUPPORTED_VERSIONS, true)) {
            http_response_code(426);
            echo json_encode([
                'error' => 'Unsupported webhook version',
                'supported' => self::SUPPORTED_VERSIONS,
                'received' => $version,
            ]);
            return;
        }

        $decoded = json_decode($raw, true);
        if (!$decoded) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid payload']);
            return;
        }

        // BUG-017: aceptar tanto evento único como batch.
        $events = $this->extractEventsFromBody($decoded);
        if (empty($events)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid payload: no events']);
            return;
        }

        // Idempotencia + filtrado batch (uno duplicado no descarta el resto)
        $toProcess = [];
        $duplicates = 0;
        foreach ($events as $event) {
            if (empty($event['event_type'])) continue;
            $idemKey = (string) ($event['idempotency_key'] ?? '');
            if ($idemKey !== '') {
                if ($this->alreadySeen($idemKey)) {
                    $duplicates++;
                    continue;
                }
                $this->markSeen($idemKey);
            }
            $toProcess[] = $event;
        }

        if (empty($toProcess) && $duplicates > 0) {
            http_response_code(200);
            echo json_encode(['ok' => true, 'duplicate' => true, 'count' => $duplicates]);
            return;
        }

        http_response_code(200);
        // Señal al dispatcher: este plugin entiende batches.
        header('X-Tpv-Batch-Supported: 1');
        echo json_encode([
            'ok' => true,
            'processed' => count($toProcess),
            'duplicates' => $duplicates,
        ]);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            flush();
        }

        // Employee válido para operaciones de stock.
        $ctx = Context::getContext();
        if (!$ctx->employee || !$ctx->employee->id) {
            try {
                $ctx->employee = new Employee(1);
            } catch (\Throwable $ignored) {
            }
        }

        foreach ($toProcess as $event) {
            try {
                $this->dispatch($event);
            } catch (\Throwable $e) {
                TpvSyncLog::error('webhook', (int) ($event['resource_id'] ?? 0),
                    'dispatch exception: ' . $e->getMessage()
                );
            }
        }
    }

    /**
     * Extrae lista de eventos del body decodificado.
     *
     *   1. Objeto único:        {"event_type":"...", ...}
     *   2. Envelope batch:      {"events":[{...},{...}]}
     *   3. Array desnudo:       [{...},{...}]
     */
    private function extractEventsFromBody(array $body): array
    {
        if (isset($body['event_type'])) return [$body];
        if (isset($body['events']) && is_array($body['events'])) return $body['events'];
        if (array_keys($body) === range(0, count($body) - 1)) return $body;
        return [];
    }

    /**
     * Verifica firma HMAC-SHA256 del webhook entrante.
     *
     * Público estático para permitir tests unitarios sin instanciar la clase
     * (que requiere PS bootstrap). Se usa también desde handle() internamente.
     */
    public static function verifySignature(string $body, string $sig, string $secret, int $timestamp = 0): bool
    {
        if ($sig === '' || $secret === '') {
            return false;
        }
        // Anti-replay: si llega timestamp lo incluimos en el material firmado.
        // Compat: aceptamos también firma legacy (solo body) para no romper
        // mientras el TPV todavía emita ambas. Tras migración: quitar legacy.
        if ($timestamp > 0) {
            $expectedTs = 'sha256=' . hash_hmac('sha256', $timestamp . "\n" . $body, $secret);
            if (hash_equals($expectedTs, $sig)) return true;
        }
        $expectedLegacy = 'sha256=' . hash_hmac('sha256', $body, $secret);
        return hash_equals($expectedLegacy, $sig);
    }

    private function alreadySeen(string $idemKey): bool
    {
        // Purga oportunista de entradas >24h (sin depender de cron)
        Db::getInstance()->execute(
            'DELETE FROM ' . _DB_PREFIX_ . 'tpv_sync_webhook_idem
             WHERE seen_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)'
        );
        $v = Db::getInstance()->getValue(
            'SELECT 1 FROM ' . _DB_PREFIX_ . 'tpv_sync_webhook_idem
             WHERE idempotency_key = "' . pSQL($idemKey) . '"'
        );
        return !empty($v);
    }

    private function markSeen(string $idemKey): void
    {
        Db::getInstance()->insert('tpv_sync_webhook_idem', [
            'idempotency_key' => pSQL(Tools::substr($idemKey, 0, 128)),
            'seen_at' => date('Y-m-d H:i:s'),
        ], false, true, Db::INSERT_IGNORE);
    }

    private function dispatch(array $payload): void
    {
        $eventType = (string) $payload['event_type'];
        $resourceId = (int) ($payload['resource_id'] ?? 0);
        $fields = $payload['changed_fields'] ?? [];

        TpvSyncLog::write('ok', 'webhook', $resourceId, $eventType . ' recibido', 'webhook');

        $api = new TpvSyncApiClient();
        $products = new TpvSyncProduct($api);
        $orders = new TpvSyncOrder($api);

        switch ($eventType) {
            case 'product.created':
            case 'product.updated':
                if ($resourceId > 0) {
                    $products->updateFromTpv($resourceId);
                }
                break;
            case 'product.deleted':
                if ($resourceId > 0) {
                    $products->deleteProductFromTpv($resourceId);
                }
                break;
            case 'stock.adjusted':
                $tpvId = (int) ($fields['product_id'] ?? $resourceId);
                $qty = (float) ($fields['quantity'] ?? 0);
                if ($tpvId > 0 && $this->acceptStockEvent($tpvId, 'product', $payload)) {
                    $products->updateStock($tpvId, $qty);
                }
                break;
            case 'variant.stock_adjusted':
                $povId = (int) ($fields['product_option_value_id'] ?? $resourceId);
                $qty = (float) ($fields['quantity'] ?? 0);
                if ($povId > 0 && $this->acceptStockEvent($povId, 'variant', $payload)) {
                    $products->updateVariantStock($povId, $qty);
                }
                break;
            case 'special.created':
            case 'special.deleted':
            case 'variants.updated':
            case 'variant.created':
            case 'option.created':
            case 'option.deleted':
                $tpvId = (int) ($fields['product_id'] ?? $resourceId);
                if ($tpvId > 0) {
                    $products->updateFromTpv($tpvId);
                }
                break;
            case 'order.created':
                // Venta física en el TPV — solo log. La tienda PS no necesita
                // reflejar esto; el stock ya lo sincroniza el evento stock.adjusted.
                break;
            case 'order.status_changed':
                $tpvStatusId = (int) ($fields['order_status_id'] ?? 0);
                if ($resourceId > 0 && $tpvStatusId > 0 && Configuration::get('TPVSYNC_MODULE_ORDERS')) {
                    $orders->updatePsStatus($resourceId, $tpvStatusId);
                }
                break;
            case 'return.created':
                $tpvOrderId = (int) ($fields['order_id'] ?? 0);
                if ($tpvOrderId > 0) {
                    $orders->createPsRefund($tpvOrderId, $fields);
                }
                break;
            case 'category.created':
            case 'category.updated':
            case 'category.deleted':
                // Categorías: flujo inverso aporta poco (las altas suelen
                // nacer en PS, no en el TPV). Implementación pendiente si un
                // cliente lo pide.
                break;

            case 'customer.created':
            case 'customer.updated':
                if ($resourceId <= 0) break;
                require_once _PS_MODULE_DIR_ . 'tpvsync/classes/TpvSyncCustomer.php';
                $customersSvc = new TpvSyncCustomer($api);
                // El payload del webhook trae solo resource_id + array de
                // changed_fields (nombres). Hacemos GET /customers/{id} a la
                // API para traer datos frescos antes del upsert en PS.
                $r = $api->get("/customers/$resourceId");
                $data = $r['data'] ?? null;
                if (is_array($data) && !empty($data['email'])) {
                    $customersSvc->syncCustomerFromTpv($resourceId, $data);
                } else {
                    TpvSyncLog::warn('customer', $resourceId,
                        "GET /customers/$resourceId devolvió payload vacío"
                    );
                }
                break;

            case 'customer.deleted':
                if ($resourceId <= 0) break;
                require_once _PS_MODULE_DIR_ . 'tpvsync/classes/TpvSyncCustomer.php';
                (new TpvSyncCustomer($api))->deletePsCustomerByTpvId($resourceId);
                break;
            case 'csv.imported':
                // Reimportar todo (spawn de cron single-shot si PS lo soporta)
                $cmd = 'php ' . _PS_MODULE_DIR_ . 'tpvsync/scripts/cron_import.php';
                @exec($cmd . ' >/dev/null 2>&1 &');
                break;
            default:
                // evento no manejado
                break;
        }
    }

    /**
     * Descarta eventos de stock anteriores al último aplicado para ese recurso.
     * Resource key: scope:id (product:123, variant:456).
     *
     * BUG-015: prefiere `event_id` (auto_increment monotónico del TPV) sobre
     * `timestamp` (precisión 1 segundo, colisiona bajo carga). La columna
     * `last_ts` reutiliza su tipo INT — almacena event_id cuando viene, o
     * timestamp Unix como fallback. Migración compatible: nada que cambiar
     * en BD.
     */
    private function acceptStockEvent(int $resourceId, string $scope, array $payload): bool
    {
        // Preferir event_id si llega — comparación monotónica sin colisiones.
        $useEventId = isset($payload['event_id']) && (int) $payload['event_id'] > 0;
        if ($useEventId) {
            $incoming = (int) $payload['event_id'];
        } else {
            $ts = (string) ($payload['timestamp'] ?? '');
            if ($ts === '') {
                return true;
            }
            $incoming = strtotime($ts);
            if ($incoming === false) {
                return true;
            }
        }

        // Namespace en el resource_key para que event_id (~1) y timestamps Unix
        // (~1.7e9) no se mezclen en la misma columna `last_ts`. Migración:
        // tras desplegar event_id, las filas viejas con timestamps quedan
        // obsoletas y simplemente se reinicia la cuenta por resource bajo el
        // namespace nuevo (no se descarta nada legítimo — el primer evento
        // post-deploy crea la fila eid:scope:id desde 0).
        $namespace = $useEventId ? 'eid' : 'ts';
        $key = $namespace . ':' . $scope . ':' . $resourceId;
        $last = (int) Db::getInstance()->getValue(
            'SELECT last_ts FROM ' . _DB_PREFIX_ . 'tpv_sync_stock_ts
             WHERE resource_key = "' . pSQL($key) . '"'
        );
        if ($incoming <= $last) {
            $tag = $useEventId ? "event_id" : "ts";
            TpvSyncLog::skip('stock', $resourceId,
                "stock out-of-order $tag=$incoming <= last=$last ($scope)"
            );
            return false;
        }
        Db::getInstance()->execute(
            'INSERT INTO ' . _DB_PREFIX_ . 'tpv_sync_stock_ts (resource_key, last_ts)
             VALUES ("' . pSQL($key) . '", ' . (int) $incoming . ')
             ON DUPLICATE KEY UPDATE last_ts = ' . (int) $incoming
        );
        return true;
    }
}
