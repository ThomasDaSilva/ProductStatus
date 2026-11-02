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

namespace ProductStatus\Controller;

use ProductStatus\Exception\ProductStatusErrorInterface;
use ProductStatus\Form\StatusForm;
use ProductStatus\ProductStatus;
use ProductStatus\Service\EditionLocaleResolver;
use ProductStatus\Service\ProductStatusService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Exception\TokenAuthenticationException;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Form\BaseForm;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Tools\TokenProvider;

#[Route('/admin/module/ProductStatus', name: 'product_status.')]
class ConfigurationController extends BaseAdminController
{
    #[Route('/status', name: 'status.create', methods: ['POST'])]
    public function createStatus(ProductStatusService $productStatusService, EditionLocaleResolver $editionLocaleResolver, UrlGeneratorInterface $urlGenerator, LoggerInterface $logger): Response
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, ProductStatus::DOMAIN_NAME, AccessManager::UPDATE)) {
            return $response;
        }

        $form = $this->createForm(StatusForm::class);

        return $this->saveStatus($form, null, $productStatusService, $editionLocaleResolver, $urlGenerator, $logger);
    }

    #[Route('/status/{id}', name: 'status.update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updateStatus(int $id, ProductStatusService $productStatusService, EditionLocaleResolver $editionLocaleResolver, UrlGeneratorInterface $urlGenerator, LoggerInterface $logger): Response
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, ProductStatus::DOMAIN_NAME, AccessManager::UPDATE)) {
            return $response;
        }

        $form = $this->createForm(StatusForm::class);

        return $this->saveStatus($form, $id, $productStatusService, $editionLocaleResolver, $urlGenerator, $logger);
    }

    #[Route('/status/{id}/delete', name: 'status.delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteStatus(int $id, ProductStatusService $productStatusService, TokenProvider $tokenProvider, UrlGeneratorInterface $urlGenerator, LoggerInterface $logger): Response
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, ProductStatus::DOMAIN_NAME, AccessManager::UPDATE)) {
            return $response;
        }

        $redirectUrl = $urlGenerator->generate('admin.module.configure', ['module_code' => ProductStatus::getModuleCode()]);

        try {
            $request = $this->getRequest();
            $tokenProvider->checkToken((string) ($request->request->get('_token') ?? $request->query->get('_token') ?? ''));

            $productStatusService->delete($id);
        } catch (TokenAuthenticationException) {
            $this->addFlash('danger', $this->translator->trans('Your session has expired, please reload the page and try again', [], ProductStatus::DOMAIN_NAME));
        } catch (ProductStatusErrorInterface $exception) {
            $this->addFlash('danger', $this->translator->trans($exception->translationKey(), [], ProductStatus::DOMAIN_NAME));
        } catch (\Throwable $exception) {
            $this->flashUnexpectedError($exception, $logger);
        }

        return $this->generateRedirect($redirectUrl);
    }

    /**
     * The translation is written in the edit language ({@see EditionLocaleResolver}), the
     * one the configuration screen was rendered in, never in the admin interface language.
     *
     * The back-office title is passed only when the form posted its field: a configuration
     * template overridden before 3.2.0 has none, and saving from it keeps the stored title.
     */
    private function saveStatus(
        BaseForm $form,
        ?int $id,
        ProductStatusService $productStatusService,
        EditionLocaleResolver $editionLocaleResolver,
        UrlGeneratorInterface $urlGenerator,
        LoggerInterface $logger,
    ): Response {
        $fallbackUrl = $urlGenerator->generate('admin.module.configure', ['module_code' => ProductStatus::getModuleCode()]);

        try {
            $validForm = $this->validateForm($form);
            $data = $validForm->getData();
            $postedFields = $this->getRequest()->request->all(StatusForm::getName());

            $productStatusService->save(
                id: $id,
                code: (string) $data['code'],
                color: (string) $data['color'],
                title: (string) $data['title'],
                description: '' !== (string) $data['description'] ? (string) $data['description'] : null,
                locale: $editionLocaleResolver->resolveLocale($this->getRequest()),
                adminTitle: \array_key_exists('admin_title', $postedFields) ? (string) $data['admin_title'] : null,
            );

            return $this->generateSuccessRedirect($form) ?? $this->generateRedirect($fallbackUrl);
        } catch (FormValidationException $exception) {
            $this->setupFormErrorContext(
                $this->translator->trans('Product status'),
                $this->createStandardFormValidationErrorMessage($exception),
                $form,
                $exception,
            );
            $this->addFlash('danger', $this->createStandardFormValidationErrorMessage($exception));
        } catch (ProductStatusErrorInterface $exception) {
            $this->addFlash('danger', $this->translator->trans($exception->translationKey(), [], ProductStatus::DOMAIN_NAME));
        } catch (\Throwable $exception) {
            $this->flashUnexpectedError($exception, $logger);
        }

        return $this->generateErrorRedirect($form) ?? $this->generateRedirect($fallbackUrl);
    }

    /**
     * Anything but a business error (database, bug) is logged with its details and shown
     * as a generic message: its raw text may carry SQL or internal paths.
     */
    private function flashUnexpectedError(\Throwable $exception, LoggerInterface $logger): void
    {
        $logger->error('ProductStatus: {message}', ['message' => $exception->getMessage(), 'exception' => $exception]);

        $this->addFlash('danger', $this->translator->trans('An unexpected error occurred, the change was not saved', [], ProductStatus::DOMAIN_NAME));
    }
}
