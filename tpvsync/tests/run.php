<?php
/**
 * Runner de tests del modulo PrestaShop tpvsync.
 *
 * El modulo no tenia NINGUN test hasta la auditoria de 2026-08-26. Se replica el
 * estilo del runner de la API (api/v1/tests/run_tests.php): una sola forma de
 * ejecutar tests en todo el parque, sin composer ni PHPUnit.
 *
 * Los tests NO arrancan PrestaShop. Cubren las piezas puras (la decision de si
 * una respuesta HTTP fue bien, y el valor que devuelve cada camino de
 * onPsRefund) con la respuesta simulada.
 *
 * Uso: php tests/run.php
 */

if (!defined('_PS_VERSION_')) {
    define('_PS_VERSION_', '8.1.0');
}

final class PrestaTestRunner
{
    private $passed = 0;
    private $failed = 0;
    private $failures = [];
    private $suiteName = '';

    public function suite($name)
    {
        $this->suiteName = $name;
        echo "\n\033[1;34m══ $name ══\033[0m\n";
    }

    public function test($name, callable $fn)
    {
        $before = $this->failed;
        try {
            $fn($this);
        } catch (Throwable $e) {
            $this->failed++;
            $this->failures[] = "[{$this->suiteName}] $name: EXCEPCION " . $e->getMessage();
        }
        echo $this->failed === $before
            ? "  \033[32m✓\033[0m $name\n"
            : "  \033[31m✗\033[0m $name\n";
    }

    public function assert($cond, $msg)
    {
        if ($cond) {
            $this->passed++;
        } else {
            $this->failed++;
            $this->failures[] = "[{$this->suiteName}] $msg";
        }
    }

    /**
     * Igualdad estricta. El mensaje por defecto muestra esperado vs obtenido:
     * sin eso, un fallo de umbral solo dice "fallo" y hay que ir a leer el
     * test para saber que salio.
     */
    public function assertEquals($expected, $actual, $msg = '')
    {
        $ok = $expected === $actual;
        if ($msg === '') {
            $msg = sprintf('esperado %s, obtenido %s',
                var_export($expected, true), var_export($actual, true));
        } elseif (!$ok) {
            $msg .= sprintf(' (esperado %s, obtenido %s)',
                var_export($expected, true), var_export($actual, true));
        }
        $this->assert($ok, $msg);
    }

    public function summary()
    {
        $total = $this->passed + $this->failed;
        echo "\n\033[1m══ RESULTADO: {$this->passed}/{$total} pasaron";
        if ($this->failed > 0) {
            echo " · \033[31m{$this->failed} fallaron\033[0m\033[1m";
        }
        echo " ══\033[0m\n";
        if ($this->failures) {
            echo "\n\033[31mFallos:\033[0m\n";
            foreach ($this->failures as $f) {
                echo "  • $f\n";
            }
        }

        return $this->failed === 0 ? 0 : 1;
    }
}

$t = new PrestaTestRunner();

// Orden importante: test_refund_paths.php declara los stubs de PrestaShop
// (Db, OrderSlip, Validate, TpvSyncLog) y de TpvSyncQueue/TpvSyncProduct. Deben
// existir ANTES de cargar TpvSyncOrder, que los referencia.
require_once __DIR__ . '/test_api_client_parse.php';
require_once __DIR__ . '/test_refund_paths.php';
require_once __DIR__ . '/test_sync_health_panel.php';
require_once dirname(__DIR__) . '/classes/TpvSyncOrder.php';

run_api_client_parse_tests($t);
run_refund_paths_tests($t);
run_sync_health_panel_tests($t);

exit($t->summary());
