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

namespace ProductStatus\Hook;

use ProductStatus\EventListener\ProductFormListener;
use ProductStatus\Form\StatusForm;
use ProductStatus\ProductStatus;
use ProductStatus\Service\EditionLocaleResolver;
use ProductStatus\Service\ProductStatusService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormView;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Form\TheliaFormFactory;
use Thelia\Core\Hook\BaseHook;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Tools\TokenProvider;

class HookManager extends BaseHook
{
    public function __construct(
        private readonly ProductStatusService $productStatusService,
        private readonly TheliaFormFactory $formFactory,
        private readonly TokenProvider $tokenProvider,
        private readonly EditionLocaleResolver $editionLocaleResolver,
        ?EventDispatcherInterface $dispatcher = null,
        ?ParserResolver $parserResolver = null,
    ) {
        parent::__construct($dispatcher, $parserResolver);
    }

    /**
     * @return array<string, list<array{type: string, method: string, position?: int}>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'module.configuration' => [
                ['type' => 'back', 'method' => 'onModuleConfiguration'],
            ],
            // First of the module blocks, right under the brand: read only when the module_hook row is created.
            'product.modification.form-right.bottom' => [
                ['type' => 'back', 'method' => 'onProductModificationForm', 'position' => 1],
            ],
            'product-edit.top' => [
                ['type' => 'back', 'method' => 'onProductEditTop'],
            ],
        ];
    }

    /**
     * The list shows each status with its fallback (default language, then code); the edit
     * forms are filled with what is stored in the edited language only, so saving a form
     * never copies the fallback text into that language.
     */
    public function onModuleConfiguration(HookRenderEvent $event): void
    {
        $editionLang = $this->editionLocaleResolver->resolveLang($this->getRequest());
        $locale = $this->editionLocaleResolver->resolveLocale($this->getRequest());
        $statuses = $this->productStatusService->all($locale);
        $translations = $this->productStatusService->translations($locale);

        $editForms = [];
        foreach ($statuses as $status) {
            $editForms[$status->id] = $this->withIdPrefix(
                $this->formFactory->createForm(StatusForm::class, data: [
                    'code' => $status->code,
                    'admin_title' => $translations[$status->id]['admin_title'] ?? '',
                    'title' => $translations[$status->id]['title'] ?? '',
                    'description' => $translations[$status->id]['description'] ?? '',
                    'color' => $status->color,
                ])->createView()->getView(),
                StatusForm::getName().'_'.$status->id,
            );
        }

        $event->add($this->render('ProductStatus/module_configuration.html.twig', [
            'statuses' => $statuses,
            'create_form' => $this->formFactory->createForm(StatusForm::class)->createView()->getView(),
            'edit_forms' => $editForms,
            'delete_token' => $this->tokenProvider->assignToken(),
            'edit_language_id' => $editionLang->getId(),
        ]));
    }

    /**
     * Inside the product form of the General tab, under the brand: the template names the field
     * ProductFormListener adds to that form, so the status is saved with the product, its token
     * and its permission. Titles in the edit language of the page.
     */
    public function onProductModificationForm(HookRenderEvent $event): void
    {
        $productId = (int) $event->getArgument('product_id');

        if (0 === $productId) {
            return;
        }

        $locale = $this->editionLocaleResolver->resolveLocale($this->getRequest());

        $event->add($this->render('ProductStatus/product_modification_form.html.twig', [
            'field' => ProductFormListener::FIELD,
            'form_name' => ProductFormListener::PRODUCT_FORM,
            'statuses' => $this->productStatusService->all($locale),
            'current_status' => $this->productStatusService->statusOf($productId, $locale),
            'module_code' => ProductStatus::getModuleCode(),
        ]));
    }

    public function onProductEditTop(HookRenderEvent $event): void
    {
        $productId = (int) $event->getArgument('product_id');

        if (0 === $productId) {
            return;
        }

        $currentStatus = $this->productStatusService->statusOf($productId, $this->editionLocaleResolver->resolveLocale($this->getRequest()));

        $event->add($this->render('ProductStatus/product_edit_top.html.twig', [
            'current_status' => $currentStatus,
        ]));
    }

    /**
     * The creation form and every edit form share the form name the controller reads, so
     * their HTML ids are made unique here; field names are left untouched.
     */
    private function withIdPrefix(FormView $view, string $id): FormView
    {
        $view->vars['id'] = $id;

        foreach ($view->children as $name => $child) {
            $this->withIdPrefix($child, $id.'_'.$name);
        }

        return $view;
    }
}
