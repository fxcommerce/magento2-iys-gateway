# Changelog

## 1.2.5

- Added independent Store View scoped enable/disable controls for SMS and call consent.
- Bound SMS and call channels to the main IYS Gateway enable state.
- Hidden disabled channels from the customer Newsletter page and excluded them from outbound and inbound synchronization.
- Hidden the editable GSM field when the phone source is a customer attribute.

## 1.2.4

- Added independent EMAIL, SMS and CALL consent timestamps.
- Added signed Gateway action pull and acknowledgement endpoints.
- Added bidirectional synchronization with newest-timestamp conflict resolution.
- Prevented Gateway-originated updates from creating outbound echo events.
- Added Store View scoped inbound cron and `--pull` CLI processing.
- Added phone recipient storage and validation for SMS and call permissions.
- Replaced state-only event IDs with timestamp-aware idempotent event IDs.
- Added bounded Magento package dependency constraints.

## 1.2.3

- Removed the hard runtime dependency on FxCommerce_Core while preserving the FxCommerce configuration tab.
- Added the missing Magento Cron Composer dependency.
- Added public Composer and manual installation documentation.

## 1.2.2

- Manual `--export` now drains all ready records batch by batch.
- Added per-batch console progress.

## 1.2.1

- Correctly decrypts encrypted Magento access key configuration values before parsing.

## 1.2.0

- Added Store View scoped access keys and Store/Website metadata.
