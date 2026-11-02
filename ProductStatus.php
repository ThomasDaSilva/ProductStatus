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

namespace ProductStatus;

use ProductStatus\Service\StoredDescriptionCleaner;
use Propel\Runtime\Connection\ConnectionInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\Finder\Finder;
use Thelia\Core\Install\Database;
use Thelia\Module\BaseModule;

class ProductStatus extends BaseModule
{
    public const DOMAIN_NAME = 'productstatus';

    public const UNIQUE_LINK_SCRIPT = __DIR__.'/Config/update/3.0.0.sql';

    public const ADMIN_TITLE_SCRIPT = __DIR__.'/Config/update/3.2.0.sql';

    private const FIRST_THELIA3_VERSION = '3.0.0';

    /**
     * Creates the tables and the four protected statuses once. The script never drops
     * anything: on a database where the tables already exist, it leaves every row in place.
     *
     * The one-status-per-product script runs at every activation: a database carried over
     * from the 2.x line without the module configuration (so without `is_initialized`)
     * still gets its duplicate links removed and the unique index added, and its 2.x
     * descriptions cleaned. The back-office title script runs first, at every activation: such
     * a database gets the admin_title column, filled with the titles, before the installation
     * script inserts its rows with that column. These steps change nothing on a database
     * already up to date.
     */
    public function postActivation(?ConnectionInterface $con = null): void
    {
        $database = new Database($con);
        $database->insertSql(null, [self::ADMIN_TITLE_SCRIPT]);

        if ('1' !== self::getConfigValue('is_initialized')) {
            $database->insertSql(null, [__DIR__.'/Config/TheliaMain.sql']);

            self::setConfigValue('is_initialized', '1');
        }

        $database->insertSql(null, [self::UNIQUE_LINK_SCRIPT]);
        (new StoredDescriptionCleaner())->cleanAll($con);
    }

    /**
     * Plays every Config/update/<version>.sql newer than the installed version, then, coming
     * from the 2.x line, cleans the descriptions it stored with any HTML.
     */
    public function update($currentVersion, $newVersion, ?ConnectionInterface $con = null): void
    {
        $files = Finder::create()->files()->name('*.sql')->depth(0)->in(__DIR__.'/Config/update');
        $files->sort(static fn (\SplFileInfo $left, \SplFileInfo $right): int => version_compare(
            $left->getBasename('.sql'),
            $right->getBasename('.sql'),
        ));

        $database = new Database($con);

        foreach ($files as $file) {
            $version = $file->getBasename('.sql');

            if (version_compare((string) $currentVersion, $version, '<') && version_compare($version, (string) $newVersion, '<=')) {
                $database->insertSql(null, [$file->getPathname()]);
            }
        }

        if (version_compare((string) $currentVersion, self::FIRST_THELIA3_VERSION, '<')) {
            (new StoredDescriptionCleaner())->cleanAll($con);
        }
    }

    public static function configureServices(ServicesConfigurator $servicesConfigurator): void
    {
        $servicesConfigurator->load(self::getModuleCode().'\\', __DIR__)
            ->exclude([
                __DIR__.'/I18n/*',
                __DIR__.'/Model/*',
                __DIR__.'/Tests/*',
                __DIR__.'/templates/*',
            ])
            ->autowire()
            ->autoconfigure();
    }
}
