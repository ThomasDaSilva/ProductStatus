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

use ProductStatus\Model\ProductStatusI18nQuery;
use ProductStatus\Service\ProductStatusService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * Runs on the test database of the project (`php bin/test-prepare`), never on the shop's.
 * The request goes through the kernel itself: symfony/browser-kit is not required by the module.
 */
final class ConfigurationControllerTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        $databaseName = $_SERVER['DATABASE_NAME'] ?? getenv('DATABASE_NAME');
        if (!\is_string($databaseName) || !str_ends_with($databaseName, '_test')) {
            self::fail(\sprintf('Refusing to run on the database "%s": use a *_test database.', (string) $databaseName));
        }

        parent::setUp();
    }

    public function testSavingAStatusWritesTheEditLanguageNotTheInterfaceLanguage(): void
    {
        $interfaceLang = $this->lang('fr_FR');
        $editLang = $this->lang('en_US');

        $dispatcher = static::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $service = new ProductStatusService($dispatcher);
        $statusId = $service->save(null, 'clearance', '#aabbcc', 'Clearance', null, 'en_US');
        $service->save($statusId, 'clearance', '#aabbcc', 'Déstockage', null, 'fr_FR');

        $session = new Session(new MockArraySessionStorage());
        $session->setAdminUser($this->createFixtureFactory()->admin());
        $session->set('thelia.current.admin_lang', $interfaceLang);
        $session->setAdminEditionLang($editLang);

        $request = Request::create('/admin/module/ProductStatus/status/'.$statusId, 'POST');
        $request->setSession($session);
        $request->request->set('productstatus_status', [
            'code' => 'clearance',
            'admin_title' => 'Clearance of the season',
            'title' => 'Clearance, edited',
            'description' => '',
            'color' => '#aabbcc',
            '_token' => $this->csrfToken($request, 'productstatus_status'),
        ]);

        $response = $this->handleAsMainRequest($request);

        self::assertSame(302, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([], $session->getFlashBag()->peek('danger'));
        self::assertSame('Clearance, edited', $this->title($statusId, 'en_US'));
        self::assertSame('Déstockage', $this->title($statusId, 'fr_FR'));
        self::assertSame('Clearance of the season', ProductStatusI18nQuery::create()->filterById($statusId)->filterByLocale('en_US')->findOne()?->getAdminTitle());
        self::assertNull(ProductStatusI18nQuery::create()->filterById($statusId)->filterByLocale('fr_FR')->findOne()?->getAdminTitle());
    }

    public function testAFormWithoutTheBackOfficeTitleFieldKeepsTheStoredOne(): void
    {
        $editLang = $this->lang('en_US');

        $dispatcher = static::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $statusId = (new ProductStatusService($dispatcher))->save(null, 'restock', '#aabbcc', 'Restock', null, 'en_US', 'Back in stock soon');

        $session = new Session(new MockArraySessionStorage());
        $session->setAdminUser($this->createFixtureFactory()->admin());
        $session->setAdminEditionLang($editLang);

        // As a configuration template overridden before 3.2.0 posts it: no admin_title field.
        $request = Request::create('/admin/module/ProductStatus/status/'.$statusId, 'POST');
        $request->setSession($session);
        $request->request->set('productstatus_status', [
            'code' => 'restock',
            'title' => 'Restock, edited',
            'description' => '',
            'color' => '#aabbcc',
            '_token' => $this->csrfToken($request, 'productstatus_status'),
        ]);

        $response = $this->handleAsMainRequest($request);

        self::assertSame(302, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([], $session->getFlashBag()->peek('danger'));
        self::assertSame('Restock, edited', $this->title($statusId, 'en_US'));
        self::assertSame('Back in stock soon', ProductStatusI18nQuery::create()->filterById($statusId)->filterByLocale('en_US')->findOne()?->getAdminTitle());
    }

    /**
     * IntegrationTestCase pushes a synthetic request: it is taken off the stack while the
     * kernel handles this one, so that this request is the main request the controller reads.
     */
    private function handleAsMainRequest(Request $request): Response
    {
        $requestStack = $this->requestStack();
        $pushedRequests = [];
        while (null !== $pushedRequest = $requestStack->pop()) {
            $pushedRequests[] = $pushedRequest;
        }

        try {
            return self::$kernel->handle($request);
        } finally {
            while (null !== $requestStack->pop()) {
            }
            foreach (array_reverse($pushedRequests) as $pushedRequest) {
                $requestStack->push($pushedRequest);
            }
        }
    }

    private function requestStack(): RequestStack
    {
        $requestStack = static::getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $requestStack);

        return $requestStack;
    }

    private function csrfToken(Request $request, string $tokenId): string
    {
        $requestStack = $this->requestStack();
        $tokenManager = static::getContainer()->get('security.csrf.token_manager');
        self::assertInstanceOf(CsrfTokenManagerInterface::class, $tokenManager);

        $requestStack->push($request);
        try {
            return $tokenManager->getToken($tokenId)->getValue();
        } finally {
            $requestStack->pop();
        }
    }

    private function lang(string $locale): Lang
    {
        $lang = LangQuery::create()->findOneByLocale($locale);
        self::assertInstanceOf(Lang::class, $lang, \sprintf('The test database has no %s language.', $locale));

        return $lang;
    }

    private function title(int $statusId, string $locale): ?string
    {
        return ProductStatusI18nQuery::create()->filterById($statusId)->filterByLocale($locale)->findOne()?->getTitle();
    }
}
