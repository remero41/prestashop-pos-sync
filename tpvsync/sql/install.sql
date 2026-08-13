CREATE TABLE IF NOT EXISTS `PREFIX_tpv_sync_log` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `event_type`  VARCHAR(64)     NOT NULL,
    `resource`    VARCHAR(64)     NOT NULL DEFAULT '',
    `resource_id` INT UNSIGNED    NOT NULL DEFAULT 0,
    `status`      VARCHAR(16)     NOT NULL DEFAULT 'ok',
    `message`     TEXT            DEFAULT NULL,
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_event` (`event_type`, `created_at`),
    KEY `idx_status` (`status`, `created_at`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=CHARSET_TYPE;

CREATE TABLE IF NOT EXISTS `PREFIX_tpv_sync_product_map` (
    `id_product`      INT UNSIGNED NOT NULL,
    `tpv_product_id`  INT UNSIGNED NOT NULL,
    `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_product`),
    UNIQUE KEY `uk_tpv_pid` (`tpv_product_id`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=CHARSET_TYPE;

-- Mapping de clientes PS ↔ TPV (id_customer ⇄ tpv_customer_id).
CREATE TABLE IF NOT EXISTS `PREFIX_tpv_sync_customer_map` (
    `id_customer`     INT UNSIGNED NOT NULL,
    `tpv_customer_id` INT UNSIGNED NOT NULL,
    `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_customer`),
    UNIQUE KEY `uk_tpv_cid` (`tpv_customer_id`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=CHARSET_TYPE;

CREATE TABLE IF NOT EXISTS `PREFIX_tpv_sync_combination_map` (
    `id_product_attribute` INT UNSIGNED NOT NULL,
    `tpv_option_value_id`  INT UNSIGNED NOT NULL,
    `updated_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_product_attribute`),
    UNIQUE KEY `uk_tpv_pov` (`tpv_option_value_id`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=CHARSET_TYPE;

CREATE TABLE IF NOT EXISTS `PREFIX_tpv_sync_order_map` (
    `id_order`       INT UNSIGNED NOT NULL,
    `tpv_order_id`   INT UNSIGNED NOT NULL,
    `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_order`),
    UNIQUE KEY `uk_tpv_oid` (`tpv_order_id`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=CHARSET_TYPE;

CREATE TABLE IF NOT EXISTS `PREFIX_tpv_sync_image_map` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_product`   INT UNSIGNED NOT NULL,
    `id_image`     INT UNSIGNED NOT NULL,
    `url_hash`     CHAR(32)     NOT NULL,
    `url`          VARCHAR(500) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_product_url` (`id_product`, `url_hash`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=CHARSET_TYPE;

CREATE TABLE IF NOT EXISTS `PREFIX_tpv_sync_queue` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `job_type`      VARCHAR(64)     NOT NULL,
    `payload`       TEXT            NOT NULL,
    `status`        VARCHAR(16)     NOT NULL DEFAULT 'pending',
    `attempts`      INT UNSIGNED    NOT NULL DEFAULT 0,
    `last_error`    VARCHAR(1000)   DEFAULT NULL,
    `scheduled_at`  DATETIME        NOT NULL,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME        NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_status_sched` (`status`, `scheduled_at`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=CHARSET_TYPE;

CREATE TABLE IF NOT EXISTS `PREFIX_tpv_sync_webhook_idem` (
    `idempotency_key` VARCHAR(128) NOT NULL,
    `seen_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`idempotency_key`),
    KEY `idx_seen` (`seen_at`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=CHARSET_TYPE;

CREATE TABLE IF NOT EXISTS `PREFIX_tpv_sync_stock_ts` (
    `resource_key` VARCHAR(64)  NOT NULL,
    `last_ts`      INT UNSIGNED NOT NULL,
    PRIMARY KEY (`resource_key`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=CHARSET_TYPE;

CREATE TABLE IF NOT EXISTS `PREFIX_tpv_sync_tax_map` (
    `id_tax_rules_group` INT UNSIGNED NOT NULL,
    `tpv_tax_class_id`   INT UNSIGNED NOT NULL,
    `updated_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_tax_rules_group`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=CHARSET_TYPE;
