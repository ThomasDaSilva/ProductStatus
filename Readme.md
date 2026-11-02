# Product Status

Assigns one status (code, color, and translated title, back-office title and description) to each
product. The title is the label displayed on the shop; the back-office title, optional, is shown in the
administration only. A product without status reads as the protected `normal` status. Four statuses are protected and cannot be deleted:
`normal`, `discontinued`, `sale`, `oddment`.

Thelia 3.2 or later. The 2.x line of this module is for Thelia 2.

## Installation

```
composer require thelia/product-status-module:^3.2
php bin/console module:refresh
php bin/console module:activate ProductStatus
```

The installation script (`Config/TheliaMain.sql`) runs once, on the first activation, and drops nothing:
on a database carried over from the 2.x line the existing statuses and links are kept.

`Config/update/3.0.0.sql` makes the status unique per product: it deletes the duplicate links, keeping
the oldest one of each product (the one the 2.x line displayed), then adds a unique index on
`product_product_status.product_id`. It runs on the upgrade from 2.x and at every activation, and does
nothing once the index exists. The upgrade from 2.x and every activation also clean the stored
descriptions with the rules of `save()`.

To upgrade, update the package then run `php bin/console module:refresh` before serving pages: the
code reads the columns of the new version as soon as it is deployed, and `module:refresh` plays the
update scripts below.

`Config/update/3.2.0.sql` adds the `product_status_i18n.admin_title` column and fills each translation
with its title, so that every status starts with a back-office title; the four protected statuses, found
by code, get the translated back-office titles of a new installation instead (`sale`: « Soldes »,
« Sale », « Ausverkauf »...), whatever label the shop gave them. It runs on the upgrade from an
earlier version and at every activation, before the installation script, and does nothing once the
column exists: a back-office title edited since is never overwritten. The four protected statuses of a
new installation get their titles as back-office titles as well.

## Usage

### Service

`ProductStatus\Service\ProductStatusService` (autowired) is the single entry point:

| Method | |
|---|---|
| `statusOf(int $productId, string $locale): ProductStatusView` | status of a product, `normal` when it has none (read only) |
| `statusesOf(array $productIds, string $locale): array` | `array<int, ProductStatusView>` keyed by product id, two queries whatever the number of products |
| `all(string $locale): array` | every status, ordered by id |
| `ids(): array` | the id of every status, ordered by id, without reading any translation |
| `assign(int $productId, int $statusId): void` | replaces the status of the product; dispatches `ProductStatusEvents::PRODUCT_STATUS_CHANGED` only when it changes |
| `save(?int $id, string $code, string $color, string $title, ?string $description, string $locale, ?string $adminTitle = null): int` | creates or updates a status; `$adminTitle` null keeps the stored back-office title, an empty string removes it; the code is lower-cased, unique and a slug (`a-z`, `0-9`, `-`, `_`) when it is new or changed (a 2.x code with spaces can be kept as is), the code of a protected status cannot change, the color is `#rgb` or `#rrggbb` (stored `#rrggbb`), the description keeps only `<b><strong><i><em><a><br>` without any attribute but the `href` of an http(s), mailto or relative link |
| `translations(string $locale): array` | titles, back-office titles (`admin_title`) and descriptions stored in `$locale`, without fallback |
| `delete(int $id): void` | refuses a protected status; the products of the deleted status read `normal` again and each dispatches `PRODUCT_STATUS_CHANGED` |

Business errors are `ProductStatus\Exception\ProductStatusException` (a `\DomainException`); an unknown
status id is an `UnknownProductStatusException` (an `\InvalidArgumentException`). Both implement
`ProductStatusErrorInterface`, whose `translationKey()` is a message of the `productstatus` domain.
Titles and descriptions missing in the requested locale fall back to the default language, unless the
shop strictly uses the requested language (`default_lang_without_translation` = 0); a status without any
title then shows its code.

### Event

`productstatus.product_status.changed` (`ProductStatus\Event\ProductStatusChangedEvent`: `productId`,
`previousStatusId`, null when the product had no status, `statusId`).

### API

`/api/front/products` and `/api/front/products/{id}` carry the status of the product:

```json
"ProductStatusAddon": {
  "productStatus": {"code": "sale", "title": "Sale", "description": "clearance sale", "color": "#986dff"}
}
```

The title and description are in the language of the shop session (default language otherwise). The
protected flag and the dates are not exposed. The description is HTML restricted to a few tags and
attributes (descriptions stored by the 2.x line are cleaned on upgrade and on activation); a front that
prints it should still escape or sanitize it.

### Front theme

The module answers the `product.bottom` theme hook (`theme_hook('product.bottom', {product: product})`,
called by Flexy on the product page): it prints the title and the description of the status, and nothing
for the `normal` status. It reads the status from the `ProductStatusAddon` of the product resource when
the theme passes it. The CSS modifier `product-status--<code>` is the code reduced to a slug. Override `templates/theme-hook/product_status.html.twig` to restyle it.

### Back-office

Statuses are edited in the back-office edit language, chosen with the language switcher of the
configuration screen (`edit_language_id`, then the edit language kept in the admin session), never in the
language of the admin interface. The edit forms show what is stored in
that language only, empty when a status has no translation there.

The status of a product is chosen in the General tab of the product edit page, under the brand, and
saved with the product by its Save button (the `product.modification.form-right.bottom` hook of the
default-twig back-office, first of the module blocks there, and a field added to the
`thelia_product_modification` form). The select lists each status as its back-office title in the edit
language of the page, its title without one, followed by its code, which keeps apart statuses sharing a title; the badge above it shows the saved
status. Saving the status shown unchanged writes nothing.

It needs the update permission of the module, checked when the form is built and again before writing.
Without it the select is shown disabled and not posted; a product form that still carries the field (a
page opened before the permission was withdrawn) is refused as a whole, product included, and so is a
form carrying a status deleted since the page was displayed. The product is saved before its status: a
status deleted between that check and the write leaves a warning and the rest of the product saved. The
badge on top of the page links to the configuration screen.

## Changes in 3.2.0

- A translated back-office title (`admin_title`), optional, entered on the configuration screen and shown
  in its list and in the status select of the product edit page. The upgrade fills it with the title of
  each translation, and with the translated names of a new installation for the four protected statuses. It is never displayed on the shop nor
  exposed by the API: the title stays the label of the status there.
- The configuration screen calls the title « Label » and lists Code, Title, Label; the button of the edit
  window reads « Save ».
- `ProductStatusView::$adminTitle`, and `ProductStatusService::save()` takes the back-office title as an
  optional last argument. The configuration screen passes it only when its form posts the field: a
  configuration template overridden before 3.2.0 keeps the stored back-office titles.
- Translation keys of the `productstatus` domain: `The status name` becomes `The status label`, `Title of
  the status. Will be displayed in frontOffice` becomes `Label of the status. Will be displayed in
  frontOffice`, `The status title is required` becomes `The status label is required`; `The status
  title`, `Shown in the back office only`, `Title`, `Label` and `Save` are new. A shop that overrides
  the old keys moves its translations to the new ones.

## Changes in 3.1.0

- The status select moves from the Modules tab of the product edit page to its General tab, under the
  brand, and is saved with the product. The block of the Modules tab is gone: Thelia deletes its
  registration (`onProductTabContent` on `product.tab-content`) when it registers the hooks again.
- The product status assignment route (`product_status.product.assign`) and its form
  (`ProductStatusAssignForm`), used by that block only, are removed.
- `ProductStatusService::ids()` lists the status ids without reading their translations.

## Changes in 3.0.0

- Thelia 3 only (PHP 8.3 or later). Smarty templates, loops and `routing.xml` are gone.
- The `product_status` and `product_product_status` loops are not ported, nor their filtering of
  products by status (`product_status_code` / `product_status_id` on the product loop). Read the status
  through the API (`/api/front/products`) or `ProductStatusService`.
- The front banner of the `product.bottom` hook is now a theme hook; the 2.x script that moved it and
  removed the restocking alert button was specific to one theme and is not ported.
- One status per product (unique index, see Installation).

## Tests

Integration tests, run from the root of the Thelia project against its test database:

```
php bin/test-prepare
vendor/bin/phpunit --bootstrap vendor/thelia/modules/ProductStatus/Tests/bootstrap.php vendor/thelia/modules/ProductStatus/Tests
```
