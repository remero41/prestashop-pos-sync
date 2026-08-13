<?php
declare(strict_types=1);

require_once _PS_MODULE_DIR_ . 'tpvsync/classes/TpvSyncSecrets.php';

/**
 * FrontController AJAX que procesa UN lote de sincronización inicial.
 *
 * URL pública: https://<shop>/module/tpvsync/syncbatch?action=push|pull&token=<csrf>
 *
 * Existe para que el wizard de "primera sincronización" pueda procesar
 * catálogos grandes sin caer en el `max_execution_time` de PHP. La UI llama
 * a este endpoint en bucle (uno por lote): cada llamada es una request HTTP
 * nueva → cada lote tiene su propio max_execution_time fresco. Mientras la
 * versión "todo en una request" se comía 30s y reventaba con catálogos de
 * 4000+ productos, este patrón escala indefinidamente.
 *
 * Auth: token corto generado al inicio del wizard y guardado en Configuration.
 * NO usamos la sesión admin de PS porque el endpoint vive en el front
 * controller (rama pública); además esto evita problemas de timeout de
 * sesión durante imports largos.
 */
class TpvSyncSyncbatchModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $auth = false;
    public $ajax = true;

    public function display()
    {
        // Liberar el lock de sesión PHP cuanto antes. Sin esto, PHP mantiene
        // un lock exclusivo en el archivo de sesión durante toda la request
        // (~5-10s en un push), serializando peticiones concurrentes del
        // mismo usuario. Como este endpoint NO usa la sesión (auth por token
        // propio), cerrarla libera el lock y permite paralelismo real.
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }

        // Cada lote procesa N productos en serie. Con N=100 y ~50ms por
        // producto, el peor caso ronda los 5-10s — dentro del límite por
        // defecto. Aun así pedimos 120s explícitamente como cinturón de
        // seguridad: hay productos con muchas variantes/imágenes que tardan
        // 1-2s ellos solos. Sin esto, php.ini=30 mataba lotes ocasionalmente.
        @set_time_limit(120);
        @ini_set('max_execution_time', '120');

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $action = (string) Tools::getValue('action', '');
        $token  = (string) Tools::getValue('token', '');

        $expected = TpvSyncSecrets::get('TPVSYNC_BATCH_TOKEN');
        if ($expected === '' || !hash_equals($expected, $token)) {
            http_response_code(403);
            echo json_encode(['error' => 'invalid_token']);
            exit;
        }

        if (!in_array($action, ['push', 'pull', 'count', 'reconcile', 'analyze_divergence', 'divergence_action', 'check_sync'], true)) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_action']);
            exit;
        }

        // Si la petición lleva ?principal=tpv|ps, persistimos esa decisión.
        // El JS lo envía en la PRIMERA llamada del wizard inicial, derivado del
        // botón pulsado ("Manda TPV" / "Manda PrestaShop"). En llamadas
        // sucesivas (lotes 2..N) el parámetro no llega y no machaca nada.
        $principal = (string) Tools::getValue('principal', '');
        if (in_array($principal, ['tpv', 'ps'], true)) {
            Configuration::updateValue('TPVSYNC_PRINCIPAL', $principal);
        }

        // El módulo expone los dos métodos públicos processPushBatch /
        // processPullBatch. Cada uno persiste el offset entre llamadas y
        // devuelve `done=true` cuando ya no quedan filas.
        // `count` es para el peek del wizard (asíncrono, no bloquea el render).
        /** @var Tpvsync $module */
        $module = $this->module;
        try {
            if ($action === 'push') {
                $r = $module->processPushBatch();
            } elseif ($action === 'pull') {
                $r = $module->processPullBatch();
            } elseif ($action === 'reconcile') {
                $r = $module->processReconcileBatch();
            } elseif ($action === 'analyze_divergence') {
                $r = $module->analyzeDivergence();
            } elseif ($action === 'divergence_action') {
                $r = $module->processDivergenceAction();
            } elseif ($action === 'check_sync') {
                // Acción unificada del nuevo botón "Comprobar sincronización":
                // devuelve los 4 números (synced/islands_ps/islands_tpv/divergences)
                // sin pedir al usuario reconcile + analyze por separado.
                $r = $module->runSyncStatusCheck();
            } else { // count
                $r = $module->fetchTpvCount();
            }
            echo json_encode($r);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'error'     => 'exception',
                'message'   => $e->getMessage(),
                'done'      => false,
                'processed' => 0,
                'total'     => 0,
            ]);
        }
        exit;
    }

    /**
     * No queremos que PrestaShop renderice el layout HTML del front en una
     * respuesta JSON: el frame, header y footer del tema rompen el JSON.
     */
    public function initContent()
    {
    }

    public function setMedia($isNewTheme = false)
    {
    }
}
