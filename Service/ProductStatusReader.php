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

use ProductStatus\Model\Map\ProductStatusTableMap;
use ProductStatus\Model\ProductProductStatusQuery;
use ProductStatus\Model\ProductStatusI18nQuery;
use ProductStatus\Model\ProductStatusQuery;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Propel;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Lang;

/**
 * Read side of the module, free of any service so that API resource addons
 * (built with `new` by the Propel bridge) can use it through {@see ProductStatusMemo}.
 */
final readonly class ProductStatusReader
{
    public const DEFAULT_STATUS_CODE = 'normal';

    /**
     * @return list<int> every status id, ordered by id, without reading any translation
     */
    public function ids(): array
    {
        return array_map('intval', ProductStatusQuery::create()->orderById()->select('Id')->find()->getData());
    }

    /**
     * @param list<int> $productIds
     *
     * @return array<int, int> status id by product id
     */
    public function linkedStatusIds(array $productIds): array
    {
        $rows = ProductProductStatusQuery::create()
            ->filterByProductId($productIds, Criteria::IN)
            ->orderById()
            ->select(['ProductId', 'ProductStatusId'])
            ->find();

        $statusIdByProductId = [];
        foreach ($rows as $row) {
            $statusIdByProductId[(int) $row['ProductId']] ??= (int) $row['ProductStatusId'];
        }

        return $statusIdByProductId;
    }

    /**
     * One query: statuses with their translation in $locale, falling back to the default
     * language unless the shop strictly uses the requested language
     * (`default_lang_without_translation` = {@see Lang::STRICTLY_USE_REQUESTED_LANGUAGE}).
     * A status without any title shows its code.
     *
     * @param list<int>|null $statusIds null loads every status
     */
    public function loadViews(string $locale, ?array $statusIds, bool $includeDefault): ProductStatusViewSet
    {
        $conditions = [];
        $parameters = [
            ':locale' => $locale,
            ':useFallback' => Lang::STRICTLY_USE_REQUESTED_LANGUAGE === (int) ConfigQuery::getDefaultLangWhenNoTranslationAvailable() ? 0 : 1,
        ];

        if (null !== $statusIds && [] !== $statusIds) {
            $placeholders = [];
            foreach ($statusIds as $index => $statusId) {
                $placeholders[] = ':id'.$index;
                $parameters[':id'.$index] = $statusId;
            }
            $conditions[] = 'ps.id IN ('.implode(', ', $placeholders).')';
        }
        if ($includeDefault) {
            $conditions[] = 'ps.code = :defaultCode';
            $parameters[':defaultCode'] = self::DEFAULT_STATUS_CODE;
        }
        if (null !== $statusIds && [] === $conditions) {
            return new ProductStatusViewSet([], null);
        }

        $sql = 'SELECT ps.id, ps.code, ps.color, ps.protected,
                COALESCE(NULLIF(requested.title, \'\'), fallback.title) AS title,
                COALESCE(NULLIF(requested.admin_title, \'\'), fallback.admin_title) AS admin_title,
                COALESCE(NULLIF(requested.description, \'\'), fallback.description) AS description
            FROM product_status ps
            LEFT JOIN product_status_i18n requested ON requested.id = ps.id AND requested.locale = :locale
            LEFT JOIN lang default_lang ON default_lang.by_default = 1 AND :useFallback = 1
            LEFT JOIN product_status_i18n fallback ON fallback.id = ps.id AND fallback.locale = default_lang.locale'
            .([] === $conditions ? '' : ' WHERE '.implode(' OR ', $conditions))
            .' ORDER BY ps.id';

        $statement = Propel::getReadConnection(ProductStatusTableMap::DATABASE_NAME)->prepare($sql);
        $statement->execute($parameters);

        $byId = [];
        $default = null;
        /** @var array{id: int|string, code: ?string, color: ?string, protected: int|string, title: ?string, admin_title: ?string, description: ?string} $row */
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $code = (string) $row['code'];
            $view = new ProductStatusView(
                id: (int) $row['id'],
                code: $code,
                color: ProductStatusColor::forDisplay($row['color']),
                title: null === $row['title'] || '' === $row['title'] ? $code : $row['title'],
                description: null === $row['description'] || '' === $row['description'] ? null : $row['description'],
                protected: 1 === (int) $row['protected'],
                adminTitle: null === $row['admin_title'] || '' === $row['admin_title'] ? null : $row['admin_title'],
            );
            $byId[$view->id] = $view;
            if (self::DEFAULT_STATUS_CODE === $code) {
                $default = $view;
            }
        }

        return new ProductStatusViewSet($byId, $default);
    }

    /**
     * Titles, back-office titles and descriptions stored in $locale, without any fallback: an
     * edit form shows what is written in the edited language, empty when nothing is.
     *
     * @return array<int, array{title: string, admin_title: string, description: string}> keyed by status id
     */
    public function translations(string $locale): array
    {
        $rows = ProductStatusI18nQuery::create()
            ->filterByLocale($locale)
            ->select(['Id', 'Title', 'AdminTitle', 'Description'])
            ->find();

        $translations = [];
        foreach ($rows as $row) {
            $translations[(int) $row['Id']] = [
                'title' => (string) $row['Title'],
                'admin_title' => (string) $row['AdminTitle'],
                'description' => (string) $row['Description'],
            ];
        }

        return $translations;
    }
}
