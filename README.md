# XUI Reseller Panel

A production-oriented reseller panel for **3x-ui** nodes, built in **pure PHP** with **JSON storage**, **admin and reseller roles**, **public customer access routes**, **Telegram bot integration**, **backup and logging tools**, **optional panel-to-panel sync**, and a strong emphasis on **shared-hosting compatibility**.

> This panel is designed to be the **business and control layer**. 3x-ui acts as the **node/backend layer**. The panel keeps its own records for reseller permissions, credit accounting, notices, tickets, API access, Telegram bindings, public `/get` access, logs, and sync metadata.

---

## Table of Contents

- [What this project is](#what-this-project-is)
- [Main feature overview](#main-feature-overview)
- [Role-by-role features](#role-by-role-features)
- [Core business rules](#core-business-rules)
- [Telegram bot](#telegram-bot)
- [Reseller API](#reseller-api)
- [Public customer access](#public-customer-access)
- [Security and hardening](#security-and-hardening)
- [System logs, backups, and transactions](#system-logs-backups-and-transactions)
- [Panel-to-panel sync](#panel-to-panel-sync)
- [Requirements](#requirements)
- [Project structure](#project-structure)
- [Installation](#installation)
- [3x-ui node setup notes](#3x-ui-node-setup-notes)
- [Upgrade guidance](#upgrade-guidance)
- [Deployment and security notes](#deployment-and-security-notes)
- [Shared hosting notes](#shared-hosting-notes)
- [GitHub publishing notes](#github-publishing-notes)
- [Limitations and practical notes](#limitations-and-practical-notes)
- [Release summary](#release-summary)

---

## What this project is

This project is a **reseller operations panel for 3x-ui** with the following design goals:

- no SQL dependency
- easy deployment on shared hosting or standard VPS setups
- clear separation between **node control** and **business logic**
- strong reseller management and customer delivery flows
- maintainable PHP code without frontend framework lock-in
- local-only storage for sensitive customer-access metadata such as phone, email, and PIN

It is intended for operators who want to manage one or many 3x-ui nodes while keeping reseller rules, customer access, and support tools in their own panel.

---

## Main feature overview

- Multi-server **3x-ui** support
- Admin and reseller roles
- JSON-only storage with file locking and atomic writes
- Server/inbound **template assignment** per reseller
- Reseller credit accounting in **GB**
- Customer creation on **one selected permitted server/inbound**
- Fixed or **first-use** expiration mode
- Per-reseller limit controls for:
  - IP limit
  - expiration days
  - min/max customer traffic in GB
  - restrict mode
- Public subscription page via `/user/<subscription_key>`
- Public customer self-access route via `/get`
- Local **pure PHP QR code generation**
- Notices for resellers and public/customer pages
- Ticketing system
- Admin activity and system logs
- Credit transaction history
- Backup create/download/delete
- Optional reseller API with optional payload encryption
- Telegram bot for reseller and customer self-service
- Optional panel-to-panel sync between master and slave panels
- Shared-hosting-friendly helper scripts for polling and sync
- Apache/LiteSpeed URL protection for app/storage/config paths

---

## Role-by-role features

### Admin features

Admin can:

- create, edit, enable, disable, and remove resellers
- set reseller credit in **GB**
- define reseller restrictions and limits
- manage 3x-ui nodes/servers
- import and manage inbound templates
- assign allowed templates to specific resellers
- configure app settings, security, Telegram, API, page shield, sync, and backup options
- create notices for:
  - resellers only
  - public/customer pages only
  - both
- review reseller activity logs
- review rotating system logs for areas such as:
  - login access
  - login errors
  - `/get` access/errors
  - firewall-related errors
- clear supported logs
- create, download, and delete backups
- review reseller credit transactions and charges
- delete tickets
- run sync/manual helper actions where supported

### Reseller features

Reseller can:

- log in with automatic admin/reseller detection on the login page
- view dashboard, balance, restrictions, and notices
- create customers on **allowed templates only**
- choose customer traffic, expiration mode, expiration days, and IP limit within admin-defined rules
- search, sort, and filter customers
- view customer status, usage, left traffic, expiry, configs, QR, and subscription links
- use primary subscription base links when configured on the node
- see built-in panel subscription as fallback
- use tickets and notices
- change their own password
- manage their Telegram user ID and bot binding token
- use the reseller API if enabled by admin

### Customer / public features

Customer-facing/public features include:

- `/user/<subscription_key>` subscription page
- `/get` customer access by **phone + PIN** or **email + PIN** when configured on the customer
- usage, left traffic, expiry, status, configs, copy buttons, and QR display
- public notices when enabled by admin

---

## Core business rules

### 1) 3x-ui is the node/backend layer

This panel does **not** use 3x-ui as the source of truth for reseller business rules.

The panel keeps its own records for:

- admins
- resellers
- nodes/templates permissions
- customer metadata
- phone/email/PIN access
- transactions
- notices
- tickets
- Telegram bindings
- activity/system logs
- sync metadata

### 2) JSON storage, not SQL

The project uses JSON storage on purpose.

Storage is split into directories under `storage/` for:

- config
- data
- cache
- locks
- logs
- backups

Writes use panel-side storage helpers and locking patterns rather than SQL tables.

### 3) Credit accounting in GB

The reseller balance is tracked in **GB**.

Customer allocation also uses **GB**.

The panel enforces safer accounting around create/edit/delete flows, including:

- charging only when customer creation succeeds
- syncing usage before sensitive credit-affecting operations where required
- blocking abusive traffic reductions below used amount
- optional reseller restrict mode for stricter delegated control

### 4) Restrict mode

When **restrict** is enabled on a reseller, the reseller cannot perform actions that reduce control or quota in unauthorized ways.

This includes restrictions such as:

- no customer deletion
- no customer disable/enable toggle
- no lowering customer traffic

### 5) Reseller min/max customer traffic enforcement

Admin can enforce **minimum** and **maximum** customer traffic per reseller.

Rules:

- `0` means no limit for that boundary
- if a min is set, reseller cannot create/edit below that value
- if a max is set, reseller cannot create/edit above that value

Example:

- min = `2`
- max = `10`

Then that reseller can only set customer traffic from **2 GB to 10 GB**.

### 6) IP limit and expiration controls

Admin can set reseller-level caps for:

- max customer IP limit
- max expiration days

Rules:

- if reseller max is `0`, customer value may be any non-negative allowed value, including `0` for unlimited where supported
- if reseller max is non-zero, customer values must stay inside that cap

### 7) Expiration modes

Customer expiration supports two modes:

- **fixed**: countdown starts immediately from save/create
- **first_use**: countdown starts after first real connection/use

This is reflected in the panel and in the reseller API.

### 8) Customer runtime status buckets

Customer list/status now reflects runtime-like states instead of only local `active/disabled`.

Displayed states include:

- **Active**
- **Depleted** when remaining traffic is exhausted
- **Ended** when expiration is reached
- **Disabled**

Admin and reseller customer lists include separate filtering/bucket views for:

- All
- Active
- Depleted / Ended

---

## Telegram bot

The panel includes a Telegram bot module designed for reseller and customer self-service.

### Supported bot transport modes

- webhook
- polling

### Proxy support

Telegram transport supports optional proxy settings such as:

- HTTP
- HTTPS
- SOCKS5

### Reseller bot functions

Typical reseller bot operations include:

- account linking by one-time token
- balance view
- customers list
- customer details
- subscription retrieval
- sync actions
- traffic changes within allowed rules
- notices/help/menu

### Customer bot functions

Customers can bind to the bot using supported identifiers such as subscription key or UUID and then view:

- status
- subscription information
- usage-related data

### Polling helper files

Included helper files:

- `scripts/telegram_poll_runner.sh`
- `scripts/telegram_poll_cron.php`
- `scripts/telegram_bot.service.example`

Recommended usage:

- **webhook** when possible
- **systemd service** on VPS when polling is preferred
- **cron once per minute with long polling** on shared hosting/cPanel

---

## Reseller API

The panel includes an optional reseller API when enabled by admin.

### Authentication

Authentication supports reseller API keys through headers such as:

- `Authorization: Bearer <api-key>`
- `X-Reseller-Api-Key: <api-key>`

### API capabilities

Typical capabilities include:

- profile
- templates
- customers list
- customer details
- customer create/edit
- customer toggle/delete/sync when allowed
- password change

### Optional encryption

Admin can enable API encryption so reseller payloads use a reseller-specific key flow.

### Current V1 response notes

The current V1 API responses include compatibility fields such as:

- `api_version`
- `allow_fractional_traffic_gb` on reseller profile
- `server_type`, `template_id`, and `node_id` on customer/template payloads where relevant

This keeps existing V1 clients working while exposing the newer fields needed by current panel features.

### Included examples

Project examples are included in:

- `public/assets/examples/reseller_api_example.php.txt`

The bundled API example is PHP-only so the package stays clean and self-consistent for shared-hosting deployments.

---

## Public customer access

### `/user/<subscription_key>`

This route shows the customer subscription view with:

- primary subscription URL
- fallback panel URL
- config list
- copy buttons
- QR display
- usage/status information

### `/get`

The `/get` route is an optional customer self-access portal.

A customer can access it using either:

- **phone + PIN**
- **email + PIN**

Behavior notes:

- phone and email are stored **locally in the panel**
- PIN is stored **locally and hashed**
- these values are **not stored in 3x-ui**
- if phone/email/PIN are not configured for a customer, `/get` access is not available for that customer
- phone is treated as the primary contact style, with email available as an additional lookup path

### QR codes

QR codes are generated **locally by pure PHP**.

This means:

- no external QR website dependency
- no Python/process requirement
- better compatibility with shared hosting

QR is available in public customer-facing views and internal subscription/config views.

---

## Security and hardening

### Request protections

The project includes multiple request-side protections, including:

- CSRF tokens
- origin / request metadata-aware POST checks with compatibility fallbacks
- request sanitization and input normalization
- brute-force/rate-limit handling for login and public access routes
- install locking after setup

### Browser/output protections

The project adds browser-side hardening headers such as:

- no-cache headers
- noindex/nofollow style headers
- CSP and related browser hardening
- script/path protection rules for sensitive directories on Apache/LiteSpeed

### App/storage/config protection

Included `.htaccess` rules protect direct URL access to areas such as:

- `storage/`
- `app/`
- `config.php`
- hidden files
- sensitive extensions
- helper script directories

### JavaScript hardening and page shield

The project includes optional browser-side hardening features such as:

- hardened/served JS wrapper mode
- optional page shield response wrapping
- optional POST shielding for weak transport scenarios

Important:

These features are **extra hardening/obfuscation**, not a replacement for valid HTTPS.

Use proper HTTPS whenever possible.

---

## System logs, backups, and transactions

### System logs

Admin can review rotating logs for system areas such as:

- login access
- login errors
- `/get` access
- `/get` errors
- firewall-related errors

Admin can also clear supported logs.

### Reseller activity log

The panel stores reseller action history for events such as:

- create/edit/delete customer
- enable/disable where allowed
- related operational actions

### Ticketing

The panel includes internal ticketing for admin and reseller use.

Admin can manage and delete tickets.

### Backup system

Admin can:

- create full backups
- download backups
- delete backups

### Credit transaction history

Admin can inspect credit ledger/transaction history for reseller top-ups and related GB credit changes.

---

## Panel-to-panel sync

The project includes a pure PHP sync service between two panels.

### Modes

- Off
- Master
- Slave

### Scope

The sync service is intended for operational entities only, including:

- customers
- resellers
- servers/nodes
- supporting template/link records needed for those entities to remain usable

It does **not** act as a full clone of admin credentials/settings.

### Settings

Sync supports:

- master URL
- shared secret
- interval in seconds
- optional prune of missing synced records
- optional proxy for sync transport

### Helper script

Included helper:

- `scripts/panel_sync_cron.php`

---

## Requirements

Minimum intended environment:

- PHP **5.6+**
- cURL extension
- JSON support
- session support
- OpenSSL recommended
- Apache/LiteSpeed with `.htaccess` support, or equivalent server rules

Practical production recommendation:

- modern PHP 8.x
- valid HTTPS
- Apache, LiteSpeed, or correctly configured Nginx equivalent rules

---

## Project structure

```text
.
├── app/
│   ├── PanelApp.php
│   ├── bootstrap.php
│   ├── lib/
│   │   ├── JsonStore.php
│   │   ├── XuiAdapter.php
│   │   ├── PurePhpQr.php
│   │   └── functions.php
│   └── views/
├── public/
│   ├── index.php
│   └── assets/
│       ├── app.css
│       ├── app.js
│       ├── key.js
│       └── examples/
├── scripts/
│   ├── telegram_poll_runner.sh
│   ├── telegram_poll_cron.php
│   ├── telegram_bot.service.example
│   └── panel_sync_cron.php
├── storage/
│   ├── backups/
│   ├── cache/
│   ├── config/
│   ├── data/
│   ├── locks/
│   └── logs/
├── config.php
├── index.php
├── .htaccess
└── README.md
```

---

## Installation

### Recommended deployment layout

Best practice:

- point web root to `public/`
- keep `app/` and `storage/` outside direct web access when possible

Also supported:

- deploy the whole project under domain root or a subdirectory such as `/shop` or `/panel`, using the included `.htaccess` protection rules on Apache/LiteSpeed

### Basic install steps

1. Upload the project
2. Ensure `storage/` is writable by PHP
3. Open `/install`
4. Create the first admin account
5. Log in as admin
6. Add one or more 3x-ui nodes
7. Test node connection
8. Import inbounds
9. Create resellers
10. Assign templates and limits
11. Configure security, backup, Telegram, API, and sync settings as needed

### Install lock

After installation, the panel creates an install lock so `/install` cannot be reused to overwrite the existing admin setup.

---

## 3x-ui node setup notes

When adding a node, enter the base URL and panel path carefully.

Examples:

- Base URL: `https://node.example.com`
- Panel Path: `/panel`

or for custom panel base paths:

- Base URL: `https://node.example.com`
- Panel Path: `/cpanel`

Avoid duplicating paths such as putting the same path in both fields incorrectly.

If your host blocks outbound custom ports, node connection tests may fail even with correct credentials until outbound access is allowed or the node is moved behind accessible ports.

---

## Upgrade guidance

Before upgrading:

1. back up the full project
2. back up `storage/`
3. replace code files carefully
4. preserve live `storage/` if this is an upgrade, not a fresh install

When upgrading across older internal patch generations, keep a backup first because newer versions introduced additional:

- settings fields
- bot settings
- API settings
- notice records
- sync metadata
- customer phone/email/PIN metadata
- logs and transaction views

---

## Deployment and security notes

### Apache / LiteSpeed

The included `.htaccess` files are important and should not be removed unless you are replacing them with equivalent server rules.

### Nginx

Nginx does **not** read `.htaccess`.

If deploying on Nginx, you must add equivalent deny/rewrite rules yourself for:

- front controller routing
- protected paths
- sensitive file extensions

### HTTPS recommendation

Use valid HTTPS whenever possible.

The optional shield/hardening features are **not** equal to proper transport security.

---

## Shared hosting notes

This project intentionally remains practical on shared hosting.

Important realities:

- cron is usually minute-based
- long-running services may be unavailable
- some hosts block outbound ports
- some hosts restrict shell/process execution
- some hosts have strict file permission rules

That is why the project relies on:

- pure PHP QR generation
- JSON storage
- PHP-based helper scripts
- Apache/LiteSpeed-compatible `.htaccess` protection

---

## GitHub publishing notes

Before pushing to GitHub:

- keep `storage/` empty except placeholder `.gitkeep` files
- do not commit live customer data, backups, logs, cache, or secrets
- review `config.php`
- review `public/assets/key.js` and any local environment values
- remove or replace any proprietary branding or secrets used in production

Included in this clean release:

- old temporary QR/Python helper leftovers removed
- repository-friendly `.gitignore` added
- cumulative code kept intact

---

## Limitations and practical notes

- 3x-ui API behavior can vary by release and environment
- live testing against your exact 3x-ui deployment is still recommended
- page shield and client-side encryption layers are extra wrappers, not full transport guarantees
- Telegram polling on shared hosting is never as immediate as webhook or a persistent service
- Nginx users must provide their own equivalent server rules

---

## Release summary

This clean release is intended to be the **GitHub-ready cumulative package** with the latest merged feature set up to the current state, while removing obsolete development leftovers that are no longer used by the active code path.

It keeps the current working feature line, including:

- admin/reseller/customer flows
- 3x-ui multi-node integration
- reseller rules and restrictions
- subscription and `/get` customer access
- pure PHP QR
- notices, logs, tickets, backups, and transactions
- reseller API
- Telegram bot
- panel sync
- deployment hardening

If you publish it publicly, add your own screenshots, branding, and license before release.


## Shared-hosting maintenance cron

A shared-hosting friendly maintenance runner is included at `scripts/cron.php`. It can be called once per minute from cPanel cron. The script performs only the tasks enabled in **Admin → Settings** and only when each configured period is due.

It can handle:

- customer state sync from 3x-ui
- periodic stale cache cleanup
- automatic backups

Example cron command:

```bash
/usr/bin/php /home/USERNAME/public_html/panel/scripts/cron.php >/dev/null 2>&1
```

The same settings page also lets the admin enable or disable customer pagination and choose the per-page size for the Admin and Reseller customer lists.
