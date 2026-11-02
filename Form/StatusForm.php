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

namespace ProductStatus\Form;

use ProductStatus\ProductStatus;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Thelia\Core\Translation\Translator;
use Thelia\Form\BaseForm;

/**
 * Create or edit a product status (code, back-office title, label shown on the shop as the
 * title, color, description).
 *
 * The code field is always editable here; a protected status refusing a code
 * change is enforced by {@see \ProductStatus\Service\ProductStatusService::save()}.
 * The back-office template marks the field read-only when editing a protected
 * status, purely as a usability hint.
 *
 * Labels and help texts are translated here, in the module domain: translation_domain
 * false keeps the form theme from translating them a second time in its own domain
 * (the default-twig back-office turns « Label » into « Libellé »).
 */
class StatusForm extends BaseForm
{
    protected function buildForm(): void
    {
        $translator = Translator::getInstance();

        $this->formBuilder
            ->add('code', TextType::class, [
                'required' => true,
                'constraints' => [new NotBlank()],
                'translation_domain' => false,
                'label' => $translator->trans('The status code', [], ProductStatus::DOMAIN_NAME),
                'label_attr' => [
                    'help' => $translator->trans('It must be unique', [], ProductStatus::DOMAIN_NAME),
                ],
            ])
            ->add('admin_title', TextType::class, [
                'required' => false,
                'constraints' => [new Length(max: 255)],
                'translation_domain' => false,
                'label' => $translator->trans('The status title', [], ProductStatus::DOMAIN_NAME),
                'label_attr' => [
                    'help' => $translator->trans('Shown in the back office only', [], ProductStatus::DOMAIN_NAME),
                ],
            ])
            ->add('title', TextType::class, [
                'required' => true,
                'constraints' => [new NotBlank(), new Length(max: 255)],
                'translation_domain' => false,
                'label' => $translator->trans('The status label', [], ProductStatus::DOMAIN_NAME),
                'label_attr' => [
                    'help' => $translator->trans('Label of the status. Will be displayed in frontOffice', [], ProductStatus::DOMAIN_NAME),
                ],
            ])
            ->add('description', TextareaType::class, [
                'required' => false,
                'translation_domain' => false,
                'label' => $translator->trans('The status description', [], ProductStatus::DOMAIN_NAME),
                'label_attr' => [
                    'help' => $translator->trans('The text displayed in frontOffice', [], ProductStatus::DOMAIN_NAME),
                ],
            ])
            ->add('color', ColorType::class, [
                'required' => true,
                'constraints' => [new NotBlank()],
                'translation_domain' => false,
                'label' => $translator->trans('Status color', [], ProductStatus::DOMAIN_NAME),
                'label_attr' => [
                    'help' => $translator->trans('Choose a color', [], ProductStatus::DOMAIN_NAME),
                ],
            ]);
    }

    public static function getName(): string
    {
        return 'productstatus_status';
    }
}
