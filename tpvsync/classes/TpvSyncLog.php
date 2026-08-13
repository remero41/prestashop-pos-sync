<?php
declare(strict_types=1);
/**
 * Logger compacto — escribe en la tabla PREFIX_tpv_sync_log.
 *
 * Diseño: métodos estáticos en vez de instanciar. Los hooks/servicios del
 * módulo escriben muchos eventos y no queremos que un fallo de log rompa
 * el flow del pedido/producto. Cualquier excepción se silencia.
 */
class TpvSyncLog
{
    public static function ok(string $resource, int $resourceId, string $message): void
    {
        self::write('ok', $resource, $resourceId, $message);
    }

    public static function error(string $resource, int $resourceId, string $message): void
    {
        self::write('error', $resource, $resourceId, $message);
    }

    public static function skip(string $resource, int $resourceId, string $message): void
    {
        self::write('skip', $resource, $resourceId, $message);
    }

    public static function warn(string $resource, int $resourceId, string $message): void
    {
        self::write('warn', $resource, $resourceId, $message);
    }

    public static function write(string $status, string $resource, int $resourceId, string $message, string $eventType = 'sync'): void
    {
        try {
            Db::getInstance()->insert('tpv_sync_log', [
                'event_type' => pSQL($eventType),
                'resource' => pSQL($resource),
                'resource_id' => (int) $resourceId,
                'status' => pSQL($status),
                'message' => pSQL(Tools::substr($message, 0, 5000)),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Logger no puede romper flows. Opcional: PrestaShopLogger como fallback.
            try {
                PrestaShopLogger::addLog('[tpvsync] ' . $status . ' ' . $resource . ':' . $resourceId . ' ' . $message, 3);
            } catch (\Throwable $ignored) {
            }
        }
    }
}
