<?php

return [

    // Single-currency ledger. Amounts are stored as integers in minor units.
    'currency' => env('LEDGER_CURRENCY', 'AZN'),

    // Chart-of-accounts codes the billing workflow posts to.
    'accounts' => [
        'bank' => '1000',
        'receivable' => '1100',
        'vat_payable' => '2100',
        'sales' => '4000',
    ],

    'invoice_prefix' => 'INV-',

    'payment_terms_days' => 14,

];
