# Changelog

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
