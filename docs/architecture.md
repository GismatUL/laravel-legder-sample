# Architecture

A layered, modular monolith: one deployable Laravel application, one MySQL
database, Redis for cache and queues. Why not services: [ADR 0003](adr/0003-modular-monolith.md).

## Components

```mermaid
flowchart LR
    subgraph Clients
        API[REST API clients]
        Admin[Admin users<br/>Filament]
        CLI[Artisan commands]
    end

    subgraph App[Laravel application]
        direction TB
        HTTP[Controllers / Filament pages<br/>Policies, Form Requests]
        Services[Services<br/>Ledger, Invoice, Payment,<br/>Bank, Report]
        Repos[Repositories<br/>locking reads, aggregates]
        Events[Domain events<br/>LedgerPosted]
        Outbox[(outbox_messages)]
        HTTP --> Services --> Repos
        Services -- after commit --> Events
        Services -- same transaction --> Outbox
    end

    subgraph Workers
        Queue[Queue worker<br/>DeliverOutboxMessage,<br/>ReconcileBankTransactions]
        Scheduler[Scheduler<br/>outbox:relay]
    end

    MySQL[(MySQL 8.4)]
    Redis[(Redis<br/>cache, queue, locks)]
    SMTP[Mail server]
    ERP[External system<br/>payment webhook]

    API --> HTTP
    Admin --> HTTP
    CLI --> Services
    Repos --> MySQL
    Outbox --> MySQL
    Events --> Redis
    App -- jobs --> Redis
    Redis --> Queue
    Queue --> SMTP
    Queue -- timeouts, retries,<br/>circuit breaker --> ERP
    Scheduler --> Queue
```

## Modules

The code is split by layer (`Services`, `Repositories`, `Http`), and the
services fall into three modules with one-way dependencies:

| Module | Classes | Depends on |
|---|---|---|
| Ledger | `LedgerService`, `ChartOfAccounts`, `SequenceService`, `ReportService`, `Reports\*` | — |
| Billing | `InvoiceService`, `PaymentService`, `Outbox\*` | Ledger |
| Banking | `BankStatementImporter`, `BankReconciler`, `ReconcileBankTransactions` | Billing (matches bank lines to payments) |

Ledger knows nothing about invoices: a posting carries an optional `source`
(polymorphic), and the ledger's own consumers react to `LedgerPosted`.

## Recording a payment

The write path that touches most of the design decisions.

```mermaid
sequenceDiagram
    autonumber
    participant C as Client
    participant API as InvoicePaymentController
    participant PS as PaymentService
    participant DB as MySQL
    participant R as Redis
    participant W as Queue worker
    participant X as External system

    C->>API: POST /invoices/7/payments<br/>Idempotency-Key, X-Request-Id
    API->>PS: record()
    PS->>DB: BEGIN
    PS->>DB: SELECT invoice ... FOR UPDATE
    PS->>DB: find payment by idempotency key
    alt key already used
        PS-->>API: same payment (200, Idempotent-Replayed)
    end
    PS->>DB: INSERT journal entry + lines (Dr Bank / Cr Receivable)
    PS->>DB: INSERT payment, UPDATE invoice
    PS->>DB: INSERT outbox_messages (payment_recorded)
    PS->>DB: COMMIT
    Note over PS,R: after commit only
    PS->>R: LedgerPosted → bump report cache version
    PS->>R: push DeliverOutboxMessage (carries request_id)
    API-->>C: 201 Created
    R->>W: job
    W->>DB: claim message (conditional UPDATE)
    W->>X: POST webhook (Idempotency-Key, X-Signature)<br/>timeout 5 s, 3 attempts, circuit breaker
    W->>DB: status sent / pending + last_error
```

What each step protects against:

| Step | Protects against | Details |
|---|---|---|
| 4 | Two requests paying the same invoice at once | [ADR 0004](adr/0004-locking-and-idempotency.md) |
| 5 | A client retrying after a timeout | [ADR 0004](adr/0004-locking-and-idempotency.md) |
| 7 | An unbalanced or edited ledger | [ADR 0001](adr/0001-money-as-integer-minor-units.md), [ADR 0002](adr/0002-append-only-ledger.md) |
| 9 | A payment without its notification, or a notification for a rolled-back payment | [ADR 0005](adr/0005-transactional-outbox.md) |
| 11 | A stale trial balance | [ADR 0006](adr/0006-report-cache-invalidation.md) |
| 16 | A slow or failing receiver | [ADR 0007](adr/0007-outbound-webhook-resilience.md) |
| 1, 12 | Losing track of a request across processes | [ADR 0008](adr/0008-request-correlation-and-json-logs.md) |

## Core data model

```mermaid
erDiagram
    accounts ||--o{ journal_lines : "posted to"
    journal_entries ||--|{ journal_lines : has
    journal_entries |o--o| journal_entries : "reversal_of"
    invoices ||--|{ invoice_lines : has
    customers ||--o{ invoices : billed
    invoices ||--o| journal_entries : "issue posting"
    invoices ||--o{ payments : receives
    payments ||--|| journal_entries : "payment posting"
    bank_transactions |o--o| payments : "matched to"
    outbox_messages }o..|| invoices : "payload refers to"
    outbox_messages }o..|| payments : "payload refers to"
```

Column-level documentation is in the database itself: every column has a
comment (`SHOW FULL COLUMNS FROM invoices`).

## Runtime (Docker)

| Service | Image | Role |
|---|---|---|
| `app` | `Dockerfile` target `app` (PHP 8.4 FPM, OPcache) | HTTP requests |
| `web` | target `web` (Nginx with `public/` baked in) | Static files, proxies PHP to `app:9000` |
| `queue` | same image as `app` | `queue:work redis` |
| `scheduler` | same image as `app` | `schedule:work` (`outbox:relay` every 5 minutes) |
| `mysql` | `mysql:8.4` | Data |
| `redis` | `redis:7-alpine` | Cache (db 1), queue and locks (db 0) |
| `mailpit` | `axllent/mailpit` | Catches outgoing email |

Operations: [operations.md](operations.md).
