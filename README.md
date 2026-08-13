# prestashop-pos-sync

Módulo **PrestaShop** que sincroniza una tienda con el TPV Catinfog a través de
su **API v1**: catálogo, stock, pedidos, devoluciones y clientes.

Su equivalente para WooCommerce es
[`woocomerce-pos-sync`](https://github.com/remero41/woocomerce-pos-sync).

Compatible con **PrestaShop 1.7.8+ y 9.x** · PHP **8.1+**.

---

## Qué sincroniza

| Dirección | Qué |
|-----------|-----|
| **TPV → PrestaShop** | Productos, precios, stock, ofertas especiales, categorías, variantes, clientes (opcional) |
| **PrestaShop → TPV** | Productos (alta / edición / borrado), stock (simple y por variante), pedidos online, devoluciones (`OrderSlip`), cambios de estado |
| **Bidireccional** | Stock — cada venta descuenta en ambos lados sin duplicarse |

---

## Arquitectura

```
PrestaShop (frontend + BO)
       │
       │ hooks:
       │   actionProductSave/Add/Update    → push catálogo
       │   actionUpdateQuantity            → push stock
       │   actionValidateOrderBefore/…     → envío de pedido
       │   actionObjectOrderSlipAddAfter   → devolución
       │   actionOrderStatusPostUpdate     → cambio de estado
       │   displayBackOfficeHeader         → CSS/JS del admin
       ▼
   tpvsync.php
       │
       ├── classes/
       │   ├── TpvSyncApiClient.php        cURL + OAuth2 + retry 429 + circuit breaker
       │   ├── TpvSyncCircuitBreaker.php   closed / open / half-open
       │   ├── TpvSyncProduct.php          catálogo (CRUD + reconciliación)
       │   ├── TpvSyncOrder.php            pedidos y devoluciones
       │   ├── TpvSyncCustomer.php         clientes
       │   ├── TpvSyncQueue.php            reintentos con backoff exponencial
       │   ├── TpvSyncWebhook.php          receptor TPV → PS (HMAC + idempotencia + ordering guard)
       │   ├── TpvSyncSecrets.php          cifrado de credenciales en BD
       │   ├── TpvSyncNotifications.php    avisos de incidencias
       │   └── TpvSyncLog.php              persistencia en `PREFIX_tpv_sync_log`
       ├── controllers/front/              endpoints `webhook` y `syncbatch`
       ├── scripts/                        crons (queue, reconcile, purge, import, notifications)
       ├── bin/tpvsync                     CLI
       ├── sql/                            install.sql + uninstall.sql
       ├── translations/                   es · en · fr
       └── views/                          CSS, JS y plantillas del back office
```

### Resiliencia

- **Circuit breaker** de tres estados: ante fallos repetidos del TPV deja de
  martillear la API y reintenta de forma escalonada.
- **Cola con backoff exponencial**: nada se pierde si el TPV está caído.
- **Webhooks firmados con HMAC**, con protección de repetición (*replay*) e
  idempotencia, de modo que un reenvío no duplica stock ni pedidos.

---

## Instalación

1. Copia la carpeta `tpvsync/` dentro de `modules/` de tu PrestaShop.
2. Back office → **Módulos → Catinfog Conector (TPV)** → **Instalar**.
3. Rellena la configuración del módulo:
   - **URL de la API**: por ejemplo `https://tu-tpv.ejemplo.com/api/v1`
   - **Client ID** y **Client Secret**: los emite el TPV con
     ```bash
     php api/v1/setup.php --create-client
     ```
4. Pulsa **Probar conexión** y, si todo va bien, lanza la **sincronización inicial**.

### Crons recomendados

```cron
*/5  * * * * php /ruta/a/modules/tpvsync/scripts/cron_queue.php
0    * * * * php /ruta/a/modules/tpvsync/scripts/cron_reconcile.php
30   3 * * * php /ruta/a/modules/tpvsync/scripts/cron_purge.php
```

---

## Licencia

[Academic Free License 3.0 (AFL-3.0)](LICENSE), la licencia habitual de los
módulos de PrestaShop.
