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

namespace ProductStatus\Service;

final readonly class ProductStatusView
{
    public function __construct(
        public int $id,
        public string $code,
        public string $color,
        public string $title,
        public ?string $description,
        public bool $protected,
        // Translated title shown in the back office only; the title is the label of the shop.
        public ?string $adminTitle = null,
    ) {
    }
}
