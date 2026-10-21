<?php

use App\Outbox\Handlers\NotifyPaymentWebhook;
use App\Outbox\Handlers\SendInvoiceIssuedEmail;

return [

    // Single-currency ledger. Amounts are stored as integers in minor units of
    // this ISO 4217 currency (2 decimals for AZN, see App\Support\Currency).
    // Don't change it once amounts are stored: they would be reinterpreted.
    'currency' => env('LEDGER_CURRENCY', 'AZN'),

    // Chart-of-accounts codes the billing workflow posts to.
    'accounts' => [
        'bank' => '1000',
        'receivable' => '1100',
        'vat_payable' => '2100',
        'sales' => '4000',
    ],

    // Accounts (keys above) that manual journal entries can't post to.
    // Receivables must equal the open invoices, so only invoices and payments post there.
    'control_accounts' => ['receivable'],

    // Outbox message type => handler (App\Outbox\OutboxHandler) that delivers it.
    'outbox_handlers' => [
        'invoice_issued' => SendInvoiceIssuedEmail::class,
        'payment_recorded' => NotifyPaymentWebhook::class,
    ],

    'invoice_prefix' => 'INV-',

    'payment_terms_days' => 14,

    // API tokens expire; clients request a new one with their credentials.
    'api_token_days' => (int) env('LEDGER_API_TOKEN_DAYS', 30),

    // Cached reports are invalidated on every posting; the TTL only bounds
    // how long an unused entry stays in the cache.
    'report_cache_seconds' => (int) env('LEDGER_REPORT_CACHE_SECONDS', 600),

    // Outbound "payment recorded" webhook (NotifyPaymentWebhook). Off when the URL is empty.
    'webhook' => [
        'url' => env('LEDGER_WEBHOOK_URL'),
        'secret' => env('LEDGER_WEBHOOK_SECRET', ''),
        'connect_timeout' => (int) env('LEDGER_WEBHOOK_CONNECT_TIMEOUT', 2),
        'timeout' => (int) env('LEDGER_WEBHOOK_TIMEOUT', 5),
        // Failures in a row that open the circuit, and how long it stays open.
        'circuit_threshold' => (int) env('LEDGER_WEBHOOK_CIRCUIT_THRESHOLD', 5),
        'circuit_cool_down' => (int) env('LEDGER_WEBHOOK_CIRCUIT_COOL_DOWN', 60),
    ],

];
