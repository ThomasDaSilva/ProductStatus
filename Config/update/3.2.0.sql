-- 3.2.0: product_status_i18n.admin_title, a translated title shown in the back office only (the
-- title stays the label displayed on the shop).
-- Idempotent: does nothing when the table is missing or the column already exists. Also played by
-- ProductStatus::postActivation(). No semicolon may end a line inside a statement: the SQL file
-- is cut on ";\n".
SET @product_status_i18n_exists = (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'product_status_i18n');
SET @product_status_admin_title_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'product_status_i18n' AND column_name = 'admin_title');
SET @product_status_statement = IF(@product_status_i18n_exists = 1 AND @product_status_admin_title_exists = 0, 'ALTER TABLE `product_status_i18n` ADD COLUMN `admin_title` VARCHAR(255) NULL AFTER `title`', 'DO 0');
PREPARE product_status_update FROM @product_status_statement;
EXECUTE product_status_update;
DEALLOCATE PREPARE product_status_update;
