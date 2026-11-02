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

namespace ProductStatus\Exception;

final class ProductStatusException extends \DomainException implements ProductStatusErrorInterface
{
    private function __construct(
        private readonly string $translationKey,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function codeRequired(): self
    {
        return new self('The status code is required', 'The status code is required.');
    }

    public static function invalidCode(string $code): self
    {
        return new self('The code may only contain lowercase letters, digits, "-" and "_"', \sprintf('Invalid status code "%s", expected [a-z0-9_-]+.', $code));
    }

    public static function titleRequired(): self
    {
        return new self('The status label is required', 'The status label is required.');
    }

    public static function invalidColor(string $color): self
    {
        return new self('The color must be written #rgb or #rrggbb', \sprintf('Invalid color "%s", expected #rgb or #rrggbb.', $color));
    }

    public static function duplicateCode(string $code): self
    {
        return new self('This code already exists, please pick another one', \sprintf('The status code "%s" already exists, please pick another one.', $code));
    }

    public static function protectedCodeChange(): self
    {
        return new self('The code of a protected status cannot be changed', 'The code of a protected status cannot be changed.');
    }

    public static function protectedDeletion(): self
    {
        return new self('A protected status cannot be deleted', 'A protected status cannot be deleted.');
    }

    public function translationKey(): string
    {
        return $this->translationKey;
    }
}
