<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ProductStatus\Tests;

use ProductStatus\ProductStatus;
use Thelia\Test\IntegrationTestCase;

/**
 * Plays the back-office title script on a translation table shaped as 3.1 left it (no
 * admin_title column): the column is added and filled with the titles once. DDL commits implicitly: this test runs outside any transaction and
 * puts the column back itself.
 *
 * Runs on the test database of the project (`php bin/test-prepare`), never on the shop's.
 */
final class AdminTitleScriptTest extends IntegrationTestCase
{
    protected bool $useTransaction = false;

    protected function setUp(): void
    {
        $databaseName = $_SERVER['DATABASE_NAME'] ?? getenv('DATABASE_NAME');
        if (!\is_string($databaseName) || !str_ends_with($databaseName, '_test')) {
            self::fail(\sprintf('Refusing to run on the database "%s": use a *_test database.', (string) $databaseName));
        }

        parent::setUp();
    }

    protected function tearDown(): void
    {
        if (!$this->adminTitleColumnExists()) {
            $this->getPropelConnection()->exec('ALTER TABLE product_status_i18n ADD COLUMN admin_title VARCHAR(255) NULL AFTER title');
        }

        parent::tearDown();
    }

    public function testActivationAddsTheColumnFilledWithTheTitlesThenLeavesItAlone(): void
    {
        $connection = $this->getPropelConnection();
        $titlesBefore = $this->titles();
        $connection->exec('ALTER TABLE product_status_i18n DROP COLUMN admin_title');

        (new ProductStatus())->postActivation($connection);

        self::assertTrue($this->adminTitleColumnExists());
        self::assertSame($titlesBefore, $this->titles());
        self::assertSame([], $this->rowsWhoseAdminTitleIsNotTheTitle(), 'Each translation gets its title as back-office title.');

        $connection->exec("UPDATE product_status_i18n SET admin_title = 'Edited since' WHERE id = 1 AND locale = 'en_US'");
        (new ProductStatus())->postActivation($connection);

        self::assertSame(['1 en_US'], $this->rowsWhoseAdminTitleIsNotTheTitle(), 'A later activation overwrites nothing.');
        $connection->exec("UPDATE product_status_i18n SET admin_title = title WHERE id = 1 AND locale = 'en_US'");
    }

    private function adminTitleColumnExists(): bool
    {
        return (int) $this->getPropelConnection()
            ->query('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = \'product_status_i18n\' AND column_name = \'admin_title\'')
            ->fetchColumn() > 0;
    }

    /**
     * @return list<string> "<id> <locale>" of each translation whose back-office title differs from its title
     */
    private function rowsWhoseAdminTitleIsNotTheTitle(): array
    {
        return $this->getPropelConnection()
            ->query('SELECT CONCAT(id, \' \', locale) FROM product_status_i18n WHERE NOT (admin_title <=> NULLIF(title, \'\')) ORDER BY id, locale')
            ->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function titles(): array
    {
        return $this->getPropelConnection()
            ->query('SELECT id, locale, title FROM product_status_i18n ORDER BY id, locale')
            ->fetchAll(\PDO::FETCH_ASSOC);
    }
}
