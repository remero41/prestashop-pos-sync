<?php
declare(strict_types=1);

/**
 * Sincronización bidireccional de clientes PrestaShop ↔ TPV.
 *
 * Hooks PS:
 *   - actionObjectCustomerAddAfter    → POST /customers (idempotente por email)
 *   - actionObjectCustomerUpdateAfter → PATCH /customers/{id}
 *   - actionObjectCustomerDeleteBefore→ DELETE /customers/{id} (soft-delete TPV)
 *   - actionObjectAddressAddAfter     → re-push del customer dueño de la dirección
 *   - actionObjectAddressUpdateAfter  → idem
 *
 * Webhook receptor: customer.created/updated/deleted (handler PS llama a
 * syncCustomerFromTpv / deletePsCustomerByTpvId).
 *
 * Modo principal:
 *   - 'tpv': cambios PS en customers mapeados se ignoran (anti-bucle).
 *   - 'ps' o vacío: PS manda. Cambios PS se empujan al TPV libremente.
 *
 * Anti-bucle: $GLOBALS['tpvsync_skip_customer_push'] activo durante el
 * procesado de webhooks customer.* para que update_user_meta no re-empuje.
 */
class TpvSyncCustomer
{
    private TpvSyncApiClient $api;

    public function __construct(TpvSyncApiClient $api)
    {
        $this->api = $api;
    }

    // ─── Helpers de mapping ────────────────────────────────────────────────

    public function findTpvByPs(int $idCustomer): int
    {
        $row = Db::getInstance()->getRow(
            'SELECT tpv_customer_id FROM ' . _DB_PREFIX_ . 'tpv_sync_customer_map
             WHERE id_customer = ' . (int) $idCustomer
        );
        return (int) ($row['tpv_customer_id'] ?? 0);
    }

    public function findPsByTpv(int $tpvCustomerId): int
    {
        $row = Db::getInstance()->getRow(
            'SELECT id_customer FROM ' . _DB_PREFIX_ . 'tpv_sync_customer_map
             WHERE tpv_customer_id = ' . (int) $tpvCustomerId
        );
        return (int) ($row['id_customer'] ?? 0);
    }

    public function setMap(int $idCustomer, int $tpvCustomerId): void
    {
        Db::getInstance()->execute(
            'INSERT INTO ' . _DB_PREFIX_ . 'tpv_sync_customer_map (id_customer, tpv_customer_id, updated_at)
             VALUES (' . (int) $idCustomer . ', ' . (int) $tpvCustomerId . ', NOW())
             ON DUPLICATE KEY UPDATE tpv_customer_id = ' . (int) $tpvCustomerId . ', updated_at = NOW()'
        );
    }

    public function deleteMap(int $idCustomer): void
    {
        Db::getInstance()->execute(
            'DELETE FROM ' . _DB_PREFIX_ . 'tpv_sync_customer_map WHERE id_customer = ' . (int) $idCustomer
        );
    }

    // ─── PS → TPV: hooks ───────────────────────────────────────────────────

    /**
     * Hook actionObjectCustomerAddAfter / actionObjectCustomerUpdateAfter.
     * Empuja create-or-update al TPV.
     */
    public function pushPsCustomerToTpv(int $idCustomer): void
    {
        if (!empty($GLOBALS['tpvsync_skip_customer_push'])) return;
        if ($idCustomer <= 0) return;

        $customer = new Customer($idCustomer);
        if (!Validate::isLoadedObject($customer)) return;

        // Modo principal=tpv: si ya tiene mapping, dejar que el webhook
        // re-lea desde el TPV. Si no tiene mapping (isla PS), no propagar.
        $principal = (string) Configuration::get('TPVSYNC_PRINCIPAL');
        if ($principal === 'tpv') {
            $tpvId = $this->findTpvByPs($idCustomer);
            if ($tpvId === 0) return;
            TpvSyncLog::skip('customer', $idCustomer, "Modo principal=tpv: cambios PS ignorados");
            return;
        }

        $payload = $this->buildPayloadFromCustomer($customer);
        if ($payload === null) return; // sin email válido

        $tpvId = $this->findTpvByPs($idCustomer);
        if ($tpvId > 0) {
            $r = $this->api->patch("/customers/$tpvId", $payload);
            $isNotFound = !empty($r['type']) && strpos((string) $r['type'], 'not_found') !== false;
            if ($isNotFound) {
                $this->deleteMap($idCustomer);
                TpvSyncLog::warn('customer', $tpvId,
                    "PATCH /customers/$tpvId 404 — mapping huérfano, recreando (PS=$idCustomer)"
                );
                $tpvId = 0;
            } elseif (!TpvSyncApiClient::fueBien($r)) {
                // BUG-A: rama de FALLO. La rama isNotFound de arriba se conserva
                // INTACTA: alli el 404 no es un fallo, es la senal de que el
                // mapping quedo huerfano y hay que recrear el cliente.
                TpvSyncLog::error('customer', $tpvId, $this->formatApiError($r));
                return;
            } else {
                TpvSyncLog::ok('customer', $tpvId, "Customer PS $idCustomer actualizado en TPV");
                return;
            }
        }

        // POST /customers — la API es idempotente por email (action=matched
        // si ya existe). Pasamos client_external_id para el mapeo inverso.
        $payload['client_external_id'] = (string) $idCustomer;
        $r = $this->api->post('/customers', $payload);
        $newId = (int) ($r['data']['customer_id'] ?? 0);
        if ($newId === 0) {
            TpvSyncLog::error('customer', $idCustomer, $this->formatApiError($r));
            return;
        }
        $this->setMap($idCustomer, $newId);
        $action = (string) ($r['data']['action'] ?? 'unknown');
        TpvSyncLog::ok('customer', $newId, "Customer $action en TPV (PS=$idCustomer email={$payload['email']})");
    }

    /**
     * Hook actionObjectCustomerDeleteBefore.
     * Soft-delete en TPV (status=0).
     */
    public function pushPsCustomerDeleteToTpv(int $idCustomer): void
    {
        if (!empty($GLOBALS['tpvsync_skip_customer_push'])) return;
        if ($idCustomer <= 0) return;

        $tpvId = $this->findTpvByPs($idCustomer);
        if ($tpvId === 0) return;

        $r = $this->api->delete("/customers/$tpvId");
        if (!TpvSyncApiClient::fueBien($r)) {   // BUG-A: rama de FALLO
            TpvSyncLog::error('customer', $tpvId, "DELETE /customers/$tpvId: " . $this->formatApiError($r));
            return;
        }
        TpvSyncLog::ok('customer', $tpvId, "Customer soft-deleted en TPV (PS=$idCustomer)");
    }

    // ─── Bulk push (sincronización inicial PS → TPV) ───────────────────────

    /**
     * Empuja todos los customers PS al TPV en lotes.
     *
     * @return array<string,int> stats (sent, created, matched, skipped, errors)
     */
    public function pushAllPsCustomers(int $batchSize = 100, int $offset = 0): array
    {
        $stats = ['sent' => 0, 'created' => 0, 'matched' => 0, 'skipped' => 0, 'errors' => 0];
        $rows = Db::getInstance()->executeS(
            'SELECT id_customer FROM ' . _DB_PREFIX_ . 'customer
             WHERE active = 1 AND deleted = 0
             ORDER BY id_customer ASC
             LIMIT ' . (int) $offset . ', ' . (int) $batchSize
        ) ?: [];

        if (empty($rows)) return $stats;

        foreach ($rows as $r) {
            $idCustomer = (int) $r['id_customer'];
            // Saltar si ya tiene mapping (no reenviar lo ya sincronizado).
            if ($this->findTpvByPs($idCustomer) > 0) {
                $stats['skipped']++;
                continue;
            }

            $customer = new Customer($idCustomer);
            if (!Validate::isLoadedObject($customer)) {
                $stats['errors']++;
                continue;
            }
            $payload = $this->buildPayloadFromCustomer($customer);
            if ($payload === null) {
                $stats['skipped']++;
                continue;
            }
            $payload['client_external_id'] = (string) $idCustomer;

            $resp = $this->api->post('/customers', $payload);
            $newId = (int) ($resp['data']['customer_id'] ?? 0);
            if ($newId === 0) {
                $stats['errors']++;
                continue;
            }
            $this->setMap($idCustomer, $newId);
            $action = (string) ($resp['data']['action'] ?? 'unknown');
            if ($action === 'created')      $stats['created']++;
            elseif ($action === 'matched')  $stats['matched']++;
            $stats['sent']++;
        }
        return $stats;
    }

    // ─── TPV → PS: receptor de webhooks ───────────────────────────────────

    /**
     * Upsert de customer TPV en PS. Crea o actualiza por email.
     * Llamado desde el handler de webhooks tras GET /customers/{id} a la API.
     *
     * @param int   $tpvCustomerId
     * @param array $fields email, firstname, lastname, telephone, id_tax,
     *                      address: {address_1, address_2, city, postcode,
     *                                country_code, zone_code}
     */
    public function syncCustomerFromTpv(int $tpvCustomerId, array $fields): void
    {
        if ($tpvCustomerId <= 0) return;
        $email = trim((string) ($fields['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            TpvSyncLog::skip('customer', $tpvCustomerId, 'sin email válido en payload TPV');
            return;
        }

        $GLOBALS['tpvsync_skip_customer_push'] = true;
        try {
            // Buscar por mapping local primero, luego por email.
            $idCustomer = $this->findPsByTpv($tpvCustomerId);
            if ($idCustomer === 0) {
                $idCustomer = (int) Db::getInstance()->getValue(
                    'SELECT id_customer FROM ' . _DB_PREFIX_ . "customer
                     WHERE email = '" . pSQL($email) . "' AND deleted = 0"
                );
            }

            $isNew = $idCustomer === 0;
            $customer = $isNew ? new Customer() : new Customer($idCustomer);

            $customer->email = $email;
            $customer->firstname = mb_substr((string) ($fields['firstname'] ?? ''), 0, 255);
            $customer->lastname = mb_substr((string) ($fields['lastname'] ?? ''), 0, 255);

            if ($isNew) {
                // PS exige password al crear; generamos uno aleatorio (el cliente
                // no lo necesita: si quiere acceso al frontend usará "Olvidé mi contraseña").
                $customer->passwd = Tools::hash(bin2hex(random_bytes(16)));
                $customer->id_default_group = (int) Configuration::get('PS_CUSTOMER_GROUP');
                if ($customer->id_default_group <= 0) $customer->id_default_group = 3; // Visitante por defecto
                $customer->id_lang = (int) Configuration::get('PS_LANG_DEFAULT');
                $customer->newsletter = !empty($fields['newsletter']) ? 1 : 0;
                $customer->active = 1;
                if (!$customer->add()) {
                    TpvSyncLog::error('customer', $tpvCustomerId, 'Customer::add() falló');
                    return;
                }
            } else {
                $customer->newsletter = !empty($fields['newsletter']) ? 1 : 0;
                if (!$customer->update()) {
                    TpvSyncLog::error('customer', $tpvCustomerId, 'Customer::update() falló');
                    return;
                }
            }

            // Address por defecto (billing en PS): si viene en el payload,
            // upsert. PS necesita una dirección por cada customer para
            // facturación. La tabla address tiene country/state numéricos.
            if (!empty($fields['address']) && is_array($fields['address'])) {
                $this->upsertAddress((int) $customer->id, $fields['address'],
                    $fields['firstname'] ?? '', $fields['lastname'] ?? '',
                    $fields['telephone'] ?? '', $fields['id_tax'] ?? '');
            }

            // Map id_customer ↔ tpv_customer_id
            $this->setMap((int) $customer->id, $tpvCustomerId);
            TpvSyncLog::ok('customer', $tpvCustomerId,
                ($isNew ? 'Customer creado' : 'Customer actualizado') . " en PS (id=" . (int) $customer->id . ", email=$email)"
            );
        } finally {
            $GLOBALS['tpvsync_skip_customer_push'] = false;
        }
    }

    /**
     * Soft-unlink: borra el mapping (no borra el customer PS porque puede
     * tener pedidos históricos). Marca config con timestamp para auditoría.
     */
    public function deletePsCustomerByTpvId(int $tpvCustomerId): void
    {
        if ($tpvCustomerId <= 0) return;
        $idCustomer = $this->findPsByTpv($tpvCustomerId);
        if ($idCustomer === 0) return;

        $GLOBALS['tpvsync_skip_customer_push'] = true;
        try {
            $this->deleteMap($idCustomer);
            // Marcamos en customer (custom field) para auditoría
            Db::getInstance()->execute(
                'UPDATE ' . _DB_PREFIX_ . 'customer
                 SET note = CONCAT(IFNULL(note, ""), "\n[tpv_deleted_at=' . pSQL(date('c')) . ']")
                 WHERE id_customer = ' . (int) $idCustomer
            );
            TpvSyncLog::ok('customer', $tpvCustomerId, "Customer PS $idCustomer desenlazado (TPV lo borró)");
        } finally {
            $GLOBALS['tpvsync_skip_customer_push'] = false;
        }
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    /**
     * Construye payload para POST/PATCH /customers desde un Customer PS.
     * Devuelve null si no hay email válido.
     */
    private function buildPayloadFromCustomer(Customer $customer): ?array
    {
        $email = trim((string) $customer->email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return null;

        $payload = [
            'email'      => $email,
            'firstname'  => mb_substr((string) $customer->firstname, 0, 32),
            'lastname'   => mb_substr((string) $customer->lastname, 0, 32),
            'telephone'  => '',
            'newsletter' => (bool) $customer->newsletter,
        ];

        // Dirección por defecto del customer PS (la primera no soft-deleted).
        $addressRow = Db::getInstance()->getRow(
            'SELECT a.id_address, a.firstname, a.lastname, a.company,
                    a.address1, a.address2, a.city, a.postcode, a.phone,
                    a.vat_number,
                    c.iso_code AS country_code, s.iso_code AS zone_code
             FROM ' . _DB_PREFIX_ . 'address a
             LEFT JOIN ' . _DB_PREFIX_ . 'country c ON c.id_country = a.id_country
             LEFT JOIN ' . _DB_PREFIX_ . 'state   s ON s.id_state   = a.id_state
             WHERE a.id_customer = ' . (int) $customer->id . ' AND a.deleted = 0
             ORDER BY a.id_address ASC'
        );
        if ($addressRow) {
            $payload['telephone'] = mb_substr((string) ($addressRow['phone'] ?? ''), 0, 32);
            if (!empty($addressRow['vat_number'])) {
                $payload['id_tax'] = trim((string) $addressRow['vat_number']);
            }
            if (!empty($addressRow['address1'])) {
                $payload['address'] = [
                    'firstname'    => (string) ($addressRow['firstname'] ?? ''),
                    'lastname'     => (string) ($addressRow['lastname'] ?? ''),
                    'company'      => (string) ($addressRow['company'] ?? ''),
                    'address_1'    => (string) ($addressRow['address1'] ?? ''),
                    'address_2'    => (string) ($addressRow['address2'] ?? ''),
                    'city'         => (string) ($addressRow['city'] ?? ''),
                    'postcode'     => (string) ($addressRow['postcode'] ?? ''),
                    'country_code' => (string) ($addressRow['country_code'] ?? ''),
                    'zone_code'    => (string) ($addressRow['zone_code'] ?? ''),
                ];
            }
        }
        return $payload;
    }

    /**
     * Crea o actualiza la dirección por defecto del customer PS.
     */
    private function upsertAddress(int $idCustomer, array $a, string $defaultFirstname = '', string $defaultLastname = '', string $phone = '', string $vat = ''): void
    {
        if (empty($a['address_1'])) return;

        // País
        $idCountry = 0;
        if (!empty($a['country_code'])) {
            $idCountry = (int) Db::getInstance()->getValue(
                'SELECT id_country FROM ' . _DB_PREFIX_ . "country
                 WHERE iso_code = '" . pSQL((string) $a['country_code']) . "'"
            );
        }
        if ($idCountry === 0) {
            $idCountry = (int) Configuration::get('PS_COUNTRY_DEFAULT');
        }

        $idState = 0;
        if (!empty($a['zone_code'])) {
            $idState = (int) Db::getInstance()->getValue(
                'SELECT id_state FROM ' . _DB_PREFIX_ . "state
                 WHERE iso_code = '" . pSQL((string) $a['zone_code']) . "' AND id_country = $idCountry"
            );
        }

        // ¿Ya tiene address? Reusamos la primera no soft-deleted.
        $existingAddrId = (int) Db::getInstance()->getValue(
            'SELECT id_address FROM ' . _DB_PREFIX_ . 'address
             WHERE id_customer = ' . (int) $idCustomer . ' AND deleted = 0
             ORDER BY id_address ASC'
        );

        $address = $existingAddrId > 0 ? new Address($existingAddrId) : new Address();
        $address->id_customer = $idCustomer;
        $address->alias = $address->alias ?: 'TPV';
        $address->firstname = mb_substr((string) ($a['firstname'] ?: $defaultFirstname), 0, 255);
        $address->lastname = mb_substr((string) ($a['lastname'] ?: $defaultLastname), 0, 255);
        $address->company = mb_substr((string) ($a['company'] ?? ''), 0, 255);
        $address->address1 = mb_substr((string) $a['address_1'], 0, 128);
        $address->address2 = mb_substr((string) ($a['address_2'] ?? ''), 0, 128);
        $address->city = mb_substr((string) ($a['city'] ?? ''), 0, 64);
        $address->postcode = mb_substr((string) ($a['postcode'] ?? ''), 0, 12);
        $address->id_country = $idCountry;
        if ($idState > 0) $address->id_state = $idState;
        if ($phone !== '') $address->phone = mb_substr($phone, 0, 32);
        if ($vat !== '') $address->vat_number = mb_substr($vat, 0, 32);

        if ($existingAddrId > 0) {
            $address->update();
        } else {
            $address->add();
        }
    }

    /**
     * Formatea {error, errors:[{field,message}]} como string legible.
     */
    private function formatApiError(array $r): string
    {
        $base = (string) ($r['error'] ?? '');
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
            $base = ($base !== '' ? $base . ' — ' : '') . implode('; ', $details);
        }
        if ($base === '') $base = substr((string) json_encode($r), 0, 400);
        return $base;
    }
}
