# Ledger Sample: double-entry accounting in Laravel

A small accounting backend built with **Laravel 13**, **MySQL 8** and **Filament 5**. It has a versioned REST API and an admin panel. I wrote it as a code sample to show how I handle financial data in Laravel: double-entry bookkeeping, invoicing, payments and bank reconciliation, with the rules enforced in the service layer, backed by database constraints, and covered by tests, including real multi-process concurrency tests on MySQL.

It is a code sample, not a production system. The scope is kept small on purpose so the important parts are easy to review. [Known limitations](#known-limitations-and-production-considerations) lists what a production deployment would still need.

I built it with AI assistance (Claude Code), the same way I work day to day. I reviewed and tested every part, and I can explain any design decision in it.

## Contents

1. [What it does](#what-it-does)
2. [Requirements and installation](#requirements-and-installation)
3. [Environment](#environment)
4. [Database and migrations](#database-and-migrations)
5. [Queue](#queue)
6. [Tests and quality checks](#tests-and-quality-checks)
7. [Accounting architecture](#accounting-architecture)
8. [Double-entry example](#double-entry-example)
9. [Invoice and payment lifecycle](#invoice-and-payment-lifecycle)
10. [Idempotency and transaction safety](#idempotency-and-transaction-safety)
11. [Authorization](#authorization)
12. [REST API](#rest-api)
13. [Admin panel](#admin-panel)
14. [CI](#ci)
15. [Known limitations and production considerations](#known-limitations-and-production-considerations)

## What it does

| Area | What's implemented |
|---|---|
| **General ledger** | Chart of accounts, journal entries with debit/credit lines, manual entries, reversals, trial balance |
| **Invoicing** | Draft → Issued → Partially paid / Paid, or Void. Per-line VAT. Gap-free invoice numbers |
| **Payments** | Partial and full payments, overpayment protection, idempotent recording (`Idempotency-Key`) |
| **Bank reconciliation** | CSV statement import, queued automatic matching against recorded payments |
| **Access control** | Roles (admin, accountant, viewer) enforced by policies in the API and the admin panel |
| **REST API** | `/api/v1`, Sanctum tokens with read/write abilities, OpenAPI docs |
| **Admin panel** | Filament resources for all of the above, plus a trial balance page and a dashboard |

## Requirements and installation

PHP 8.3+, Composer, MySQL 8.0.16+ (CHECK constraints) or 8.4. SQLite is enough to run the test suite.

```bash
git clone <repository-url> laravel-ledger-sample && cd laravel-ledger-sample
composer install
cp .env.example .env
php artisan key:generate
# create an empty MySQL database named ledger_sample, or change DB_* in .env
php artisan migrate --seed
php artisan serve
```

- Admin panel: `http://127.0.0.1:8000/admin`
- API docs: `http://127.0.0.1:8000/docs/api` (local environment only)

The seeder creates a chart of accounts, three customers and four invoices (paid, partially paid, issued and draft), and one demo user per role. Every demo user's password is `password`. They are for local use only.

| Email | Role |
|---|---|
| `admin@example.com` | admin |
| `accountant@example.com` | accountant |
| `viewer@example.com` | viewer |

```bash
php artisan ledger:trial-balance                          # exits 1 if debits != credits
php artisan bank:import database/samples/bank-statement.csv
php artisan queue:work --stop-when-empty                  # runs the reconciliation job
```

## Environment

All configuration is in `.env` (see `.env.example`, which contains no secrets). The application-specific settings:

| Variable | Default | Meaning |
|---|---|---|
| `LEDGER_CURRENCY` | `AZN` | ISO 4217 code of the single ledger currency. Decides the number of decimals (2 for AZN, 0 for JPY, 3 for KWD). **Don't change it once amounts are stored:** they would be reinterpreted. |
| `LEDGER_API_TOKEN_DAYS` | `30` | API token lifetime |
| `QUEUE_CONNECTION` | `database` | Queue for the reconciliation job |

Account codes the billing workflow posts to, the control accounts, the invoice prefix and the payment terms are in `config/ledger.php`.

For production, set `APP_ENV=production` and `APP_DEBUG=false`. Debug mode shows stack traces with SQL bindings. Set the secrets (`APP_KEY`, `DB_PASSWORD`) through the environment, not in a committed file.

## Database and migrations

```bash
php artisan migrate            # apply
php artisan migrate:rollback   # every migration has a working down() (checked in CI)
```

| Table | Notes |
|---|---|
| `accounts` | `code` unique. Deactivated, never deleted (`journal_lines` restrict delete) |
| `journal_entries` | `idempotency_key` unique, `reversal_of_id` unique (an entry is reversed at most once), `source` morph (`invoice`) |
| `journal_lines` | Amounts are `BIGINT UNSIGNED` minor units. The FK to the entry restricts delete |
| `invoices`, `invoice_lines` | `number` unique. Totals stored in minor units |
| `payments` | `idempotency_key` unique |
| `bank_transactions` | `fingerprint` unique (duplicate import protection), `payment_id` unique (a payment matches one bank line) |
| `sequences` | Gap-free counters (invoice numbers), created by a migration |
| `users` | `role`: `admin`, `accountant` or `viewer` |

On MySQL, these CHECK constraints back up the service rules, so a raw SQL statement or a bug can't break them either:

- a journal line has exactly one positive side;
- `invoices.total = subtotal + tax_total`;
- `amount_paid <= total`;
- `payments.amount > 0`.

SQLite can't add CHECK constraints to an existing table, so the SQLite test database relies on the service checks alone. The constraint tests run on MySQL.

Upgrading an existing database: `2026_10_19_000001` adds `users.role` and sets **existing** users to `admin`, because before it every user had full access. New users default to `viewer`.

## Queue

`BankStatementImporter` inserts the statement rows, then dispatches `ReconcileBankTransactions`. Run a worker:

```bash
php artisan queue:work
php artisan queue:failed          # jobs that failed after the last attempt
php artisan queue:retry all
```

The job:

- **Is safe to retry and to run twice.** Each bank line is matched in its own transaction with row locks. A line that is already reconciled is skipped. `bank_transactions.payment_id` is unique, so two runs can't match one payment twice; a run that loses that race leaves the line open.
- **Is unique until processing.** While a run waits in the queue, more imports don't queue more runs. When a run starts, the lock is released, so an import during the run queues the next one. It used to be `ShouldBeUnique`, which kept the lock during the run, so an import at that moment dispatched nothing and its rows waited for the next import. There's a regression test for this.
- **Limits:** 3 tries, backoff 10 s / 60 s, 120 s timeout. After the last failure it's stored in `failed_jobs` and logged by `failed()`. That log has the exception class and message only, and for SQL errors only the SQLSTATE, because a `QueryException` message contains bank references and amounts.

The job has no external side effects (no API calls or emails), so it needs no outbox. If a webhook or notification were added, it should go through an outbox table written in the same transaction.

## Tests and quality checks

```bash
php artisan test                                                     # in-memory SQLite
DB_CONNECTION=mysql DB_DATABASE=ledger_sample_test php artisan test  # MySQL: all tests, including concurrency
vendor/bin/phpstan analyse                                           # Larastan, level 7
vendor/bin/pint --test                                               # code style
```

Last local run (PHP 8.3.18, MySQL 8.4.3): SQLite 168 passed and 7 skipped (the MySQL-only tests). MySQL 175 of 175 passed. Larastan reports 0 errors and Pint passes.

| Area | What's tested |
|---|---|
| Money | Parsing without floats, per-currency decimals (0/2/3), invalid currencies, rounding, allocation, **overflow detection** in every operation, largest representable amount |
| Ledger | Balanced / unbalanced, zero/negative/two-sided lines, empty and one-line entries, inactive and unknown accounts, control accounts, immutability, reversal once, no reversal of a reversal |
| Idempotency | Replay returns the original. A changed amount, date, method, reference, description or memo is a conflict. Line order doesn't matter |
| Invoices | Totals and per-line VAT rounding, numbering, rollback of the number on failure, every state transition (full matrix), due date rules, overflow → 422 |
| Payments | Partial / full, exactly the outstanding amount vs one cent more, before the issue date, in the future, paid invoice, rollback when the ledger posting fails, retry after the failure |
| Reports | Trial balance totals, `as_of` boundaries (the day included, the next excluded), **receivables in the ledger = open invoices** across every invoice state |
| Reconciliation | Matching, unmatched and outgoing lines, duplicate files and lines, malformed rows (including rolled-over dates and too-long references), job re-runs, the uniqueness regression, failure logging |
| Authorization | Every write route for every role, token ability limits per role, role downgrade after token issue, mass assignment of the role, admin panel pages and actions (also called directly through Livewire) |
| API | Auth, rate limiting, validation, consistent error codes, pagination, filters, N+1 check on the invoice list |
| MySQL only | CHECK constraints and FK restrict. **Concurrency:** 6 processes paying one invoice at once (no overpayment), 6 retries with one key (one payment), 6 manual entries with one key (one entry), 6 concurrent issues (numbers 1-6, no gaps or duplicates) |

The concurrency tests (`tests/Feature/MySql/ConcurrencyTest.php`) start separate PHP processes (`tests/Concurrency/worker.php`) with their own database connections. The processes wait for a shared start time, so they really do hit the database at once. They found a deadlock in invoice numbering, which is now fixed (see [below](#idempotency-and-transaction-safety)).

## Accounting architecture

```
HTTP (API controllers)  ─┐
Filament (admin panel)  ─┼─►  Policies  ─►  Form Requests / DTOs  ─►  Services  ─►  Repository interfaces  ─►  Eloquent
Artisan commands, jobs  ─┘   (who may)     (shape validation)       (business rules,      (queries, row locks,
                                                                     transactions)         aggregates)
```

| Class | Responsibility |
|---|---|
| `Support\Money`, `Support\Currency` | Immutable amount in minor units. Currency-aware decimals. Overflow-checked arithmetic |
| `Services\LedgerService` | The only writer of journal entries. Validates the whole entry, writes it in one transaction, handles idempotency and reversals |
| `Services\InvoiceService` | Draft create/update/delete, totals, issue (number + posting), void (reversal) |
| `Services\PaymentService` | Records payments: invoice row lock, idempotency, overpayment and date checks, posting, status |
| `Services\SequenceService` | Gap-free numbers from a locked counter row |
| `Services\BankStatementImporter`, `BankReconciler`, `Jobs\ReconcileBankTransactions` | Statement import and matching |
| `Enums\InvoiceStatus` | The invoice state machine (`canTransitionTo()`) |
| `Enums\UserRole`, `Policies\*` | Who may do what |
| `Exceptions\DomainRuleException` | Base class for business-rule errors with a stable code and HTTP status (`LedgerException`, `BillingException`, `MoneyException`) |

Design rules:

- **Controllers are thin.** They take a Form Request, call one service or repository method and return an API Resource.
- **Form Requests validate the shape** of input. **Services enforce the business rules.** The API, the admin panel and the CLI use the same services, so they can't disagree. For example, the Filament account edit page goes through `AccountService` like the API does.
- **Money is never a float.** Amounts are integers in minor units (`BIGINT`). Input and output are decimal strings (`"118.00"`), so JSON clients never round them. PHP silently turns an overflowing integer into a float, so `Money` checks every result and throws `money.overflow` (422) instead. Before this change, `quantity 1000000 × unit_price 9999999999999.99` passed validation and crashed inside `multiply()`.
- **The ledger is append-only.** Posted entries can't be updated or deleted: model events throw, the policy says no, and the database restricts the delete. Corrections are linked reversing entries.
- **Receivables is a control account.** Manual entries can't post to it (`ledger.control_account`). Only invoices and payments can, so the receivables balance always equals the sum of open invoices. A test checks that across every invoice state.

## Double-entry example

A customer pays **200.00 AZN** against invoice `INV-000007`, which was issued earlier for 236.00 (200.00 + 18% VAT).

When the invoice was issued, `InvoiceService::issue()` posted:

| Account | Debit | Credit |
|---|---|---|
| 1100 Accounts receivable | 236.00 | |
| 4000 Sales revenue | | 200.00 |
| 2100 VAT payable | | 36.00 |

The payment, `PaymentService::record()`, posts:

| Account | Debit | Credit |
|---|---|---|
| 1000 Bank | 200.00 | |
| 1100 Accounts receivable | | 200.00 |

**Why it balances:** both sides are 200.00. Money arrived in the bank (an asset grows: debit), and the customer owes 200.00 less (another asset shrinks: credit). `LedgerService` refuses any entry where the totals differ, and the trial balance proves it for the whole ledger.

**How it relates to the invoice:** in the same transaction, `invoices.amount_paid` goes from 0 to 200.00 and the status becomes *Partially paid*. Outstanding is now 36.00, which is exactly the balance left on 1100 for this invoice. The journal entry's `source` is the invoice, and the payment row stores the entry id.

**Retries:** the request carries `Idempotency-Key: psp-tx-8f3a91`. If the network drops after the commit and the client retries with the same key and the same payload, it gets the original payment back (`200`, `Idempotent-Replayed: true`) and nothing is posted twice. The same key with any other amount, date, method or reference is a `409`.

## Invoice and payment lifecycle

```
Draft ──issue──► Issued ──pay──► Partially paid ──pay──► Paid
                   │  └──────────pay (full amount)──────►┘
                   └──void──► Void
```

| Transition | Rules | Ledger |
|---|---|---|
| create / edit / delete | Drafts only. Totals and VAT are calculated by the server | none |
| issue | Draft only. Total > 0. Due date not before today (default: 14 days). Takes the next number | Dr Receivable / Cr Sales, Cr VAT |
| pay | Issued or Partially paid. Amount > 0 and ≤ outstanding. Dated no earlier than the issue date and not in the future | Dr Bank / Cr Receivable |
| void | Issued without payments only | Reversal of the issue entry |

"Overdue" is not a stored state. It means Issued or Partially paid with a due date in the past (`?overdue=1`, a filter in the admin panel). Void is the cancelled state. There is no refund or credit-note workflow, so an invoice with payments can't be voided.

## Idempotency and transaction safety

| Operation | Protection |
|---|---|
| Record payment | `Idempotency-Key` header (required), unique `payments.idempotency_key`. Invoice row lock before the key check, so a duplicate waits for the first request and then finds its payment. Full-payload comparison. |
| Manual journal entry | Optional `Idempotency-Key`, unique `journal_entries.idempotency_key`. There's no row to lock, so the unique index decides: the losing request catches the violation, re-reads the winner with a locking read (a plain read inside a REPEATABLE READ transaction may still see the old snapshot) and compares the payload. |
| Issue, void, reverse | State machine under a row lock. A second call is a `409` and posts nothing. Reversals also use the key `reversal:{id}` and a unique `reversal_of_id`. |
| Invoice number | Counter row `SELECT ... FOR UPDATE` in the issuing transaction. A rollback also rolls back the number, so there are no gaps. |
| Statement import | Row fingerprints with a unique index. `insertOrIgnore` skips rows already imported. |

Payload comparison is field by field (date, description, amounts, accounts, memo, method, reference) and line order doesn't matter, so there's no JSON canonicalization that could depend on key order. Keys are unique across the organization: this is a single-organization system (see [limitations](#known-limitations-and-production-considerations)).

**Locking.** Each workflow locks one invoice row and then the rows it creates, always in the same order, and makes no network calls while holding locks. The concurrency tests found one real deadlock: `SequenceService` ran `INSERT IGNORE` on every call. On an existing row, InnoDB takes a shared lock on the duplicate key, and two concurrent issues each held that lock while waiting to upgrade it for `FOR UPDATE`. MySQL killed one of them (error 1213). Now the counter is created by a migration and only locked on the hot path. On top of that, the top-level financial transactions retry up to 3 times if MySQL picks them as a deadlock victim (`LedgerService::DEADLOCK_ATTEMPTS`). Everything inside rolls back, so a retry is safe. The concurrency tests also pass with the retry disabled.

## Authorization

This is a single-organization system: every authenticated user can read all of its financial data. Roles decide what a user can change.

| | Viewer | Accountant | Admin |
|---|---|---|---|
| Read everything, reports | ✓ | ✓ | ✓ |
| Customers, invoices, payments, void | | ✓ | ✓ |
| Manual journal entries, reversals | | ✓ | ✓ |
| Bank statement import | | ✓ | ✓ |
| Chart of accounts (create, edit) | | | ✓ |
| Edit or delete posted entries | | | |

- Policies (`app/Policies`) are checked on every API write route (`->can()` in `routes/api.php`) and by Filament for pages and actions. A hidden admin-panel button is not the protection: the action is refused server-side too, and a test calls it directly through Livewire to prove it.
- API tokens never carry more than the role allows: a viewer's token is `ledger:read` only, and requesting `ledger:write` is a 422. The role is also checked on every request, so a role downgrade applies to tokens issued before it.
- `role` is not mass assignable.

## REST API

Base URL: `/api/v1`. Every response is JSON. The OpenAPI docs are generated from the code with [Scramble](https://scramble.dedoc.co) at `/docs/api` (local environment only). The raw spec is at `/docs/api.json`.

### Authentication

```bash
curl -X POST http://127.0.0.1:8000/api/v1/auth/tokens \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"email":"accountant@example.com","password":"password","device_name":"cli"}'
# {"token":"1|...","token_type":"Bearer","abilities":["ledger:read","ledger:write"],"expires_at":"..."}
```

Send the token as `Authorization: Bearer <token>`. Tokens expire after 30 days and are stored hashed by Sanctum. Ask for `"abilities": ["ledger:read"]` to get a read-only token. Rate limits: 5 token requests per minute per email and IP, and 120 requests per minute per user elsewhere.

### Endpoints

| Method | Path | Token ability | Role |
|---|---|---|---|
| `POST` | `/auth/tokens` | — | — |
| `DELETE` | `/auth/tokens/current` | any | any |
| `GET` | `/accounts`, `/accounts/{id}` | read | any |
| `POST`, `PATCH` | `/accounts`, `/accounts/{id}` | write | admin |
| `GET` | `/customers`, `/customers/{id}` | read | any |
| `POST`, `PATCH` | `/customers`, `/customers/{id}` | write | accountant, admin |
| `GET` | `/invoices` (filters: `status`, `customer_id`, `number`, `overdue`), `/invoices/{id}` | read | any |
| `POST`, `PUT`, `DELETE` | `/invoices`, `/invoices/{id}` (drafts only) | write | accountant, admin |
| `POST` | `/invoices/{id}/issue`, `/invoices/{id}/void` | write | accountant, admin |
| `GET`, `POST` | `/invoices/{id}/payments` | read / write | any / accountant, admin |
| `GET` | `/journal-entries` (filters: `from`, `to`, `account_id`), `/journal-entries/{id}` | read | any |
| `POST` | `/journal-entries`, `/journal-entries/{id}/reversal` | write | accountant, admin |
| `GET` | `/reports/trial-balance?as_of=YYYY-MM-DD` | read | any |
| `POST` | `/bank-statements` (multipart CSV, max 2 MB) → `202` | write | accountant, admin |
| `GET` | `/bank-transactions?reconciled=0\|1` | read | any |

Lists are paginated (`per_page` up to 100, default 25) with Laravel's `data` / `links` / `meta` envelope. Money fields are objects: `{"amount": "118.00", "currency": "AZN"}`.

### Example: record a payment

```bash
curl -X POST http://127.0.0.1:8000/api/v1/invoices/1/payments \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: psp-tx-8f3a91' \
  -d '{"amount":"118.00","paid_on":"2026-10-18","method":"bank_transfer"}'
```

`201` the first time. A retry with the same key and payload gets `200` with `Idempotent-Replayed: true`.

### Errors

Every error body has a `message` and a stable `code`.

| Status | When | `code` |
|---|---|---|
| `401` | No, invalid or expired token | `unauthenticated` |
| `403` | Token lacks the ability, or the role doesn't allow the action | `forbidden` |
| `404` | Unknown resource (the model class is not named) | `not_found` |
| `409` | State conflict (issue twice, edit an issued invoice, idempotency conflict) | e.g. `billing.invalid_transition`, `billing.idempotency_conflict` |
| `422` | Invalid input (adds `errors: {field: [...]}`) | `validation_failed` |
| `422` | Business rule | e.g. `ledger.unbalanced`, `ledger.control_account`, `billing.overpayment`, `billing.payment_before_issue`, `money.overflow` |
| `429` | Rate limit (with `Retry-After`) | `too_many_requests` |

The business-rule codes are listed in `app/Exceptions/LedgerException.php`, `BillingException.php` and `MoneyException.php`.

## Admin panel

`/admin`, with the same users as the API. Every role can log in; what a user sees and can do follows the role.

- Invoices: create and edit drafts (the totals are calculated by `InvoiceService`), plus Issue, Record payment and Void actions. Each action calls the same service as the API, and business-rule errors are shown as notifications.
- Journal entries: post manual entries and reverse them. Entries have no edit or delete.
- Chart of accounts: code and type are locked once the account has postings, which `AccountService` enforces, not only the form.
- Bank reconciliation list with an unreconciled filter, the trial balance page (with an *as of* date), and a dashboard that reads balances from the ledger.

`Model::shouldBeStrict()` is on outside production, so lazy loading (N+1) and silently discarded attributes throw instead of failing quietly.

## CI

`.github/workflows/tests.yml` runs on every push and pull request:

1. `composer validate`, then `composer install` from the lock file
2. Pint (`--test`)
3. Larastan level 7
4. PHPUnit on SQLite
5. On a clean MySQL 8.4 service: `migrate:fresh --seed`, `ledger:trial-balance` (fails the build if the seeded ledger doesn't balance), and `migrate:rollback`
6. PHPUnit on MySQL, which also runs the CHECK-constraint and concurrency tests

No credentials are needed: the MySQL service uses an empty root password inside the CI container.

## Known limitations and production considerations

Not implemented, on purpose:

- **One organization.** There's no tenant isolation. For multi-tenant use: add an `organization_id` to every financial table and to the users, apply a global scope, put it into every unique index (`code`, `number`, `idempotency_key`, `fingerprint`) and into the sequence name, and add cross-tenant tests for every endpoint.
- **One currency.** No exchange rates or revaluation. `Money` refuses to add or compare different currencies.
- **No refunds, credit notes, bank fees or write-offs.** A paid invoice can't be reversed. Write-offs would need a document type that posts to receivables, since manual entries can't.
- **No accounting periods or period closing.** Manual entries can be back-dated.
- **No audit trail of who posted what.** Entries have `posted_at` but no `user_id`. In production I'd add it, plus an activity log for master-data changes.
- **No user management screen.** Roles are set in the database or the seeder.
- **No manual matching screen** for unmatched bank lines.
- **SQLite tests skip** the CHECK-constraint and concurrency tests. The MySQL run in CI covers them.

A production deployment would also need: HTTPS and `APP_DEBUG=false`, a real secrets store, a supervised queue worker with alerts on `failed_jobs`, centralized logs, backups with restore tests, MySQL in strict mode (the default), a timezone policy (the app runs in UTC, `config/app.php`, so "today" for issue and payment dates is the UTC date), and a review of the rate limits.
