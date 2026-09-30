<?php
declare(strict_types=1);
/**
 * Sincronización de pedidos — PrestaShop ↔ TPV.
 *
 * Flujos:
 *   sendToTpv(idOrder)       — PS → TPV: POST /orders al validar el pedido.
 *   onPsStatusChanged()      — PS → TPV: PATCH /orders/{id}/status al cambiar estado.
 *   onPsRefund()             — PS → TPV: POST /orders/{id}/returns por cada línea de slip.
 *   updatePsStatus()         — TPV → PS: llamado desde el webhook cuando el TPV cambia estado.
 *   createPsRefund()         — TPV → PS: crea OrderSlip en PS cuando el TPV devuelve.
 *
 * Mapeo order: tabla PREFIX_tpv_sync_order_map (id_order_ps ⇄ tpv_order_id).
 *
 * Idempotency-Keys determinísticas:
 *   - 'ps-order-<id_order>'              para POST /orders
 *   - 'ps-refund-<id_slip>-<tpv_prod>'   para POST /orders/{id}/returns
 */
class TpvSyncOrder
{
    private TpvSyncApiClient $api;

    /**
     * Mapa order_state PS → TPV order_status_id.
     * Los IDs del TPV corresponden a la tabla oc_order_status del OpenCart
     * modificado (1=pendiente, 2=en proceso, 3=enviado, 5=completado, 7=cancelado,
     * 11=dinero devuelto, 14=expirado).
     *
     * PS no tiene valores fijos de order_state (son filas en `order_state`),
     * así que mapeamos por los IDs "canónicos" que PS crea en una instalación
     * limpia. Si el cliente tiene IDs custom, ver método mapPsStateToTpv() —
     * busca por nombre traducido como fallback.
     */
    private const PS_STATE_TO_TPV = [
        // order_state.id → tpv status
        1 => 1,  // Awaiting check payment → pendiente
        2 => 2,  // Payment accepted → en proceso
        3 => 2,  // Processing in progress → en proceso
        4 => 3,  // Shipped → enviado
        5 => 5,  // Delivered → completado
        6 => 7,  // Canceled → cancelado
        7 => 11, // Refunded → dinero devuelto
        8 => 7,  // Payment error → cancelado
        9 => 2,  // On backorder (paid) → en proceso
        10 => 1, // On backorder (not paid) → pendiente
        11 => 1, // Awaiting bank wire → pendiente
        12 => 1, // Remote payment accepted → pendiente
    ];

    /** Mapa inverso TPV → nombre PS (buscamos el order_state correspondiente por nombre). */
    private const TPV_STATE_TO_PS_NAME = [
        1 => 'Awaiting bank wire payment', // pendiente
        2 => 'Payment accepted',
        3 => 'Shipped',
        5 => 'Delivered',
        7 => 'Canceled',
        11 => 'Refunded',
        14 => 'Canceled',
    ];

    public function __construct(TpvSyncApiClient $api)
    {
        $this->api = $api;
    }

    // ─── Mapa orders ─────────────────────────────────────────────────────────

    public function findTpvByPs(int $idOrder): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT tpv_order_id FROM ' . _DB_PREFIX_ . 'tpv_sync_order_map
             WHERE id_order = ' . (int) $idOrder . ''
        );
    }

    public function findPsByTpv(int $tpvOrderId): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT id_order FROM ' . _DB_PREFIX_ . 'tpv_sync_order_map
             WHERE tpv_order_id = ' . (int) $tpvOrderId . ''
        );
    }

    private function setOrderMap(int $idOrder, int $tpvOrderId): void
    {
        Db::getInstance()->execute(
            'INSERT INTO ' . _DB_PREFIX_ . 'tpv_sync_order_map (id_order, tpv_order_id, created_at)
             VALUES (' . (int) $idOrder . ', ' . (int) $tpvOrderId . ', NOW())
             ON DUPLICATE KEY UPDATE tpv_order_id = ' . (int) $tpvOrderId
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  PS → TPV
    // ═══════════════════════════════════════════════════════════════════════

    public function sendToTpv(int $idOrder): void
    {
        // Sin módulo de pedidos activado solo descontamos stock (lo gestiona
        // actionUpdateQuantity) y salimos.
        if (!Configuration::get('TPVSYNC_MODULE_ORDERS')) {
            return;
        }
        if ($this->findTpvByPs($idOrder) > 0) {
            return; // ya enviado
        }

        $order = new Order($idOrder);
        if (!Validate::isLoadedObject($order)) {
            return;
        }
        $customer = new Customer((int) $order->id_customer);
        $products = $this->buildProducts($order);
        if (empty($products)) {
            TpvSyncLog::skip('order', $idOrder, 'sin productos mapeados al TPV');
            return;
        }

        // Evitar doble descuento: el TPV descuenta stock al registrar el pedido,
        // así que los hooks de actionUpdateQuantity que dispara PS al validar
        // no deben re-empujar. Marcamos un skip temporal durante este request.
        $GLOBALS['tpvsync_skip_stock_push'] = true;

        $vouchers = $this->buildVouchers($order);
        $payment = $this->buildAddress($order, 'invoice');
        $shipping = $this->buildAddress($order, 'delivery');
        $idTax = $this->resolveTaxId($order, $customer);

        $payload = [
            'products' => $products,
            'payment_method' => (string) $order->payment,
            'total' => (float) $order->total_paid,
            'comment' => 'PrestaShop #' . $idOrder,
            'firstname' => (string) $customer->firstname,
            'lastname' => (string) $customer->lastname,
            'email' => (string) $customer->email,
            'telephone' => (string) ($payment['_phone'] ?? ''),
        ];
        unset($payment['_phone']);
        if ($idTax !== '') {
            $payload['id_tax'] = $idTax;
        }
        if (!empty($payment)) {
            $payload['payment'] = $payment;
        }
        if (!empty($shipping)) {
            $payload['shipping'] = $shipping;
        }
        if (!empty($vouchers)) {
            $payload['vouchers'] = $vouchers;
        }

        $idem = 'ps-order-' . $idOrder;
        $result = $this->api->post('/orders', $payload, $idem);

        $GLOBALS['tpvsync_skip_stock_push'] = false;

        if (!empty($result['data']['order_id'])) {
            $tpvOrderId = (int) $result['data']['order_id'];
            $this->setOrderMap($idOrder, $tpvOrderId);
            TpvSyncLog::ok('order', $idOrder, "Creado en TPV order_id=$tpvOrderId");
            return;
        }

        $code = $result['errors'][0]['error'] ?? '';
        $msg = $result['errors'][0]['message'] ?? (string) json_encode($result);

        if ($code === 'insufficient_stock' || strpos((string) $msg, 'insufficient_stock') !== false) {
            // El TPV no tiene stock y PS sí lo cobró: mejor on-hold que auto-refund.
            $this->moveToOnHold($order, 'TPV sin stock: ' . $msg);
            TpvSyncLog::error('order', $idOrder, '409 insufficient_stock: ' . $msg);
            return;
        }
        TpvSyncLog::error('order', $idOrder, 'POST TPV: ' . $msg);
        (new TpvSyncQueue($this->api))->enqueue('order.send', [
            'id_order' => $idOrder,
        ], Tools::substr((string) $msg, 0, 500));
    }

    private function moveToOnHold(Order $order, string $note): void
    {
        // En PS no existe "on-hold" nativo: si el cliente ha definido uno lo usamos,
        // si no caemos al cancelled para que el admin actúe.
        $onHoldId = (int) Configuration::get('PS_OS_AWAITING_PAYMENT') ?: 0;
        if ($onHoldId <= 0) {
            $onHoldId = (int) Configuration::get('PS_OS_CANCELED');
        }
        if ($onHoldId > 0) {
            $history = new OrderHistory();
            $history->id_order = (int) $order->id;
            $history->changeIdOrderState($onHoldId, (int) $order->id);
            $history->add();
        }
        // Añadir comentario admin
        try {
            $msg = new Message();
            $msg->message = $note;
            $msg->id_order = (int) $order->id;
            $msg->private = 1;
            $msg->add();
        } catch (\Throwable $e) {
            // no-op
        }
    }

    /**
     * Construye la lista products[] desde los OrderDetail de PS.
     * Mapea cada linea a product_id TPV. Skipea silenciosamente las líneas
     * sin mapeo — es legítimo en tiendas mixtas (productos digitales WC-only
     * que nunca se vendieron en el TPV).
     */
    private function buildProducts(Order $order): array
    {
        $ps = (new TpvSyncProduct($this->api));
        $out = [];
        foreach ($order->getProducts() as $row) {
            $psId = (int) $row['product_id'];
            $tpvId = $ps->findTpvByPs($psId);
            if ($tpvId === 0) {
                continue;
            }
            $qty = (float) $row['product_quantity'];
            if ($qty <= 0) {
                continue;
            }
            // PS guarda precios con/ sin IVA en distintos campos según configuración.
            // `total_price_tax_excl`/`total_price_tax_incl` vienen ya calculados
            // con los descuentos aplicados.
            $totNet = isset($row['total_price_tax_excl'])
                ? (float) $row['total_price_tax_excl']
                : (float) $row['product_price'] * $qty;
            $totGross = isset($row['total_price_tax_incl'])
                ? (float) $row['total_price_tax_incl']
                : $totNet;
            $totTax = max(0.0, $totGross - $totNet);
            $qtySafe = $qty > 0 ? $qty : 1.0;
            $out[] = [
                'product_id' => $tpvId,
                'name' => (string) $row['product_name'],
                'quantity' => $qty,
                'price' => $totNet / $qtySafe,
                'tax' => $totTax / $qtySafe,
                'total' => $totNet,
            ];
        }
        return $out;
    }

    /**
     * Cupones PS → vouchers[] para la API TPV.
     * En PS los cupones se aplican al pedido (tabla `order_cart_rule`). Cada
     * fila tiene `value` (bruto con IVA) y `name`/`code`.
     */
    private function buildVouchers(Order $order): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT ocr.value AS amount, cr.code
             FROM ' . _DB_PREFIX_ . 'order_cart_rule ocr
             LEFT JOIN ' . _DB_PREFIX_ . 'cart_rule cr ON cr.id_cart_rule = ocr.id_cart_rule
             WHERE ocr.id_order = ' . (int) $order->id
        );
        if (!$rows) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $amt = round((float) $r['amount'], 2);
            if ($amt <= 0) {
                continue;
            }
            $code = trim((string) ($r['code'] ?? ''));
            $out[] = ['code' => $code, 'amount' => $amt];
        }
        return $out;
    }

    /**
     * Normaliza una Address PS al formato que espera la API TPV.
     * Tipo: 'invoice' (billing) o 'delivery' (shipping).
     * Ojo: la API usa keys `address_1`, `address_2`, `city`, `postcode`,
     * `country`, `zone`; nosotros mandamos nombres — la API los resuelve.
     *
     * Extra: devuelve '_phone' para que el caller pueda extraerlo al top-level
     * del payload (el TPV no tiene phone por dirección, solo uno por pedido).
     */
    private function buildAddress(Order $order, string $type): array
    {
        $addrId = $type === 'invoice' ? (int) $order->id_address_invoice : (int) $order->id_address_delivery;
        if ($addrId === 0) {
            return [];
        }
        $addr = new Address($addrId);
        if (!Validate::isLoadedObject($addr)) {
            return [];
        }
        $country = new Country((int) $addr->id_country, (int) Configuration::get('PS_LANG_DEFAULT'));
        $state = (int) $addr->id_state > 0
            ? new State((int) $addr->id_state)
            : null;

        return [
            'company' => (string) $addr->company,
            'address_1' => (string) $addr->address1,
            'address_2' => (string) $addr->address2,
            'city' => (string) $addr->city,
            'postcode' => (string) $addr->postcode,
            'country' => Validate::isLoadedObject($country) ? (string) $country->name : '',
            'country_id' => 0, // el TPV los resuelve por nombre
            'zone' => $state && Validate::isLoadedObject($state) ? (string) $state->name : '',
            'zone_id' => 0,
            '_phone' => (string) ($addr->phone ?: $addr->phone_mobile),
        ];
    }

    /**
     * NIF/CIF/NIE del cliente. PS estándar no tiene un campo fiscal global
     * (usa address->vat_number), así que preferimos:
     *   1. order->invoice_address_vat_number (PS 9+) si existe
     *   2. address_invoice->vat_number
     *   3. address_invoice->dni
     *   4. customer->id_tax (meta custom) si el cliente lo define
     * Hook `tpvsync_customer_tax_id` disponible para casos a medida vía
     * Hook::exec().
     */
    private function resolveTaxId(Order $order, Customer $customer): string
    {
        $addr = new Address((int) $order->id_address_invoice);
        $candidates = [];
        if (Validate::isLoadedObject($addr)) {
            $candidates[] = (string) $addr->vat_number;
            $candidates[] = (string) $addr->dni;
        }
        foreach ($candidates as $v) {
            if ($v !== '') {
                return trim($v);
            }
        }
        // Hook opcional — no bloqueamos si no lo implementa ningún módulo.
        if (class_exists('Hook')) {
            try {
                $res = Hook::exec('tpvsyncCustomerTaxId', [
                    'order' => $order,
                    'customer' => $customer,
                    'address' => $addr,
                ], null, true);
                if (is_array($res)) {
                    foreach ($res as $v) {
                        if (is_string($v) && $v !== '') {
                            return trim($v);
                        }
                    }
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
        return '';
    }

    // ─── Cambio de estado PS → TPV ───────────────────────────────────────────

    public function onPsStatusChanged(int $idOrder, int $idOrderState): void
    {
        // Si el cambio vino del TPV (webhook), no lo devolvemos.
        if (!empty($GLOBALS['tpvsync_skip_status_push'])) {
            return;
        }
        $tpvOrderId = $this->findTpvByPs($idOrder);
        if ($tpvOrderId === 0) {
            return;
        }
        $tpvStatus = $this->mapPsStateToTpv($idOrderState);
        if ($tpvStatus === 0) {
            return;
        }
        $this->api->patch("/orders/$tpvOrderId/status", [
            'order_status_id' => $tpvStatus,
            'comment' => 'Estado actualizado desde PrestaShop',
        ]);
        TpvSyncLog::ok('order', $idOrder, "Estado PS→TPV status_id=$tpvStatus");
    }

    private function mapPsStateToTpv(int $psStateId): int
    {
        if (isset(self::PS_STATE_TO_TPV[$psStateId])) {
            return self::PS_STATE_TO_TPV[$psStateId];
        }
        // Fallback por nombre — por si el cliente reorganizó IDs
        $state = new OrderState($psStateId, (int) Configuration::get('PS_LANG_DEFAULT'));
        if (!Validate::isLoadedObject($state)) {
            return 0;
        }
        $name = Tools::strtolower((string) $state->name);
        if (str_contains($name, 'cancel')) {
            return 7;
        }
        if (str_contains($name, 'refund') || str_contains($name, 'reembols')) {
            return 11;
        }
        if (str_contains($name, 'deliver') || str_contains($name, 'entreg')) {
            return 5;
        }
        if (str_contains($name, 'ship') || str_contains($name, 'envi')) {
            return 3;
        }
        if (str_contains($name, 'paid') || str_contains($name, 'acept') || str_contains($name, 'pagad')) {
            return 2;
        }
        return 0;
    }

    // ─── Refund PS → TPV ─────────────────────────────────────────────────────

    /**
     * Empuja a la API la devolucion de un order slip de PrestaShop.
     *
     * @return bool true si la API confirmo la devolucion (o si no habia nada que
     *              propagar). Antes era void y la cola devolvia true a ciegas:
     *              una devolucion fallida se marcaba como sincronizada y se
     *              borraba de la cola (BUG-D, auditoria 2026-08-26). Es dinero.
     *
     * Los caminos y su signo estan tabulados en docs/ONPSREFUND_CAMINOS.md. OJO
     * al cambiarlos: un `return;` sin valor devuelve null, que es falsy, y
     * convertiria un exito en un reintento perpetuo.
     *
     * NO reencola: de eso se encarga quien llama (el hook de PS o la cola). Si
     * reencolara aqui Y ademas devolviera false, la cola reintentaria la entrada
     * y la misma devolucion quedaria encolada dos veces.
     */
    public function onPsRefund(int $idOrder, int $idOrderSlip): bool
    {
        if (!empty($GLOBALS['tpvsync_skip_refund_push'])) {
            return true;   // omision deliberada (la devolucion viene DEL TPV), no es un fallo
        }
        $tpvOrderId = $this->findTpvByPs($idOrder);
        if ($tpvOrderId === 0) {
            TpvSyncLog::skip('order', $idOrder, "Refund slip $idOrderSlip: pedido sin mapeo TPV");

            return true;   // terminal: sin mapeo no hay nada que propagar, reintentar no lo arregla
        }
        $slip = new OrderSlip($idOrderSlip);
        if (!Validate::isLoadedObject($slip)) {
            return true;   // terminal: un slip que no carga no se arregla reintentando
        }

        // Líneas del slip con producto + cantidad
        $rows = Db::getInstance()->executeS(
            'SELECT osd.id_order_detail, osd.product_quantity, osd.amount_tax_incl,
                    od.product_name, od.product_id
             FROM ' . _DB_PREFIX_ . 'order_slip_detail osd
             JOIN ' . _DB_PREFIX_ . 'order_detail od ON od.id_order_detail = osd.id_order_detail
             WHERE osd.id_order_slip = ' . (int) $idOrderSlip
        );
        if (!$rows) {
            return true;   // terminal: slip sin lineas, nada que propagar
        }

        $ps = new TpvSyncProduct($this->api);
        $errors = 0;
        foreach ($rows as $r) {
            $tpvProductId = $ps->findTpvByPs((int) $r['product_id']);
            if ($tpvProductId === 0) {
                continue;
            }
            $qty = abs((int) $r['product_quantity']);
            if ($qty <= 0) {
                continue;
            }
            $idem = "ps-refund-$idOrderSlip-$tpvProductId";
            $res = $this->api->post("/orders/$tpvOrderId/returns", [
                'product_id' => $tpvProductId,
                'quantity' => $qty,
                'product_name' => (string) $r['product_name'],
                'comment' => 'Refund PrestaShop slip #' . $idOrderSlip,
                'return_reason_id' => 0,
                'return_action_id' => 0,
                // Sin return_status_id: la API da de alta la devolución ya
                // ejecutada (3) y rechaza cualquier otro con 422
                // invalid_return_status (api_tpv, 22-08-2026). Con el 1 que se
                // mandaba, ningún reembolso llegaba al TPV.
            ], $idem);
            if (empty($res['data']['return_id']) && empty($res['return_id'])) {
                $errors++;
                $msg = $res['errors'][0]['message'] ?? (string) json_encode($res);
                TpvSyncLog::error('order', $idOrder, "Refund slip $idOrderSlip prod $tpvProductId: $msg");
            }
        }
        if ($errors > 0) {
            // El enqueue vivia aqui. Se ha movido al hook de PrestaShop
            // (tpvsync.php::hookActionOrderSlipAdd): si reencolaramos aqui Y
            // ademas devolvieramos false, la cola marcaria la entrada como
            // fallida y la reintentaria — con la misma devolucion encolada dos
            // veces. Reencolar es de quien gestiona la cola, no de esta funcion.
            return false;
        }
        TpvSyncLog::ok('order', $idOrder, "Refund slip $idOrderSlip propagado a TPV order $tpvOrderId");

        return true;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  TPV → PS (llamados desde el webhook handler)
    // ═══════════════════════════════════════════════════════════════════════

    public function updatePsStatus(int $tpvOrderId, int $tpvStatusId): void
    {
        $idOrder = $this->findPsByTpv($tpvOrderId);
        if ($idOrder === 0) {
            return;
        }
        $order = new Order($idOrder);
        if (!Validate::isLoadedObject($order)) {
            return;
        }
        $psStateId = $this->mapTpvToPsState($tpvStatusId);
        if ($psStateId === 0) {
            return;
        }
        $GLOBALS['tpvsync_skip_status_push'] = true;
        try {
            $history = new OrderHistory();
            $history->id_order = $idOrder;
            $history->changeIdOrderState($psStateId, $idOrder);
            $history->add();
        } finally {
            $GLOBALS['tpvsync_skip_status_push'] = false;
        }
        TpvSyncLog::ok('order', $idOrder, "Estado TPV $tpvStatusId → PS state $psStateId");
    }

    private function mapTpvToPsState(int $tpvStatusId): int
    {
        // Direct known-ID shortcuts (instalación PS estándar)
        foreach (self::PS_STATE_TO_TPV as $psId => $tpvId) {
            if ($tpvId === $tpvStatusId) {
                return (int) $psId;
            }
        }
        // Fallback por nombre
        $name = self::TPV_STATE_TO_PS_NAME[$tpvStatusId] ?? '';
        if ($name === '') {
            return 0;
        }
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $found = Db::getInstance()->getValue(
            'SELECT os.id_order_state FROM ' . _DB_PREFIX_ . 'order_state os
             JOIN ' . _DB_PREFIX_ . 'order_state_lang osl ON osl.id_order_state = os.id_order_state
             WHERE osl.name LIKE "' . pSQL($name) . '%"
               AND osl.id_lang = ' . (int) $idLang . ''
        );
        return (int) $found;
    }

    /**
     * Crea un OrderSlip en PS cuando el TPV nos notifica una devolución.
     * No reenviamos de vuelta gracias al flag $GLOBALS['tpvsync_skip_refund_push'].
     *
     * Payload desde el webhook: total, products[{product_id, quantity}].
     * Si solo llega total (sin líneas) hacemos un slip "resumen" sobre el pedido.
     */
    public function createPsRefund(int $tpvOrderId, array $fields): void
    {
        $idOrder = $this->findPsByTpv($tpvOrderId);
        if ($idOrder === 0) {
            return;
        }
        $order = new Order($idOrder);
        if (!Validate::isLoadedObject($order)) {
            return;
        }
        $total = (float) ($fields['total'] ?? 0);
        if ($total <= 0) {
            return;
        }

        $GLOBALS['tpvsync_skip_refund_push'] = true;
        try {
            $slip = new OrderSlip();
            $slip->id_customer = (int) $order->id_customer;
            $slip->id_order = $idOrder;
            $slip->conversion_rate = 1.0;
            $slip->total_products_tax_incl = $total;
            $slip->total_products_tax_excl = $total;
            $slip->total_shipping_tax_incl = 0;
            $slip->total_shipping_tax_excl = 0;
            $slip->amount = $total;
            $slip->shipping_cost = 0;
            $slip->shipping_cost_amount = 0;
            $slip->partial = 1;
            if (!$slip->add()) {
                TpvSyncLog::error('order', $idOrder, 'No se pudo crear OrderSlip');
                return;
            }
        } finally {
            $GLOBALS['tpvsync_skip_refund_push'] = false;
        }
        TpvSyncLog::ok('order', $idOrder, "Refund TPV $tpvOrderId creado en PS slip $slip->id");
    }
}
