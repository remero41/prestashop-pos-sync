# Changelog

Formato: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versionado: [SemVer](https://semver.org/).

## [1.1.0] - 2026-04-24

### Changed
- `declare(strict_types=1)` en todas las clases, controllers y scripts del módulo.
- Infraestructura de calidad: `composer.json` (dev-tools), `phpstan.neon` level 5, `phpcs.xml` PSR-12, CI en `.github/workflows/ci.yml`.

### Added
- Suite e2e `cazabugs_100.sh` (100 tests / 10 áreas) en `.memoria_prestashop/tests/`. Cubre auth, config, import, push, variantes, stock edge, webhooks, orders, datos raros, queue, race.

## [1.0.0] - 2026-04-xx

Release inicial. Bugs corregidos durante el desarrollo (ver `.memoria_prestashop/tests/README.md`):
1. `Tools::link_rewrite` no existe en PS 9 → `Tools::str2url`.
2. `Attribute` no existe en PS 9 → `ProductAttribute` con fallback.
3. `Db::getValue()` añade `LIMIT 1` automático → quitado de queries.
4. `Employee` vacío en webhooks/CLI → `new Employee(1)` en fallback.
5. `actionProductAdd` no dispara en PS 9 → suscripción también a `actionProductSave` + guard anti-duplicate.
6. `mapCombinationIds` sólo se llamaba en POST → también en PATCH + fallback GET.
7. UNIQUE `tpv_product_id` en map → DELETE previo al reconciliar refs duplicadas.
8. `deleteProductInTpv` no limpiaba el map local → DELETE de product_map + combination_map + image_map.
9. SQL queries multi-línea rompían con nombres que contenían `\` o `"` → queries en una línea con MD5(name).
10. Import síncrono en BO timeouts a 30s → `set_time_limit(600)` + batches con botón "Continuar".
11. `POST /batch` response envuelve en `data.results` → cliente leía `results` → siempre vacío.
12. Webhook handler sin Employee → fix en `TpvSyncWebhook::handle()`.
