<?php
declare(strict_types=1);
/**
 * FrontController que recibe los webhooks del TPV.
 *
 * URL pública: https://<shop>/module/tpvsync/webhook
 * (PS resuelve ese path a esta clase: TpvSyncWebhookModuleFrontController).
 *
 * El controller solo orquesta: firma, idempotencia y dispatch viven en
 * TpvSyncWebhook para que sean testables sin bootstrap del front.
 */
class TpvSyncWebhookModuleFrontController extends ModuleFrontController
{
    /** Webhook → sin sesión, sin SSL-forced-redirects. */
    public $ssl = true;
    public $auth = false;
    public $ajax = true;

    public function display()
    {
        // El handler escribe el status code y el body directamente.
        $handler = new TpvSyncWebhook();
        $handler->handle();
        exit;
    }

    /**
     * PS ejecuta init() antes de display(); por defecto renderiza layout
     * HTML, nosotros no queremos eso en un endpoint máquina→máquina.
     */
    public function initContent()
    {
        // Skip parent::initContent() — evita cargar tema y encabezados HTML
    }
}
