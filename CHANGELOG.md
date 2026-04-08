# Changelog

## Hotfix 38 V1 final

- fixed reseller API customer list route so it no longer misses the customer summary argument
- completed V1 API parity for reseller fractional traffic permission and forward-compatible template/customer fields
- added API, Telegram, 3x-ui, and panel-sync security log channels for access and error auditing
- removed obsolete Python QR leftovers and Python reseller API example from the shipped package
- cleaned legacy hotfix note clutter and packaged a single final V1 release note

## Hotfix 37 audit checkpoint

- audited the rebuilt hotfix36 package against the available hotfix notes and the current codebase
- removed obsolete Python QR helper artifacts that were no longer used by the live panel
- kept QR rendering on the pure PHP path only
- added per-reseller fractional traffic permission so admin can allow decimals or force whole-GB customer allocations
- updated notes to reflect the cumulative checkpoint state more accurately

## Hotfix 36

- fixed admin settings save flow so customer sync, pagination, cleanup, and backup values persist correctly
- added configurable oldest-first customer sync window to reduce 3x-ui load per cron run
- added separate visible auto-sync toggles for admin and reseller customer lists plus configurable visible batch size
- added automatic backup rotation count so old backups are trimmed safely after new backups are created
- hardened maintenance cleanup so it only touches cache, QR, cookie, and temp-style files inside safe storage paths
- added maintenance cron lock file to avoid overlapping shared-hosting cron runs

## Clean release snapshot

This release is the cleaned cumulative package assembled from the latest working feature line.

### Included feature groups

- Admin/reseller authentication and dashboards
- Multi-node 3x-ui integration and inbound template assignment
- Reseller credit accounting in GB
- Restrict mode and accounting-safe customer edit/delete logic
- IP limit and expiration rules
- Fixed and first-use expiration modes
- Subscription base priority and panel fallback subscription delivery
- Public `/get` access using phone/email with hashed PIN
- Pure PHP QR generation
- Notices, tickets, backups, logs, and transactions
- Reseller API with examples and optional encryption
- Telegram bot with polling/webhook support and helper scripts
- Panel-to-panel sync
- Storage/app/config URL protection and deployment hardening
- Customer status buckets (Active / Depleted / Ended / Disabled)

### Clean-up applied in this release

- removed obsolete Python QR helper path from the repository package
- removed stale QR vendor Python files and `__pycache__`
- removed old hotfix note files from the repository package
- kept helper scripts and current active code paths intact
