-- 3.2.0: product_status_i18n.admin_title, a translated title shown in the back office only (the
-- title stays the label displayed on the shop). When the column is created, each translation gets
-- its title as back-office title, so that no status starts without one, then the four protected
-- statuses (found by code) get the translated back-office titles of a new installation
-- (TheliaMain.sql), whatever label the shop gave them.
-- Idempotent: does nothing when the table is missing or the column already exists, so a
-- back-office title edited since is never overwritten. Also played by ProductStatus::postActivation().
-- No semicolon may end a line inside a statement: the SQL file is cut on ";\n".
SET @product_status_i18n_exists = (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'product_status_i18n');
SET @product_status_admin_title_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'product_status_i18n' AND column_name = 'admin_title');
SET @product_status_needs_admin_title = (@product_status_i18n_exists = 1 AND @product_status_admin_title_exists = 0);
SET @product_status_statement = IF(@product_status_needs_admin_title, 'ALTER TABLE `product_status_i18n` ADD COLUMN `admin_title` VARCHAR(255) NULL AFTER `title`', 'DO 0');
PREPARE product_status_update FROM @product_status_statement;
EXECUTE product_status_update;
DEALLOCATE PREPARE product_status_update;
SET @product_status_statement = IF(@product_status_needs_admin_title, 'UPDATE `product_status_i18n` SET `admin_title` = NULLIF(`title`, '''')', 'DO 0');
PREPARE product_status_update FROM @product_status_statement;
EXECUTE product_status_update;
DEALLOCATE PREPARE product_status_update;
SET @product_status_statement = IF(@product_status_needs_admin_title, 'UPDATE `product_status_i18n` translation INNER JOIN `product_status` status ON status.`id` = translation.`id` INNER JOIN (SELECT ''normal'' AS `code`, ''fr_FR'' AS `locale`, ''Normal'' AS `admin_title` UNION ALL SELECT ''discontinued'', ''fr_FR'', ''Arrêté'' UNION ALL SELECT ''sale'', ''fr_FR'', ''Soldes'' UNION ALL SELECT ''oddment'', ''fr_FR'', ''Fin de série'' UNION ALL SELECT ''normal'', ''en_US'', ''Normal'' UNION ALL SELECT ''discontinued'', ''en_US'', ''Discontinued'' UNION ALL SELECT ''sale'', ''en_US'', ''Sale'' UNION ALL SELECT ''oddment'', ''en_US'', ''Oddment'' UNION ALL SELECT ''normal'', ''de_DE'', ''Normal'' UNION ALL SELECT ''discontinued'', ''de_DE'', ''Eingestellt'' UNION ALL SELECT ''sale'', ''de_DE'', ''Ausverkauf'' UNION ALL SELECT ''oddment'', ''de_DE'', ''Restposten'' UNION ALL SELECT ''normal'', ''es_ES'', ''Normal'' UNION ALL SELECT ''discontinued'', ''es_ES'', ''Interrumpido'' UNION ALL SELECT ''sale'', ''es_ES'', ''Ventas'' UNION ALL SELECT ''oddment'', ''es_ES'', ''Remanente'' UNION ALL SELECT ''normal'', ''it_IT'', ''Normale'' UNION ALL SELECT ''discontinued'', ''it_IT'', ''Smettere'' UNION ALL SELECT ''sale'', ''it_IT'', ''Saldi'' UNION ALL SELECT ''oddment'', ''it_IT'', ''Fine serie'') AS seeded ON seeded.`code` = status.`code` AND seeded.`locale` = translation.`locale` SET translation.`admin_title` = seeded.`admin_title`', 'DO 0');
PREPARE product_status_update FROM @product_status_statement;
EXECUTE product_status_update;
DEALLOCATE PREPARE product_status_update;
