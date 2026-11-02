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

use ProductStatus\Event\ProductStatusChangedEvent;
use ProductStatus\Event\ProductStatusEvents;
use ProductStatus\Exception\ProductStatusException;
use ProductStatus\Exception\UnknownProductStatusException;
use ProductStatus\Model\Map\ProductStatusTableMap;
use ProductStatus\Model\ProductProductStatus;
use ProductStatus\Model\ProductProductStatusQuery;
use ProductStatus\Model\ProductStatus;
use ProductStatus\Model\ProductStatusQuery;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Propel;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Single entry point to read and write product statuses.
 *
 * A product has at most one status; a product without any link reads as the
 * protected `normal` status (nothing is written for it).
 */
final readonly class ProductStatusService
{
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
        private ProductStatusReader $reader = new ProductStatusReader(),
        private ProductStatusDescriptionSanitizer $descriptionSanitizer = new ProductStatusDescriptionSanitizer(),
    ) {
    }

    public function statusOf(int $productId, string $locale): ProductStatusView
    {
        return $this->statusesOf([$productId], $locale)[$productId];
    }

    /**
     * @param array<int> $productIds
     *
     * @return array<int, ProductStatusView> keyed by product id, one entry per requested id
     */
    public function statusesOf(array $productIds, string $locale): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));

        if ([] === $productIds) {
            return [];
        }

        $statusIdByProductId = $this->reader->linkedStatusIds($productIds);
        $views = $this->reader->loadViews(
            $locale,
            array_values(array_unique($statusIdByProductId)),
            includeDefault: \count($statusIdByProductId) < \count($productIds),
        );

        $result = [];
        foreach ($productIds as $productId) {
            $result[$productId] = $views->viewForStatusId($statusIdByProductId[$productId] ?? null);
        }

        return $result;
    }

    /**
     * @return list<ProductStatusView>
     */
    public function all(string $locale): array
    {
        return array_values($this->reader->loadViews($locale, null, includeDefault: false)->byId);
    }

    /**
     * @return list<int> every status id, ordered by id
     */
    public function ids(): array
    {
        return $this->reader->ids();
    }

    /**
     * Titles, back-office titles and descriptions as stored in $locale, empty when a status has
     * none there.
     *
     * @return array<int, array{title: string, admin_title: string, description: string}> keyed by status id
     */
    public function translations(string $locale): array
    {
        return $this->reader->translations($locale);
    }

    public function assign(int $productId, int $statusId): void
    {
        if (null === ProductStatusQuery::create()->findPk($statusId)) {
            throw new UnknownProductStatusException($statusId);
        }

        $connection = $this->writeConnection();
        $connection->beginTransaction();

        try {
            // One link at most per product: product_product_status.product_id is unique.
            $link = ProductProductStatusQuery::create()
                ->filterByProductId($productId)
                ->findOne($connection);
            $previousStatusId = $link?->getProductStatusId();

            if ($previousStatusId === $statusId) {
                $connection->commit();

                return;
            }

            ($link instanceof ProductProductStatus ? $link : (new ProductProductStatus())->setProductId($productId))
                ->setProductStatusId($statusId)
                ->save($connection);

            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }

        ProductStatusMemo::clear();

        $this->eventDispatcher->dispatch(
            new ProductStatusChangedEvent($productId, $previousStatusId, $statusId),
            ProductStatusEvents::PRODUCT_STATUS_CHANGED,
        );
    }

    /**
     * Creates ($id null) or updates a status and its translation in $locale. The title is the
     * label displayed on the shop; $adminTitle, optional, is shown in the back office only:
     * null leaves the stored one untouched, an empty string removes it.
     *
     * @throws ProductStatusException        when the code or the title is empty, the color invalid, a new or changed code not a slug, the code already used or changed on a protected status
     * @throws UnknownProductStatusException when $id matches no status
     */
    public function save(?int $id, string $code, string $color, string $title, ?string $description, string $locale, ?string $adminTitle = null): int
    {
        $code = mb_strtolower(trim($code));
        $title = trim($title);
        $adminTitle = null === $adminTitle ? null : trim($adminTitle);

        $normalizedColor = ProductStatusColor::normalize($color);

        if ('' === $code) {
            throw ProductStatusException::codeRequired();
        }
        if ('' === $title) {
            throw ProductStatusException::titleRequired();
        }
        if (null === $normalizedColor) {
            throw ProductStatusException::invalidColor($color);
        }

        $status = null === $id ? new ProductStatus() : ProductStatusQuery::create()->findPk($id);

        if (null === $status) {
            throw new UnknownProductStatusException($id);
        }
        $codeChanged = $status->isNew() || $status->getCode() !== $code;
        if (1 === $status->getProtected() && $codeChanged) {
            throw ProductStatusException::protectedCodeChange();
        }
        if ($codeChanged && !ProductStatusCode::isValid($code)) {
            throw ProductStatusException::invalidCode($code);
        }

        $codeQuery = ProductStatusQuery::create()->filterByCode($code);
        if (null !== $id) {
            $codeQuery->filterById($id, Criteria::NOT_EQUAL);
        }

        if ($codeQuery->exists()) {
            throw ProductStatusException::duplicateCode($code);
        }

        $description = null === $description ? null : $this->descriptionSanitizer->sanitize($description);

        $status
            ->setCode($code)
            ->setColor($normalizedColor)
            ->setLocale($locale)
            ->setTitle($title)
            ->setDescription('' === $description ? null : $description);

        if (null !== $adminTitle) {
            $status->setAdminTitle('' === $adminTitle ? null : $adminTitle);
        }

        $status->save();

        ProductStatusMemo::clear();

        return (int) $status->getId();
    }

    /**
     * Deletes a status; its products fall back to `normal`, and each of them dispatches
     * {@see ProductStatusEvents::PRODUCT_STATUS_CHANGED} towards the `normal` status.
     *
     * @throws ProductStatusException        when the status is protected
     * @throws UnknownProductStatusException when $id matches no status
     */
    public function delete(int $id): void
    {
        $status = ProductStatusQuery::create()->findPk($id);

        if (null === $status) {
            throw new UnknownProductStatusException($id);
        }
        if (1 === $status->getProtected()) {
            throw ProductStatusException::protectedDeletion();
        }

        $productIds = array_map(
            'intval',
            ProductProductStatusQuery::create()->filterByProductStatusId($id)->select(['ProductId'])->find()->getData(),
        );
        $normalStatusId = (int) ProductStatusQuery::create()->findOneByCode(ProductStatusReader::DEFAULT_STATUS_CODE)?->getId();

        $status->delete();

        ProductStatusMemo::clear();

        foreach ($productIds as $productId) {
            $this->eventDispatcher->dispatch(
                new ProductStatusChangedEvent($productId, $id, $normalStatusId),
                ProductStatusEvents::PRODUCT_STATUS_CHANGED,
            );
        }
    }

    private function writeConnection(): ConnectionInterface
    {
        return Propel::getWriteConnection(ProductStatusTableMap::DATABASE_NAME);
    }
}
