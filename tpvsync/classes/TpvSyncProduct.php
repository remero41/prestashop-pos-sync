<?php
declare(strict_types=1);
/**
 * Sincronización de productos — PrestaShop ↔ TPV.
 *
 * Mapea entre el modelo de catálogo de PS (Product + Combination + StockAvailable)
 * y el modelo de la API TPV (product + options + option_values + stock).
 *
 * El mapa PS↔TPV se persiste como meta en dos tablas propias:
 *   - `tpv_sync_product_map`     — id_product_ps ⇄ tpv_product_id (+ urls imagen)
 *   - `tpv_sync_combination_map` — id_product_attribute_ps ⇄ product_option_value_id
 *
 * Flujos:
 *   importAll()              — TPV → PS: trae todos los productos y los crea/actualiza.
 *   pushProductToTpv()       — PS → TPV: POST /products o PATCH /products/{id}.
 *   pushStockChange()        — PS → TPV: PATCH /products/{id}/stock con delta.
 *   updateFromTpv()          — TPV → PS: lee un producto del TPV y upserta localmente.
 *   updateStock()            — TPV → PS: fija stock absoluto en PS.
 *   updateVariantStock()     — TPV → PS: fija stock de una combinación PS.
 *   deleteProductInTpv()     — PS → TPV: DELETE /products/{id}.
 *   reconcile()              — cron periódico, TPV autoritativo para stock.
 *
 * Guards anti-bucle: cada llamada TPV→PS activa $GLOBALS['tpvsync_skip_*']
 * antes de escribir en PS. Los hooks de PS consultan ese flag y salen pronto
 * para que no reenviemos al TPV lo que acabamos de recibir del TPV.
 */
class TpvSyncProduct
{
    private TpvSyncApiClient $api;

    public function __construct(TpvSyncApiClient $api)
    {
        $this->api = $api;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Helpers de mapeo PS ⇄ TPV
    // ═══════════════════════════════════════════════════════════════════════

    public function findPsByTpv(int $tpvId): int
    {
        $row = Db::getInstance()->getRow(
            'SELECT id_product FROM ' . _DB_PREFIX_ . 'tpv_sync_product_map WHERE tpv_product_id = ' . (int) $tpvId
        );
        return (int) ($row['id_product'] ?? 0);
    }

    // ─── Venta a peso ────────────────────────────────────────────────────────
    //
    // El TPV vende algunos productos a peso (API: `sold_by_weight`): precio por kg y
    // stock con decimales. PS guarda unidades enteras, así que esos productos no se
    // publican (se desactivan si ya lo estaban) ni intercambian stock con el TPV. La
    // marca vive en tpv_sync_product_map.a_peso; la columna se garantiza al usarla
    // (como el TPV con su propia columna), sin depender de que el módulo se haya
    // actualizado por el back office.

    private static ?bool $hayColumnaAPeso = null;

    public static function asegurarColumnaAPeso(): void
    {
        if (self::$hayColumnaAPeso) {
            return;
        }
        $t = _DB_PREFIX_ . 'tpv_sync_product_map';
        if (!Db::getInstance()->executeS("SHOW COLUMNS FROM $t LIKE 'a_peso'")) {
            Db::getInstance()->execute("ALTER TABLE $t ADD COLUMN a_peso TINYINT(1) NOT NULL DEFAULT 0");
        }
        self::$hayColumnaAPeso = true;
    }

    private function marcarAPeso(int $idProduct, bool $aPeso): void
    {
        self::asegurarColumnaAPeso();
        Db::getInstance()->execute(
            'UPDATE ' . _DB_PREFIX_ . 'tpv_sync_product_map SET a_peso = ' . ($aPeso ? 1 : 0)
            . ' WHERE id_product = ' . (int) $idProduct
        );
    }

    /** ¿El TPV vende a peso este producto? Por id del TPV. */
    public function esAPeso(int $tpvId): bool
    {
        self::asegurarColumnaAPeso();
        return (string) Db::getInstance()->getValue(
            'SELECT a_peso FROM ' . _DB_PREFIX_ . 'tpv_sync_product_map WHERE tpv_product_id = ' . (int) $tpvId
        ) === '1';
    }

    public function findTpvByPs(int $idProduct): int
    {
        $row = Db::getInstance()->getRow(
            'SELECT tpv_product_id FROM ' . _DB_PREFIX_ . 'tpv_sync_product_map WHERE id_product = ' . (int) $idProduct
        );
        return (int) ($row['tpv_product_id'] ?? 0);
    }

    /**
     * Catálogo del TPV indexado por model y sku — cargado UNA vez por request
     * y reusado para evitar GET /products?search por cada producto sin mapeo
     * local. En pushes iniciales con catálogos grandes esta optimización
     * convierte O(N×search_RTT) en O(1×N/per_page páginas).
     *
     * Devuelve ['by_model' => [model => tpv_id], 'by_sku' => [sku => tpv_id]].
     */
    private array $tpvCatalogIndex = [];
    private bool $tpvCatalogIndexLoaded = false;

    private function getTpvCatalogIndex(): array
    {
        if ($this->tpvCatalogIndexLoaded) {
            return $this->tpvCatalogIndex;
        }
        $this->tpvCatalogIndexLoaded = true;
        $this->tpvCatalogIndex = ['by_model' => [], 'by_sku' => []];

        try {
            // Pedimos solo los campos que necesitamos para el lookup
            // (product_id, model, sku) usando ?fields= si la API lo soporta;
            // si no, igual descargamos todo. Paginamos con cursor hasta vaciar.
            $cursor = null;
            $pageGuard = 0;
            do {
                $params = ['per_page' => 200, 'fields' => 'product_id,model,sku'];
                if ($cursor !== null) { $params['cursor'] = $cursor; }
                $r = $this->api->get('/products', $params);
                foreach (($r['data'] ?? []) as $row) {
                    $tid = (int) ($row['product_id'] ?? 0);
                    if ($tid <= 0) { continue; }
                    $m = (string) ($row['model'] ?? '');
                    $s = (string) ($row['sku'] ?? '');
                    if ($m !== '' && !isset($this->tpvCatalogIndex['by_model'][$m])) {
                        $this->tpvCatalogIndex['by_model'][$m] = $tid;
                    }
                    if ($s !== '' && !isset($this->tpvCatalogIndex['by_sku'][$s])) {
                        $this->tpvCatalogIndex['by_sku'][$s] = $tid;
                    }
                }
                $cursor = $r['meta']['cursor'] ?? null;
                if ($cursor === null) {
                    // Compatibilidad con esquemas viejos que devuelven links.next.
                    $next = (string) ($r['links']['next'] ?? '');
                    if ($next !== '' && preg_match('/cursor=([^&]+)/', $next, $m2)) {
                        $cursor = urldecode($m2[1]);
                    }
                }
                $pageGuard++;
            } while ($cursor !== null && $pageGuard < 100);
        } catch (\Throwable $e) {
            // Si la precarga falla, seguimos con índice vacío — cada producto
            // que no encuentre match irá al flujo de create normal y la API
            // devolverá 422 si hay conflicto. Logueamos para diagnóstico.
            TpvSyncLog::error('catalog_cache', 0, 'precarga TPV: ' . $e->getMessage());
        }

        return $this->tpvCatalogIndex;
    }

    public function setMap(int $idProduct, int $tpvId): void
    {
        // La tabla tiene PK en id_product y UNIQUE en tpv_product_id.
        // Borramos cualquier mapeo previo que use el mismo tpv_product_id para
        // otro id_product (caso: reconciliación cuando se crea producto PS
        // nuevo con una reference que coincide con un producto TPV ya mapeado
        // a un PS viejo — el PS nuevo debe asumir el mapeo).
        Db::getInstance()->execute(
            'DELETE FROM ' . _DB_PREFIX_ . 'tpv_sync_product_map
             WHERE tpv_product_id = ' . (int) $tpvId . ' AND id_product <> ' . (int) $idProduct
        );
        Db::getInstance()->execute(
            'INSERT INTO ' . _DB_PREFIX_ . 'tpv_sync_product_map (id_product, tpv_product_id, updated_at)
             VALUES (' . (int) $idProduct . ', ' . (int) $tpvId . ', NOW())
             ON DUPLICATE KEY UPDATE tpv_product_id = ' . (int) $tpvId . ', updated_at = NOW()'
        );
    }

    private function findCombinationByPov(int $povId): int
    {
        $row = Db::getInstance()->getRow(
            'SELECT id_product_attribute FROM ' . _DB_PREFIX_ . 'tpv_sync_combination_map WHERE tpv_option_value_id = ' . (int) $povId
        );
        return (int) ($row['id_product_attribute'] ?? 0);
    }

    private function findPovByCombination(int $idProductAttribute): int
    {
        $row = Db::getInstance()->getRow(
            'SELECT tpv_option_value_id FROM ' . _DB_PREFIX_ . 'tpv_sync_combination_map WHERE id_product_attribute = ' . (int) $idProductAttribute
        );
        return (int) ($row['tpv_option_value_id'] ?? 0);
    }

    private function setCombinationMap(int $idProductAttribute, int $povId): void
    {
        Db::getInstance()->execute(
            'DELETE FROM ' . _DB_PREFIX_ . 'tpv_sync_combination_map
             WHERE tpv_option_value_id = ' . (int) $povId . ' AND id_product_attribute <> ' . (int) $idProductAttribute
        );
        Db::getInstance()->execute(
            'INSERT INTO ' . _DB_PREFIX_ . 'tpv_sync_combination_map (id_product_attribute, tpv_option_value_id, updated_at)
             VALUES (' . (int) $idProductAttribute . ', ' . (int) $povId . ', NOW())
             ON DUPLICATE KEY UPDATE tpv_option_value_id = ' . (int) $povId . ', updated_at = NOW()'
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  TPV → PS:  import_all y upsert
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Importa productos del TPV a PS usando batch GET /products/{id} (50 por
     * request) para reducir RTTs, y procesando las tiendas en bloques con
     * checkpoint tras cada lote.
     *
     * @param array{offset?:int,limit?:int} $options
     *   offset: saltar los primeros N productos (continuar desde un batch previo)
     *   limit:  máximo productos a procesar en esta llamada (para evitar timeouts)
     */
    public function importAll(array $options = []): array
    {
        // Subimos el límite de tiempo a 10 min dentro del request (algunos PS
        // ignoran el .user.ini para requests del BO). Si el SAPI está en modo
        // seguro esto es un no-op, pero no rompe.
        @set_time_limit(600);
        @ini_set('max_execution_time', '600');

        $offset = max(0, (int) ($options['offset'] ?? 0));
        $limit = (int) ($options['limit'] ?? 0); // 0 = sin límite

        $stats = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'total_seen' => 0,
            'processed' => 0,
            'next_offset' => 0,
        ];

        // 1) Stubs (listado ligero) — solo necesitamos los IDs.
        // La API usa paginación por cursor (no offset). Cacheamos la lista
        // completa de IDs en una opción Configuration entre lotes para no
        // re-descargar 27 páginas × N lotes (antes: 2700 requests para
        // 5000 productos en lotes de 50; ahora: 27 requests totales).
        $cacheKey = 'TPVSYNC_PULL_IDS_CACHE';
        $cacheTsKey = 'TPVSYNC_PULL_IDS_CACHE_TS';
        $cacheTs = (int) Configuration::get($cacheTsKey);
        $cacheJson = (string) Configuration::get($cacheKey);
        $ids = [];
        // TTL 30 min — si el pull tarda más, mejor refrescar para coger
        // productos nuevos creados durante el import.
        if ($cacheJson !== '' && (time() - $cacheTs) < 1800) {
            $cached = json_decode($cacheJson, true);
            if (is_array($cached)) { $ids = array_map('intval', $cached); }
        }
        if (empty($ids)) {
            $allStubs = $this->api->getAll('/products', ['status' => 1, 'fields' => 'product_id']);
            foreach ($allStubs as $stub) {
                $id = (int) ($stub['product_id'] ?? 0);
                if ($id > 0) { $ids[] = $id; }
            }
            // Guardar en cache para los siguientes lotes.
            Configuration::updateValue($cacheKey, json_encode($ids));
            Configuration::updateValue($cacheTsKey, time());
        }
        $stats['total_seen'] = count($ids);
        if ($offset > 0) { $ids = array_slice($ids, $offset); }
        if ($limit > 0)  { $ids = array_slice($ids, 0, $limit); }

        // 2) Detalles. Optimización: si la API soporta listar productos
        // FULL en /products?per_page=50 (incluyendo description, images,
        // options), nos ahorramos el /batch de 50 GETs individuales.
        // De lo contrario, fallback a /batch.
        //
        // Probamos primero con /products?per_page=50&fields=... pidiendo los
        // campos que upsert() necesita. Si la respuesta no trae las options
        // detalladas (algunos serializadores las omiten en list), caemos al
        // batch para esos productos.
        if (empty($ids)) {
            $stats['next_offset'] = 0;
            return $stats;
        }

        // Estrategia: pedimos los detalles UNO A UNO pero en una sola request
        // batch firmada, consumiendo MUCHO menos RTT por lote. /batch ya está
        // optimizado en el TPV: una request HMAC, N sub-operations procesadas
        // en serie pero sin overhead HTTP por cada una.
        foreach (array_chunk($ids, 50) as $chunk) {
            $ops = [];
            foreach ($chunk as $pid) {
                $ops[] = ['method' => 'GET', 'path' => '/products/' . $pid];
            }
            $resp = $this->api->batch($ops);
            foreach ($resp['results'] ?? [] as $i => $r) {
                $tpvId = $chunk[$i] ?? 0;
                $stats['processed']++;
                if (($r['status'] ?? 0) !== 200 || empty($r['body']['data'])) {
                    $stats['errors']++;
                    TpvSyncLog::error('product', (int) $tpvId,
                        'batch HTTP ' . ($r['status'] ?? 0) . ': ' . substr((string) json_encode($r['body'] ?? null), 0, 200)
                    );
                    continue;
                }
                try {
                    $res = $this->upsert($r['body']['data']);
                    if (!isset($stats[$res])) {
                        $stats[$res] = 0;
                    }
                    $stats[$res]++;
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    $trace = $e->getFile() . ':' . $e->getLine();
                    TpvSyncLog::error('product', (int) $tpvId,
                        'import: ' . $e->getMessage() . ' @ ' . $trace
                    );
                }
            }
        }

        $stats['next_offset'] = $offset + $stats['processed'];
        if ($stats['next_offset'] >= $stats['total_seen']) {
            $stats['next_offset'] = 0; // terminado
        }

        TpvSyncLog::ok('product', 0,
            'Import TPV→PS lote: ' . ($stats['created'] ?? 0) . ' creados, '
            . ($stats['updated'] ?? 0) . ' actualizados, '
            . ($stats['skipped'] ?? 0) . ' skipped, '
            . $stats['errors'] . ' errores (procesados=' . $stats['processed']
            . '/' . $stats['total_seen'] . ', next_offset=' . $stats['next_offset'] . ')'
        );
        return $stats;
    }

    /**
     * Crea o actualiza un producto PS a partir del shape de la API.
     *
     * La API devuelve precios con IVA (X-Price-Format: gross). PS guarda
     * internamente el precio SIN IVA en ps_product.price + tax_rules_group.
     * Por simplicidad (y paridad con el plugin WC), grabamos el gross como
     * price con tax_rules_group=0, asumiendo que la tienda PS trabaja con
     * precios con IVA incluido en el front (modo "Prices entered with tax").
     * Si el cliente PS tiene tax_rules_group ≠ 0, conviene ajustar el admin.
     */
    public function upsert(array $p): string
    {
        $tpvId = (int) $p['product_id'];
        $psId = $this->findPsByTpv($tpvId);

        // External mapping reverse: si el TPV nos envió `external_id` (es nuestro
        // id_product que el TPV recordaba de una sincronización previa), y NO
        // teníamos mapping local (lo borraron, BD reseteada, etc), reconstruimos
        // el mapping a partir de él en vez de crear un producto nuevo.
        // Esto evita la duplicación clásica: TPV manda 5275 productos tras
        // reset → PS no los reconoce y los crea todos como nuevos → ya existían
        // bajo el mismo id_product, BD se llena de duplicados.
        if ($psId === 0 && isset($p['external_id'])) {
            $candidate = (int) $p['external_id'];
            if ($candidate > 0) {
                // Verificamos que el id_product existe localmente y NO está ya
                // mapeado a otro tpv_id (sería un cruce raro). Si pasa el chequeo,
                // adoptamos.
                $exists = (int) Db::getInstance()->getValue(
                    'SELECT id_product FROM ' . _DB_PREFIX_ . 'product WHERE id_product = ' . $candidate
                );
                $alreadyMapped = (int) Db::getInstance()->getValue(
                    'SELECT tpv_product_id FROM ' . _DB_PREFIX_ . 'tpv_sync_product_map WHERE id_product = ' . $candidate
                );
                if ($exists > 0 && $alreadyMapped === 0) {
                    $psId = $candidate;
                    // Re-creamos el mapping local para que el resto del flujo
                    // ya tenga match.
                    $this->setMap($psId, $tpvId);
                    TpvSyncLog::ok('product', $tpvId,
                        "Mapping reconstruido vía external_id (PS id=$psId ↔ TPV id=$tpvId)"
                    );
                } elseif ($exists > 0 && $alreadyMapped > 0 && $alreadyMapped !== $tpvId) {
                    // El id_product PS ya está mapeado a OTRO tpv_product_id.
                    // No tocamos nada — mejor crear nuevo y registrar la
                    // anomalía para que el humano la mire.
                    TpvSyncLog::skip('product', $tpvId,
                        "external_id=$candidate ya está mapeado a TPV id=$alreadyMapped — saltamos adopción"
                    );
                }
            }
        }

        // Venta a peso: no se publica. Si ya estaba en PS se DESACTIVA (no se borra ni se
        // desenlaza); si no estaba, no se crea. Cuando el TPV lo desmarque, este mismo
        // upsert le devuelve el estado del TPV (vuelve a activarse).
        if (!empty($p['sold_by_weight'])) {
            if ($psId > 0) {
                $this->marcarAPeso($psId, true);
                $this->deleteProductFromTpv($tpvId, 'se vende a peso en el TPV');
            }
            return 'a_peso';
        }
        if ($psId > 0) {
            $this->marcarAPeso($psId, false);
        }

        $GLOBALS['tpvsync_skip_product_push'] = true;
        // Durante el upsert TPV→PS, los StockAvailable::setQuantity() internos
        // disparan actionUpdateQuantity (nuestro hook PS→TPV). Sin este guard
        // re-empujaríamos el stock que acabamos de recibir del TPV al TPV.
        $GLOBALS['tpvsync_skip_stock_push'] = true;

        // FIX PS 9: StockManager::prepareMovement exige id_employee int. En
        // contextos sin Employee cargado (imports desde CLI, webhooks, AJAX)
        // Context->employee->id es null y PS revienta con TypeError al crear
        // el producto. Forzamos un employee fallback (admin, id=1) antes de
        // crear/actualizar. Se restaura al salir.
        $ctx = Context::getContext();
        $prevEmployee = $ctx->employee;
        if (!$prevEmployee || !$prevEmployee->id) {
            $fallback = new Employee(1);
            if (Validate::isLoadedObject($fallback)) {
                $ctx->employee = $fallback;
            }
        }
        try {
            $isNew = ($psId === 0);
            if ($isNew) {
                $product = new Product();
                // Multi-shop: al crear, asociar el producto a todas las tiendas.
                // Si el admin quiere restringir a una tienda concreta lo hará
                // desde el BO tras la sincronización. En mono-shop esto es no-op.
                if (class_exists('Shop') && method_exists('Shop', 'getShops')) {
                    $shops = Shop::getShops(true, null, true);
                    if (!empty($shops)) {
                        $product->id_shop_list = array_map('intval', $shops);
                    }
                }
            } else {
                $product = new Product($psId);
                if (!Validate::isLoadedObject($product)) {
                    $product = new Product();
                    $isNew = true;
                }
            }

            $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
            // isCatalogName de PS rechaza <>{}. Los removemos antes de truncar.
            $nameRaw = (string) ($p['name'] ?? '');
            $nameRaw = str_replace(['<', '>', '{', '}'], '', $nameRaw);
            $name = Tools::substr($nameRaw, 0, 128);
            if (trim((string) $name) === '') {
                $name = 'Producto TPV ' . $tpvId;
            }
            // `description` en PS valida con isCleanHtml (sin scripts/iframes).
            // Si viene HTML sucio del TPV lo dejamos como texto plano (strip_tags)
            // — menos estético pero no bloquea el import. El admin siempre puede
            // reenriquecerlo desde PS si quiere.
            $descrRaw = (string) ($p['description'] ?? '');
            $descr = (method_exists('Validate', 'isCleanHtml') && Validate::isCleanHtml($descrRaw))
                ? $descrRaw
                : strip_tags($descrRaw);

            // `name` se replica en todos los idiomas activos para evitar errores
            // de validación cuando PS tiene multilingüe activo pero el TPV solo
            // aporta un nombre. Cualquier idioma sin valor → isCatalogName false.
            $linkRewrite = Tools::str2url($name) ?: 'producto-tpv-' . $tpvId;
            $product->name = $this->allLangNameI18n($name);
            $product->description = $this->allLangNameI18n($descr);
            $product->link_rewrite = $this->allLangNameI18n($linkRewrite);

            $product->active = ((int) ($p['status'] ?? 0) === 1) ? 1 : 0;
            $special = $p['special_price'] ?? null;
            $priceRaw = (float) ($special !== null && $special !== '' ? $special : ($p['price'] ?? 0));
            // PS::isPrice rechaza precios negativos. En el TPV ciertos productos
            // técnicos (cargos, devoluciones) tienen precio negativo y no tienen
            // sentido en un catálogo online — los saltamos explícitamente en
            // vez de propagar un error opaco. El map NO se crea: el siguiente
            // push desde PS los ignorará también.
            if ($priceRaw < 0) {
                TpvSyncLog::skip('product', $tpvId,
                    "Producto skipped: precio negativo ($priceRaw) name=\"$name\" — no válido para PS"
                );
                return 'skipped';
            }
            // PS::isPrice exige max 10 dígitos enteros (regex /^[0-9]{1,10}(\.[0-9]{1,9})?$/).
            // Cualquier precio >= 10.000.000.000 € lo rechaza con
            // "La propiedad Product->price no es válida". Cuando un cliente
            // tiene productos así (típicamente fixtures de stress test que
            // se colaron al TPV) skipeamos el producto en vez de petar el
            // import entero. Threshold: 1e10 (mismo que PS). Loguear name
            // y precio para que el sysadmin vea qué fixture limpiar.
            if ($priceRaw >= 1e10) {
                TpvSyncLog::skip('product', $tpvId,
                    "Producto skipped: precio fuera de rango PS (price=$priceRaw, máx PS=9999999999) name=\"$name\" — probable fixture de test, revisa el TPV"
                );
                return 'skipped';
            }
            $product->price = $priceRaw;
            $product->wholesale_price = 0;
            $product->id_tax_rules_group = 0;
            // id_category_default es obligatorio en PS para crear el producto.
            // Si el TPV manda categorías reales en este import, syncCategories
            // las asignará después y reemplazará este valor. Si no manda
            // ninguna, se queda en home (PS lo exige — todo producto cuelga
            // de algo). El comportamiento aquí REPLICA lo que viene del TPV;
            // no inventamos categorías propias.
            $product->id_category_default = (int) Configuration::get('PS_HOME_CATEGORY');

            // Mapeo de identificadores (mismo criterio que pushProductToTpv):
            //   - model TPV numérico (8-14 dígitos) → EAN-13 / UPC de PS
            //   - sku TPV                           → reference de PS
            //
            // reference tiene max 64 chars en PS; ean13 max 13; upc max 12.
            // Si el TPV manda algo más largo truncamos para no romper la
            // validación isCatalogReference / isEan13 / isUpc.
            $modelTpv = trim((string) ($p['model'] ?? ''));
            $skuTpv = trim((string) ($p['sku'] ?? ''));
            $sanitizeRef = fn(string $s) => Tools::substr(
                str_replace(['<', '>', ';', '=', '{', '}'], '', $s),
                0,
                64
            );
            if ($modelTpv !== '' && preg_match('/^\d{8,14}$/', $modelTpv)) {
                if (strlen($modelTpv) === 13) {
                    $product->ean13 = $modelTpv;
                } elseif (strlen($modelTpv) === 12) {
                    $product->upc = $modelTpv;
                } else {
                    $product->ean13 = $modelTpv; // isbn/8-dig fallback
                }
                $product->reference = $sanitizeRef($skuTpv !== '' ? $skuTpv : $modelTpv);
            } else {
                $product->reference = $sanitizeRef($modelTpv !== '' ? $modelTpv : $skuTpv);
            }

            // ObjectModel::add() puede tirar PrestaShopException con el detalle
            // exacto del campo que falló (isUnsignedId, isCatalogName, etc.).
            // Lo capturamos y lo propagamos como mensaje legible — importAll()
            // lo pone en el log.
            if ($isNew) {
                try {
                    if (!$product->add()) {
                        throw new RuntimeException(
                            'Product::add devolvió false (name="' . $name . '" tpv=' . $tpvId . ')'
                        );
                    }
                } catch (PrestaShopException $e) {
                    throw new RuntimeException('PrestaShopException en Product::add: ' . $e->getMessage() . ' — name="' . $name . '" tpv=' . $tpvId);
                }
                // Asignación inicial: home solo como fallback — syncCategories
                // de más abajo reemplazará esto si el TPV envió categorías reales.
                try {
                    $product->addToCategories([(int) Configuration::get('PS_HOME_CATEGORY')]);
                } catch (\Throwable $e) {
                    TpvSyncLog::error('product', $tpvId, 'addToCategories: ' . $e->getMessage());
                }
                $created = true;
            } else {
                try {
                    $product->update();
                } catch (PrestaShopException $e) {
                    throw new RuntimeException('PrestaShopException en Product::update: ' . $e->getMessage() . ' — tpv=' . $tpvId);
                }
                $created = false;
            }

            $this->setMap((int) $product->id, $tpvId);

            // Stock (productos sin variantes). En multi-shop propagamos a todas
            // las tiendas; en mono-shop es una sola llamada.
            if (empty($p['options'])) {
                $qty = (int) ($p['quantity'] ?? 0);
                $shopIds = $this->allShopIds();
                if (empty($shopIds)) {
                    StockAvailable::setQuantity((int) $product->id, 0, $qty);
                } else {
                    foreach ($shopIds as $sid) {
                        StockAvailable::setQuantity((int) $product->id, 0, $qty, $sid);
                    }
                }
            } else {
                $this->syncVariations((int) $product->id, $p['options'] ?? [], (float) $product->price);
            }

            // Imágenes (solo la primera + galería — no bloqueamos con fetch pesado:
            // si la URL viene vacía, saltamos)
            if (!empty($p['images']) && is_array($p['images'])) {
                $this->syncImages((int) $product->id, $p['images']);
            } elseif (!empty($p['image'])) {
                $this->syncImages((int) $product->id, [['url' => $p['image'], 'is_main' => 1, 'sort_order' => 0]]);
            }

            // Categorías
            if (!empty($p['categories']) && is_array($p['categories'])) {
                $this->syncCategories((int) $product->id, $p['categories']);
            }

            return $created ? 'created' : 'updated';
        } catch (PrestaShopException $e) {
            throw new RuntimeException('PrestaShopException: ' . $e->getMessage());
        } finally {
            $GLOBALS['tpvsync_skip_product_push'] = false;
            $GLOBALS['tpvsync_skip_stock_push'] = false;
            $ctx->employee = $prevEmployee;
        }
    }

    public function updateFromTpv(int $tpvId): void
    {
        $r = $this->api->get('/products/' . $tpvId);
        if (!empty($r['data'])) {
            $this->upsert($r['data']);
        }
    }

    public function updateStock(int $tpvId, float $quantity): void
    {
        $psId = $this->findPsByTpv($tpvId);
        if ($psId === 0 || $this->esAPeso($tpvId)) {
            return; // a peso: stock en kg con decimales, PS no lo lleva
        }
        $GLOBALS['tpvsync_skip_stock_push'] = true;
        try {
            $shopIds = $this->allShopIds();
            if (empty($shopIds)) {
                StockAvailable::setQuantity($psId, 0, (int) $quantity);
            } else {
                foreach ($shopIds as $sid) {
                    StockAvailable::setQuantity($psId, 0, (int) $quantity, $sid);
                }
            }
        } finally {
            $GLOBALS['tpvsync_skip_stock_push'] = false;
        }
    }

    public function updateVariantStock(int $povId, float $quantity): void
    {
        $combId = $this->findCombinationByPov($povId);
        if ($combId === 0) {
            return;
        }
        $combination = new Combination($combId);
        if (!Validate::isLoadedObject($combination)) {
            return;
        }
        $GLOBALS['tpvsync_skip_stock_push'] = true;
        try {
            $shopIds = $this->allShopIds();
            if (empty($shopIds)) {
                StockAvailable::setQuantity((int) $combination->id_product, $combId, (int) $quantity);
            } else {
                foreach ($shopIds as $sid) {
                    StockAvailable::setQuantity((int) $combination->id_product, $combId, (int) $quantity, $sid);
                }
            }
        } finally {
            $GLOBALS['tpvsync_skip_stock_push'] = false;
        }
    }

    public function deleteProductFromTpv(int $tpvId, string $motivo = 'borrado en TPV'): void
    {
        $psId = $this->findPsByTpv($tpvId);
        if ($psId === 0) {
            return;
        }
        $GLOBALS['tpvsync_skip_product_push'] = true;
        try {
            $product = new Product($psId);
            if (Validate::isLoadedObject($product)) {
                $product->active = 0;
                $product->update();
            }
        } finally {
            $GLOBALS['tpvsync_skip_product_push'] = false;
        }
        TpvSyncLog::ok('product', $tpvId, 'Producto PS ' . $psId . ' desactivado (' . $motivo . ')');
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  PS → TPV
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Empuja un producto PS al TPV. Si tenemos mapeo → PATCH; si no → POST.
     * Devuelve true si el TPV aceptó, false en cualquier error (incluye
     * circuit-open). Los hooks no usan el retorno, pero la UI admin sí para
     * reportar contadores reales al admin tras un bulk.
     */
    /**
     * Set de identificadores (reference, ean, upc) que aparecen DUPLICADOS
     * en el catálogo PS. Lo cargamos una vez por request: si la reference
     * de un producto colisiona con la de otro, NO la usamos como `model`
     * para el TPV (porque el endpoint bulk hace upsert por model y dos
     * productos PS con la misma reference se aplastarían en uno solo en
     * el TPV — PS pierde catálogo silenciosamente).
     */
    private array $duplicateIdentifiers = [];
    private bool $duplicateIdentifiersLoaded = false;

    private function getDuplicateIdentifiers(): array
    {
        if ($this->duplicateIdentifiersLoaded) {
            return $this->duplicateIdentifiers;
        }
        $this->duplicateIdentifiersLoaded = true;
        $this->duplicateIdentifiers = ['reference' => [], 'gtin' => []];

        // Reference duplicadas (case-insensitive, trimmed).
        $rows = Db::getInstance()->executeS(
            "SELECT TRIM(reference) AS k, COUNT(*) AS n
             FROM " . _DB_PREFIX_ . "product
             WHERE state = 1 AND reference IS NOT NULL AND TRIM(reference) <> ''
             GROUP BY TRIM(reference)
             HAVING n > 1"
        ) ?: [];
        foreach ($rows as $r) {
            $this->duplicateIdentifiers['reference'][(string) $r['k']] = true;
        }

        // GTIN (ean13/upc) duplicados.
        $rows = Db::getInstance()->executeS(
            "SELECT k, COUNT(*) AS n FROM (
                SELECT TRIM(ean13) AS k FROM " . _DB_PREFIX_ . "product
                  WHERE state = 1 AND ean13 IS NOT NULL AND TRIM(ean13) <> ''
                UNION ALL
                SELECT TRIM(upc) AS k FROM " . _DB_PREFIX_ . "product
                  WHERE state = 1 AND upc IS NOT NULL AND TRIM(upc) <> ''
             ) t GROUP BY k HAVING n > 1"
        ) ?: [];
        foreach ($rows as $r) {
            $this->duplicateIdentifiers['gtin'][(string) $r['k']] = true;
        }
        return $this->duplicateIdentifiers;
    }

    /**
     * Resuelve qué `tax_class_id` TPV corresponde a un `id_tax_rules_group`
     * de PS, consultando la tabla `tpv_sync_tax_map`. Si no hay mapeo
     * guardado (admin aún no configuró ese grupo), cae al default global
     * `TPVSYNC_DEFAULT_TAX_CLASS_ID`.
     *
     * Cache estática por proceso PHP: el push masivo llama a esta función
     * por cada producto y la tabla de mapeo es pequeña (un puñado de
     * tax_rules_groups en cualquier tienda real).
     */
    private static array $taxMapCache = [];

    public function resolveTpvTaxClassId(int $idTaxRulesGroup): int
    {
        if ($idTaxRulesGroup <= 0) {
            return (int) Configuration::get('TPVSYNC_DEFAULT_TAX_CLASS_ID');
        }
        if (!array_key_exists($idTaxRulesGroup, self::$taxMapCache)) {
            $row = Db::getInstance()->getValue(
                'SELECT tpv_tax_class_id FROM ' . _DB_PREFIX_ . 'tpv_sync_tax_map
                 WHERE id_tax_rules_group = ' . $idTaxRulesGroup
            );
            self::$taxMapCache[$idTaxRulesGroup] = $row !== false ? (int) $row : -1;
        }
        $mapped = self::$taxMapCache[$idTaxRulesGroup];
        if ($mapped >= 0) return $mapped;
        return (int) Configuration::get('TPVSYNC_DEFAULT_TAX_CLASS_ID');
    }

    /**
     * Invalida la cache de tax mapping. Llamado por la UI tras guardar.
     */
    public static function clearTaxMapCache(): void
    {
        self::$taxMapCache = [];
    }

    /**
     * Construye el payload PS → TPV de un producto individual. Extraído de
     * pushProductToTpv para poder reusar en pushProductsBulk sin duplicar la
     * lógica de mapeo de identificadores y precios.
     *
     * Devuelve null si el producto no es válido (sin Product en BD, etc).
     */
    private function buildPushPayload(int $idProduct): ?array
    {
        $product = new Product($idProduct, false, (int) Configuration::get('PS_LANG_DEFAULT'));
        if (!Validate::isLoadedObject($product)) {
            return null;
        }

        $ean = trim((string) $product->ean13);
        $upc = trim((string) $product->upc);
        $ref = trim((string) $product->reference);
        $gtin = $ean !== '' ? $ean : $upc;

        // Decidir el `model` que mandamos al TPV. Para que el upsert por
        // model NO colapse productos distintos en uno, usamos el identificador
        // SOLO si es único en el catálogo PS. Si está duplicado, caemos al
        // fallback `__PS__<id>` (siempre único). Esto evita que 33 productos
        // PS con reference=00010 se aplasten en un único producto del TPV.
        $dups = $this->getDuplicateIdentifiers();
        $fallback = '__PS__' . $idProduct;

        if ($gtin !== '' && preg_match('/^\d{8,14}$/', $gtin) && empty($dups['gtin'][$gtin])) {
            $model = $gtin;
        } elseif ($ref !== '' && empty($dups['reference'][$ref])) {
            $model = $ref;
        } else {
            $model = $fallback;
        }
        // SKU: la API del TPV valida sku único en POST (validateProductBody:
        // "sku already exists"). Si la reference PS está duplicada en >1
        // productos, caemos al fallback __PS__id_product también para el
        // sku — sino el segundo POST devolvería 422. Mismo patrón que el
        // model. Solo afecta a productos PS con reference compartida (raro
        // pero ocurrió en clientes con CSVs migrados de otros sistemas).
        if ($ref !== '' && empty($dups['reference'][$ref])) {
            $skuForTpv = $ref;
        } else {
            $skuForTpv = $fallback;
        }

        $name = is_array($product->name) ? (string) array_values($product->name)[0] : (string) $product->name;
        $descr = is_array($product->description) ? (string) array_values($product->description)[0] : (string) $product->description;
        $priceGross = (float) Product::getPriceStatic($idProduct, true, null, 6);

        // Guard: el TPV exige price >= 0. Algunos clientes PS modelan
        // descuentos manuales como un "producto" con precio negativo. Eso
        // sería válido en PS pero el TPV lo rechaza con 422. No hay forma
        // honesta de mapearlo (un voucher sería el equivalente real). Skip
        // explícito con mensaje claro. Mismo guard que el plugin WC para
        // mantener paridad.
        if ($priceGross < 0) {
            TpvSyncLog::skip('product', $idProduct,
                "Producto con precio negativo (" . $priceGross . " €) no soportado: el TPV no acepta precios negativos. "
                . "Si lo usas como descuento, conviértelo a un cupón/voucher o a producto a 0€ con el descuento aparte. "
                . "Este producto NO se sincronizará."
            );
            return null;
        }

        $payload = [
            'name'        => $name,
            'description' => $descr,
            'price'       => $priceGross,
            'model'       => $model,
            'sku'         => $skuForTpv,
            'status'      => (int) $product->active === 1 ? 1 : 0,
            // client_external_id: nuestro id_product local. El TPV lo guarda
            // en api_external_mapping (channel='prestashop', client_id=el del
            // JWT) y nos permite reconstruir mappings tras un reset sin
            // duplicar productos. Se envía siempre — si el TPV no soporta el
            // campo, lo ignora silenciosamente.
            'client_external_id' => (string) $idProduct,
        ];

        // Mapeo de impuestos PS → TPV. Dos niveles:
        //   1. UI per-class: el admin define qué tax_class_id del TPV
        //      corresponde a cada `id_tax_rules_group` PS (tabla
        //      `palyp_tpv_sync_tax_map`).
        //   2. Default global: si la clase fiscal del producto no tiene
        //      mapping (o el producto no tiene id_tax_rules_group), usamos
        //      `TPVSYNC_DEFAULT_TAX_CLASS_ID`.
        //   3. Sin nada → no enviamos el campo y la API usa su default
        //      (oc_product.tax_class_id=0 = "Sin impuestos").
        $taxClassId = $this->resolveTpvTaxClassId((int) $product->id_tax_rules_group);
        if ($taxClassId > 0) {
            $payload['tax_class_id'] = $taxClassId;
        }

        $combinations = $product->getAttributeCombinations((int) Configuration::get('PS_LANG_DEFAULT'));
        if (!empty($combinations)) {
            $options = $this->buildOptionsForTpv($combinations, $priceGross);
            if (!empty($options)) {
                $payload['options'] = $options;
            }
        }

        return $payload + ['__id_product' => $idProduct]; // helper interno (NO se manda al TPV)
    }

    /**
     * Push masivo de hasta 100 productos en una sola request HTTP firmada
     * usando el endpoint POST /products/bulk del TPV. Sustituye el bucle
     * de pushProductToTpv para acelerar la primera sincronización en
     * catálogos grandes (de ~150ms/producto a ~5ms/producto efectivos).
     *
     * Limitaciones conocidas vs. el push singular:
     *  - El endpoint bulk del TPV NO procesa `options` (variantes) ni
     *    images. Si un producto tiene variantes, lo procesamos por la vía
     *    singular para no perder datos.
     *  - Tampoco maneja categorías por ahora (se podría extender después).
     *
     * Devuelve ['sent' => int, 'updated' => int, 'created' => int,
     *           'errors' => int, 'fallback' => int] — fallback cuenta los
     * productos que se enviaron por singular porque tenían variantes.
     */
    public function pushProductsBulk(array $idProducts): array
    {
        $stats = ['sent' => 0, 'updated' => 0, 'created' => 0, 'errors' => 0, 'fallback' => 0];

        $bulkPayloads = [];
        $bulkPsIds = [];
        foreach ($idProducts as $pid) {
            $pid = (int) $pid;
            $payload = $this->buildPushPayload($pid);
            if ($payload === null) {
                $stats['errors']++;
                continue;
            }
            // Si tiene variantes, usamos el flujo singular para preservar
            // mapeo de combinations y options. El bulk del TPV no las
            // entiende y silenciar perdería datos.
            if (!empty($payload['options'])) {
                $ok = $this->pushProductToTpv($pid);
                if ($ok) { $stats['sent']++; } else { $stats['errors']++; }
                $stats['fallback']++;
                continue;
            }
            unset($payload['__id_product']);
            $bulkPayloads[] = $payload;
            $bulkPsIds[]    = $pid;
        }

        if (empty($bulkPayloads)) {
            return $stats;
        }

        $resp = $this->api->post('/products/bulk', ['items' => $bulkPayloads]);
        // BUG-A: rama de FALLO — antes un 4xx/5xx sin la clave 'errors' no entraba
        // aqui y el bulk se daba por bueno sin caer al fallback singular.
        if (!TpvSyncApiClient::fueBien($resp)) {
            // El bulk falló entero. Caemos al singular para no perder los
            // productos — más lento pero garantiza progreso.
            // Log con el field+message exacto para diagnosticar qué item rompió.
            $err0 = $resp['errors'][0] ?? [];
            $msg = ($err0['field'] ?? '') !== ''
                ? ($err0['field'] . ': ' . ($err0['message'] ?? 'unknown'))
                : ($err0['message'] ?? $resp['error'] ?? 'unknown');
            TpvSyncLog::error('bulk', 0, "POST /products/bulk falló: $msg — fallback a singular");
            foreach ($bulkPsIds as $pid) {
                $ok = $this->pushProductToTpv($pid);
                if ($ok) { $stats['sent']++; } else { $stats['errors']++; }
                $stats['fallback']++;
            }
            return $stats;
        }

        // Persistir mapeos PS → TPV con los ids devueltos. results trae
        // {index, product_id, action} en el mismo orden que enviamos.
        $results = $resp['data']['results'] ?? [];
        foreach ($results as $r) {
            $idx = (int) ($r['index'] ?? -1);
            $tpvId = (int) ($r['product_id'] ?? 0);
            if ($idx < 0 || $idx >= count($bulkPsIds) || $tpvId === 0) { continue; }
            $psId = $bulkPsIds[$idx];
            $this->setMap($psId, $tpvId);
            $action = (string) ($r['action'] ?? '');
            if ($action === 'created') {
                $stats['created']++;
            } elseif ($action === 'updated') {
                $stats['updated']++;
            }
            $stats['sent']++;
        }
        return $stats;
    }

    public function pushProductToTpv(int $idProduct): bool
    {
        if (!empty($GLOBALS['tpvsync_skip_product_push'])) {
            return false;
        }
        $payload = $this->buildPushPayload($idProduct);
        if ($payload === null) {
            return false;
        }
        $model = (string) ($payload['model'] ?? '');
        $skuForTpv = (string) ($payload['sku'] ?? '');
        unset($payload['__id_product']);

        $tpvId = $this->findTpvByPs($idProduct);

        // Reconciliación por model/sku si no hay mapeo local. Antes hacíamos
        // un GET /products?search=$needle por CADA producto sin mapeo — eso
        // duplicaba el RTT en pushes iniciales (4000+ productos × 70ms cada
        // search HTTP firmado). Ahora intentamos primero con un catálogo
        // precargado en memoria del propio request: una sola llamada masiva
        // al inicio (paginada hasta vaciar) sustituye N searches.
        if ($tpvId === 0) {
            $needle = $model !== '' ? $model : $skuForTpv;
            if ($needle !== '') {
                $catalog = $this->getTpvCatalogIndex();
                $found = $catalog['by_model'][$model] ?? $catalog['by_sku'][$skuForTpv] ?? 0;
                if ($found > 0) {
                    $tpvId = (int) $found;
                    $this->setMap($idProduct, $tpvId);
                    TpvSyncLog::ok('product', $tpvId,
                        "Reconciliado PS $idProduct con TPV existente (cache index)"
                    );
                }
            }
        }

        if ($tpvId > 0) {
            $r = $this->api->patch('/products/' . $tpvId, $payload);
            // Si el TPV ya no tiene este producto (404 → type contiene
            // "not_found"), el mapping local apunta a un huérfano: el TPV
            // se reseteó, el producto se borró manualmente, o el cliente
            // reinstaló el TPV. Limpiamos el mapping y caemos al flujo POST
            // de abajo para recrear. Mismo patrón que el plugin WC.
            $isNotFound = !empty($r['type']) && strpos((string) $r['type'], 'not_found') !== false;
            if ($isNotFound) {
                Db::getInstance()->execute(
                    'DELETE FROM ' . _DB_PREFIX_ . 'tpv_sync_product_map
                     WHERE id_product = ' . (int) $idProduct
                );
                TpvSyncLog::warn('product', $tpvId,
                    "PATCH /products/$tpvId devolvió 404 — mapping huérfano, recreando en TPV (PS=$idProduct)"
                );
                $tpvId = 0; // fall through a creación
            } elseif (!TpvSyncApiClient::fueBien($r)) {
                // BUG-A: rama de FALLO. La rama de 404 huerfano de arriba se
                // conserva INTACTA: alli el 404 es una senal, no un error.
                TpvSyncLog::error('product', $tpvId,
                    'PATCH TPV: ' . ($r['error'] ?? substr((string) json_encode($r), 0, 200))
                );
                return false;
            } else {
                // Mapear combinaciones añadidas/modificadas: PATCH puede devolver
                // options con IDs frescos si el cliente añadió variantes nuevas
                // en PS. Sin este mapeo, un posterior stock push por variante no
                // encontraría el pov y fallaría silenciosamente.
                if (!empty($r['data']['options']) && is_array($r['data']['options'])) {
                    $this->mapCombinationIds($idProduct, $r['data']['options']);
                } elseif (!empty($payload['options'])) {
                    // Solo releemos del TPV si el producto realmente tiene
                    // variantes (las acabamos de mandar en el payload). Antes
                    // hacíamos este GET extra para CADA producto sin variantes,
                    // duplicando RTT en pushes iniciales con catálogos planos.
                    $detail = $this->api->get('/products/' . $tpvId);
                    if (!empty($detail['data']['options']) && is_array($detail['data']['options'])) {
                        $this->mapCombinationIds($idProduct, $detail['data']['options']);
                    }
                }
                TpvSyncLog::ok('product', $tpvId, "Producto PS $idProduct actualizado en TPV (model=$model)");
                return true;
            }
        }

        // Create
        $payload['quantity'] = (float) StockAvailable::getQuantityAvailableByProduct($idProduct, 0);
        $r = $this->api->post('/products', $payload);
        $newId = (int) ($r['data']['product_id'] ?? 0);
        if ($newId === 0) {
            // Mensaje enriquecido: si la API devuelve `errors: [{field, message}]`
            // los pintamos field:message; field:message para que el log sea útil
            // a la primera (antes había que adivinar qué validación rompió).
            $apiError = (string) ($r['error'] ?? '');
            if (!empty($r['errors']) && is_array($r['errors'])) {
                $details = [];
                foreach ($r['errors'] as $err) {
                    if (is_array($err)) {
                        $f = $err['field'] ?? '?';
                        $m = $err['message'] ?? json_encode($err);
                        $details[] = "$f: $m";
                    } else {
                        $details[] = (string) $err;
                    }
                }
                $apiError = ($apiError !== '' ? $apiError . ' — ' : '') . implode('; ', $details);
            }
            if ($apiError === '') {
                $apiError = substr((string) json_encode($r), 0, 400);
            }
            TpvSyncLog::error('product', $idProduct,
                "POST TPV fallo (PS $idProduct model=$model sku=$skuForTpv): $apiError"
            );
            return false;
        }
        $this->setMap($idProduct, $newId);

        // Persistir mapeo de combinaciones si el TPV devolvió options con IDs
        if (!empty($r['data']['options']) && is_array($r['data']['options'])) {
            $this->mapCombinationIds($idProduct, $r['data']['options']);
        }

        TpvSyncLog::ok('product', $newId, "Producto PS $idProduct creado en TPV (model=$model sku=$skuForTpv)");
        return true;
    }

    public function deleteProductInTpv(int $idProduct): void
    {
        $tpvId = $this->findTpvByPs($idProduct);
        if ($tpvId === 0) {
            return;
        }
        $this->api->delete('/products/' . $tpvId);
        // Limpiar mapping local: el producto PS dejó de existir, el map ya no
        // sirve para nada y dejaría huérfanos que rompen findPsByTpv() si se
        // reutiliza ese PS id en el futuro.
        Db::getInstance()->execute(
            'DELETE FROM ' . _DB_PREFIX_ . 'tpv_sync_product_map WHERE id_product = ' . (int) $idProduct
        );
        Db::getInstance()->execute(
            'DELETE FROM ' . _DB_PREFIX_ . 'tpv_sync_combination_map
             WHERE id_product_attribute IN (SELECT id_product_attribute FROM ' . _DB_PREFIX_ . 'product_attribute WHERE id_product = ' . (int) $idProduct . ')'
        );
        Db::getInstance()->execute(
            'DELETE FROM ' . _DB_PREFIX_ . 'tpv_sync_image_map WHERE id_product = ' . (int) $idProduct
        );
        TpvSyncLog::ok('product', $tpvId, "DELETE TPV (PS $idProduct) + map local limpiado");
    }

    /**
     * Empuja un cambio de stock al TPV. PS pasa la cantidad ABSOLUTA; el TPV
     * usa delta, así que leemos el stock TPV y calculamos la diferencia.
     *
     * Si la lectura de stock TPV falla, encolamos un job `stock.push` con
     * target absoluto para reintentar cuando el TPV vuelva. Recalcular contra
     * 0 asumiendo "no hay stock" sería destructivo.
     */
    public function pushStockChange(int $idProduct, int $idProductAttribute, int $quantity): void
    {
        if (!empty($GLOBALS['tpvsync_skip_stock_push'])) {
            return;
        }
        $tpvId = $this->findTpvByPs($idProduct);
        if ($tpvId === 0) {
            return;
        }
        // A peso: el entero de PS pisaría el stock decimal del TPV (4,25 kg → 4).
        if ($this->esAPeso($tpvId)) {
            return;
        }

        if ($idProductAttribute > 0) {
            $povId = $this->findPovByCombination($idProductAttribute);
            if ($povId === 0) {
                TpvSyncLog::skip('stock', $idProductAttribute, 'sin mapeo combination → pov');
                return;
            }
            $this->api->patch("/products/$tpvId/variants/$povId", [
                'quantity' => $quantity,
            ]);
            return;
        }

        $current = $this->api->get("/products/$tpvId/stock");
        $tpvQty = null;
        if (isset($current['total']['quantity'])) {
            $tpvQty = (float) $current['total']['quantity'];
        } elseif (isset($current['data']['quantity'])) {
            $tpvQty = (float) $current['data']['quantity'];
        }
        if ($tpvQty === null) {
            TpvSyncLog::error('stock', $tpvId, 'No pude leer stock TPV — encolando');
            (new TpvSyncQueue($this->api))->enqueue('stock.push', [
                'id_product' => $idProduct,
                'id_product_attribute' => $idProductAttribute,
                'tpv_product_id' => $tpvId,
                'absolute_target' => $quantity,
                'reason' => 'ajuste_manual',
                'comment' => "PS product $idProduct (reintento)",
            ], 'stock read failed');
            return;
        }
        $delta = $quantity - $tpvQty;
        if (abs($delta) < 0.0001) {
            return;
        }
        $this->api->patch("/products/$tpvId/stock", [
            'quantity_change' => $delta,
            'reason' => 'ajuste_manual',
            'comment' => "PS product $idProduct",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Reconciliación periódica
    // ═══════════════════════════════════════════════════════════════════════

    public function reconcile(int $limit = 100): array
    {
        $stats = ['checked' => 0, 'fixed' => 0, 'variant_fixed' => 0, 'skipped' => 0, 'errors' => 0];
        $products = $this->api->getAll('/products', ['status' => 1, 'per_page' => min($limit, 100)]);
        $tpvIds = [];
        foreach ($products as $stub) {
            $tpvId = (int) ($stub['product_id'] ?? 0);
            if (!$tpvId) {
                continue;
            }
            if ($this->findPsByTpv($tpvId) === 0) {
                $stats['skipped']++;
                continue;
            }
            $tpvIds[] = $tpvId;
            if (count($tpvIds) >= $limit) {
                break;
            }
        }
        foreach (array_chunk($tpvIds, 50) as $chunk) {
            $ops = [];
            foreach ($chunk as $pid) {
                $ops[] = ['method' => 'GET', 'path' => "/products/$pid"];
            }
            $resp = $this->api->batch($ops);
            foreach ($resp['results'] ?? [] as $i => $r) {
                if (($r['status'] ?? 0) !== 200 || empty($r['body']['data'])) {
                    $stats['errors']++;
                    continue;
                }
                $data = $r['body']['data'];
                $tpvId = (int) ($data['product_id'] ?? $chunk[$i]);
                $psId = $this->findPsByTpv($tpvId);
                if ($psId === 0) {
                    continue;
                }
                $stats['checked']++;
                if (!empty($data['sold_by_weight'])) {
                    $stats['skipped']++;
                    continue;
                }
                if (!empty($data['options'])) {
                    foreach ($data['options'] as $opt) {
                        foreach ($opt['values'] ?? [] as $val) {
                            $povId = (int) ($val['product_option_value_id'] ?? 0);
                            $qty = (float) ($val['quantity'] ?? 0);
                            if (!$povId) {
                                continue;
                            }
                            $combId = $this->findCombinationByPov($povId);
                            if (!$combId) {
                                continue;
                            }
                            $psQty = (float) StockAvailable::getQuantityAvailableByProduct($psId, $combId);
                            if (abs($psQty - $qty) > 0.0001) {
                                $this->updateVariantStock($povId, $qty);
                                $stats['variant_fixed']++;
                            }
                        }
                    }
                } else {
                    $psQty = (float) StockAvailable::getQuantityAvailableByProduct($psId, 0);
                    $tpvQty = (float) ($data['quantity'] ?? 0);
                    if (abs($psQty - $tpvQty) > 0.0001) {
                        $this->updateStock($tpvId, $tpvQty);
                        $stats['fixed']++;
                    }
                }
            }
        }
        TpvSyncLog::ok('reconcile', 0,
            'checked=' . $stats['checked'] . ' fixed=' . $stats['fixed']
            . ' variant_fixed=' . $stats['variant_fixed']
            . ' skipped=' . $stats['skipped'] . ' errors=' . $stats['errors']
        );
        return $stats;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Categorías
    // ═══════════════════════════════════════════════════════════════════════

    private function syncCategories(int $idProduct, array $categories): void
    {
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $realIds = [];
        foreach ($categories as $cat) {
            $name = trim((string) ($cat['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $catId = $this->findOrCreateCategory($name, $idLang);
            if ($catId > 0) {
                $realIds[] = $catId;
            }
        }
        // Replicamos exactamente lo que viene del TPV. Si no manda ninguna
        // categoría, dejamos `home` (PS exige al menos una). Si manda
        // categorías reales, esas SUSTITUYEN al home — no mezclamos. Antes
        // se añadía el home siempre y aparecía pegado al lado de la
        // categoría real, lo que confundía al cliente.
        if (empty($realIds)) {
            $realIds = [(int) Configuration::get('PS_HOME_CATEGORY')];
        }
        $product = new Product($idProduct);
        if (Validate::isLoadedObject($product)) {
            $product->updateCategories(array_values(array_unique($realIds)));
            // Aseguramos que id_category_default apunta a una real cuando
            // existe, para que el producto no figure como "perteneciente
            // principalmente a Inicio" en backoffice.
            if (!in_array((int) $product->id_category_default, $realIds, true)) {
                $product->id_category_default = $realIds[0];
                $product->save();
            }
        }
    }

    private function findOrCreateCategory(string $name, int $idLang): int
    {
        $name = str_replace(['<', '>', '{', '}', "\0"], '', $name);
        // Lookup por MD5 para evitar escapar comillas/backslash en el nombre.
        // SQL en UNA línea — PS ha tenido bugs con saltos de línea en queries.
        try {
            $sql = 'SELECT cl.id_category FROM ' . _DB_PREFIX_ . 'category_lang cl WHERE MD5(cl.name) = "' . md5($name) . '" AND cl.id_lang = ' . (int) $idLang ;
            $found = Db::getInstance()->getValue($sql);
            if ($found) {
                return (int) $found;
            }
        } catch (\Throwable $e) {
            TpvSyncLog::error('category', 0, 'lookup(' . $name . '): ' . $e->getMessage());
        }
        $cat = new Category();
        $cat->name = $this->allLangNameI18n($name);
        $cat->link_rewrite = $this->allLangNameI18n(Tools::str2url($name) ?: ('cat-' . uniqid()));
        $cat->id_parent = (int) Configuration::get('PS_HOME_CATEGORY');
        $cat->active = 1;
        try {
            if ($cat->add()) {
                return (int) $cat->id;
            }
        } catch (\Throwable $e) {
            TpvSyncLog::error('category', 0, 'add(' . $name . '): ' . $e->getMessage());
        }
        return 0;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Imágenes
    // ═══════════════════════════════════════════════════════════════════════

    private function syncImages(int $idProduct, array $images): void
    {
        // Las URLs importadas se marcan en tpv_sync_image_map para no re-descargar
        foreach ($images as $img) {
            $url = trim((string) ($img['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $exists = Db::getInstance()->getValue(
                'SELECT 1 FROM ' . _DB_PREFIX_ . 'tpv_sync_image_map
                 WHERE id_product = ' . (int) $idProduct . '
                   AND url_hash = "' . pSQL(md5($url)) . '"'
            );
            if ($exists) {
                continue;
            }
            $tmp = $this->downloadImage($url);
            if (!$tmp) {
                continue;
            }
            $image = new Image();
            $image->id_product = $idProduct;
            $image->position = Image::getHighestPosition($idProduct) + 1;
            $image->cover = !empty($img['is_main']) ? 1 : 0;
            if (!$image->add()) {
                @unlink($tmp);
                continue;
            }
            $newPath = $image->getPathForCreation() . '.' . $image->image_format;
            if (!@copy($tmp, $newPath)) {
                @unlink($tmp);
                continue;
            }
            @unlink($tmp);

            // Regenerar miniaturas con los tipos de imagen definidos en PS.
            try {
                if (class_exists('ImageManager')) {
                    foreach (ImageType::getImagesTypes('products') as $type) {
                        ImageManager::resize(
                            $newPath,
                            $image->getPathForCreation() . '-' . stripslashes($type['name']) . '.' . $image->image_format,
                            (int) $type['width'],
                            (int) $type['height']
                        );
                    }
                }
            } catch (\Throwable $e) {
                // Error al generar thumbs no rompe la importación.
            }

            Db::getInstance()->insert('tpv_sync_image_map', [
                'id_product' => (int) $idProduct,
                'id_image' => (int) $image->id,
                'url_hash' => pSQL(md5($url)),
                'url' => pSQL(Tools::substr($url, 0, 500)),
            ]);
        }
    }

    private function downloadImage(string $url): ?string
    {
        // SEGURIDAD (HIGH): SSRF guard — bloquea fetches a IPs internas/cloud
        // metadata. Sin esto, una URL maliciosa (proveniente del TPV o de un
        // canal externo) podría hacer el servidor PS sondear su red interna.
        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return null;
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) return null;
        $host = strtolower($parts['host']);
        if (in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)) return null;
        $ips = @gethostbynamel($host) ?: [];
        if (empty($ips)) return null;
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return null;
            }
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false, // antes era true — abría puerta a redirect SSRF
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ct = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if ($body === false || $code !== 200 || $body === '') {
            return null;
        }
        $mime = strtolower(trim(strtok($ct, ';') ?: ''));
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'tpv_img_');
        if ($tmp === false) {
            return null;
        }
        file_put_contents($tmp, $body);
        return $tmp;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Variaciones — TPV → PS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Crea/actualiza combinaciones PS a partir de options[] de la API TPV.
     * Cada option TPV (Talla, Color) se mapea a un AttributeGroup PS, y cada
     * value a un Attribute. Después genera combinaciones cartesianas no —
     * usamos el mismo criterio del plugin WC: una combinación por value con
     * UN solo atributo (el TPV no modela combinaciones cruzadas).
     */
    private function syncVariations(int $idProduct, array $options, float $basePrice): void
    {
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        foreach ($options as $opt) {
            $groupName = trim((string) ($opt['option_name'] ?? ''));
            if ($groupName === '') {
                continue;
            }
            $groupId = $this->findOrCreateAttributeGroup($groupName, $idLang);
            if ($groupId === 0) {
                continue;
            }
            foreach ($opt['values'] ?? [] as $val) {
                $valueName = trim((string) ($val['value_name'] ?? ''));
                if ($valueName === '') {
                    continue;
                }
                $povId = (int) ($val['product_option_value_id'] ?? 0);
                $qty = (int) ($val['quantity'] ?? 0);
                $optPrice = (float) ($val['price'] ?? 0);
                $prefix = $val['price_prefix'] ?? '+';
                $priceImpact = $prefix === '-' ? -$optPrice : $optPrice;
                // Barcode de la variante TPV (oc_product_option_value_code.code_bar).
                // Lo propagamos a Combination::ean13/upc/reference según formato.
                $barcode = trim((string) ($val['code_bar'] ?? ''));

                $attrId = $this->findOrCreateAttribute($groupId, $valueName, $idLang);
                if ($attrId === 0) {
                    continue;
                }

                $combId = $povId > 0 ? $this->findCombinationByPov($povId) : 0;
                if ($combId === 0) {
                    // Buscar por producto+atributo si ya existía sin mapeo
                    $combId = $this->findCombinationByAttribute($idProduct, $attrId);
                }

                if ($combId === 0) {
                    $combId = $this->createCombination($idProduct, $attrId, $priceImpact, $barcode);
                    if ($combId === 0) {
                        continue;
                    }
                } elseif ($barcode !== '') {
                    // Combination existente — actualizar barcode si el TPV
                    // lo cambió (ej. el dependiente reasignó el código).
                    $this->updateCombinationBarcode($combId, $barcode);
                }
                if ($povId > 0) {
                    $this->setCombinationMap($combId, $povId);
                }
                StockAvailable::setQuantity($idProduct, $combId, $qty);
            }
        }
    }

    private function findOrCreateAttributeGroup(string $name, int $idLang): int
    {
        try {
            $sql = 'SELECT agl.id_attribute_group FROM ' . _DB_PREFIX_ . 'attribute_group_lang agl WHERE MD5(agl.name) = "' . md5($name) . '" AND agl.id_lang = ' . (int) $idLang ;
            $found = Db::getInstance()->getValue($sql);
            if ($found) {
                return (int) $found;
            }
        } catch (\Throwable $e) {
            TpvSyncLog::error('attribute_group', 0, 'lookup(' . $name . '): ' . $e->getMessage());
        }
        $ag = new AttributeGroup();
        $langs = $this->allLangNameI18n($name);
        $ag->name = $langs;
        $ag->public_name = $langs;
        $ag->group_type = 'select';
        try {
            if ($ag->add()) {
                return (int) $ag->id;
            }
        } catch (\Throwable $e) {
            TpvSyncLog::error('attribute_group', 0, 'add(' . $name . '): ' . $e->getMessage());
        }
        return 0;
    }

    private function findOrCreateAttribute(int $groupId, string $name, int $idLang): int
    {
        try {
            $sql = 'SELECT a.id_attribute FROM ' . _DB_PREFIX_ . 'attribute a JOIN ' . _DB_PREFIX_ . 'attribute_lang al ON al.id_attribute = a.id_attribute WHERE a.id_attribute_group = ' . (int) $groupId . ' AND MD5(al.name) = "' . md5($name) . '" AND al.id_lang = ' . (int) $idLang ;
            $found = Db::getInstance()->getValue($sql);
            if ($found) {
                return (int) $found;
            }
        } catch (\Throwable $e) {
            TpvSyncLog::error('attribute', 0, 'lookup(' . $name . '): ' . $e->getMessage());
        }
        // En PS 9 la clase ObjectModel para "valor de atributo" se llama
        // ProductAttribute (la clase Attribute sin sufijo no existe). Ojo:
        // no confundir con Combination que es `ps_product_attribute` (una
        // variación concreta). ProductAttribute mapea a `ps_attribute`.
        $attrClass = class_exists('ProductAttribute') ? 'ProductAttribute'
            : (class_exists('Attribute') ? 'Attribute' : null);
        if ($attrClass === null) {
            TpvSyncLog::error('attribute', 0, 'Ni ProductAttribute ni Attribute existen en este PS');
            return 0;
        }
        $a = new $attrClass();
        $a->id_attribute_group = $groupId;
        $a->name = $this->allLangNameI18n($name);
        try {
            if ($a->add()) {
                return (int) $a->id;
            }
        } catch (\Throwable $e) {
            TpvSyncLog::error('attribute', 0, 'add(' . $name . '): ' . $e->getMessage());
        }
        return 0;
    }

    /**
     * Devuelve todos los id_shop activos. En mono-shop devuelve [1] (o lo
     * que marque PS_SHOP_DEFAULT). Cacheado en memoria del request.
     *
     * @return array<int,int>
     */
    private function allShopIds(): array
    {
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            if (class_exists('Shop') && method_exists('Shop', 'getShops')) {
                try {
                    $cache = array_map('intval', Shop::getShops(true, null, true));
                } catch (\Throwable $e) {
                    $cache = [];
                }
            }
        }
        return $cache;
    }

    /**
     * Devuelve [id_lang => $value] con el mismo valor repetido en todos los
     * idiomas activos. PS rechaza guardar un ObjectModel multilingüe si falta
     * el valor en cualquier idioma activo. Para el sync TPV no tenemos
     * traducciones por idioma — duplicamos.
     *
     * @return array<int, string>
     */
    private function allLangNameI18n(string $value): array
    {
        static $cache = null;
        if ($cache === null) {
            $ids = [];
            foreach (Language::getLanguages(false) as $l) {
                $ids[] = (int) $l['id_lang'];
            }
            if (empty($ids)) {
                $ids = [(int) Configuration::get('PS_LANG_DEFAULT')];
            }
            $cache = $ids;
        }
        return array_fill_keys($cache, $value);
    }

    private function findCombinationByAttribute(int $idProduct, int $idAttribute): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT pac.id_product_attribute FROM ' . _DB_PREFIX_ . 'product_attribute_combination pac
             JOIN ' . _DB_PREFIX_ . 'product_attribute pa ON pa.id_product_attribute = pac.id_product_attribute
             WHERE pa.id_product = ' . (int) $idProduct . '
               AND pac.id_attribute = ' . (int) $idAttribute . ''
        );
    }

    private function createCombination(int $idProduct, int $idAttribute, float $priceImpact, string $barcode = ''): int
    {
        $pa = new Combination();
        $pa->id_product = $idProduct;
        $pa->price = $priceImpact;
        $pa->wholesale_price = 0;
        $pa->default_on = 0;
        // Barcode escaneable de la combinación: PS usa ean13 (8-13 dígitos) o
        // upc (12 dígitos). Si viene un código alfanumérico (reference-style),
        // lo guardamos en `reference` que admite cualquier formato.
        if ($barcode !== '') {
            if (preg_match('/^\d{8,13}$/', $barcode)) {
                $pa->ean13 = $barcode;
            } elseif (preg_match('/^\d{12}$/', $barcode)) {
                $pa->upc = $barcode;
            } else {
                $pa->reference = mb_substr($barcode, 0, 64);
            }
        }
        try {
            if (!$pa->add()) {
                return 0;
            }
            Db::getInstance()->insert('product_attribute_combination', [
                'id_attribute' => (int) $idAttribute,
                'id_product_attribute' => (int) $pa->id,
            ]);
            return (int) $pa->id;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Actualiza el barcode (ean13/upc/reference) de una combination existente.
     * Llamado cuando el TPV cambia el code_bar de una variante ya mapeada.
     */
    private function updateCombinationBarcode(int $idCombination, string $barcode): void
    {
        if ($idCombination <= 0 || $barcode === '') return;
        $pa = new Combination($idCombination);
        if (!Validate::isLoadedObject($pa)) return;
        if (preg_match('/^\d{8,13}$/', $barcode)) {
            $pa->ean13 = $barcode;
        } elseif (preg_match('/^\d{12}$/', $barcode)) {
            $pa->upc = $barcode;
        } else {
            $pa->reference = mb_substr($barcode, 0, 64);
        }
        try {
            $pa->update();
        } catch (\Throwable $e) {
            TpvSyncLog::error('combination', $idCombination, 'updateBarcode: ' . $e->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Variaciones — PS → TPV
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * @param array<int,array> $combinations resultado de Product::getAttributeCombinations
     */
    private function buildOptionsForTpv(array $combinations, float $basePrice): array
    {
        // Reagrupar por id_product_attribute para reconstruir cada
        // combinación entera (PS devuelve N filas por combinación, una por
        // attribute_group). Cada combinación es UNA SKU única en PS con su
        // ean13/upc/reference propio.
        $byAttr = [];
        foreach ($combinations as $c) {
            $idAttr = (int) ($c['id_product_attribute'] ?? 0);
            if ($idAttr === 0) continue;
            $gname = trim((string) ($c['group_name'] ?? ''));
            $vname = trim((string) ($c['attribute_name'] ?? ''));
            if ($gname === '' || $vname === '') continue;

            if (!isset($byAttr[$idAttr])) {
                $byAttr[$idAttr] = [
                    'groups'   => [],   // [group_name => value_name]
                    'quantity' => max(0, (int) ($c['quantity'] ?? 0)),
                    'price_diff' => (float) ($c['price'] ?? 0),
                    // Identificadores comerciales por combinación PS:
                    'ean13'     => trim((string) ($c['ean13'] ?? '')),
                    'upc'       => trim((string) ($c['upc'] ?? '')),
                    'reference' => trim((string) ($c['reference'] ?? '')),
                ];
            }
            $byAttr[$idAttr]['groups'][$gname] = $vname;
        }

        if (empty($byAttr)) return [];

        // Detectar atributos involucrados (ej. "Talla", "Color"). Orden
        // estable: el orden de la primera combinación que veamos.
        $groupOrder = [];
        foreach ($byAttr as $combo) {
            foreach (array_keys($combo['groups']) as $gname) {
                if (!in_array($gname, $groupOrder, true)) $groupOrder[] = $gname;
            }
        }

        // Si solo hay UN attribute group (ej. solo Talla) → paridad con WC:
        // 1 opción "Talla" con valores S/M/L. Stock por value = quantity de
        // la combinación. Barcode por value (GTIN preferente, fallback
        // reference).
        if (count($groupOrder) === 1) {
            $gname = $groupOrder[0];
            $values = [];  // value_name => meta acumulada
            foreach ($byAttr as $combo) {
                $vname = $combo['groups'][$gname] ?? '';
                if ($vname === '') continue;
                $bc = $combo['ean13'] !== '' ? $combo['ean13']
                    : ($combo['upc'] !== '' ? $combo['upc'] : $combo['reference']);
                if (!isset($values[$vname])) {
                    $values[$vname] = [
                        'quantity'   => 0,
                        'price_diff' => $combo['price_diff'],
                        'barcode'    => '',
                    ];
                }
                $values[$vname]['quantity']  += $combo['quantity'];
                $values[$vname]['price_diff'] = $combo['price_diff'];
                if ($bc !== '' && $values[$vname]['barcode'] === '') {
                    $values[$vname]['barcode'] = $bc;
                }
            }
            return [$this->buildTpvOption($gname, $values)];
        }

        // Multi-atributo (Talla × Color, etc.): aplanar a UNA sola opción
        // con valores combinados ("S-Rojo", "M-Verde", ...). Solo aparecen
        // las combinaciones que existen como id_product_attribute en PS
        // (no producto cartesiano completo). Mismo patrón que el plugin WC
        // — evita la sobreventa que causaría sumar stock por attribute_group
        // independiente y preserva el barcode por combinación real.
        $combinedLabel = implode('-', $groupOrder);
        $values = [];
        foreach ($byAttr as $combo) {
            $parts = [];
            foreach ($groupOrder as $gname) {
                $vname = $combo['groups'][$gname] ?? '';
                if ($vname === '') { $parts = null; break; }
                $parts[] = $vname;
            }
            if ($parts === null) continue;
            $comboLabel = implode('-', $parts);
            $bc = $combo['ean13'] !== '' ? $combo['ean13']
                : ($combo['upc'] !== '' ? $combo['upc'] : $combo['reference']);
            if (!isset($values[$comboLabel])) {
                $values[$comboLabel] = [
                    'quantity'   => 0,
                    'price_diff' => $combo['price_diff'],
                    'barcode'    => '',
                ];
            }
            $values[$comboLabel]['quantity']  += $combo['quantity'];
            $values[$comboLabel]['price_diff'] = $combo['price_diff'];
            if ($bc !== '' && $values[$comboLabel]['barcode'] === '') {
                $values[$comboLabel]['barcode'] = $bc;
            }
        }
        return [$this->buildTpvOption($combinedLabel, $values)];
    }

    /**
     * Construye una entry del array `options` en formato esperado por la
     * API del TPV. Helper compartido entre paths 1-attr y multi-attr.
     */
    private function buildTpvOption(string $name, array $values): array
    {
        $out = [];
        foreach ($values as $vname => $meta) {
            $entry = [
                'name'         => $vname,
                'quantity'     => (int) $meta['quantity'],
                'price'        => abs((float) $meta['price_diff']),
                'price_prefix' => (float) $meta['price_diff'] < 0 ? '-' : '+',
                'subtract'     => 1,
            ];
            if (!empty($meta['barcode'])) {
                $entry['barcode'] = $meta['barcode'];
            }
            $out[] = $entry;
        }
        return [
            'name'     => $name,
            'type'     => 'select',
            'required' => true,
            'values'   => $out,
        ];
    }

    /**
     * Tras un POST /products que devuelve options[] con IDs, persiste el
     * mapeo combination_ps ↔ product_option_value_id.
     *
     * El plugin agrupa por group_name+value_name, el TPV responde los IDs
     * generados. Buscamos la combinación PS que matchea ese par y grabamos.
     */
    private function mapCombinationIds(int $idProduct, array $options): void
    {
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $product = new Product($idProduct, false, $idLang);
        if (!Validate::isLoadedObject($product)) {
            return;
        }
        $combinations = $product->getAttributeCombinations($idLang);
        // Indexado case-insensitive por (group|value) → combination_id
        $byGV = [];
        foreach ($combinations as $c) {
            $gk = mb_strtolower(trim((string) ($c['group_name'] ?? '')));
            $vk = mb_strtolower(trim((string) ($c['attribute_name'] ?? '')));
            if ($gk !== '' && $vk !== '') {
                $byGV[$gk . '|' . $vk] = (int) $c['id_product_attribute'];
            }
        }
        $mapped = 0;
        foreach ($options as $opt) {
            $gname = mb_strtolower(trim((string) ($opt['option_name'] ?? '')));
            foreach ($opt['values'] ?? [] as $val) {
                $vname = mb_strtolower(trim((string) ($val['value_name'] ?? '')));
                $povId = (int) ($val['product_option_value_id'] ?? 0);
                if ($povId === 0 || $vname === '') {
                    continue;
                }
                $key = $gname . '|' . $vname;
                if (isset($byGV[$key])) {
                    $this->setCombinationMap($byGV[$key], $povId);
                    $mapped++;
                }
            }
        }
        if ($mapped > 0) {
            TpvSyncLog::ok('product', $idProduct, "Mapeadas $mapped combinaciones PS↔TPV");
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  Reconciliación bidireccional (post-reconnect)
    // ═══════════════════════════════════════════════════════════════════════════
    //
    // Llamada cuando el cliente pulsa "Reconectar" tras una pausa >5 min.
    // Compara catálogos TPV ↔ PS y aplica solo los deltas. Política:
    //
    //   1. Producto en TPV pero no en PS  → upsert (crear en PS).
    //   2. Producto en PS  pero no en TPV → push (crear en TPV).
    //   3. Producto en ambos pero distinto → gana el lado con `updated_at`
    //      más reciente (TPV: products.date_modified; PS: ps_product.date_upd).
    //
    // No bloquea la UI: se procesa todo en una sola llamada (los catálogos
    // suelen ser de cientos de productos, no millones; una pasada batch
    // termina en <30s para 5000 productos).

    /**
     * @return array{checked:int,synced:int,fixed:int,skipped:int,errors:int}
     */
    public function reconcileBidirectional(): array
    {
        $stats = ['checked' => 0, 'synced' => 0, 'fixed' => 0, 'skipped' => 0, 'errors' => 0];

        // Paso 1: snapshot completo del TPV (paginado por la API).
        // Pedimos campos mínimos para comparar: model, sku, price, status,
        // quantity, date_modified.
        try {
            $tpvList = $this->api->getAll('/products', ['per_page' => 200]);
        } catch (\Throwable $e) {
            TpvSyncLog::error('reconcile_bi', 0, 'getAll TPV: ' . $e->getMessage());
            $stats['errors']++;
            return $stats;
        }

        $tpvByModel = [];   // model → tpv data
        $tpvIds = [];
        foreach ($tpvList as $p) {
            $tpvId = (int) ($p['product_id'] ?? 0);
            if (!$tpvId) {
                continue;
            }
            $tpvIds[$tpvId] = true;
            $model = trim((string) ($p['model'] ?? ''));
            if ($model !== '') {
                $tpvByModel[$model] = $p;
            }
        }

        // Paso 2: snapshot del PS — id_product, ean13, reference (≈ model/sku),
        // date_upd. Limit 5000 para no devastar memoria.
        $psRows = Db::getInstance()->executeS(
            'SELECT p.id_product, p.ean13, p.reference, p.date_upd
             FROM ' . _DB_PREFIX_ . 'product p
             WHERE p.state = 1
             LIMIT 5000'
        ) ?: [];
        $psByModel = [];
        foreach ($psRows as $row) {
            $key = trim((string) ($row['ean13'] ?? '')) ?: trim((string) ($row['reference'] ?? ''));
            if ($key !== '') {
                $psByModel[$key] = $row;
            }
        }

        // Paso 3: para cada producto del TPV, decidir.
        foreach ($tpvList as $p) {
            $tpvId   = (int) ($p['product_id'] ?? 0);
            if (!$tpvId) {
                continue;
            }
            $stats['checked']++;
            $psId = $this->findPsByTpv($tpvId);

            if ($psId === 0) {
                // No existe vínculo. ¿Coincide por model con uno de PS sin mapeo?
                $model = trim((string) ($p['model'] ?? ''));
                if ($model !== '' && isset($psByModel[$model])) {
                    // Hay producto PS con mismo model: lo mapeamos sin re-importar.
                    $psId = (int) $psByModel[$model]['id_product'];
                    $this->setMap($psId, $tpvId);
                    $stats['synced']++;
                    continue;
                }
                // No existe en PS → crear.
                try {
                    $r = $this->upsert($p);
                    if ($r === 'created' || $r === 'updated') {
                        $stats['synced']++;
                    } else {
                        $stats['skipped']++;
                    }
                } catch (\Throwable $e) {
                    TpvSyncLog::error('reconcile_bi', $tpvId, 'upsert: ' . $e->getMessage());
                    $stats['errors']++;
                }
                continue;
            }

            // Existe en ambos: ¿hay diferencias? Política updated_at gana.
            $tpvUpdated = strtotime((string) ($p['date_modified'] ?? ''));
            $psRow = Db::getInstance()->getRow(
                'SELECT date_upd FROM ' . _DB_PREFIX_ . 'product
                 WHERE id_product = ' . (int) $psId
            );
            $psUpdated = $psRow ? strtotime((string) ($psRow['date_upd'] ?? '')) : 0;

            // Comparación rápida por campos clave (evita upsert costoso si
            // todo coincide). Si stock difiere, lo arreglamos siempre — no
            // hace falta condicionar al timestamp porque el stock no se
            // modifica con date_upd y queremos que el TPV sea fuente.
            $tpvQty = (float) ($p['quantity'] ?? 0);
            $psQty  = (float) StockAvailable::getQuantityAvailableByProduct($psId, 0);
            if (abs($psQty - $tpvQty) > 0.0001) {
                $this->updateStock($tpvId, $tpvQty);
                $stats['fixed']++;
            }

            // Si TPV es más reciente → re-importar a PS (gana TPV).
            // Si PS  es más reciente → empujar PS al TPV (gana PS).
            if ($tpvUpdated > 0 && $tpvUpdated > $psUpdated + 60) {
                try {
                    $this->upsert($p);
                    $stats['fixed']++;
                } catch (\Throwable $e) {
                    TpvSyncLog::error('reconcile_bi', $tpvId, 'upsert (TPV gana): ' . $e->getMessage());
                    $stats['errors']++;
                }
            } elseif ($psUpdated > 0 && $psUpdated > $tpvUpdated + 60) {
                try {
                    if ($this->pushProductToTpv($psId)) {
                        $stats['fixed']++;
                    }
                } catch (\Throwable $e) {
                    TpvSyncLog::error('reconcile_bi', $tpvId, 'push (PS gana): ' . $e->getMessage());
                    $stats['errors']++;
                }
            }
        }

        // Paso 4: productos en PS sin vínculo → push al TPV.
        // (Ya cubrimos los que tienen mismo model arriba; aquí los huérfanos.)
        $orphans = Db::getInstance()->executeS(
            'SELECT p.id_product FROM ' . _DB_PREFIX_ . 'product p
             LEFT JOIN ' . _DB_PREFIX_ . 'tpv_sync_product_map m ON m.id_product = p.id_product
             WHERE m.id_product IS NULL AND p.state = 1
             LIMIT 1000'
        ) ?: [];
        foreach ($orphans as $row) {
            $idProduct = (int) $row['id_product'];
            try {
                if ($this->pushProductToTpv($idProduct)) {
                    $stats['synced']++;
                } else {
                    $stats['skipped']++;
                }
            } catch (\Throwable $e) {
                TpvSyncLog::error('reconcile_bi', $idProduct, 'push huérfano: ' . $e->getMessage());
                $stats['errors']++;
            }
        }

        TpvSyncLog::ok('reconcile_bi', 0, sprintf(
            'checked=%d synced=%d fixed=%d skipped=%d errors=%d',
            $stats['checked'], $stats['synced'], $stats['fixed'], $stats['skipped'], $stats['errors']
        ));
        return $stats;
    }
}
