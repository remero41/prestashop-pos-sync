<?php
declare(strict_types=1);
/**
 * Cliente HTTP para la API TPV v1 desde PrestaShop.
 *
 * Réplica funcional de TPV_Sync_API_Client (WooCommerce) pero usando el
 * stack de PrestaShop:
 *   - Guzzle vía `Tools::file_get_contents_curl` directo con cURL nativo.
 *     No dependemos de Guzzle 7 porque el módulo debe funcionar en instalaciones
 *     mínimas, y PS empaqueta Guzzle 7 solo a partir de 8.x.
 *   - Tokens OAuth2 cacheados en la tabla `configuration` con TTL (sin
 *     tabla propia para evitar locking).
 *   - Circuit breaker (ver TpvSyncCircuitBreaker) para no seguir golpeando
 *     un TPV caído.
 *   - Retry automático en 429 respetando Retry-After (idéntico al plugin WC).
 *
 * Todas las firmas (method, path, body) replican la API pública del plugin WC
 * para que los handlers sean portables casi 1:1.
 */
class TpvSyncApiClient
{
    /** @var string Base URL como https://tu-tpv.ejemplo.com/api/v1 (sin trailing slash) */
    private string $baseUrl;
    private string $clientId;
    private string $clientSecret;
    private ?string $token = null;
    private int $tokenExp = 0;
    private TpvSyncCircuitBreaker $breaker;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) Configuration::get('TPVSYNC_API_URL'), '/');
        $this->clientId = (string) Configuration::get('TPVSYNC_CLIENT_ID') ?: 'prestashop';
        $this->clientSecret = TpvSyncSecrets::get('TPVSYNC_CLIENT_SECRET');
        $this->breaker = new TpvSyncCircuitBreaker();
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->clientId !== '' && $this->clientSecret !== '';
    }

    public function breaker(): TpvSyncCircuitBreaker
    {
        return $this->breaker;
    }

    // ─── Token OAuth2 ────────────────────────────────────────────────────────

    private function getToken(): string
    {
        if ($this->token !== null && time() < $this->tokenExp - 60) {
            return $this->token;
        }

        // Cache compartida cross-request en la tabla configuration (TTL manual).
        // PS no tiene transients; usamos un key + timestamp como JSON.
        $cached = TpvSyncSecrets::get('TPVSYNC_TOKEN_CACHE');
        if ($cached) {
            $decoded = json_decode((string) $cached, true);
            if (is_array($decoded) && !empty($decoded['token']) && (int) ($decoded['exp'] ?? 0) > time() + 60) {
                $this->token = (string) $decoded['token'];
                $this->tokenExp = (int) $decoded['exp'];
                return $this->token;
            }
        }

        $body = json_encode([
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);
        $resp = $this->httpRaw('POST', $this->baseUrl . '/auth/token', [
            'Content-Type' => 'application/json',
        ], $body);

        if ($resp['error']) {
            throw new RuntimeException('TPV API auth: ' . $resp['error']);
        }
        $data = json_decode((string) $resp['body'], true);
        if (empty($data['access_token'])) {
            throw new RuntimeException('TPV API sin access_token: ' . substr((string) $resp['body'], 0, 200));
        }
        $this->token = (string) $data['access_token'];
        $this->tokenExp = time() + (int) ($data['expires_in'] ?? 3600);

        TpvSyncSecrets::set('TPVSYNC_TOKEN_CACHE', json_encode([
            'token' => $this->token,
            'exp' => $this->tokenExp,
        ]));

        return $this->token;
    }

    // ─── Métodos HTTP públicos ───────────────────────────────────────────────

    public function get(string $path, array $params = []): array
    {
        if (!$this->breaker->allowRequest()) {
            return $this->breakerResponse();
        }
        $url = $this->baseUrl . $path;
        if ($params) {
            $url .= '?' . http_build_query($params);
        }
        $resp = $this->doWithRetry('GET', $path, function () use ($url) {
            return $this->httpRaw('GET', $url, $this->authHeaders(), null);
        });
        return $this->parse($resp, 'GET', $path);
    }

    /**
     * POST — opcional idempotencyKey: el servidor cachea la respuesta 24h y
     * reintentos con mismo key + mismo body devuelven la misma respuesta.
     */
    public function post(string $path, array $body = [], ?string $idempotencyKey = null): array
    {
        if (!$this->breaker->allowRequest()) {
            return $this->breakerResponse();
        }
        $send = function () use ($path, $body, $idempotencyKey) {
            $headers = $this->authHeaders();
            if ($idempotencyKey !== null) {
                $headers['Idempotency-Key'] = $idempotencyKey;
            }
            $bodyStr = json_encode($body);
            $headers = array_merge($headers, $this->signHeaders($bodyStr));
            $resp = $this->doWithRetry('POST', $path, function () use ($path, $headers, $bodyStr) {
                return $this->httpRaw('POST', $this->baseUrl . $path, $headers, $bodyStr);
            });
            return $this->parse($resp, 'POST', $path);
        };
        return $this->withHmacAutoRecovery($path, $send);
    }

    public function patch(string $path, array $body = []): array
    {
        if (!$this->breaker->allowRequest()) {
            return $this->breakerResponse();
        }
        $send = function () use ($path, $body) {
            $bodyStr = json_encode($body);
            $headers = array_merge($this->authHeaders(), $this->signHeaders($bodyStr));
            $resp = $this->doWithRetry('PATCH', $path, function () use ($path, $headers, $bodyStr) {
                return $this->httpRaw('PATCH', $this->baseUrl . $path, $headers, $bodyStr);
            });
            return $this->parse($resp, 'PATCH', $path);
        };
        return $this->withHmacAutoRecovery($path, $send);
    }

    /**
     * Caso especial: /auth/resume autenticado con HTTP Basic en vez de Bearer.
     *
     * Cuando el cliente está pausado (status=0), /auth/token devuelve 401 y por
     * tanto getToken() falla. La API expone /auth/resume con Basic auth como
     * excepción justamente para reanudarse. Aquí mandamos client_id:client_secret
     * codificados en base64 y devolvemos la misma estructura que post() para
     * que el caller no necesite saber que el camino fue distinto.
     */
    public function resumeWithBasicAuth(): array
    {
        if (!$this->breaker->allowRequest()) {
            return $this->breakerResponse();
        }
        $auth = base64_encode($this->clientId . ':' . $this->clientSecret);
        $headers = [
            'Authorization' => 'Basic ' . $auth,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ];
        $resp = $this->httpRaw('POST', $this->baseUrl . '/auth/resume', $headers, '{}');
        return $this->parse($resp, 'POST', '/auth/resume');
    }

    public function delete(string $path): array
    {
        if (!$this->breaker->allowRequest()) {
            return $this->breakerResponse();
        }
        $send = function () use ($path) {
            $headers = array_merge($this->authHeaders(), $this->signHeaders(''));
            $resp = $this->doWithRetry('DELETE', $path, function () use ($path, $headers) {
                return $this->httpRaw('DELETE', $this->baseUrl . $path, $headers, null);
            });
            return $this->parse($resp, 'DELETE', $path);
        };
        return $this->withHmacAutoRecovery($path, $send);
    }

    /**
     * Wrapper de auto-recovery silencioso para HMAC desync.
     * Espejo del que tiene el plugin WC: si POST/PATCH/DELETE devuelve 401
     * con `signature_invalid`, el módulo re-registra el webhook con un secret
     * nuevo pre-acordado y reintenta UNA vez. Si el reintento sigue fallando,
     * propaga el error original (lo procesa la queue).
     *
     * No actúa para los endpoints que forman parte de la propia recuperación
     * (/webhooks*, /auth/verify, /auth/token) para evitar bucles.
     */
    private function withHmacAutoRecovery(string $path, callable $send): array
    {
        if (!empty($GLOBALS['tpvsync_in_recovery'])) {
            return $send();
        }

        $r = $send();

        // Recovery por token revocado/expirado: aplica a TODOS los endpoints,
        // incluido /webhooks (de hecho ahí es donde más nos hace falta — es la
        // primera llamada Bearer que hace el módulo tras conectar). Pasa cuando
        // el TPV revoca el token (force_disconnect, pause, expiry) y el plugin
        // sigue usando el cache TTL. Vaciamos cache y reintentamos UNA vez.
        if ($this->isTokenInvalid($r) && $path !== '/auth/token') {
            $this->invalidateTokenCache();
            $GLOBALS['tpvsync_in_recovery'] = true;
            try {
                $r = $send();
            } finally {
                unset($GLOBALS['tpvsync_in_recovery']);
            }
        }

        // Recovery por HMAC desync: NO se aplica a los endpoints de la propia
        // recuperación (/webhooks, /auth/verify, /auth/token) para evitar bucles.
        $skipHmacRecovery = (
            $path === '/webhooks' ||
            strncmp($path, '/webhooks/', 10) === 0 ||
            $path === '/auth/verify' ||
            $path === '/auth/token'
        );

        if ($skipHmacRecovery || !$this->isHmacInvalid($r)) {
            return $r;
        }

        $GLOBALS['tpvsync_in_recovery'] = true;
        try {
            $recovered = $this->reRegisterWebhookSilently();
        } finally {
            unset($GLOBALS['tpvsync_in_recovery']);
        }
        if (!$recovered) {
            return $r;
        }
        return $send();
    }

    private function isHmacInvalid(array $r): bool
    {
        if (empty($r)) return false;
        $type = (string)($r['type'] ?? '');
        if (strpos($type, 'signature_invalid') !== false) return true;
        foreach (($r['errors'] ?? []) as $e) {
            if (($e['error'] ?? '') === 'signature_invalid') return true;
        }
        return false;
    }

    /**
     * Detecta 401 por token revocado/expirado (distinto de HMAC desync).
     * Pasa cuando el TPV ha invalidado el token (force_disconnect, pause, expiry)
     * pero el cache local sigue creyéndolo válido por TTL. La reacción correcta
     * es vaciar el cache y reintentar — getToken() pedirá uno nuevo a /auth/token.
     */
    private function isTokenInvalid(array $r): bool
    {
        if (empty($r)) return false;
        if ((int)($r['status'] ?? 0) !== 401) return false;
        $type = (string)($r['type'] ?? '');
        $errors = (array)($r['errors'] ?? []);
        $codes = ['invalid_token', 'token_revoked', 'token_expired', 'missing_token'];
        foreach ($codes as $code) {
            if (strpos($type, $code) !== false) return true;
            foreach ($errors as $e) {
                if (($e['error'] ?? '') === $code) return true;
            }
        }
        // Si llega 401 sin error code claro, asumimos token-related antes que
        // signature (ya filtrado arriba en isHmacInvalid). Mejor ser laxo aquí:
        // un retry con token fresco es barato y la alternativa es quedarse colgado.
        return true;
    }

    /**
     * Vacía el cache de token (memoria + Configuration). Llamar cuando el TPV
     * devuelve 401 para forzar a getToken() a pedir uno nuevo en la siguiente
     * llamada.
     */
    private function invalidateTokenCache(): void
    {
        $this->token = null;
        $this->tokenExp = 0;
        TpvSyncSecrets::set('TPVSYNC_TOKEN_CACHE', '');
    }

    /**
     * Re-registra el webhook con un secret nuevo pre-acordado. El POST
     * /webhooks de la API ya hace dedup (DELETE FROM ... WHERE client+url),
     * así que no necesitamos borrar el viejo (que además fallaría por HMAC).
     */
    private function reRegisterWebhookSilently(): bool
    {
        $newSecret = bin2hex(random_bytes(32));
        $catalogOn = (bool) \Configuration::get('TPVSYNC_MODULE_CATALOG');
        $ordersOn  = (bool) \Configuration::get('TPVSYNC_MODULE_ORDERS');
        $events = array_values(array_filter([
            $catalogOn ? 'product.created' : null,
            $catalogOn ? 'product.updated' : null,
            $catalogOn ? 'product.deleted' : null,
            $catalogOn ? 'stock.adjusted' : null,
            $catalogOn ? 'special.created' : null,
            $catalogOn ? 'special.deleted' : null,
            $catalogOn ? 'variant.created' : null,
            $catalogOn ? 'variants.updated' : null,
            $catalogOn ? 'option.created' : null,
            $catalogOn ? 'option.deleted' : null,
            $catalogOn ? 'csv.imported' : null,
            $ordersOn ? 'order.created' : null,
            $ordersOn ? 'order.payment_changed' : null,
            $ordersOn ? 'return.created' : null,
            $ordersOn ? 'return.deleted' : null,
        ]));
        $url = \Context::getContext()->link->getModuleLink(
            'tpvsync', 'webhook', [], \Configuration::get('PS_SSL_ENABLED') ? true : null
        );
        $r = $this->post('/webhooks', [
            'url'    => $url,
            'secret' => $newSecret,
            'events' => $events,
        ]);
        if (!empty($r['data']['webhook_id'])) {
            \Configuration::updateValue('TPVSYNC_WEBHOOK_ID', (int)$r['data']['webhook_id']);
            TpvSyncSecrets::set('TPVSYNC_WEBHOOK_SECRET', (string)($r['data']['secret'] ?? $newSecret));
            \Configuration::updateValue('TPVSYNC_HEALTH_CHECKED_AT', 0);
            return true;
        }
        return false;
    }

    /**
     * POST /batch — 50 operaciones máx por request. Se hace chunk automático.
     *
     * La API envuelve la respuesta con `data` por convención: el shape real
     * es `{"data":{"results":[...]}}`, así que miramos ambos niveles (en
     * caso de que el servidor cambie el envelope).
     */
    public function batch(array $operations): array
    {
        if (empty($operations)) {
            return ['results' => []];
        }
        $results = [];
        foreach (array_chunk($operations, 50) as $chunk) {
            $r = $this->post('/batch', ['operations' => $chunk]);
            $batch = $r['data']['results'] ?? $r['results'] ?? [];
            if (is_array($batch)) {
                $results = array_merge($results, $batch);
            }
        }
        return ['results' => $results];
    }

    /** Recoge todas las páginas concatenando `data` hasta que se agote `cursor`. */
    public function getAll(string $path, array $params = []): array
    {
        $params['per_page'] = 100;
        $all = [];
        $cursor = null;
        do {
            if ($cursor) {
                $params['cursor'] = $cursor;
            }
            $r = $this->get($path, $params);
            $items = $r['data'] ?? [];
            $all = array_merge($all, $items);
            $cursor = $r['meta']['cursor'] ?? null;
        } while ($cursor && count($items) > 0);
        return $all;
    }

    // ─── Ciclo retry 429 ─────────────────────────────────────────────────────
    //
    // La API TPV limita 200 req/min en writes. Cuando responde 429, manda
    // `Retry-After` (segundos). Reintentamos hasta 3 veces respetando ese
    // header, con jitter. Total wait cap: 90s (evita bloquear admin-ajax).

    /** @param callable():array $fn devuelve el $resp de httpRaw */
    private function doWithRetry(string $method, string $path, callable $fn): array
    {
        $maxAttempts = 3;
        $maxTotalWait = 90;
        $waited = 0;
        $last = null;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $last = $fn();
            $code = (int) ($last['code'] ?? 0);
            if ($code !== 429) {
                return $last;
            }
            $retryAfter = $last['headers']['retry-after'] ?? '';
            $sleep = 1;
            if ($retryAfter !== '') {
                if (ctype_digit((string) $retryAfter)) {
                    $sleep = (int) $retryAfter;
                } else {
                    $ts = strtotime((string) $retryAfter);
                    if ($ts !== false) {
                        $sleep = max(1, $ts - time());
                    }
                }
            }
            $sleep = min(60, max(1, $sleep)) + random_int(0, 1);
            if ($waited + $sleep > $maxTotalWait || $attempt === $maxAttempts) {
                return $last;
            }
            sleep($sleep);
            $waited += $sleep;
        }
        return $last ?? ['code' => 0, 'body' => '', 'headers' => [], 'error' => 'retry loop'];
    }

    // ─── Core HTTP: cURL ─────────────────────────────────────────────────────

    /**
     * @return array{code:int, body:string, headers:array<string,string>, error:?string}
     */
    private function httpRaw(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => $this->flattenHeaders($headers),
        ]);
        if ($body !== null && in_array($method, ['POST', 'PATCH', 'PUT', 'DELETE'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $parsedHeaders = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $line) use (&$parsedHeaders) {
            $len = strlen($line);
            if (strpos($line, ':') !== false) {
                [$k, $v] = array_map('trim', explode(':', $line, 2));
                $parsedHeaders[strtolower($k)] = $v;
            }
            return $len;
        });

        $respBody = curl_exec($ch);
        $error = null;
        if ($respBody === false) {
            $error = curl_error($ch) ?: 'curl error';
        }
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'code' => $code,
            'body' => (string) $respBody,
            'headers' => $parsedHeaders,
            'error' => $error,
        ];
    }

    private function flattenHeaders(array $h): array
    {
        $out = [];
        foreach ($h as $k => $v) {
            $out[] = $k . ': ' . $v;
        }
        return $out;
    }

    private function authHeaders(): array
    {
        $lang = 'es';
        if (isset(Context::getContext()->language)) {
            $iso = Context::getContext()->language->iso_code ?? 'es';
            $lang = strtolower(substr((string) $iso, 0, 2)) ?: 'es';
        }
        return [
            'Authorization' => 'Bearer ' . $this->getToken(),
            'Content-Type' => 'application/json',
            'Accept' => 'application/problem+json, application/json',
            'Accept-Language' => $lang,
            'X-Price-Format' => 'gross',
            'X-Client-Version' => 'tpvsync-ps/' . TpvSync::VERSION,
            // X-Channel: el TPV usa este header para enrutar el matching
            // multi-canal vía api_external_mapping. Permite que un mismo TPV
            // sirva a PS, WC y Shopify a la vez sin que sus mappings se pisen.
            'X-Channel' => 'prestashop',
        ];
    }

    /**
     * Firma HMAC-SHA256(body + "\n" + ts, shared_secret) de requests SALIENTES.
     *
     * OJO: el secret que firma outbound (PS→TPV) es el `shared_secret` de
     * `api_clients`, NO el `secret` del webhook (que firma inbound TPV→PS).
     * Son dos secretos distintos con propósitos distintos.
     *
     * Por simplicidad, el módulo NO firma outbound salvo que el admin grabe
     * un secret específico en TPVSYNC_SHARED_SECRET. Los clientes creados con
     * `setup.php --create-client` nacen con `signing_required=0` así que
     * Bearer OAuth2 es suficiente para identificarlos.
     *
     * Si el admin del TPV habilita `signing_required=1` para el cliente, hay
     * que grabar ese mismo `shared_secret` en Configuration aquí. No se genera
     * automáticamente porque tiene que coincidir con lo que hay en BD del TPV.
     */
    private function signHeaders(string $body): array
    {
        $secret = TpvSyncSecrets::get('TPVSYNC_SHARED_SECRET');
        if ($secret === '') {
            return [];
        }
        $ts = (string) time();
        $mac = hash_hmac('sha256', $body . "\n" . $ts, $secret);
        return [
            'X-Timestamp' => $ts,
            'X-Signature' => 'sha256=' . $mac,
        ];
    }

    private function parse(array $resp, string $method, string $path): array
    {
        if (!empty($resp['error'])) {
            TpvSyncLog::error('api', 0, $method . ' ' . $path . ': ' . $resp['error']);
            $this->breaker->recordFailure();
            // _status=0: no hubo respuesta HTTP (DNS, timeout, TLS). _ok=false.
            return ['error' => $resp['error'], '_status' => 0, '_ok' => false];
        }
        $code = (int) $resp['code'];
        $body = json_decode((string) $resp['body'], true) ?? [];

        if ($code >= 500) {
            $msg = $body['errors'][0]['message'] ?? "HTTP $code";
            TpvSyncLog::error('api', 0, "$method $path → $code: $msg");
            $this->breaker->recordFailure();
        } elseif ($code >= 400) {
            $msg = $body['errors'][0]['message'] ?? "HTTP $code";
            TpvSyncLog::error('api', 0, "$method $path → $code: $msg");
            // 4xx = cliente: backend responde → breaker success.
            $this->breaker->recordSuccess();
        } else {
            $this->breaker->recordSuccess();
        }

        return self::decide($code, $body);
    }

    /**
     * Enriquece el cuerpo de la respuesta con el veredicto HTTP.
     *
     * BUG-A (auditoria 2026-08-26): parse() devolvia SOLO el cuerpo. El status se
     * calculaba, alimentaba al breaker y al log, y se DESCARTABA. Siete puntos de
     * este modulo concluian exito con "empty(errors) && empty(error)" — y este
     * mismo cliente NEGOCIA application/problem+json en el Accept (linea ~489),
     * un formato de error que NO lleva la clave 'errors'. Resultado: un 4xx se
     * daba por bueno. Igual un 502 de proxy (cuerpo HTML, json_decode → []) o un
     * 429 tras agotar reintentos.
     *
     * Guion bajo en las claves para no colisionar con el payload de la API.
     *
     * Pura a proposito (static, sin $this, sin PrestaShop): es lo que permite
     * testearla sin arrancar el framework. No la hagas depender del estado.
     */
    public static function decide($code, array $body)
    {
        $body['_status'] = (int) $code;
        $body['_ok'] = ($code >= 200 && $code < 300);

        return $body;
    }

    /**
     * Exito = lo dice el status HTTP. NUNCA "el cuerpo no trae la clave errors":
     * problem+json, el HTML de un 502 y un 429 agotado no la traen, y colaban
     * como exito (BUG-A).
     *
     * El fallback por cuerpo solo actua si quien llama no paso por decide(), e
     * incluye 'type' para unificar el antipatron hermano: varios sitios miraban
     * esa clave y acertaban POR ACCIDENTE — detectaban el error por la forma del
     * cuerpo, no por el status. Funcionaban, pero enmascaraban el diagnostico.
     *
     * Nota: breakerResponse() sigue trayendo error/errors, asi que el circuito
     * abierto se detecta por el fallback aunque nunca pase por decide().
     */
    public static function fueBien($r)
    {
        if (is_array($r) && array_key_exists('_ok', $r)) {
            return (bool) $r['_ok'];
        }

        return is_array($r)
            && empty($r['errors'])
            && empty($r['error'])
            && empty($r['type']);
    }

    private function breakerResponse(): array
    {
        return [
            'error' => 'circuit_open',
            'errors' => [[
                'error' => 'circuit_open',
                'message' => 'Circuit breaker abierto — backend indispuesto',
            ]],
        ];
    }
}
