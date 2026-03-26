# XUI Reseller Panel

Production-oriented reseller management panel for **3x-ui** nodes, built in **pure PHP** with **JSON storage**, **admin/reseller roles**, **3x-ui multi-server support**, **subscription delivery**, **Telegram bot integration**, **backup tools**, **panel-to-panel sync**, and a strong focus on **shared-hosting compatibility**.

> This project is designed as **your business/control layer**, while **3x-ui** acts as the node/backend layer. The panel keeps its own accounting, permissions, notices, tickets, reseller limits, public access routes, and automation features.

---

## Highlights

- Multi-server **3x-ui** support
- Admin + reseller roles
- JSON-only storage with file locking and atomic writes
- Per-reseller **GB credit accounting**
- Per-reseller allowed **server/inbound templates**
- Customer creation on **one selected permitted inbound**
- Public subscription route and public `/get` lookup route
- Local **pure PHP** QR generation
- Ticketing system
- Notices for resellers and public/client pages
- Backup creation, download, and delete
- Reseller API with optional encryption
- Telegram bot for reseller/client self-service
- Optional panel-to-panel sync (master/slave)
- Shared-hosting-friendly, no SQL required
- No frontend framework dependency

---

## Main Feature Set

### Admin features

- Create, edit, enable, disable, and remove resellers
- Create and manage 3x-ui servers/nodes
- Import and manage inbound templates
- Assign allowed templates to each reseller
- Credit reseller traffic in **GB**
- Set reseller limits such as:
  - max user IP limit
  - max expiration days
  - restrict mode
- Configure security, backup, shield, Telegram, API, and sync settings
- Create notices for:
  - resellers
  - public/client pages
  - both
- View reseller activity logs
- Create, download, and delete backups
- Run manual sync jobs where supported

### Reseller features

- Login to a dedicated reseller dashboard
- View available credit and restrictions
- Create customers on allowed templates only
- Search, sort, filter, and view customers
- See usage, left traffic, expiry, subscription links, and configs
- Copy/export subscription/config data
- Use phone+PIN protected `/get` access for end users if enabled per customer
- Open and reply to tickets
- Change their own password
- Manage Telegram User ID / bot binding
- Use reseller API if enabled by admin

### Customer/public features

- Public subscription page via `/user/<subscription_key>`
- Optional public `/get` route with **phone + PIN** access
- Status display with usage / left traffic / expiry
- Config list with copy buttons
- Local QR code rendering
- Public notices if enabled by admin

---

## Core Design

### 1) 3x-ui is the node layer, not the business layer

This panel does **not** treat 3x-ui as the source of truth for reseller business logic.

Instead, the panel keeps its own records for:

- reseller accounts
- permissions
- traffic credit
- notices
- tickets
- backup history
- bot bindings
- panel sync metadata
- phone/PIN public access

### 2) JSON storage, not SQL

The project is intentionally SQL-free.

Data is stored in JSON files with:

- per-record or grouped structured files
- file locking with `flock()` where needed
- temp-file write + rename for safer atomic updates
- cache/log/lock/config separation inside `storage/`

### 3) Shared hosting compatibility first

The project is designed to remain usable on environments where:

- MySQL is not desired
- shell access is limited
- Python is unavailable
- the panel must stay simple to deploy and move

---

## Feature Details

## Traffic credit and customer accounting

- Reseller credit is tracked in **GB**
- Customer initial traffic is also set in **GB**
- Credit is deducted when a customer is created successfully
- Unused traffic may be refunded according to the panel’s current accounting rules
- Customer usage sync is used to avoid credit abuse
- Restricted resellers cannot perform forbidden downgrade-style actions

### Restrict mode

When a reseller is marked as **restricted**, the reseller cannot:

- delete customers
- reduce traffic below allowed behavior
- disable/enable customers via toggle

This is meant for stricter delegated sales environments.

---

## Expiration modes

Customer expiration supports two modes:

- **fixed**: duration starts immediately
- **first_use**: duration starts on first connection/use

This is available in both panel UI and reseller API.

---

## IP limit and expiration limit controls

Admin can define reseller-level limits such as:

- maximum allowed per-customer IP limit
- maximum allowed expiration days

Behavior:

- reseller can only set values within allowed limits
- if the reseller max is `0`, that means unlimited permission for that field
- if the reseller max is non-zero, `0` is not allowed for customer-level unlimited bypass

---

## Subscription delivery

The panel supports two subscription concepts:

### Panel subscription route

A panel-managed route like:

`/user/<subscription_key>`

### External subscription base

Each server can optionally define a subscription base such as:

`https://example.com/user/`

If configured, the external/base subscription link is shown as the **primary** subscription URL, while the built-in panel link remains available as a fallback.

### External proxy config handling

If an inbound contains external proxy URLs/config variants:

- export prefers those external proxy configs
- the default inbound config is ignored in that case
- if no external proxy exists, the default config is used

---

## Public `/get` customer portal

Optional customer self-access route:

`/get`

If a reseller sets a **phone number** and **PIN** on a customer, the customer can enter them on `/get` and view:

- matching client accounts
- usage / remaining traffic
- expiry
- subscription links
- individual configs
- QR codes

### Phone/PIN rules

- phone is **optional**
- PIN is **optional**
- `/get` works **only when both are set**
- phone is stored **locally in the panel**
- PIN is stored **locally and hashed**
- these values are **not stored in 3x-ui**

---

## Local QR generation

QR codes are generated **locally by PHP**.

This means:

- no external QR website dependency
- no Python dependency
- no `proc_open` requirement
- better compatibility with shared hosting

If QR creation fails for any reason, the panel can fall back gracefully instead of breaking the whole page.

---

## Telegram bot

The project includes a Telegram bot module with support for:

- reseller account linking
- client binding/self-service
- polling mode
- webhook mode
- proxy support for Telegram requests

### Telegram proxy support

Supported proxy styles for bot transport:

- HTTP
- HTTPS
- SOCKS5

### Reseller bot actions

Examples include:

- balance
- list customers
- customer details
- subscription links
- sync
- create/edit helpers
- traffic changes within allowed rules

### Client bot actions

Examples include:

- bind by subscription key or UUID
- status
- subscription link retrieval

### Polling helpers

Included helper files:

- `scripts/telegram_poll_runner.sh`
- `scripts/telegram_poll_cron.php`
- `scripts/telegram_bot.service.example`

Use **webhook** when possible. If using polling:

- VPS: prefer a long-running service
- cPanel/shared hosting: use cron once per minute with long polling

---

## Reseller API

Optional reseller API is available when enabled by admin.

Typical capabilities include:

- profile
- templates
- customers list
- customer details
- create/edit/toggle/delete/sync (subject to permissions/restrictions)
- password change

Authentication supports reseller API key headers, and optional encryption can be enabled by admin.

Example helper files are included in the project.

---

## Notices system

Admin can create notices for:

- resellers only
- public/client pages only
- both

Notices may be time-limited or persistent depending on the form values used.

---

## Backup system

Admin can:

- create full backups
- download backups
- delete backups

This is intended for quick panel/data portability and safe upgrades.

---

## Panel-to-panel sync

The project includes optional sync between two panels.

### Modes

- Off
- Master
- Slave

### Purpose

Useful when you want a slave panel to mirror selected operational data from a master panel.

### Scope

The sync service is intended for operational entities such as:

- servers
- resellers
- customers
- supporting template/link data required for those records to remain usable

It does **not** mirror core admin/settings data as a full panel clone.

### Transport

- pure PHP
- optional proxy support for sync requests
- cron-based trigger supported

---

## Security and hardening

The project includes several hardening layers.

### Request protection

- CSRF token protection
- stricter POST handling
- request sanitization
- login hardening
- brute-force throttling for login and public routes

### Output/browser protection

- no-cache headers
- anti-index/no-follow headers
- CSP and browser hardening headers
- storage/app/config URL access protection with `.htaccess` rules for Apache/LiteSpeed

### Install lock

After install, the panel creates an install lock so `/install` cannot be abused to overwrite credentials on an already-deployed instance.

### JavaScript hardening

Optional JS-serving hardening is available, but it should be treated as **obfuscation/hardening**, not a replacement for proper transport security.

### Page shield

The optional page shield is an **extra browser-side response wrapper** for weak transport situations.

Important:

- it is **not a replacement for valid HTTPS**
- browser-side encryption over unsafe transport can still be tampered with
- use proper HTTPS whenever possible

---

## Requirements

Minimum intended environment:

- PHP **5.6+**
- cURL extension
- JSON support
- session support
- OpenSSL recommended
- Apache/LiteSpeed with `.htaccess`, or equivalent web-server rules

Practical recommendation for production:

- modern PHP 8.x is preferred
- Apache or Nginx with correct rewrite rules
- HTTPS strongly recommended

---

## Project structure

```text
.
├── app/
│   ├── PanelApp.php
│   ├── bootstrap.php
│   ├── lib/
│   └── views/
├── public/
│   ├── index.php
│   └── assets/
├── scripts/
│   ├── telegram_poll_runner.sh
│   ├── telegram_poll_cron.php
│   ├── telegram_bot.service.example
│   └── panel_sync_cron.php
├── storage/
│   ├── cache/
│   ├── config/
│   ├── data/
│   ├── locks/
│   └── logs/
├── config.php
├── index.php
└── .htaccess
```

---

## Installation

### Recommended deployment layout

Best practice:

- web root points to `public/`
- keep `app/` and `storage/` outside direct web access if your server layout allows it

Also supported:

- deploy the whole project under a subdirectory or root with the included `.htaccess` protection rules on Apache/LiteSpeed

### Basic install steps

1. Upload the project
2. Ensure `storage/` is writable by PHP
3. Open `/install`
4. Create the first admin account
5. Log in as admin
6. Add 3x-ui server(s)
7. Test node connection
8. Import inbounds
9. Create resellers
10. Assign templates and limits

---

## 3x-ui server setup notes

When adding a node, enter the server base and panel path carefully.

Examples:

- Base URL: `https://node.example.com`
- Panel Path: `/panel`

or, for custom base path examples:

- Base URL: `https://node.example.com`
- Panel Path: `/cpanel`

Avoid duplicated path combinations.

If your hosting blocks non-standard outbound ports, panel-side connectivity to 3x-ui may fail until the hosting provider opens those ports or the node is moved behind allowed ports.

---

## Upgrade guide

Before upgrading:

1. back up the whole project
2. back up `storage/`
3. replace code files
4. keep existing live storage unless you intend a clean reinstall

When upgrading from older hotfix generations, always keep a backup because newer features introduced:

- additional config fields
- install lock
- bot settings
- API settings
- notices
- sync metadata
- phone/PIN access data

---

## Deployment security notes

### Apache / LiteSpeed

The included `.htaccess` rules help block direct access to:

- `storage/`
- `app/`
- `config.php`
- hidden files
- sensitive extensions
- helper script directories where appropriate

### Nginx

Nginx does **not** read `.htaccess`.

If you deploy on Nginx, you must add equivalent deny rules yourself.

### Strong recommendation

Use valid HTTPS and keep the site behind a normal 80/443 reverse-proxy/public entry point where possible.

---

## Shared hosting notes

This project is intentionally designed to remain practical on shared hosting, but keep these realities in mind:

- long-running services may not be available
- webhook is usually better than polling if supported
- cron frequency is usually minute-based
- some hosts block outbound ports
- some hosts disable process execution

That is why the project moved to:

- pure PHP QR generation
- JSON storage
- PHP-based cron helpers

---

## Included helper scripts

### Telegram

- `scripts/telegram_poll_runner.sh`
- `scripts/telegram_poll_cron.php`
- `scripts/telegram_bot.service.example`

### Panel sync

- `scripts/panel_sync_cron.php`

---

## Recommended GitHub notes

When pushing this project to GitHub:

- keep `storage/` mostly empty in the repository
- keep placeholder `.gitkeep` files only
- never commit live production data
- never commit real bot tokens, sync secrets, panel URLs with secrets, or API keys
- review `config.php` before publishing a public repository

Also consider adding a `.gitignore` for:

- `storage/data/*`
- `storage/cache/*`
- `storage/logs/*`
- `storage/config/*`
- real backup archives
- temporary files

---

## Limitations and practical notes

- 3x-ui API behavior can vary slightly by version and setup
- live testing against your exact 3x-ui deployment is still recommended
- page-shield-style browser encryption is extra obfuscation, not real transport security
- Telegram polling on shared hosting is never as immediate as webhook or a persistent service
- Nginx users must add their own equivalent deny/rewrite rules

---

## Suggestions for future improvements

Good next steps if you want to extend the project further:

- admin 2FA
- IP allowlist for admin login
- scheduled backup retention cleanup
- richer Telegram conversational flows
- optional email notifications
- node health alerts
- audit export/reporting
- stronger settings grouping/UI polish

---

## License / ownership

Add your own license text here before publishing.

If this is a private business panel, make that explicit in the repository.

Example:

- Proprietary / internal use only
- or your selected open-source license

---

## Final note

This project is best viewed as a **3x-ui reseller operations panel** with:

- strong shared-hosting practicality
- reseller-centric business logic
- JSON-based portability
- a broad feature set layered on top of node management

If you publish it to GitHub, this README is a good base to start from and adjust with your branding, license, screenshots, and deployment examples.
