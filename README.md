<p align="center">
    <a href="https://fleetbase.io">
        <img src="https://github.com/user-attachments/assets/197c404c-06b1-4fd4-8a26-624f1d481966" width="120" height="120" alt="Fleetbase Ledger" />
    </a>
</p>

<h1 align="center">Fleetbase Ledger</h1>

<p align="center">
    Accounting, invoicing, wallets, payments, and financial reporting for Fleetbase.
</p>

<p align="center">
    <a href="https://github.com/fleetbase/ledger/blob/main/LICENSE.md"><img src="https://img.shields.io/badge/license-AGPL--3.0--or--later-blue.svg" alt="License: AGPL-3.0-or-later" /></a>
    <a href="https://github.com/fleetbase/ledger/actions/workflows/server.yml"><img src="https://github.com/fleetbase/ledger/actions/workflows/server.yml/badge.svg" alt="PHP CI" /></a>
    <a href="https://github.com/fleetbase/ledger/actions/workflows/ember.yml"><img src="https://github.com/fleetbase/ledger/actions/workflows/ember.yml/badge.svg" alt="Ember CI" /></a>
    <a href="https://packagist.org/packages/fleetbase/ledger-api"><img src="https://img.shields.io/packagist/v/fleetbase/ledger-api.svg" alt="Packagist version" /></a>
    <a href="https://www.npmjs.com/package/@fleetbase/ledger-engine"><img src="https://img.shields.io/npm/v/@fleetbase/ledger-engine.svg" alt="npm version" /></a>
    <a href="https://www.fleetbase.io/docs/ledger"><img src="https://img.shields.io/badge/docs-ledger-111827.svg" alt="Ledger documentation" /></a>
</p>

<p align="center">
    <a href="https://www.fleetbase.io/images/screenshots/ledger/ledger-dashboard.webp">
        <img src="https://www.fleetbase.io/images/screenshots/ledger/ledger-dashboard.webp" alt="Fleetbase Ledger dashboard" />
    </a>
</p>

## Overview

Ledger is the finance and billing extension for Fleetbase. It adds a complete financial management layer to the Fleetbase console, including double-entry bookkeeping, customer invoicing, invoice templates, digital wallets, payment gateway processing, immutable transaction history, and standard financial reports.

Ledger ships as both a Laravel package and an Ember engine. The backend package provides the accounting models, services, routes, gateway drivers, events, observers, migrations, and console commands. The frontend engine provides the Ledger console experience for billing, payments, accounting, reports, and settings.

Ledger is included with standard Fleetbase installations. See the [Fleetbase Ledger documentation](https://www.fleetbase.io/docs/ledger) for the product guide, concepts, and setup walkthroughs.

## Contents

- [Features](#features)
- [Architecture](#architecture)
- [Requirements](#requirements)
- [Installation](#installation)
- [Development](#development)
- [API and Extension Points](#api-and-extension-points)
- [Documentation](#documentation)
- [Contributing](#contributing)
- [Security](#security)
- [License](#license)

## Features

### Accounting

- Chart of accounts for asset, liability, equity, revenue, and expense accounts.
- Double-entry journal entries with debit and credit accounts.
- Cached account balances with recalculation support.
- General ledger views per account and across the company.
- System-created and manual journal entries for operational accounting workflows.

### Billing and Invoicing

- Customer invoices with line items, tax, subtotal, total, balance, due date, notes, and terms.
- Invoice lifecycle support for draft, sent, viewed, paid, overdue, cancelled, refunded, and void states.
- Invoice templates with company branding and registered template context variables.
- Invoice previews, rendered PDFs, invoice emails, and public customer invoice pages.
- Manual payment recording and invoice transaction history.
- Fleet-Ops purchase-rate integration for automatically generating draft invoices from orders.

### Wallets and Transactions

- Digital wallets for companies, users, customers, drivers, and other Fleetbase subjects.
- Wallet operations for top-ups, credits, transfers, payouts, freezes, unfreezes, and recalculation.
- Atomic balance changes through `WalletService`.
- Immutable transaction records for wallet activity, payment activity, and operational money movement.
- Direction-aware transaction history for credits, debits, deposits, payouts, transfers, refunds, and reversals.

### Payment Gateways

- Built-in gateway drivers for Stripe, QPay, and Cash/manual payments.
- Gateway configuration with encrypted credentials at rest.
- Sandbox and live environments.
- Purchases, refunds, setup intents, tokenization where supported, and gateway transaction history.
- Public gateway webhook endpoint with driver-level signature verification.
- Idempotent gateway processing through `GatewayTransaction` records.

### Reports and Dashboard

- Financial dashboard with KPIs, revenue trends, cash flow summaries, invoice status, AR aging, wallet balances, and activity.
- Standard financial reports for balance sheet, income statement, cash flow statement, trial balance, AR aging, wallet summary, and general ledger.
- Report services built around double-entry accounting data and Fleetbase transaction records.

### Fleetbase Integrations

- Fleet-Ops integration for purchase-rate invoice creation and order accounting.
- Storefront integration for direct storefront sale journal entries.
- Company and user observers that provision default accounts and wallets automatically.
- Invoice, payment, and accounting settings inside the Fleetbase console.

## Architecture

Ledger is split into two distributable packages:

| Package | Runtime | Description |
| --- | --- | --- |
| [`fleetbase/ledger-api`](https://packagist.org/packages/fleetbase/ledger-api) | Laravel / PHP | Backend models, routes, services, migrations, gateway drivers, observers, events, resources, reports, and console commands. |
| [`@fleetbase/ledger-engine`](https://www.npmjs.com/package/@fleetbase/ledger-engine) | Ember | Fleetbase console engine for the Ledger dashboard, billing, payments, accounting, reports, and settings screens. |

Backend routes are mounted under the configured Ledger API prefix, which defaults to `ledger`.

| Route group | Authentication | Purpose |
| --- | --- | --- |
| `POST /ledger/webhooks/{driver}` | Public, driver verified | Payment gateway webhook callbacks. |
| `/ledger/public/invoices/{public_id}` | Public | Customer invoice view, gateway list, and payment flow. |
| `/ledger/v1/wallet/*` | API key | Customer and driver wallet API endpoints. |
| `/ledger/int/v1/*` | Fleetbase session | Console APIs for accounts, invoices, journals, wallets, transactions, gateways, settings, and reports. |

The Ember engine mounts at the Fleetbase extension route `ledger` and exposes console sections for billing, payments, accounting, reports, and settings.

## Requirements

- PHP `^8.0`
- Composer
- Fleetbase Core API
- Fleetbase FleetOps API
- Node.js `>=18`
- pnpm
- Ember CLI compatible with the workspace

## Installation

Ledger comes pre-installed with Fleetbase. In a standard Fleetbase instance, open the console sidebar and navigate to Ledger to begin. Default accounts and wallets are provisioned automatically for new companies and users.

For package-level installation:

```bash
composer require fleetbase/ledger-api
```

```bash
pnpm install @fleetbase/ledger-engine
```

If you are adding Ledger to an existing Fleetbase installation, run migrations through your normal Fleetbase deployment flow, then provision defaults for existing records:

```bash
php artisan ledger:provision
```

## Development

### Fleetbase workspace linking

When working on Ledger inside a full Fleetbase checkout, use Fleetbase's package linker from the repository root instead of hand-editing `console/package.json`, `api/composer.json`, or `console/pnpm-workspace.yaml`.

Install the linker once from the Fleetbase repository root:

```bash
npm link
```

Enable Ledger as a local development package:

```bash
flb-package-linker enable ledger
flb-package-linker install ledger
```

Use `--install` to let the linker run the required package-manager commands immediately:

```bash
flb-package-linker enable ledger --install
```

Check link state with:

```bash
flb-package-linker status
flb-package-linker doctor
```

See the [Fleetbase development setup guide](https://www.fleetbase.io/docs/platform/quickstart/development-setup) for Docker mounts, local Ember dev server setup, package-linker details, and unlink/reset commands. Fleetbase runs Laravel Octane, so reload the API worker after PHP changes:

```bash
docker compose exec application php artisan octane:reload
```

### Package-level development

Install dependencies:

```bash
composer install
pnpm install
```

Run the Ember engine locally:

```bash
pnpm start
```

Frontend checks:

```bash
pnpm lint
pnpm test
pnpm build
```

Backend checks:

```bash
composer test:lint
composer test:types
composer test:unit
composer test
```

Ledger Artisan commands:

```bash
php artisan ledger:provision
php artisan ledger:backfill-direction
php artisan ledger:update-overdue-invoices
```

`ledger:provision` is idempotent and can target all companies, one company, accounts only, or wallets only. `ledger:backfill-direction` fills missing transaction directions on older transaction rows. `ledger:update-overdue-invoices` marks sent or viewed invoices as overdue when their due date has passed.

## API and Extension Points

Ledger exposes backend services for accounting, wallets, invoices, and payments:

- `LedgerService` creates double-entry journal entries and powers financial reports.
- `WalletService` manages wallet provisioning and balance-changing operations.
- `InvoiceService` creates and manages invoices, including order-based invoice creation.
- `PaymentService` coordinates gateway charges, refunds, setup intents, events, and gateway transaction persistence.
- `PaymentGatewayManager` resolves and initializes configured payment gateway drivers.

Custom payment gateways can extend `AbstractGatewayDriver` and implement the `GatewayDriverInterface` contract. Gateway drivers provide a code, name, capability list, configuration schema, purchase/refund behavior, and optional webhook or tokenization support.

Ledger also registers invoice template context variables with Fleetbase's template rendering system so invoice templates can reference invoice, transaction, account, and wallet data during rendering.

## Documentation

- [Ledger documentation](https://www.fleetbase.io/docs/ledger)
- [Core concepts](https://www.fleetbase.io/docs/ledger/getting-started/core-concepts)
- [Payment gateways](https://www.fleetbase.io/docs/ledger/payments/gateways)
- [Adding a payment gateway driver](https://www.fleetbase.io/docs/extension-development/recipes/adding-a-payment-gateway-driver)
- [Fleetbase development setup](https://www.fleetbase.io/docs/platform/quickstart/development-setup)

## Contributing

Contributions are welcome. Please read the [contributing guide](CONTRIBUTING.md) before opening a pull request.

For local changes, keep frontend and backend checks focused on the area you touched and include relevant test output in your pull request.

## Security

Please do not report security issues in public GitHub issues. Contact Fleetbase at [hello@fleetbase.io](mailto:hello@fleetbase.io) with details so the team can coordinate a responsible fix.

## License

Fleetbase Ledger is open-source software licensed under the [AGPL-3.0-or-later](LICENSE.md).
