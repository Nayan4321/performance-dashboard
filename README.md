# Performance Dashboard + Inventory

Laravel 12 / MySQL app for Hostinger shared hosting. Two parts:

1. **Performance dashboards** synced from **Zenoti** (live) and **CallGear** (wired in, switched off until API access arrives). Zoho-style dashboard builder with KPI tiles, bar / line / pie / doughnut charts, funnels and tables; branch tabs (summary first, then each branch) and an employee filter; numbers refresh every 60 seconds.
2. **Inventory** (separate, not synced): products with prices and tax, branch stock-order requests with auto-calculated totals, stock-manager approval, status tracking, stock movement between branches and PDF invoices.

No Node/npm build step: Bootstrap, icons and Chart.js are bundled in `public/vendor/`.

---

## Access model

| Concept | Where to manage | Notes |
|---|---|---|
| **Roles** | Admin › Roles & permissions | Built in: `super-admin`, `management`, `org-admin`, `branch-manager`, `employee`, `stock-manager`, `branch-stock-admin`. Add your own roles and tick permissions. |
| **Extra permissions per user** | Admin › Users › Edit | On top of the user's roles. |
| **Modules per organization** | Admin › Modules | Matrix of which module belongs to which organization. `super-admin` and `management` always see everything. |
| **Module override per user** | Admin › Users › Edit | "Always show" / "Always hide" beats the organization default. |
| **Data scope** | user's organization + branch | `performance.view-all-branches` → everything; `performance.view-organization` → own org; `performance.view-branch` → own branch; none of these → only the linked employee's own numbers. |

Synced employees (Zenoti, later CallGear) automatically get a login with the `employee` role, **disabled** until an admin activates it and sets a password (change with `ZENOTI_AUTO_USER_ACTIVE`). Manual users are created in Admin › Users and can be linked to an employee record.

### Inventory statuses

`Submitted → Approval in process → Approved → Payment requested → Payment completed → Completed`, with `Rejected` (reason required) possible until payment completes, and `Cancelled` by the requester before approval. Only users with `inventory.orders.approve` (stock manager) change status. An invoice can be generated from *Approved* onwards. On *Completed*, quantities move from the supplying branch to the requesting branch (Stock levels page).

---

## Deploying to Hostinger (Premium / Business shared hosting)

1. **PHP version**: hPanel › Advanced › PHP Configuration → **PHP 8.3 or newer**. Make sure `pdo_mysql`, `mbstring`, `gd`, `zip`, `intl`, `fileinfo` are enabled (they are by default).
2. **Database**: hPanel › Databases › MySQL Databases → create a database + user. Note the names (they start with `u123456789_`).
3. **Upload the code** (File Manager or SSH/Git) to `domains/YOUR-DOMAIN/laravel/` (a folder next to `public_html`, not inside it).
   - If you upload the source without `vendor/`, run over SSH: `cd domains/YOUR-DOMAIN/laravel && composer install --no-dev --optimize-autoloader`.
   - Or upload the ready-made zip that already includes `vendor/`.
4. **Point the domain at `public/`** (pick one):
   - SSH: `rm -rf ~/domains/YOUR-DOMAIN/public_html && ln -s ~/domains/YOUR-DOMAIN/laravel/public ~/domains/YOUR-DOMAIN/public_html`
   - Or put the whole project *in* `public_html`; the root `.htaccess` forwards everything into `public/`.
5. **Configure**: copy `.env.example` to `.env` and fill in `APP_URL`, `DB_*`, `ADMIN_EMAIL`, `ADMIN_PASSWORD`. Then:
   ```bash
   php artisan key:generate
   php artisan migrate --seed --force       # creates tables, roles, modules, starter dashboard and the super admin
   php artisan storage:link
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   ```
   Optional sample data to try things before Zenoti is connected: `php artisan db:seed --class=DemoDataSeeder --force` (logins `manager@demo.test`, `stock@demo.test`, `andheri@demo.test`, password `password`; delete them before going live).
6. **Cron** (hPanel › Advanced › Cron Jobs), every minute:
   ```
   cd /home/u123456789/domains/YOUR-DOMAIN/laravel && php artisan schedule:run >> /dev/null 2>&1
   ```
   This runs the Zenoti/CallGear syncs (appointments + sales every 5 min, guests + leads every 15 min, employees hourly, centers daily).

## Installing updates (no SSH)

Log in as super admin › **Upload update** › choose the update zip › review the file list › tick the box › **Install update**. The app backs up every file it replaces (in `storage/app/update-backups/`), writes the new files, runs database migrations and any seeders named in the zip's `update.json`, and clears caches. **Roll back files** puts the previous files back (database changes stay). Updates can never change `.env`, `storage/` or `bootstrap/cache/`.

If you ever copy files by hand with the File Manager, click **Finish install** on the same page to run migrations and clear caches.

## Connecting Zenoti

1. In `.env`: `ZENOTI_ENABLED=true`, `ZENOTI_API_KEY=...` (from Zenoti › Configuration › Integrations › Apps / API keys), and a long random `ZENOTI_WEBHOOK_SECRET`. Run `php artisan config:cache` after editing `.env`.
2. Check what the API returns and that field names match: `php artisan zenoti:probe centers`, then `php artisan zenoti:probe employees --center=CENTER_ID`, `appointments`, `sales`. If a path differs for your tenant, override it with `ZENOTI_EP_*` in `.env` (see `config/zenoti.php`); field mapping lives in `app/Services/Zenoti/ZenotiMapper.php`.
3. First import (e.g. 90 days of history): `php artisan integrations:sync zenoti --days=90`. Centers become branches under `ZENOTI_DEFAULT_ORGANIZATION`; move branches to other organizations in Admin › Organizations.
4. **Webhooks for instant updates**: in Zenoti's webhook settings register `https://YOUR-DOMAIN/webhooks/zenoti?token=YOUR_ZENOTI_WEBHOOK_SECRET` for guest, employee, appointment and invoice events. Every webhook is stored and shown in Admin › Integrations; the cron sync catches anything a webhook misses.
5. The **Admin dashboard** mirrors Zenoti's own (appointments, bookings, collections, sales, liabilities, product/package/gift card/membership sales, appointments by status) plus new guests. Appointment statuses and sale categories are normalised in `ZenotiMapper::appointmentStatus()` / `saleCategory()`; the original values are kept (`raw_status`, `item_type`) so the mapping can be checked. The **Collections** tile needs `ZENOTI_EP_COLLECTIONS` set to your payments/collections report path. Provider utilization and guest feedback are not synced yet. Zenoti has no "list all guests" API, so guests are created from the guest details on each appointment (their first booking counts as their registration date).
6. Leads: set `ZENOTI_EP_LEADS` once you confirm which Zenoti CRM/opportunity endpoint your account uses. Until then leads come from webhooks and the manual Leads screen.

## Connecting CallGear (when access arrives)

**Interactive call processing (works now, no Data API needed):** set a long random `CALLGEAR_WEBHOOK_SECRET` in `.env`, then in the CallGear virtual PBX scenario add an *Interactive call processing* operation with URL `https://YOUR-DOMAIN/webhooks/callgear/incoming?token=THAT_SECRET`, method GET or POST, parameters `cdr_id`, `start_time`, `numa`, `numb`. Every incoming call is logged on the **Calls** page, matched to the Zenoti guest by phone number and to the branch by the dialed number (set each branch's phone in Admin › Organizations). The site answers `{}` (no instruction), so configure the scenario's next/fallback step as CallGear recommends; to steer routing set `CALLGEAR_INCOMING_REPLY`, e.g. `{"returned_code": 1}`.


Set `CALLGEAR_ENABLED=true`, `CALLGEAR_ACCESS_TOKEN`, `CALLGEAR_WEBHOOK_SECRET`, put each branch's CallGear site id on Admin › Organizations, then `php artisan integrations:sync callgear`. The client (`app/Services/CallGear/`) is written against CallGear's JSON-RPC Data API (`get.calls_report`, `get.employees`); confirm method names and fields against the docs they give you. Calls feed the **Calls** dataset in the dashboard builder. CallGear employees are matched to existing Zenoti employees by email so each person has one record.

---

## Where things live

| Path | What |
|---|---|
| `app/Support/Datasets.php` | Data sets available in the dashboard builder (add a new source here) |
| `app/Support/WidgetQuery.php` | Turns a widget + filters into chart data, applying the user's data scope |
| `app/Services/Zenoti/` | API client, field mapper, sync + webhook handling |
| `app/Services/CallGear/` | CallGear client + source (disabled until configured) |
| `app/Services/Integrations/` | Common interface so both sources sync/webhook the same way |
| `app/Services/Inventory/StockOrderService.php` | Order pricing, status rules, stock movement, invoice numbering |
| `database/seeders/RolesAndPermissionsSeeder.php` | Permission list and default roles |
| `routes/web.php`, `routes/console.php` | Routes and the sync schedule |

## Local development

```bash
composer install
cp .env.example .env   # set DB_CONNECTION=sqlite and APP_ENV=local for a quick start
php artisan key:generate && php artisan migrate --seed && php artisan db:seed --class=DemoDataSeeder
php artisan serve
php artisan test       # feature tests: login, scoping, module visibility, dashboard builder, Zenoti sync/webhooks, inventory flow
```
