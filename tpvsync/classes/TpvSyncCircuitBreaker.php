<?php
declare(strict_types=1);
/**
 * Circuit breaker sencillo con estado persistido en `configuration`.
 *
 * Tres estados: closed (normal), open (todos los requests bloqueados), half-open
 * (permitimos 1 probe tras el cooldown para ver si el backend recuperó).
 *
 * Umbrales:
 *   - 5 fallos consecutivos → open durante 60s.
 *   - Tras cooldown, half-open: un probe; si sale ok, closed; si falla, open 60s más.
 *
 * Idéntica semántica al TPV_Sync_Circuit_Breaker del plugin WC para que el
 * comportamiento sea predecible entre integraciones.
 */
class TpvSyncCircuitBreaker
{
    private const MAX_FAILS = 5;
    private const COOLDOWN = 60; // segundos

    public function allowRequest(): bool
    {
        $state = (string) Configuration::get('TPVSYNC_CB_STATE') ?: 'closed';
        $openUntil = (int) Configuration::get('TPVSYNC_CB_OPEN_UNTIL');

        if ($state === 'open') {
            if (time() >= $openUntil) {
                // Transición a half-open: dejamos pasar un probe.
                Configuration::updateValue('TPVSYNC_CB_STATE', 'half-open');
                return true;
            }
            return false;
        }
        // closed o half-open → permitimos
        return true;
    }

    public function recordSuccess(): void
    {
        $state = (string) Configuration::get('TPVSYNC_CB_STATE') ?: 'closed';
        if ($state !== 'closed') {
            Configuration::updateValue('TPVSYNC_CB_STATE', 'closed');
        }
        Configuration::updateValue('TPVSYNC_CB_FAILS', 0);
        Configuration::updateValue('TPVSYNC_CB_OPEN_UNTIL', 0);
    }

    public function recordFailure(): void
    {
        $state = (string) Configuration::get('TPVSYNC_CB_STATE') ?: 'closed';

        if ($state === 'half-open') {
            // El probe falló → reabrir.
            $this->open();
            return;
        }
        $fails = (int) Configuration::get('TPVSYNC_CB_FAILS') + 1;
        Configuration::updateValue('TPVSYNC_CB_FAILS', $fails);
        if ($fails >= self::MAX_FAILS) {
            $this->open();
        }
    }

    private function open(): void
    {
        Configuration::updateValue('TPVSYNC_CB_STATE', 'open');
        Configuration::updateValue('TPVSYNC_CB_OPEN_UNTIL', time() + self::COOLDOWN);
        // Nota: no reseteamos FAILS — se reinicia en el próximo recordSuccess().
    }
}
