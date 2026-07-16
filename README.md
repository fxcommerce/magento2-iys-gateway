# FxCommerce IYS Gateway for Magento 2

Magento 2 connector for Store View scoped commercial communication consent synchronization with FxCommerce IYS Gateway.

## What it does

- Reads e-mail consent from Magento newsletter subscriber status.
- Stores SMS and call permission flags and the phone recipient on the same Store View scoped subscriber record.
- Publishes independent EMAIL, SMS and CALL consent timestamps.
- Queues consent changes instead of blocking storefront or admin requests.
- Sends signed HMAC-SHA256 batches to FxCommerce IYS Gateway.
- Pulls newer IYS decisions and applies them to Magento without creating echo events.
- Resolves Magento/IYS differences channel by channel using the newest consent timestamp.
- Keeps Store View, Website, source record and synchronization history metadata.
- Supports retry, exponential backoff, cron and full manual push/pull commands.
- Uses one access key per Magento Store View.

The module does not require merchants to enter Gateway URLs, integration IDs or API secrets manually. The IYS Gateway panel generates a single Store View access key.

## Requirements

- Magento Open Source or Adobe Commerce 2.4.x
- PHP 8.1, 8.2 or 8.3
- Magento cron configured
- An active FxCommerce IYS Gateway tenant, IYS account and Magento integration

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

Download the `FxCommerce_IysGateway.zip` release asset and extract it in the Magento root. The archive already contains:

```text
app/code/FxCommerce/IysGateway
```

Then run:

```bash
unzip FxCommerce_IysGateway.zip -d .
php bin/magento module:enable FxCommerce_IysGateway
php bin/magento setup:upgrade
php bin/magento setup:di:compile
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
9. Enable cron synchronization and select the required batch/retry settings.

The access key is only displayed once in IYS Gateway and is encrypted in Magento configuration storage.

## Initial synchronization

Queue and export all subscriber consent states for one Store View:

```bash
php bin/magento fxcommerce:iys:sync --store-id=1 --export
```

Pull and apply all pending newer IYS decisions for the same Store View:

```bash
php bin/magento fxcommerce:iys:sync --store-id=1 --pull
```

Run both directions in one operation:

```bash
php bin/magento fxcommerce:iys:sync --store-id=1 --export --pull
```

Manual `--export` and `--pull` operations drain ready records batch by batch and print progress. Cron intentionally processes bounded batches per Store View on each run.

## Timestamp conflict rules

EMAIL, SMS and CALL are evaluated independently:

- Magento timestamp newer: Magento state is sent to IYS.
- IYS timestamp newer: IYS state is applied to Magento.
- Same timestamp and same state: no transfer is required.
- Same timestamp and different state: the record is marked as a conflict and neither side is overwritten automatically.

Every decision is retained by the Gateway with source, target, timestamps, status and reason.

## Security

- Store View access keys are encrypted by Magento's encrypted config backend.
- Requests include integration ID, API key, timestamp, body SHA-256 and HMAC-SHA256 signature.
- The Gateway rejects stale timestamps, invalid signatures and Store View mismatches.
- Queue event IDs provide idempotent delivery.
- Gateway-originated updates are protected from outbound echo loops.
- SMS and call approvals require a valid phone recipient.

## CLI options

```text
--store-id   Synchronize a single Magento Store View
--from-id    Start after a newsletter subscriber ID
--limit      Limit records added to the queue
--export     Drain all currently ready Magento-to-Gateway records
--pull       Drain all currently ready Gateway-to-Magento actions
```

## License

Copyright FxCommerce. Source available under the terms in `LICENSE.md`.
