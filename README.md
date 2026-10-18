# Ledger Sample: double-entry accounting in Laravel

[![tests](https://github.com/GismatUL/laravel-legder-sample/actions/workflows/tests.yml/badge.svg)](https://github.com/GismatUL/laravel-legder-sample/actions/workflows/tests.yml)

A small accounting backend built with Laravel 13, MySQL and Filament 5: a double-entry ledger, invoicing, payments and bank reconciliation, with a REST API and an admin panel. It's a code sample for job applications. It has never run in production and has no real users. [Limitations](#limitations) lists what it doesn't do.

It was built with AI assistance (Claude Code).

## Contents

- [Features](#features)
- [Setup](#setup)
- [Configuration](#configuration)
- [Database](#database)
- [Background jobs](#background-jobs)
- [Tests and checks](#tests-and-checks)
- [Architecture](#architecture)
- [Double-entry example](#double-entry-example)
- [Invoice lifecycle](#invoice-lifecycle)
- [Idempotency and concurrency](#idempotency-and-concurrency)
- [Authorization](#authorization)
- [REST API](#rest-api)
- [Admin panel](#admin-panel)
- [CI](#ci)
- [Limitations](#limitations)

## Features

| Area | What's implemented |
|---|---|
| General ledger | Chart of accounts, journal entries with debit/credit lines, manual entries, reversals, trial balance |
| Invoicing | Draft → Issued → Partially paid / Paid, or Void. Per-line VAT. Gap-free invoice numbers |
| Payments | Partial and full payments, overpayment check, idempotent recording with an `Idempotency-Key` header |
| Bank reconciliation | CSV statement import, a queued job that matches lines to payments, and a page for matching the rest by hand |
| Invoice email | Sent to the customer when an invoice is issued, through a database outbox and a queued job |
| Access control | Three roles (admin, accountant, viewer), checked by policies in the API and the admin panel |
| Audit trail | Journal entries record who posted them. An activity log records changes to accounts and customers, and manual bank matches |

## Setup

Tested with PHP 8.3 and MySQL 8.4. The test suite also runs on SQLite.

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
- API docs (local environment only): `http://127.0.0.1:8000/docs/api`

The seeder creates a chart of accounts, three customers, four invoices (paid, partially paid, issued, draft) and one user per role. All three users have the password `password`; they're demo accounts for local use.

| Email | Role |
|---|---|
| `admin@example.com` | admin |
| `accountant@example.com` | accountant |
| `viewer@example.com` | viewer |

```bash
php artisan ledger:trial-balance                          # exits 1 if debits != credits
php artisan bank:import database/samples/bank-statement.csv
php artisan queue:work --stop-when-empty                  # runs queued jobs (matching, invoice emails)
php artisan schedule:work                                 # runs outbox:relay every 5 minutes
```

With the default `MAIL_MAILER=log`, invoice emails are written to `storage/logs/laravel.log`.

## Configuration

`.env.example` has every setting and no secrets. The application-specific ones:

| Variable | Default | Meaning |
|---|---|---|
| `LEDGER_CURRENCY` | `AZN` | ISO 4217 code of the ledger's only currency. It sets the number of decimals (2 for AZN, 0 for JPY, 3 for KWD). Don't change it after amounts are stored: they would be read with the wrong number of decimals. |
| `LEDGER_API_TOKEN_DAYS` | `30` | API token lifetime |
| `QUEUE_CONNECTION` | `database` | Queue for the matching job and the invoice emails |

Account codes, control accounts, the invoice prefix, payment terms and outbox handlers are in `config/ledger.php`. The app runs in UTC (`config/app.php`), so "today" for issue and payment dates is the UTC date.

## Database

Each table has its own migration in `database/migrations`, and every table and column (except `id` and the timestamps) has a database comment that explains it. On MySQL you can read them with `SHOW FULL COLUMNS FROM invoices`.

| Table | Notes |
|---|---|
| `accounts` | `code` unique. An account with postings can't be deleted (foreign key restrict) |
| `journal_entries` | `idempotency_key` unique. `reversal_of_id` unique, so an entry can be reversed only once. `source` points to the invoice. `posted_by` is null for system postings |
| `journal_lines` | Amounts as `BIGINT UNSIGNED` minor units. An entry with lines can't be deleted (restrict) |
| `invoices`, `invoice_lines` | `number` unique. Totals in minor units |
| `payments` | `idempotency_key` unique |
| `bank_transactions` | `fingerprint` unique (repeated imports are skipped), `payment_id` unique (one bank line per payment), `match_method` (`auto`/`manual`), `matched_by` |
| `activity_log` | User, event, subject, old/new values as JSON. The model refuses updates and deletes |
| `outbox_messages` | One row per message to send (`dedupe_key` unique), with status, attempts and the last error |
| `sequences` | Counters for gap-free numbers. The invoice counter is created by a migration |
| `users` | `role`. A user referenced by postings, matches or log entries can't be deleted |

On MySQL, the `journal_lines`, `invoices` and `payments` migrations add CHECK constraints that repeat some service rules at the database level:

- a journal line has exactly one positive side;
- `invoices.total = subtotal + tax_total`;
- `amount_paid <= total`;
- `payments.amount > 0`.

SQLite can't add CHECK constraints to an existing table, so on SQLite only the service checks apply. MySQL needs 8.0.16 or later to enforce them.
## Background jobs

```bash
php artisan queue:work
php artisan queue:failed
php artisan queue:retry all
```

**`ReconcileBankTransactions`** is dispatched after a statement import.

- **Matching:** a line matches when its reference contains an invoice number and the amount is exact. Each line is handled in its own transaction with row locks. Lines already reconciled are skipped, and `bank_transactions.payment_id` is unique, so running the job twice doesn't match a payment twice.
- **Uniqueness:** `ShouldBeUniqueUntilProcessing`. A run waiting in the queue absorbs further imports. Once it starts, a new import can queue the next run. With the earlier `ShouldBeUnique`, an import during a run queued nothing, and its lines waited for the next import.
- **Retries:** 3 tries, backoff 10 s and 60 s, 120 s timeout. On final failure only the exception class and message are logged. For SQL errors only the SQLSTATE is logged, because the full message contains bank references and amounts.

**`DeliverOutboxMessage`** sends the invoice email.

- **Sending the email:** a database transaction can't include sending an email. So `InvoiceService::issue()` writes an `outbox_messages` row in the same transaction as the posting, and the job is dispatched after the commit. If the issue rolls back, no row and no job exist.
- **Claiming:** the job claims the row with one conditional `UPDATE`, so two workers don't send the same message, and no row lock is held while the email is sent. A claim expires after 10 minutes, so a dead worker's message is picked up again. The job's 60 s timeout keeps a live worker well inside that window.
- **Delivery guarantee:** delivery is at least once. If a worker crashes after sending but before marking the row sent, the email is sent again.
- **Retries:** 5 tries with increasing backoff; after the last one the status is `failed`. A customer without an email address is marked `skipped`.
- **Relay:** `outbox:relay`, scheduled every 5 minutes, re-dispatches messages still pending after 5 minutes, for example if the process died between commit and dispatch.

## Tests and checks

```bash
php artisan test                                                     # SQLite in memory
DB_CONNECTION=mysql DB_DATABASE=ledger_sample_test php artisan test  # MySQL, including the MySQL-only tests
vendor/bin/phpstan analyse                                           # Larastan, level 7
vendor/bin/pint --test
```

Last local run (2026-10-19, PHP 8.3.18, MySQL 8.4.3):

- SQLite: 198 passed, 7 skipped (the MySQL-only tests)
- MySQL: 205 of 205 passed
- Larastan: 0 errors
- Pint: passed

| Area | Examples of what's tested |
|---|---|
| Money | Parsing without floats, 0/2/3-decimal currencies, invalid currency codes, rounding, allocation, integer overflow in each operation |
| Ledger | Unbalanced, one-line, empty and two-sided entries; zero and negative amounts; inactive, unknown and control accounts; immutability; reversals |
| Idempotency | Same key and payload returns the original. A different amount, date, method, reference, description or memo is a 409. Line order is ignored |
| Invoices | VAT rounding per line, numbering, number rollback on failure, every state transition, due date rule, overflow returns 422 |
| Payments | Exactly the outstanding amount vs one cent more, before the issue date, in the future, on a paid invoice, rollback when the posting fails |
| Reports | Trial balance totals and date boundaries; ledger receivables equal open invoices across all invoice states |
| Bank | Matching, duplicate imports, malformed rows (including impossible dates and long references), job re-runs, the uniqueness bug, manual matching rules |
| Outbox | Sent after commit, nothing on rollback, sent once when delivered twice, claim expiry, skipped and failed states, relay |
| Audit | `posted_by` for API and system postings, old/new values, no entry for no-op updates |
| Authorization | Every write route with every role, token abilities per role, role downgrade after token issue, admin panel actions called directly through Livewire |
| MySQL only | CHECK constraints, and four concurrency tests run as 6 parallel PHP processes: payments on one invoice, retries with one key, manual entries with one key, concurrent invoice issues |

The concurrency tests (`tests/Feature/MySql/ConcurrencyTest.php`) start separate PHP processes with their own database connections. The processes wait for a shared start time so their requests overlap. They found a deadlock in invoice numbering, which is fixed (see [below](#idempotency-and-concurrency)). Passing these tests shows these four scenarios behave correctly on one machine. It doesn't prove the absence of other races.

There are no browser tests. The Alpine.js code on the matching page isn't tested; the Livewire tests cover its server side.

## Architecture

```
API controllers  ─┐
Filament pages   ─┼─►  Policies  ─►  Form Requests / DTOs  ─►  Services  ─►  Repositories  ─►  Eloquent
Commands, jobs   ─┘
```

| Class | Responsibility |
|---|---|
| `Support\Money`, `Support\Currency` | Amounts as integers in minor units; decimals per currency; arithmetic that throws on overflow |
| `Services\LedgerService` | The only class that writes journal entries: validates the whole entry, writes it in one transaction, handles idempotency and reversals |
| `Services\InvoiceService` | Drafts, totals, issue (number, posting, outbox message), void (reversal) |
| `Services\PaymentService` | Payments: invoice row lock, idempotency, amount and date checks, posting, status |
| `Services\SequenceService` | Gap-free numbers from a locked counter row |
| `Services\BankStatementImporter`, `Services\BankReconciler` | Statement import, automatic and manual matching |
| `Outbox\Outbox`, `Jobs\DeliverOutboxMessage` | The outbox for the invoice email |
| `Models\ActivityLog`, `Models\Concerns\RecordsActivity` | The activity log |
| `Enums\InvoiceStatus` | Allowed invoice transitions (`canTransitionTo()`) |
| `Enums\UserRole`, `Policies\*` | Roles and permissions |
| `Exceptions\DomainRuleException` | Business-rule errors with a stable code and HTTP status |

How the code is organized:

- **Validation:** Form Requests check the shape of input; the services check the business rules. The financial write paths (invoices, payments, journal entries, accounts, matching) go through the same services from the API and the admin panel.
- **Customers and Filament lists:** customers have no service, because there are no business rules for them. Filament's list pages build their own Eloquent queries.
- **Money:** amounts are stored as integers in minor units and sent as decimal strings (`"118.00"`). PHP turns an overflowing integer into a float, so `Money` checks every result and throws `money.overflow` (422).
- **Corrections:** posted journal entries can't be updated or deleted. The model, the policy and a database foreign key all refuse it. Corrections are reversing entries linked to the original.
- **Receivables:** manual journal entries can't post to the receivables account (`ledger.control_account`). Only invoices and payments post there, which keeps the receivables balance equal to the open invoices. A test checks this across every invoice state.
- **Repositories:** many repository methods are thin wrappers around Eloquent queries. They hold the locking reads and aggregates, but they add little elsewhere.

## Double-entry example

Invoice `INV-000007` is issued for 236.00 AZN (200.00 + 18% VAT). `InvoiceService::issue()` posts:

| Account | Debit | Credit |
|---|---|---|
| 1100 Accounts receivable | 236.00 | |
| 4000 Sales revenue | | 200.00 |
| 2100 VAT payable | | 36.00 |

The customer pays 200.00. `PaymentService::record()` posts:

| Account | Debit | Credit |
|---|---|---|
| 1000 Bank | 200.00 | |
| 1100 Accounts receivable | | 200.00 |

**Why it balances:** both sides of each entry are equal, which `LedgerService` checks before writing. The bank account (an asset) increases with a debit, and the receivable (also an asset) decreases with a credit.

**How it relates to the invoice:** in the same transaction, `amount_paid` becomes 200.00 and the status *Partially paid*. The outstanding 36.00 equals what is left on account 1100 for this invoice. The journal entry's `source` is the invoice, and the payment row stores the entry id.

**Retries:** the request carries an `Idempotency-Key`. A retry with the same key and the same data returns the original payment (`200`, `Idempotent-Replayed: true`) and posts nothing. The same key with different data is a `409`.

## Invoice lifecycle

```
Draft ──issue──► Issued ──pay──► Partially paid ──pay──► Paid
                   │  └──────────pay (full amount)──────►┘
                   └──void──► Void
```

| Action | Rules | Posting |
|---|---|---|
| create, edit, delete | Drafts only. The server calculates totals and VAT | none |
| issue | Draft with a total above zero. Due date not in the past (default: 14 days) | Dr Receivable / Cr Sales, Cr VAT |
| pay | Issued or Partially paid. Amount above zero and not above the outstanding balance. Date between the issue date and today | Dr Bank / Cr Receivable |
| void | Issued, with no payments | Reversal of the issue entry |

"Overdue" isn't stored: it's an Issued or Partially paid invoice past its due date. There are no refunds or credit notes, so an invoice with payments can't be voided.

## Idempotency and concurrency

| Operation | Protection |
|---|---|
| Record payment | `Idempotency-Key` required, unique `payments.idempotency_key`. The invoice row is locked before the key is checked, so a duplicate request waits and then finds the first payment. All fields are compared. |
| Manual journal entry | Optional `Idempotency-Key`, unique `journal_entries.idempotency_key`. There's no row to lock, so the unique index decides. The losing request catches the violation, re-reads the winner with a locking read (a plain read in a REPEATABLE READ transaction can return an older snapshot) and compares the data. |
| Issue, void, reverse | Status checks under a row lock. A repeated call gets a `409` and posts nothing. A reversal also has a unique `reversal_of_id`. |
| Invoice number | Counter row read with `SELECT ... FOR UPDATE` inside the issuing transaction. A rollback also rolls back the number. |
| Statement import | Each row has a fingerprint with a unique index, and rows already imported are skipped. |

Idempotency keys are unique across the whole database, which is fine for a single organization.

**Locks:**
- Workflows that lock several rows take them in a fixed order: payments lock the invoice first; matching locks the bank line, then the payment.
- No network call happens while a lock is held: the email goes through the outbox.

**The deadlock the concurrency tests found:** `SequenceService` ran `INSERT IGNORE` on every call. When the row already exists, that takes a shared lock. Two concurrent issues then both waited to upgrade it to an exclusive lock, and MySQL aborted one (error 1213). Now the counter row is created by a migration, and normal calls only lock it.

**Deadlock retries:** top-level financial transactions are retried up to 3 times when MySQL reports a deadlock (`LedgerService::DEADLOCK_ATTEMPTS`). In 3 local runs with retries disabled, the concurrency tests still passed.

## Authorization

There's one organization: every logged-in user can read all of its data. Roles decide what a user can change.

| | Viewer | Accountant | Admin |
|---|---|---|---|
| Read everything, including reports and the activity log | ✓ | ✓ | ✓ |
| Customers, invoices, payments, voids | | ✓ | ✓ |
| Manual journal entries, reversals | | ✓ | ✓ |
| Statement import, manual matching | | ✓ | ✓ |
| Chart of accounts | | | ✓ |

- **Where it's checked:** policies in `app/Policies`, on every API write route (`->can()` in `routes/api.php`) and on Filament pages and actions. Filament also refuses hidden actions on the server, and tests call those actions directly through Livewire to check this.
- **API tokens:** a token can't carry more than the user's role allows. A viewer only gets `ledger:read`. The role is checked again on each request, so lowering a role also limits tokens issued earlier.
- **Mass assignment:** `role` is not mass assignable.

## REST API

Base URL `/api/v1`. The OpenAPI docs are generated with [Scramble](https://scramble.dedoc.co) at `/docs/api`, in the local environment only.

### Authentication

```bash
curl -X POST http://127.0.0.1:8000/api/v1/auth/tokens \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"email":"accountant@example.com","password":"password","device_name":"cli"}'
# {"token":"1|...","token_type":"Bearer","abilities":["ledger:read","ledger:write"],"expires_at":"..."}
```

- **Using the token:** send it as `Authorization: Bearer <token>`. Tokens expire after 30 days.
- **Read-only tokens:** request `"abilities": ["ledger:read"]`.
- **Rate limits:** 5 token requests per minute per email and IP, and 120 requests per minute per user elsewhere.

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
| `POST` | `/bank-statements` (multipart CSV, max 2 MB), returns `202` | write | accountant, admin |
| `GET` | `/bank-transactions?reconciled=0\|1` | read | any |
| `POST` | `/bank-transactions/{id}/match` (`payment_id`) | write | accountant, admin |

Lists are paginated (`per_page` up to 100, default 25). Money fields look like `{"amount": "118.00", "currency": "AZN"}`.

### Example: record a payment

```bash
curl -X POST http://127.0.0.1:8000/api/v1/invoices/1/payments \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: psp-tx-8f3a91' \
  -d '{"amount":"118.00","paid_on":"2026-10-18","method":"bank_transfer"}'
```

The first request returns `201`. A retry with the same key and data returns `200` with `Idempotent-Replayed: true`.

### Errors

Error responses have a `message` and a `code`.

| Status | When | `code` |
|---|---|---|
| `401` | Missing, invalid or expired token | `unauthenticated` |
| `403` | Token ability or role doesn't allow the action | `forbidden` |
| `404` | Unknown resource | `not_found` |
| `409` | Conflict with the current state (issue twice, edit an issued invoice, reused idempotency key) | e.g. `billing.invalid_transition` |
| `422` | Invalid input, with `errors` per field | `validation_failed` |
| `422` | Business rule | e.g. `ledger.unbalanced`, `billing.overpayment`, `money.overflow` |
| `429` | Rate limit, with `Retry-After` | `too_many_requests` |

All business-rule codes are in `app/Exceptions/*Exception.php`.

## Admin panel

`/admin`, with the same users and roles as the API.

- **Invoices:** create and edit drafts, then Issue, Record payment and Void. These call the same services as the API, and business-rule errors appear as notifications.
- **Journal entries:** post manual entries and reverse them. There's no edit or delete.
- **Chart of accounts:** code and type can't change once an account has postings (enforced in `AccountService`).
- **Bank reconciliation:** list of bank lines with an unreconciled filter, statement import, and **Match bank lines**. That page is a custom Livewire component: pick an unmatched bank line and it lists unmatched payments with the same amount. Alpine.js filters that list in the browser and asks for confirmation before matching.
- **Activity log:** a read-only list.
- **Trial balance and dashboard:** a trial balance page with an *as of* date, and a dashboard.

`Model::shouldBeStrict()` is enabled outside production, so lazy loading and discarded attributes throw an exception during development.

## CI

`.github/workflows/tests.yml` runs on every push and pull request:

1. `composer validate` and `composer install`
2. Pint
3. Larastan level 7
4. PHPUnit on SQLite
5. On a MySQL 8.4 service: `migrate:fresh --seed`, `ledger:trial-balance` and `migrate:rollback`
6. PHPUnit on MySQL

The workflow hasn't run on GitHub yet; all of the steps above have been run locally.

## Limitations

**Not implemented:**

- **One organization:** no tenant isolation. Multi-tenancy would need an `organization_id` on every financial table and on users, a global scope, the id in every unique index (`code`, `number`, `idempotency_key`, `fingerprint`) and in the sequence name, plus cross-tenant tests.
- **One currency:** no exchange rates. `Money` refuses to combine different currencies.
- **No refunds, credit notes, bank fees or write-offs.** A paid invoice can't be reversed.
- **No accounting periods or period closing:** manual entries can be back-dated.
- **No user management screen:** roles are set in the database or by the seeder.
- **No way to undo a manual bank match.**
- **The activity log doesn't cover invoice drafts:** issued invoices are traceable through their journal entries' `posted_by`.
- **Invoice emails can be sent twice** if a worker crashes between sending and marking the message sent.

**A real deployment would also need:**

- `APP_ENV=production` and `APP_DEBUG=false`; debug mode shows stack traces with SQL bindings
- HTTPS and a secrets store
- a supervised queue worker and scheduler, with alerts on `failed_jobs` and on failed outbox messages
- a real mail provider
- central logging
- tested backups
- a review of the rate limits
