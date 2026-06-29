# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

**Liberu Billing** — an open-source billing, invoicing, and subscription-management platform aimed at web-hosting providers, SaaS, and agencies. Stack: PHP 8.5, Laravel 13, Filament 5 (admin UI), Livewire 4, Jetstream (auth + teams), built on the `liberusoftware/boilerplate-laravel` skeleton.

## Common commands

```bash
# First-time setup (interactive installer: env, deps, migrate, seed)
bash setup.sh

# Manual setup
composer install
php artisan key:generate
php artisan migrate --seed
npm install && npm run build

# Run
php artisan serve              # http://127.0.0.1:8000
npm run dev                    # Vite dev server (HMR for assets)
php artisan horizon            # queue workers (Redis-backed)
php artisan octane:start       # RoadRunner/Octane app server (prod-style)

# Tests (PHPUnit 13; uses sqlite :memory: via phpunit.xml — no DB setup needed)
php artisan test
php artisan test --filter=InvoiceApiTest        # single test class
php artisan test --filter=test_method_name      # single method
php artisan test tests/Feature/Api              # a directory/suite

# Code style & static rewriting
./vendor/bin/pint              # format (Laravel preset, no pint.json)
./vendor/bin/pint --test       # check only, no writes
./vendor/bin/rector process    # apply automated upgrades/cleanups
./vendor/bin/rector --dry-run  # preview Rector changes
```

There is no `composer test`/`composer lint` script — invoke the binaries directly as above. CI (`.github/workflows/tests.yml`) runs `php artisan test --coverage-clover`.

## Architecture

### Three Filament panels (multi-panel)
Registered in `bootstrap/providers.php` via separate panel providers in `app/Providers/Filament/`. Each panel auto-discovers its own Resources/Pages/Widgets from a dedicated subtree — **put new admin UI under the matching panel folder, not a shared one**:

| Panel | Path | Provider | Resources discovered in |
|---|---|---|---|
| Admin (default) | `/admin` | `AdminPanelProvider` | `app/Filament/Admin/Resources` |
| App | `/app` | `AppPanelProvider` | `app/Filament/App/Resources` |
| Client | `/client` | `ClientPanelProvider` | `app/Filament/Client/Resources` |

The **App** panel (`/app`) enables **Jetstream team tenancy** (`->tenant(Team::class, ownershipRelationship: 'team')`), so its models scope to a team — only register a resource there if its model has a `team` relationship / `team_id` column. The **Admin** panel is a global super-admin panel and is intentionally **not** tenant-scoped (its resources — roles, users, site settings, menus, audit logs — are global system entities). Don't add `->tenant()` to the Admin panel: it applies a `where team_id = ?` global scope to every resource model and 500s any whose model isn't team-scoped. Authorization is RBAC via `spatie/laravel-permission` + `bezhansalleh/filament-shield` (roles/permissions managed in the Admin panel under "Administration").

### Module system (the distinctive piece)
A plugin architecture layered on top of Laravel. Core lives in `app/Modules/`: `ModuleServiceProvider` (auto-discovery + registration of routes/views/migrations/assets), `ModuleManager` (lifecycle: enable/disable/install/uninstall, persisted to the `modules` DB table via the `Module` model), `BaseModule` (abstract), and `Contracts/ModuleInterface`. Config: `config/modules.php`. Reference implementation: `app/Modules/BlogModule`. Full docs: `docs/MODULAR_ARCHITECTURE.md`.

Two module locations are scanned (`ModuleManager::discoverLocalModules`):
- `app/Modules/` → namespace `App\Modules` (legacy/flat layout)
- `app-modules/` → namespace `Modules` (PSR-4, `src/` subdirectory layout)

**Dual class-name convention** — for a directory `Foo`, the manager tries both `…\Foo\FooModule` and `…\Foo\Foo`. So `make:module Foo` (class `FooModule`) and a hand-written `FooModule/FooModule` both resolve. When adding a module class, follow one of these two exact patterns or it won't be discovered.

Module CLI:
```bash
php artisan module list|enable|disable|install|uninstall|info {Name}
php artisan make:module {Name}     # scaffolds the full module structure
```

### Service layer
Business logic lives in `app/Services/*Service.php` (e.g. `BillingService`, `PaymentGatewayService`, `WebhookService`, `TaxService`, `ServiceAutomationService`), not in controllers or Filament resources. External integrations are split into `app/Services/ControlPanels/` (cPanel/Plesk provisioning) and `app/Services/Registrars/` (domain registrars). When adding billing/provisioning logic, add or extend a service class and call it from the controller/resource/command.

### Domain models
~55 Eloquent models in `app/Models/`. Core billing flow: `Customer`/`Client` → `Invoice` + `Invoice_Item` → `Payment` (with `PaymentGateway`, `PaymentHistory`, `Credit`, `Discount`, `TaxRate`); recurring side: `Subscription`/`SubscriptionPlan`/`RecurringBillingConfiguration`/`UsageRecord`; hosting: `HostingAccount`/`HostingServer`; support: `Ticket`/`TicketResponse`/`CannedResponse`/`KnowledgeBaseArticle`. Note the non-standard names `Invoice_Item` and `Products_Service`.

### Webhooks
Outbound webhook system (`WebhookEndpoint`, `WebhookEvent` models, `WebhookService`): 19+ event types, HMAC-SHA256 signatures, retry logic. Delivery is processed by `php artisan webhooks:process` (`ProcessWebhooks` command). Note: `WebhookEndpoint::$events` is a **DB column** (subscribed event names), not Laravel's `Model::$dispatchesEvents` — Rector is configured to skip that file for this reason.

### Routing
- `routes/web.php` — session-auth web (tickets, client service management). Filament panels register their own routes.
- `routes/api.php` — Sanctum token API under `App\Http\Controllers\Api\*`; token issued at `POST /api/auth/token` (throttled), health at `GET /api/health`.
- `routes/console.php` — the scheduler. Daily: `invoices:send-reminders`, `invoices:process-reminders`, `audit:prune`. Also `services:suspend-overdue` and report generation. Custom commands live in `app/Console/Commands/`.

## Conventions

- `declare(strict_types=1);` at the top of PHP files; Rector enforces PHP 8.5 + Laravel 13 type-declaration, dead-code, and code-quality rule sets (`rector.php`).
- Run `./vendor/bin/pint` before committing (the README's contribution flow requires it).
- Tests: `tests/Feature` and `tests/Unit`, extending `Tests\TestCase`. Feature tests cover auth/teams (Jetstream), the API (`tests/Feature/Api`), and the module system (`ModuleSystemTest`).

## Further docs

- `docs/MODULAR_ARCHITECTURE.md` — module system internals and how to write extensions
- `docs/WHMCS_FEATURES.md` — webhooks, knowledge base, canned responses, bulk operations
- `docs/CONTROL_PANEL_PROVISIONING.md` — cPanel/Plesk integration
- `docs/api.yaml` — OpenAPI spec for the REST API
