# Ledger Sample: double-entry accounting in Laravel

A small accounting backend built with **Laravel 13**, **MySQL** and **Filament 5**. It has a versioned REST API and an admin panel. I wrote it as a code sample to show how I handle financial data in Laravel: double-entry bookkeeping, invoicing, payments and bank reconciliation, with the rules enforced in the service layer and covered by tests.

This is a code sample, not a production system. It has no real users. The scope is kept small on purpose so the important parts are easy to review.

I built it with AI assistance (Claude Code), the same way I work day to day. I reviewed and tested every part, and I can explain any design decision in it.

## What it does

| Area | What's implemented |
|---|---|
| **General ledger** | Chart of accounts, journal entries with debit/credit lines, manual entries, reversals, trial balance |
| **Invoicing** | Draft → Issued → Partially paid / Paid, or Void. Per-line VAT. Gap-free invoice numbers |
| **Payments** | Partial and full payments, overpayment protection, idempotent recording (`Idempotency-Key`) |
| **Bank reconciliation** | CSV statement import, queued automatic matching against recorded payments |
| **REST API** | `/api/v1`, Sanctum tokens with read/write abilities, OpenAPI docs |
| **Admin panel** | Filament resources for all of the above, plus a trial balance page and a dashboard |

## Architecture

```
HTTP (API controllers)  ─┐
Filament (admin panel)  ─┼─►  Form Requests / DTOs  ─►  Services  ─►  Repository interfaces  ─►  Eloquent
Artisan commands, jobs  ─┘     (shape validation)       (business rules,      (data access, row locks,
                                                          transactions)         aggregates)
```

- **Controllers are thin.** They take a Form Request, call one service or repository method and return an API Resource. No business logic, no queries.
- **Form Requests validate the shape** of input (types, formats, limits). **Services enforce the business rules** (an entry must balance, only drafts can be edited, no overpayment). This way the API, the admin panel and the CLI can't disagree about the rules.
- **One write path.** Creating an invoice in Filament and creating it through the API both call `InvoiceService::createDraft()`. Manual journal entries from both go through `LedgerService::postManual()`.
- **Repositories** (`app/Repositories/Contracts`, bound in `RepositoryServiceProvider`) own all queries: filters, pagination, eager loading, `SELECT ... FOR UPDATE` and SQL aggregates. Filament's table and list pages still build their own Eloquent queries, because Filament is designed around query builders. All writes still go through the services.
- **DTOs** (`app/Data`) carry validated input into services, so services never see raw request arrays.
- **Domain errors** extend `DomainRuleException`, which carries a stable error code and an HTTP status. The API renders them as JSON, and Filament shows them as notifications.

## Design decisions

**Money is never a float.** Amounts are stored as integers in minor units (`BIGINT`, cents) and handled through an immutable `App\Support\Money` value object. Parsing goes from string to integer without passing through float. The API accepts and returns amounts as decimal strings (`"118.00"`), so JavaScript clients never round them. VAT is calculated in basis points with explicit half-up rounding.

**The ledger is the source of truth, and it's append-only.**
- `LedgerService` is the only class that writes journal entries. It rejects an entry unless it has at least two lines, each line is a debit or a credit (not both), every account is active, and **total debits equal total credits**.
- Each entry and its lines are written in one database transaction.
- Posted entries can't be updated or deleted (model events throw). Mistakes are fixed with a **reversing entry**. Entries created by an invoice are reversed by voiding the invoice, so the document and the ledger stay in sync.
- An account's code and type are locked once it has postings.

**Concurrency and retries.**
- Issuing, voiding and paying an invoice lock the invoice row, so two requests on the same invoice run one after the other.
- Invoice numbers come from a counter row that's locked inside the same transaction. If issuing fails, the number is rolled back too, so the numbering has no gaps.
- Payments require an **`Idempotency-Key`** header. A retry with the same key returns the original payment (`200`, `Idempotent-Replayed: true`) instead of charging twice. The same key with a different amount is a `409`. The admin panel generates a key when the payment form opens, so a double click records only one payment.

**Background jobs.** A statement import dispatches `ReconcileBankTransactions` (queued, `ShouldBeUnique`). It walks unreconciled rows with `chunkById()` and matches each one in its own transaction with row locks. Statement rows get a fingerprint, so importing the same file twice creates no duplicates.

**Guard rails in development.** `Model::shouldBeStrict()` is on outside production, so lazy loading (N+1) throws instead of slowing pages down silently. It found one N+1 in the admin panel while the API was being built, and it has been fixed. Polymorphic columns store aliases (`invoice`), not PHP class names.

## REST API

Base URL: `/api/v1`. Every response is JSON. Interactive docs are generated from the code with [Scramble](https://scramble.dedoc.co) at **`/docs/api`** (local environment only). The raw spec is at `/docs/api.json`.

### Authentication

```bash
curl -X POST http://127.0.0.1:8000/api/v1/auth/tokens \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.com","password":"password","device_name":"cli"}'
# {"token":"1|...","token_type":"Bearer","abilities":["ledger:read","ledger:write"],"expires_at":"..."}
```

Send the token as `Authorization: Bearer <token>`. Tokens expire after 30 days (`LEDGER_API_TOKEN_DAYS`). Ask for `"abilities": ["ledger:read"]` to get a read-only token. Its write requests get a `403`. The token endpoint allows 5 attempts per minute, and other endpoints allow 120 per minute.

### Endpoints

| Method | Path | Ability |
|---|---|---|
| `POST` | `/auth/tokens` | — |
| `DELETE` | `/auth/tokens/current` | any |
| `GET` | `/accounts`, `/accounts/{id}` | read |
| `POST`, `PATCH` | `/accounts`, `/accounts/{id}` | write |
| `GET` | `/customers`, `/customers/{id}` | read |
| `POST`, `PATCH` | `/customers`, `/customers/{id}` | write |
| `GET` | `/invoices` (filters: `status`, `customer_id`, `number`, `overdue`), `/invoices/{id}` | read |
| `POST`, `PUT`, `DELETE` | `/invoices`, `/invoices/{id}` (drafts only) | write |
| `POST` | `/invoices/{id}/issue`, `/invoices/{id}/void` | write |
| `GET`, `POST` | `/invoices/{id}/payments` | read / write |
| `GET` | `/journal-entries` (filters: `from`, `to`, `account_id`), `/journal-entries/{id}` | read |
| `POST` | `/journal-entries`, `/journal-entries/{id}/reversal` | write |
| `GET` | `/reports/trial-balance?as_of=YYYY-MM-DD` | read |
| `POST` | `/bank-statements` (multipart CSV) → `202` | write |
| `GET` | `/bank-transactions?reconciled=0\|1` | read |

Lists are paginated (`per_page` up to 100, default 25) with Laravel's `data` / `links` / `meta` envelope.

### Example: record a payment

```bash
curl -X POST http://127.0.0.1:8000/api/v1/invoices/1/payments \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: psp-tx-8f3a91' \
  -d '{"amount":"118.00","paid_on":"2026-10-18","method":"bank_transfer"}'
```

### Errors

| Status | When | Body |
|---|---|---|
| `401` | No or invalid token | `{"message": "Unauthenticated."}` |
| `403` | Token lacks the ability | `{"message": "Invalid ability provided."}` |
| `404` | Unknown resource | `{"message": "Resource not found.", "code": "not_found"}` |
| `409` | State conflict (issue twice, edit an issued invoice, idempotency conflict) | `{"message": "...", "code": "billing.invalid_transition"}` |
| `422` | Invalid input | `{"message": "...", "errors": {"field": ["..."]}}` |
| `422` | Business rule (unbalanced entry, overpayment) | `{"message": "...", "code": "ledger.unbalanced"}` |
| `429` | Rate limit | `{"message": "Too Many Attempts."}` |

The `code` values are stable, so clients can react to them without parsing the message. They're listed in `app/Exceptions/LedgerException.php` and `BillingException.php`.

## Postings

| Event | Debit | Credit |
|---|---|---|
| Invoice issued | 1100 Accounts receivable (total) | 4000 Sales revenue (net), 2100 VAT payable (VAT) |
| Payment received | 1000 Bank | 1100 Accounts receivable |
| Invoice voided | Reversal of the issue entry | |

## Project layout

```
app/
├── Data/                       DTOs: InvoiceData, ManualEntryLineData, NewJournalEntry, ...
├── Exceptions/                 DomainRuleException + LedgerException, BillingException
├── Http/
│   ├── Controllers/Api/V1/     Thin controllers
│   ├── Requests/Api/V1/        Form Requests (validation)
│   └── Resources/V1/           API Resources (response shape)
├── Repositories/
│   ├── Contracts/              Interfaces
│   └── Eloquent/               Implementations
├── Services/                   LedgerService, InvoiceService, PaymentService, BankReconciler, ...
├── Support/Money.php           Money value object
├── Jobs/ReconcileBankTransactions.php
├── Reports/TrialBalance.php
└── Filament/                   Admin panel
tests/
├── Unit/                       Money
└── Feature/                    Services, admin panel, Api/V1/*
```

## Running it

Requirements: PHP 8.3+, Composer, MySQL 8.

```bash
composer install
cp .env.example .env
php artisan key:generate
# create a MySQL database named ledger_sample (or edit DB_* in .env)
php artisan migrate --seed
php artisan serve
```

- Admin panel: `http://127.0.0.1:8000/admin`. Log in as `admin@example.com` / `password`.
- API docs: `http://127.0.0.1:8000/docs/api`

The seeder creates a chart of accounts, three customers and four invoices: one paid, one partially paid, one issued and one draft.

```bash
php artisan ledger:trial-balance                                   # fails if debits != credits
php artisan bank:import database/samples/bank-statement.csv
php artisan queue:work --stop-when-empty
```

## Quality checks

```bash
php artisan test                    # 85 tests, in-memory SQLite
DB_CONNECTION=mysql DB_DATABASE=ledger_sample_test php artisan test   # same suite on MySQL
vendor/bin/phpstan analyse          # Larastan, level 7
vendor/bin/pint --test              # code style
```

SQLite ignores `lockForUpdate()`, so the suite also runs on MySQL. GitHub Actions runs all four checks (`.github/workflows/tests.yml`).

The tests cover:
- money rounding and allocation
- ledger rules (balanced, one-sided, active accounts, immutability, reversals)
- idempotent posting and payments
- invoice totals, numbering and rollback of numbers
- status transitions and overpayment
- reconciliation matching and duplicate imports
- the Filament pages and actions
- every API endpoint: auth, abilities, rate limiting, validation, error codes, pagination, filters, and a check that the invoice list doesn't run more queries as rows grow

## Kept out of scope on purpose

Things a production system would need, left out to keep the sample focused:

- Multiple currencies and exchange rates (the ledger is single-currency, `AZN` by default)
- Credit notes, refunds, and posting bank fees from the statement
- Accounting periods and period closing
- Roles and permissions beyond token abilities (every user is an admin)
- A manual matching screen for unmatched bank lines
