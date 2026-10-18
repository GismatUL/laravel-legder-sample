# Task: Versioned REST API with repository layer

Branch: feature/rest-api

## Objective

Expose the ledger, invoicing, payments, reports and bank reconciliation
through an authenticated, versioned REST API, and move data access behind
repositories, following the `ai-engineering` rules (thin controllers,
Form Requests, API Resources, services for business logic, repositories
for data access, Sanctum auth).

## Context

- Owner request: "build some rest api, and also read ai-engineering-master
  folder to follow clean code architecture from there", then "work on it
  independently, behave like tech lead".
- The project is a code sample for a job application (Laravel accounting role).
- Rules source: `ai-engineering-master/` in the project root (v0.2.0, newer
  than the copy in Downloads: adds `measurement.md`, `logging-and-monitoring.md`).

## Investigation

Verified in the codebase before changes:

- Laravel 13.37, Filament 5.10, PHPUnit 12 (no Pest), Pint configured, no
  static analysis configured.
- No `routes/api.php`, no Sanctum, no API auth.
- Business logic was already in services. Data access was done with
  Eloquent inside services, Filament resources and `Reports\TrialBalance`.
- Draft invoice create/update existed only in Filament (relationship repeater).
- The account rule "code/type can't change once posted" existed only as
  disabled fields in the Filament form.
- Domain errors were plain `DomainException`s with no HTTP mapping.

## Root Cause

Not a bug fix. Gaps: no API layer, no shared draft-invoice service, data
access mixed into services, no static analysis.

## Proposed Solution

As implemented below.

## Approval

- [x] Approved: owner asked to work independently on this.

## Implementation

- Sanctum token auth with `ledger:read` / `ledger:write` abilities, 30-day
  expiry, rate limits (5/min on the token endpoint, 120/min elsewhere).
- `/api/v1`: accounts, customers, invoices (+ issue, void, payments),
  journal entries (+ reversal), trial balance, bank statement import,
  bank transactions. 26 operations.
- Repository interfaces + Eloquent implementations bound in
  `RepositoryServiceProvider`. Services, jobs, reports, widget and Filament
  record pages now read through them.
- One write path: `InvoiceService::createDraft/updateDraft/deleteDraft` and
  `LedgerService::postManual/reverseManual` used by both API and Filament.
- `AccountService` enforces the posted-account rule (was UI-only).
- `DomainRuleException` with stable error codes; 409 for state conflicts,
  422 for rule violations, JSON 404 without model class names.
- `Idempotency-Key` header for payments (201 / 200 replay / 409 conflict)
  and optionally for manual entries.
- OpenAPI via Scramble (`/docs/api`, local only), with a Money schema
  extension and documented headers.
- Larastan level 7 (with `parseModelCastsMethod`), added to CI.
- `Model::shouldBeStrict()` outside production; morph map aliases.

## Verification

- Tests: 85/85 passed on SQLite and 85/85 on MySQL 8.4 (run 2026-10-18).
  Started at 45; 40 new tests (API + Filament edit page).
- Static analysis: Larastan level 7, 0 errors. Level 8 still reports
  nullability findings (nullable BelongsTo relations, seeder factories);
  not adopted.
- Lint: Pint passed.
- OpenAPI export: 17 paths generated without errors.

## Measurements

- Metric: SQL query count of `GET /api/v1/invoices`
- Method: `DB::enableQueryLog()` around the request in
  `InvoiceApiTest::test_list_query_count_does_not_grow_with_rows`
- Environment: PHPUnit, in-memory SQLite and MySQL 8.4 (local Laragon)
- Baseline: 3 invoices
- Change: 23 invoices
- After: same query count as baseline (asserted equal)
- Comparison: no growth with row count
- Classification: Verified (same result on two databases)
- Conclusion: the list endpoint has no N+1 on its loaded relations. Says
  nothing about response time; not timed.

## Completion

- Status: Done
- Started: 2026-10-18 18:11
- Completed: 2026-10-18 23:10
- Active work: unknown (single session, owner away)
- Elapsed time: about 5 hours

## Notes

- Bugs found and fixed along the way:
  - Filament journal entry view lazy-loaded `lines.account` (N+1), found by
    strict mode.
  - Filament invoice edit page put Money objects into Livewire state and
    failed to render. Whether this also affected `main` was not verified.
  - Payment idempotency replay check and invoice defaults for new drafts.
- Morph map changed `journal_entries.source_type` from the class name to
  `invoice`. Local DB was reseeded. A deployed database would need a data
  migration.
- The local dev server was stopped by the system (low memory) during the
  session and was not restarted.

## Remaining Work

- Push the repo to GitHub and confirm the CI run (not done: needs owner).
- Optional: PHPStan level 8, policies/roles if multi-user is needed.
