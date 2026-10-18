# Ledger Sample: double-entry accounting in Laravel

A small accounting backend built with **Laravel 13**, **MySQL** and **Filament 5**. I wrote it as a code sample to show how I handle financial data in Laravel: double-entry bookkeeping, invoicing, payments and bank reconciliation, with the rules enforced in the service layer and covered by tests.

This is a code sample, not a production system. It has no real users. The scope is kept small on purpose so the important parts are easy to review.

I built it with AI assistance (Claude Code), the same way I work day to day. I reviewed and tested every part, and I can explain any design decision in it.

## What it does

| Area | What's implemented |
|---|---|
| **General ledger** | Chart of accounts, journal entries with debit/credit lines, trial balance |
| **Invoicing** | Draft → Issued → Partially paid / Paid, or Void. Per-line VAT. Gap-free invoice numbers |
| **Payments** | Partial and full payments, overpayment protection, idempotent recording |
| **Bank reconciliation** | CSV statement import, queued automatic matching against recorded payments |
| **Admin panel** | Filament resources for all of the above, plus a trial balance page and a dashboard |

## Design decisions

**Money is never a float.** Amounts are stored as integers in minor units (`BIGINT`, cents) and handled through an immutable `App\Support\Money` value object. Parsing goes from string to integer without passing through float. VAT is calculated in basis points with explicit half-up rounding. `allocate()` splits amounts without losing a cent.

**The ledger is the source of truth, and it's append-only.**
- `LedgerService` is the only class that writes journal entries. It rejects an entry unless it has at least two lines, each line is a debit or a credit (not both), every account is active, and **total debits equal total credits**.
- Each entry and its lines are written in one database transaction, so a half-written entry can't exist.
- Posted entries and lines can't be updated or deleted (model events throw). Mistakes are fixed with a **reversing entry**, so the audit trail stays complete. Voiding an invoice posts a reversal; it doesn't delete anything.

**Concurrency and retries.**
- Issuing, voiding and paying an invoice lock the invoice row (`SELECT ... FOR UPDATE`), so two requests on the same invoice run one after the other.
- Invoice numbers come from a counter row that's locked inside the same transaction. If issuing fails, the number is rolled back too, so the numbering has no gaps (`MAX()+1` or auto-increment ids can't guarantee this).
- Payments and journal entries accept an **idempotency key**. A payment webhook that arrives twice records the payment once. Reusing a key with different amounts throws instead of returning the wrong entry. In the admin panel, the payment form generates a key when it opens, so a double click records only one payment.

**Background jobs.** Importing a bank statement dispatches `ReconcileBankTransactions` (queued, `ShouldBeUnique`). It walks unreconciled rows with `chunkById()` and matches each one in its own transaction with row locks, so overlapping runs are still safe. Rows get a fingerprint, so importing the same statement twice creates no duplicates. A bank line matches only if its reference contains the invoice number and the amount is exact. Anything else stays unmatched for a person to review.

**Business rules live in services, not in the UI.** The Filament actions only collect input and call `InvoiceService`, `PaymentService` or `LedgerService`, the same code the tests cover. Domain errors (overpayment, unbalanced entry, etc.) show up as notifications.

## Postings

| Event | Debit | Credit |
|---|---|---|
| Invoice issued | 1100 Accounts receivable (total) | 4000 Sales revenue (net), 2100 VAT payable (VAT) |
| Payment received | 1000 Bank | 1100 Accounts receivable |
| Invoice voided | Reversal of the issue entry | |

## Project layout

```
app/
├── Support/Money.php            Money value object
├── Casts/MoneyCast.php          Eloquent cast: integer column <-> Money
├── Services/
│   ├── LedgerService.php        Validates and posts entries, reversals, idempotency
│   ├── InvoiceService.php       Totals, issue, void
│   ├── PaymentService.php       Payments with locking and idempotency
│   ├── SequenceService.php      Gap-free numbering
│   ├── BankStatementImporter.php
│   └── BankReconciler.php
├── Jobs/ReconcileBankTransactions.php
├── Reports/TrialBalance.php     Aggregated in SQL
└── Filament/                    Admin panel (resources, trial balance page, dashboard widget)
tests/
├── Unit/MoneyTest.php
└── Feature/                     Ledger, invoices, payments, reconciliation, admin panel
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

Open `http://127.0.0.1:8000/admin` and log in as `admin@example.com` / `password`.

The seeder creates a chart of accounts, three customers and four invoices: one paid, one partially paid, one issued and one draft.

```bash
# Trial balance in the terminal (fails if debits don't equal credits)
php artisan ledger:trial-balance

# Import the sample bank statement, then process the queued matching job
php artisan bank:import database/samples/bank-statement.csv
php artisan queue:work --stop-when-empty
```

## Tests

```bash
php artisan test
```

By default the tests run on in-memory SQLite. SQLite ignores `lockForUpdate()`, so the suite should also run on MySQL:

```bash
DB_CONNECTION=mysql DB_DATABASE=ledger_sample_test php artisan test
```

GitHub Actions runs Pint and the suite on both databases (`.github/workflows/tests.yml`).

The tests cover: money rounding and allocation; rejected unbalanced or one-sided entries; immutability; reversals; idempotent posting and payments; invoice totals, numbering and rollback of numbers; status transitions; overpayment; reconciliation matching and duplicate imports; and the Filament pages and actions.

## Kept out of scope on purpose

Things a production system would need, left out to keep the sample focused:

- Multiple currencies and exchange rates (the ledger is single-currency, `AZN` by default)
- Credit notes, refunds, and posting bank fees from the statement
- Accounting periods and period closing
- Roles and permissions (every user is an admin)
- A manual matching screen for unmatched bank lines
