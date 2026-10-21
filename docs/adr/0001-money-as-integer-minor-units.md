# 0001. Money as integer minor units, never floats

## Status

Accepted

## Context

`0.1 + 0.2 !== 0.3` in binary floating point. A ledger has to balance to the
cent, and totals are compared for equality (debits = credits, paid = total).
MySQL `DECIMAL` is exact, but PHP turns it into a string or a float, and JSON
clients (JavaScript) parse numbers as doubles.

## Decision

- Amounts are stored as `BIGINT UNSIGNED` in minor units (qəpik for AZN).
- In PHP they live in an immutable `App\Support\Money` value object. Parsing
  goes from string to integer without passing through float.
- The number of decimals comes from the currency (`Support\Currency`: 2 for
  AZN, 0 for JPY, 3 for KWD).
- Every arithmetic result is checked: PHP silently turns an overflowing
  integer into a float, so `Money` throws `money.overflow` instead.
- VAT is computed in basis points with explicit half-up rounding, per line.
- The API sends amounts as decimal strings: `{"amount": "118.00", "currency": "AZN"}`.

## Consequences

- Equality and sums are exact; the trial balance check is a plain `===`.
- Every boundary (forms, API, CSV import, reports) converts explicitly, and
  `MoneyCast` does it for Eloquent.
- One currency per ledger (`LEDGER_CURRENCY`). `Money` refuses to combine two
  currencies; exchange rates are out of scope.
- Changing the currency after data exists would reinterpret stored amounts,
  so the setting is documented as fixed.
