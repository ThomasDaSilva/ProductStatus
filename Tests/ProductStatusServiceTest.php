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

use ProductStatus\Event\ProductStatusChangedEvent;
use ProductStatus\Event\ProductStatusEvents;
use ProductStatus\Exception\ProductStatusException;
use ProductStatus\Hook\Theme\ProductStatusThemeHook;
use ProductStatus\Model\ProductProductStatusQuery;
use ProductStatus\Model\ProductStatus;
use ProductStatus\Model\ProductStatusI18nQuery;
use ProductStatus\Model\ProductStatusQuery;
use ProductStatus\ProductStatus as ProductStatusModule;
use ProductStatus\Service\ProductStatusColor;
use ProductStatus\Service\ProductStatusDescriptionSanitizer;
use ProductStatus\Service\ProductStatusMemo;
use ProductStatus\Service\ProductStatusService;
use ProductStatus\Service\ProductStatusView;
use Propel\Runtime\Connection\ConnectionWrapper;
use Propel\Runtime\Propel;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Lang;
use Thelia\Model\Product;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;
use Twig\Environment;

/**
 * Runs on the test database of the project (`php bin/test-prepare`), never on the shop's:
 * the database name must end with `_test`.
 */
final class ProductStatusServiceTest extends IntegrationTestCase
{
    private ProductStatusService $service;

    private FixtureFactory $fixtures;

    protected function setUp(): void
    {
        $databaseName = $_SERVER['DATABASE_NAME'] ?? getenv('DATABASE_NAME');
        if (!\is_string($databaseName) || !str_ends_with($databaseName, '_test')) {
            self::fail(\sprintf('Refusing to run on the database "%s": use a *_test database.', (string) $databaseName));
        }

        parent::setUp();

        $this->service = new ProductStatusService($this->dispatcher());
        $this->fixtures = $this->createFixtureFactory();
    }

    public function testProductWithoutStatusReadsNormalAndWritesNothing(): void
    {
        $product = $this->product();

        $status = $this->service->statusOf($product->getId(), 'en_US');

        self::assertSame('normal', $status->code);
        self::assertSame('Normal', $status->title);
        self::assertTrue($status->protected);
        self::assertSame(0, ProductProductStatusQuery::create()->filterByProductId($product->getId())->count());
    }

    public function testStatusesOfRunsTheSameNumberOfQueriesForOneOrManyProducts(): void
    {
        $products = [];
        for ($index = 0; $index < 6; ++$index) {
            $products[] = $this->product()->getId();
        }
        $this->service->assign($products[1], $this->statusId('sale'));
        $this->service->assign($products[2], $this->statusId('oddment'));

        $queriesForOne = $this->countQueries(fn () => $this->service->statusesOf([$products[0]], 'en_US'));
        $statuses = [];
        $queriesForSix = $this->countQueries(function () use (&$statuses, $products): void {
            $statuses = $this->service->statusesOf($products, 'en_US');
        });

        self::assertGreaterThan(0, $queriesForOne);
        self::assertSame($queriesForOne, $queriesForSix);
        self::assertLessThanOrEqual(2, $queriesForSix);
        self::assertSame(['normal', 'sale', 'oddment', 'normal', 'normal', 'normal'], array_map(
            static fn (int $productId): string => $statuses[$productId]->code,
            $products,
        ));
    }

    public function testAssignReplacesTheStatusAndDispatchesOnlyOnChange(): void
    {
        $product = $this->product();
        $events = [];
        $listener = static function (ProductStatusChangedEvent $event) use (&$events): void {
            $events[] = [$event->productId, $event->previousStatusId, $event->statusId];
        };
        $dispatcher = $this->dispatcher();
        $dispatcher->addListener(ProductStatusEvents::PRODUCT_STATUS_CHANGED, $listener);

        try {
            $this->service->assign($product->getId(), $this->statusId('sale'));
            $this->service->assign($product->getId(), $this->statusId('sale'));
            $this->service->assign($product->getId(), $this->statusId('oddment'));
        } finally {
            $dispatcher->removeListener(ProductStatusEvents::PRODUCT_STATUS_CHANGED, $listener);
        }

        self::assertSame([
            [$product->getId(), null, $this->statusId('sale')],
            [$product->getId(), $this->statusId('sale'), $this->statusId('oddment')],
        ], $events);
        self::assertSame(1, ProductProductStatusQuery::create()->filterByProductId($product->getId())->count());
        self::assertSame('oddment', $this->service->statusOf($product->getId(), 'en_US')->code);
    }

    public function testAssignRefusesAnUnknownStatus(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->assign($this->product()->getId(), 999999);
    }

    public function testProtectedStatusCannotBeDeleted(): void
    {
        try {
            $this->service->delete($this->statusId('normal'));
            self::fail('A protected status was deleted.');
        } catch (\DomainException) {
        }

        self::assertNotNull(ProductStatusQuery::create()->findOneByCode('normal'));
    }

    public function testCreatedStatusCanBeDeleted(): void
    {
        $statusId = $this->service->save(null, 'Clearance', '#AABBCC', 'Clearance', 'Last <b>items</b><script>x</script>', 'en_US');

        $created = $this->service->all('en_US')[array_search($statusId, array_map(static fn ($view) => $view->id, $this->service->all('en_US')), true)];
        self::assertSame('clearance', $created->code);
        self::assertSame('#aabbcc', $created->color);
        self::assertSame('Last <b>items</b>x', $created->description);

        $this->service->delete($statusId);

        self::assertNull(ProductStatusQuery::create()->findPk($statusId));
    }

    public function testDuplicateCodeIsRefused(): void
    {
        $this->expectException(\DomainException::class);

        $this->service->save(null, 'SALE', '#000000', 'Another sale', null, 'en_US');
    }

    public function testCodeOfAProtectedStatusCannotChange(): void
    {
        $this->expectException(\DomainException::class);

        $this->service->save($this->statusId('sale'), 'soldes', '#000000', 'Sale', null, 'en_US');
    }

    public function testDescriptionKeepsNoDangerousAttribute(): void
    {
        $statusId = $this->service->save(
            null,
            'attributes',
            '#000000',
            'Attributes',
            '<b onmouseover="alert(1)" style="color:red">bold</b> <a href="javascript:alert(2)" onclick="alert(3)">bad</a> <a href=" JaVaScRiPt:alert(4)">spaced</a> <a href="https://example.com/?a=1&b=2" target="_blank">good</a>',
            'en_US',
        );

        self::assertSame(
            '<b>bold</b> <a>bad</a> <a>spaced</a> <a href="https://example.com/?a=1&amp;b=2">good</a>',
            $this->view($statusId, 'en_US')->description,
        );
    }

    public function testShortColorIsExpandedAndInvalidColorIsRefused(): void
    {
        $statusId = $this->service->save(null, 'short-color', '#AbC', 'Short color', null, 'en_US');

        self::assertSame('#aabbcc', $this->view($statusId, 'en_US')->color);

        $this->expectException(ProductStatusException::class);
        $this->service->save(null, 'bad-color', 'red;background:url(x)', 'Bad color', null, 'en_US');
    }

    public function testInvalidStoredColorIsDisplayedNeutral(): void
    {
        $status = (new ProductStatus())->setCode('legacy-color')->setColor('red;x');
        $status->setLocale('en_US')->setTitle('Legacy color');
        $status->save();

        self::assertSame(ProductStatusColor::NEUTRAL, $this->view((int) $status->getId(), 'en_US')->color);
    }

    public function testTranslationsHoldOnlyWhatIsStoredInTheLocale(): void
    {
        $statusId = $this->service->save(null, 'english-only', '#000000', 'English only', 'Only in English', 'en_US');

        self::assertSame(['title' => 'English only', 'admin_title' => '', 'description' => 'Only in English'], $this->service->translations('en_US')[$statusId]);
        self::assertArrayNotHasKey($statusId, $this->service->translations('fr_FR'));
        self::assertSame('English only', $this->view($statusId, 'fr_FR')->title, 'The list still shows the fallback.');
    }

    public function testBackOfficeTitleIsTranslatedOptionalAndKeptWhenNotGiven(): void
    {
        $statusId = $this->service->save(null, 'winter-sale', '#000000', 'Sale', null, 'en_US', ' Winter clearance ');
        $this->service->save($statusId, 'winter-sale', '#000000', 'Soldes', null, 'fr_FR', 'Soldes d\'hiver');

        self::assertSame('Winter clearance', $this->view($statusId, 'en_US')->adminTitle);
        self::assertSame('Soldes d\'hiver', $this->view($statusId, 'fr_FR')->adminTitle);
        self::assertSame('Sale', $this->view($statusId, 'en_US')->title, 'The label is left as it is.');

        $this->service->save($statusId, 'winter-sale', '#000000', 'Sale, edited', null, 'en_US');
        self::assertSame('Winter clearance', $this->service->translations('en_US')[$statusId]['admin_title'], 'Not given, the back-office title is kept.');

        $this->service->save($statusId, 'winter-sale', '#000000', 'Sale', null, 'en_US', '');
        self::assertSame('', $this->service->translations('en_US')[$statusId]['admin_title']);
        self::assertNull(ProductStatusI18nQuery::create()->filterById($statusId)->filterByLocale('en_US')->findOne()?->getAdminTitle(), 'An empty back-office title is stored as NULL.');
        self::assertSame('Soldes d\'hiver', $this->view($statusId, 'fr_FR')->adminTitle, 'Other languages are left as they are.');

        $withoutAdminTitle = $this->service->save(null, 'no-admin-title', '#000000', 'Label only', null, 'en_US');
        self::assertNull($this->view($withoutAdminTitle, 'en_US')->adminTitle);
    }

    public function testThemeHookPrintsTheStatusOnTheProductPageExceptNormal(): void
    {
        $withStatus = $this->product();
        $withoutStatus = $this->product();
        $this->service->assign($withStatus->getId(), $this->statusId('oddment'));
        ProductStatusMemo::reset(static fn (): string => 'en_US');

        $twig = static::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $themeHook = new ProductStatusThemeHook($twig);

        self::assertTrue($themeHook->supports('product.bottom'));
        $html = $themeHook->render('product.bottom', ['product' => ['id' => $withStatus->getId()]]);
        self::assertStringContainsString('product-status--oddment', $html);
        self::assertStringContainsString('Oddment', $html);
        self::assertSame('', $themeHook->render('product.bottom', ['product' => ['id' => $withoutStatus->getId()]]));
    }

    public function testSanitizerResistsEncodedAndMalformedPayloads(): void
    {
        $sanitizer = new ProductStatusDescriptionSanitizer();

        foreach ([
            '<a href="javascript&colon;alert(1)">a</a>' => '<a>a</a>',
            '<a href="javascript&#58;alert(1)">b</a>' => '<a>b</a>',
            '<a href="java&#x09;script:alert(1)">c</a>' => '<a>c</a>',
            '<a <script>>d</a>' => '<a>d</a>',
            '<b<svg/onload=alert(1)>>e</b>' => 'e',
            '<a href="x" <img onerror=alert(1)>>f</a>' => '<a href="x">f</a>',
        ] as $payload => $expected) {
            self::assertSame($expected, $sanitizer->sanitize($payload), $payload);
        }
    }

    public function testUpdateFrom2xCleansStoredDescriptions(): void
    {
        $status = (new ProductStatus())->setCode('legacy-description')->setColor('#000000');
        $status->setLocale('en_US')->setTitle('Legacy')->setDescription('<b onclick="alert(1)">Old</b><script>x</script>');
        $status->save();

        (new ProductStatusModule())->update('2.0.9', '3.0.0', $this->getPropelConnection());

        self::assertSame('<b>Old</b>x', ProductStatusI18nQuery::create()->filterById($status->getId())->filterByLocale('en_US')->findOne()?->getDescription());
    }

    public function testStrictLanguageModeDoesNotFallBackToTheDefaultLanguage(): void
    {
        $statusId = $this->service->save(null, 'english-status', '#000000', 'English status', null, 'en_US');
        $previousMode = ConfigQuery::getDefaultLangWhenNoTranslationAvailable();

        try {
            ConfigQuery::write('default_lang_without_translation', Lang::STRICTLY_USE_REQUESTED_LANGUAGE);
            self::assertSame('english-status', $this->view($statusId, 'fr_FR')->title);

            ConfigQuery::write('default_lang_without_translation', 1);
            self::assertSame('English status', $this->view($statusId, 'fr_FR')->title);
        } finally {
            ConfigQuery::write('default_lang_without_translation', $previousMode);
        }
    }

    public function testDeletingAStatusMovesItsProductsToNormalAndDispatchesTheChange(): void
    {
        $statusId = $this->service->save(null, 'to-delete', '#000000', 'To delete', null, 'en_US');
        $first = $this->product();
        $second = $this->product();
        $this->service->assign($first->getId(), $statusId);
        $this->service->assign($second->getId(), $statusId);

        $events = [];
        $listener = static function (ProductStatusChangedEvent $event) use (&$events): void {
            $events[] = [$event->productId, $event->previousStatusId, $event->statusId];
        };
        $dispatcher = $this->dispatcher();
        $dispatcher->addListener(ProductStatusEvents::PRODUCT_STATUS_CHANGED, $listener);

        try {
            $this->service->delete($statusId);
        } finally {
            $dispatcher->removeListener(ProductStatusEvents::PRODUCT_STATUS_CHANGED, $listener);
        }

        $normalId = $this->statusId('normal');
        self::assertEqualsCanonicalizing([
            [$first->getId(), $statusId, $normalId],
            [$second->getId(), $statusId, $normalId],
        ], $events);
        self::assertSame('normal', $this->service->statusOf($first->getId(), 'en_US')->code);
    }

    public function testNewOrChangedCodeMustBeASlugWhileALegacyCodeCanBeKept(): void
    {
        $legacy = (new ProductStatus())->setCode('bon plan')->setColor('#000000');
        $legacy->setLocale('en_US')->setTitle('Good deal');
        $legacy->save();
        $legacyId = (int) $legacy->getId();

        $this->service->save($legacyId, 'bon plan', '#111111', 'Good deal, edited', null, 'en_US');
        self::assertSame('Good deal, edited', $this->view($legacyId, 'en_US')->title);

        foreach ([[null, 'new code'], [$legacyId, 'bon plan 2'], [null, 'série']] as [$id, $code]) {
            try {
                $this->service->save($id, $code, '#000000', 'Title', null, 'en_US');
                self::fail(\sprintf('The code "%s" was accepted.', $code));
            } catch (ProductStatusException $exception) {
                self::assertSame('The code may only contain lowercase letters, digits, "-" and "_"', $exception->translationKey());
            }
        }
    }

    public function testThemeHookReusesTheStatusExposedByTheProductResource(): void
    {
        $twig = static::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $themeHook = new ProductStatusThemeHook($twig);

        $html = $themeHook->render('product.bottom', ['product' => [
            'id' => 999999999,
            'ProductStatusAddon' => ['productStatus' => [
                'code' => 'fin de série (articles indisponibles)',
                'title' => 'Exposed title',
                'description' => null,
                'color' => 'red;background:url(x)',
            ]],
        ]]);

        self::assertStringContainsString('Exposed title', $html);
        self::assertStringContainsString('product-status--fin-de-s-rie-articles-indisponibles', $html);
        self::assertStringContainsString('background-color: '.ProductStatusColor::NEUTRAL, $html);
    }

    private function product(): Product
    {
        return $this->fixtures->product($this->fixtures->category(), $this->fixtures->taxRule(), $this->fixtures->currency());
    }

    private function view(int $statusId, string $locale): ProductStatusView
    {
        foreach ($this->service->all($locale) as $view) {
            if ($view->id === $statusId) {
                return $view;
            }
        }

        self::fail(\sprintf('Status %d not found.', $statusId));
    }

    private function statusId(string $code): int
    {
        return (int) ProductStatusQuery::create()->findOneByCode($code)?->getId();
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = static::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }

    private function countQueries(callable $callable): int
    {
        $connection = Propel::getReadConnection('TheliaMain');
        self::assertInstanceOf(ConnectionWrapper::class, $connection);
        $connection->useDebug(true);
        $before = $connection->getQueryCount();

        $callable();

        return $connection->getQueryCount() - $before;
    }
}
