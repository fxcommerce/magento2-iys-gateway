# FxCommerce IYS Gateway for Magento 2

Magento 2 connector for store-scoped commercial communication consent synchronization with FxCommerce IYS Gateway.

## What it does

- Reads e-mail consent from Magento newsletter subscriber status.
- Stores SMS and call permission flags on the same store-scoped newsletter subscriber record.
- Queues consent state changes instead of blocking storefront requests.
- Sends signed HMAC-SHA256 batches to FxCommerce IYS Gateway.
- Keeps Store View, Website and source record metadata with every event.
- Supports retry, exponential backoff, cron and a full manual export command.
- Uses one access key per Magento Store View.

The module does not require merchants to enter Gateway URLs, integration IDs or API secrets manually. The IYS Gateway panel generates a single Store View access key.

## Requirements

- Magento Open Source or Adobe Commerce 2.4.x
- PHP 8.1, 8.2 or 8.3
- Magento cron configured
- An active FxCommerce IYS Gateway tenant and Magento integration

The module uses the `fxcommerce` configuration tab. When `FxCommerce_Core` is installed, the section is merged under the existing FxCommerce tab. The module can also install without FxCommerce_Core.

## Composer installation

```bash
composer config repositories.fxcommerce-iys-gateway vcs https://github.com/fxcommerce/magento2-iys-gateway.git
composer require fxcommerce/module-iys-gateway:^1.2
php bin/magento module:enable FxCommerce_IysGateway
php bin/magento setup:upgrade
php bin/magento cache:flush
```

Production mode:

```bash
php bin/magento setup:di:compile
php bin/magento setup:static-content:deploy -f
php bin/magento cache:flush
```

## Manual installation

Download a release ZIP and extract it into the Magento root so the module is located at:

```text
app/code/FxCommerce/IysGateway
```

Then run:

```bash
php bin/magento module:enable FxCommerce_IysGateway
php bin/magento setup:upgrade
php bin/magento cache:flush
```

## Configuration

1. In IYS Gateway, open **Integrations**.
2. Create one Magento connection for each Magento Store View.
3. Enter the exact Magento Store View code.
4. Copy the one-time access key.
5. In Magento, open **Stores > Configuration**.
6. Select the matching **Store View** scope.
7. Open **FxCommerce > IYS Gateway**.
8. Save the access key under **Gateway Access Key**.

The access key is only displayed once in IYS Gateway and is encrypted in Magento configuration storage.

## Initial synchronization

```bash
php bin/magento fxcommerce:iys:sync --store-id=1 --export
```

The manual `--export` operation drains all currently ready records in configured batch sizes and prints progress after every batch. Cron intentionally processes one bounded batch per Store View per run.

Example:

```text
Store tr (#1) - batch 1: 100 records exported, 1958 records remaining.
Store tr (#1) - batch 2: 100 records exported, 1858 records remaining.
Export completed: 2058 records exported in 21 batches; 0 records remaining.
```

## Security

- Store View access keys are encrypted by Magento's encrypted config backend.
- Requests include integration ID, API key, timestamp, body SHA-256 and HMAC-SHA256 signature.
- The Gateway rejects stale timestamps, invalid signatures and Store View mismatches.
- Queue event IDs provide idempotent delivery.
- Gateway-originated updates are protected from outbound echo loops.

## CLI options

```text
--store-id   Synchronize a single Magento Store View
--from-id    Start after a newsletter subscriber ID
--limit      Limit records added to the queue
--export     Drain all currently ready queue records
```

## License

Copyright FxCommerce. Source available under the terms in `LICENSE.md`.
