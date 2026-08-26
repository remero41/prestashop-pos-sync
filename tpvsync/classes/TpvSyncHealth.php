<?php
declare(strict_types=1);
/**
 * Estado de salud de la sincronización — las señales del panel.
 *
 * El estándar del nicho diagnostica con DOS señales, y la gracia está en
 * cruzarlas:
 *
 *   marca fresca + pendientes creciendo -> el ejecutor va bien, la cola lo
 *                                          desborda (subir frecuencia/lote)
 *   marca obsoleta                      -> la sincronización está rota
 *                                          (mirar credenciales/cron/TPV)
 *
 * Cada señal por separado no distingue esos dos casos, que piden acciones
 * opuestas. Por eso el panel pide las dos.
 *
 * Los umbrales son LOS MISMOS que en el conector de WooCommerce, y eso es
 * deliberado: los dos plugins atienden al mismo comerciante contra el mismo
 * TPV. Si un panel pinta ámbar a los 50 pendientes y el otro a los 200,
 * quien lleva las dos tiendas no puede comparar.
 *
 * Los umbrales viven aquí como funciones PURAS, sin PrestaShop, para poder
 * probarlos: es la única parte con lógica de verdad. El render se comprueba
 * mirando la pantalla.
 */
class TpvSyncHealth
{
    /** Marca de sincronización: verde por debajo, ámbar por debajo de la 2ª. */
    const FRESH_OK_SEC   = 900;   // 15 min
    const FRESH_WARN_SEC = 3600;  // 1 h

    /** Pendientes en cola a partir de los cuales el estándar pinta ámbar. */
    const QUEUE_WARN = 50;

    /**
     * Estados del log que significan "esto NO salió bien".
     *
     * El criterio va por la lista de FALLOS, no por la de éxitos. Aquí la
     * columna `status` está mejor acotada que en el conector de Woo —
     * TpvSyncLog expone cuatro métodos nombrados (ok/error/skip/warn) y el
     * barrido confirma que solo se usan esos—, pero write() es público y
     * admite cualquier cadena. Ir por los fallos sobrevive a que alguien
     * añada un estado sano mañana; ir por status='ok' haría que ese estado
     * nuevo se leyera como "nunca sincronizado".
     *
     * 'skip' es una DECISIÓN (no había nada que hacer) y 'warn' un aviso:
     * ninguno de los dos es un fallo de sincronización.
     */
    const FAILURE_STATUSES = ['error', 'abandoned'];

    /**
     * Nivel de la marca de última sincronización correcta.
     *
     * @param int|null $ageSeconds segundos desde la última sync OK.
     *                             null = nunca se ha sincronizado, que NO es
     *                             lo mismo que "hace mucho": tratarlo como 0
     *                             daría una antigüedad absurda (desde 1970) y
     *                             el nivel correcto por accidente.
     */
    public static function freshnessLevel($ageSeconds)
    {
        if ($ageSeconds === null) {
            return 'err';
        }
        if ($ageSeconds < self::FRESH_OK_SEC) {
            return 'ok';
        }
        if ($ageSeconds < self::FRESH_WARN_SEC) {
            return 'warn';
        }

        return 'err';
    }

    /**
     * Nivel de la cola.
     *
     * Una entrada abandonada pesa más que mil pendientes: las pendientes se
     * reintentan solas con backoff, las abandonadas ya no. Son dato perdido
     * salvo acción manual, así que mandan sobre el contador.
     */
    public static function queueLevel($pending, $abandoned)
    {
        if ((int) $abandoned > 0) {
            return 'err';
        }
        if ((int) $pending >= self::QUEUE_WARN) {
            return 'warn';
        }

        return 'ok';
    }

    /**
     * Cruza las dos señales y devuelve QUÉ pasa, no solo un color.
     *
     * Un color le dice al comerciante que algo va mal; esto le dice qué
     * mirar, que es la diferencia entre un panel y un semáforo.
     *
     * @return array kind: healthy | backlog | stalled | dropped
     */
    public static function diagnose($ageSeconds, $pending, $abandoned)
    {
        $fresh = self::freshnessLevel($ageSeconds);
        $queue = self::queueLevel($pending, $abandoned);

        // Abandonadas primero: es lo único que ya no se arregla solo.
        if ((int) $abandoned > 0) {
            return ['kind' => 'dropped', 'level' => 'err'];
        }

        // Si el ejecutor está parado, un backlog es CONSECUENCIA, no la causa:
        // mandar al comerciante a mirar el tamaño del lote sería mandarlo mal.
        if ($fresh === 'err') {
            return ['kind' => 'stalled', 'level' => 'err'];
        }

        if ($queue === 'warn') {
            return ['kind' => 'backlog', 'level' => 'warn'];
        }

        if ($fresh === 'warn') {
            return ['kind' => 'stalled', 'level' => 'warn'];
        }

        return ['kind' => 'healthy', 'level' => 'ok'];
    }

    // ─── Lectura de las señales (esto ya toca la BD) ─────────────────────────

    /** Fragmento SQL con la lista de estados de fallo, ya escapada. */
    private static function failureSqlList()
    {
        $out = [];
        foreach (self::FAILURE_STATUSES as $s) {
            $out[] = "'" . pSQL($s) . "'";
        }

        return implode(',', $out);
    }

    /**
     * Segundos desde la última sincronización CORRECTA, o null si no hay.
     *
     * La tabla tpv_sync_log ya existía y nadie la consultaba para esto. Aquí
     * la query sí está cubierta por un índice: idx_status (status, created_at).
     */
    public static function lastOkAge()
    {
        $ts = Db::getInstance()->getValue(
            'SELECT created_at FROM ' . _DB_PREFIX_ . 'tpv_sync_log
             WHERE status NOT IN (' . self::failureSqlList() . ')
             ORDER BY id DESC LIMIT 1'
        );
        if (!$ts) {
            return null;
        }

        return max(0, time() - strtotime((string) $ts));
    }

    /** Último error registrado: mensaje y cuándo. null si no hay ninguno. */
    public static function lastError()
    {
        $row = Db::getInstance()->getRow(
            'SELECT message, event_type, created_at FROM ' . _DB_PREFIX_ . 'tpv_sync_log
             WHERE status IN (' . self::failureSqlList() . ')
             ORDER BY id DESC LIMIT 1'
        );

        return $row ?: null;
    }

    /** Las señales del panel, listas para pintar. */
    public static function snapshot()
    {
        $pending = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'tpv_sync_queue WHERE status = "pending"'
        );
        $abandoned = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'tpv_sync_queue WHERE status = "abandoned"'
        );
        $age = self::lastOkAge();

        return [
            'age' => $age,
            'freshness' => self::freshnessLevel($age),
            'pending' => $pending,
            'abandoned' => $abandoned,
            'queue_level' => self::queueLevel($pending, $abandoned),
            'last_error' => self::lastError(),
            'diagnosis' => self::diagnose($age, $pending, $abandoned),
        ];
    }
}
