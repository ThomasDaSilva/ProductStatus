-- ProductStatus installation script, played once by ProductStatus::postActivation().
-- It never drops anything: existing tables and rows are kept as they are.

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `product_status`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `protected` TINYINT DEFAULT 0 NOT NULL,
    `color` CHAR(7),
    `code` VARCHAR(255),
    `created_at` DATETIME,
    `updated_at` DATETIME,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `product_product_status`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `product_id` INTEGER NOT NULL,
    `product_status_id` INTEGER NOT NULL,
    `created_at` DATETIME,
    `updated_at` DATETIME,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `product_product_status_product_id_unique` (`product_id`),
    INDEX `fi_product_status` (`product_status_id`),
    CONSTRAINT `fk_product_status`
        FOREIGN KEY (`product_status_id`)
            REFERENCES `product_status` (`id`)
            ON UPDATE RESTRICT
            ON DELETE CASCADE,
    CONSTRAINT `fk_product`
        FOREIGN KEY (`product_id`)
            REFERENCES `product` (`id`)
            ON UPDATE RESTRICT
            ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `product_status_i18n`
(
    `id` INTEGER NOT NULL,
    `locale` VARCHAR(5) DEFAULT 'en_US' NOT NULL,
    `title` VARCHAR(255),
    `admin_title` VARCHAR(255),
    `description` LONGTEXT,
    `chapo` TEXT,
    `postscriptum` TEXT,
    PRIMARY KEY (`id`,`locale`),
    CONSTRAINT `product_status_i18n_fk_c32b93`
        FOREIGN KEY (`id`)
            REFERENCES `product_status` (`id`)
            ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `product_status` (`id`, `protected`, `color`, `code`, `created_at`, `updated_at`) VALUES
(1, 1, '#6dd073', 'normal', NOW(), NOW()),
(2, 1, '#d9534f', 'discontinued', NOW(), NOW()),
(3, 1, '#986dff', 'sale', NOW(), NOW()),
(4, 1, '#2c75ff', 'oddment', NOW(), NOW());

INSERT IGNORE INTO `product_status_i18n` (`id`, `locale`, `title`, `admin_title`, `description`) VALUES
(1, 'fr_FR', 'Normal', 'Normal', 'statut normal de l''article'),
(2, 'fr_FR', 'Arrêté', 'Arrêté', 'article qui ne sera plus produit'),
(3, 'fr_FR', 'Soldes', 'Soldes', 'article remisé'),
(4, 'fr_FR', 'Fin de série', 'Fin de série', 'échange de taille possible dans la limite des stocks disponibles. Il n''y aura pas de réassort'),
(1, 'en_US', 'Normal', 'Normal', 'normal status of the product'),
(2, 'en_US', 'Discontinued', 'Discontinued', 'this product will not be made anymore'),
(3, 'en_US', 'Sale', 'Sale', 'clearance sale'),
(4, 'en_US', 'Oddment', 'Oddment', 'exchange available within the limits of available sizes'),
(1, 'de_DE', 'Normal', 'Normal', 'normaler Status des Artikels'),
(2, 'de_DE', 'Eingestellt', 'Eingestellt', 'dieser Artikel wird nicht mehr hergestellt'),
(3, 'de_DE', 'Ausverkauf', 'Ausverkauf', 'reduzierter Artikel'),
(4, 'de_DE', 'Restposten', 'Restposten', 'Größentausch im Rahmen der verfügbaren Bestände möglich. Es gibt keine Nachlieferung'),
(1, 'es_ES', 'Normal', 'Normal', 'estado normal del producto'),
(2, 'es_ES', 'Interrumpido', 'Interrumpido', 'este artículo no se producirá'),
(3, 'es_ES', 'Ventas', 'Ventas', 'ventas'),
(4, 'es_ES', 'Remanente', 'Remanente', 'intercambio posible dentro de los límites de los tamaños disponibles'),
(1, 'it_IT', 'Normale', 'Normale', 'stato normale dell''articolo'),
(2, 'it_IT', 'Smettere', 'Smettere', 'questo articolo non sarà più prodotto'),
(3, 'it_IT', 'Saldi', 'Saldi', 'saldi'),
(4, 'it_IT', 'Fine serie', 'Fine serie', 'cambio taglia possibile nei limiti delle scorte disponibili. Non ci sarà rifornimento');

SET FOREIGN_KEY_CHECKS = 1;
