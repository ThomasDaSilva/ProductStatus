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
 * admin_title column): the column is added once, filled with the titles, the four protected
 * statuses getting the back-office titles of a new installation. DDL commits implicitly: this test runs outside any transaction and
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
        $saleLabelBefore = $this->label('sale', 'fr_FR');
        // A shop label on a protected status, and a status of the shop's own.
        $connection->exec("UPDATE product_status_i18n translation INNER JOIN product_status status ON status.id = translation.id SET translation.title = 'BON PLAN' WHERE status.code = 'sale' AND translation.locale = 'fr_FR'");
        $connection->exec("INSERT INTO product_status (id, protected, color, code, created_at, updated_at) VALUES (9001, 0, '#000000', 'shop-status', NOW(), NOW())");
        $connection->exec("INSERT INTO product_status_i18n (id, locale, title) VALUES (9001, 'fr_FR', 'Statut maison')");

        try {
            $titlesBefore = $this->titles();
            $connection->exec('ALTER TABLE product_status_i18n DROP COLUMN admin_title');

            (new ProductStatus())->postActivation($connection);

            self::assertTrue($this->adminTitleColumnExists());
            self::assertSame($titlesBefore, $this->titles(), 'The labels are left as they are.');
            self::assertSame('Soldes', $this->adminTitle('sale', 'fr_FR'), 'A protected status gets the back-office title of a new installation.');
            self::assertSame('Ausverkauf', $this->adminTitle('sale', 'de_DE'));
            self::assertSame('Statut maison', $this->adminTitle('shop-status', 'fr_FR'), 'Another status gets its label.');
            self::assertSame([$this->statusId('sale').' fr_FR'], $this->rowsWhoseAdminTitleIsNotTheTitle());

            $connection->exec("UPDATE product_status_i18n SET admin_title = 'Edited since' WHERE id = 9001 AND locale = 'fr_FR'");
            (new ProductStatus())->postActivation($connection);

            self::assertSame('Edited since', $this->adminTitle('shop-status', 'fr_FR'), 'A later activation overwrites nothing.');
        } finally {
            $connection->exec('DELETE FROM product_status WHERE id = 9001');
            $connection
                ->prepare("UPDATE product_status_i18n translation INNER JOIN product_status status ON status.id = translation.id SET translation.title = :label WHERE status.code = 'sale' AND translation.locale = 'fr_FR'")
                ->execute([':label' => $saleLabelBefore]);
        }
    }

    /**
     * The back-office titles the upgrade gives the protected statuses are the ones a new
     * installation inserts: both scripts carry them, this keeps them equal.
     */
    public function testTheUpgradeGivesTheProtectedStatusesTheBackOfficeTitlesOfANewInstallation(): void
    {
        $installation = (string) file_get_contents(\dirname(__DIR__).'/Config/TheliaMain.sql');
        $upgrade = (string) file_get_contents(\dirname(__DIR__).'/Config/update/3.2.0.sql');

        preg_match_all("/^\\((\\d+), 1, '#[0-9a-f]{6}', '([a-z]+)'/m", $installation, $statuses, \PREG_SET_ORDER);
        $codeById = array_column($statuses, 2, 1);
        self::assertCount(4, $codeById);

        preg_match_all("/^\\((\\d+), '([a-z]{2}_[A-Z]{2})', '((?:[^']|'')*)', '((?:[^']|'')*)', /m", $installation, $translations, \PREG_SET_ORDER);
        $installed = [];
        foreach ($translations as [, $id, $locale, $title, $adminTitle]) {
            self::assertSame($title, $adminTitle, 'A new installation gives each protected status its title as back-office title.');
            $installed[$codeById[$id].' '.$locale] = str_replace("''", "'", $adminTitle);
        }

        preg_match_all("/SELECT ''([a-z]+)''(?: AS `code`)?, ''([a-z]{2}_[A-Z]{2})''(?: AS `locale`)?, ''((?:[^']|'''')*)''/", $upgrade, $seeded, \PREG_SET_ORDER);
        $upgraded = [];
        foreach ($seeded as [, $code, $locale, $adminTitle]) {
            $upgraded[$code.' '.$locale] = str_replace("''''", "'", $adminTitle);
        }

        ksort($installed);
        ksort($upgraded);
        self::assertCount(20, $installed);
        self::assertSame($installed, $upgraded);
    }

    private function label(string $code, string $locale): string
    {
        $statement = $this->getPropelConnection()->prepare('SELECT translation.title FROM product_status_i18n translation INNER JOIN product_status status ON status.id = translation.id WHERE status.code = :code AND translation.locale = :locale');
        $statement->execute([':code' => $code, ':locale' => $locale]);

        return (string) $statement->fetchColumn();
    }

    private function adminTitle(string $code, string $locale): ?string
    {
        $statement = $this->getPropelConnection()->prepare('SELECT translation.admin_title FROM product_status_i18n translation INNER JOIN product_status status ON status.id = translation.id WHERE status.code = :code AND translation.locale = :locale');
        $statement->execute([':code' => $code, ':locale' => $locale]);
        $adminTitle = $statement->fetchColumn();

        return false === $adminTitle || null === $adminTitle ? null : (string) $adminTitle;
    }

    private function statusId(string $code): int
    {
        $statement = $this->getPropelConnection()->prepare('SELECT id FROM product_status WHERE code = :code');
        $statement->execute([':code' => $code]);

        return (int) $statement->fetchColumn();
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
