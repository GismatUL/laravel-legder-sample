<?php

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
    ],

    'invoice_prefix' => 'INV-',

    'payment_terms_days' => 14,

    // API tokens expire; clients request a new one with their credentials.
    'api_token_days' => (int) env('LEDGER_API_TOKEN_DAYS', 30),

];
