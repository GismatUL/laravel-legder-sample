<?php

namespace App\Outbox\Handlers;

use App\Mail\InvoiceIssued;
use App\Models\Invoice;
use App\Outbox\OutboxHandler;
use Illuminate\Support\Facades\Mail;

class SendInvoiceIssuedEmail implements OutboxHandler
{
    public function handle(array $payload): bool
    {
        $invoice = Invoice::query()->with(['customer', 'lines'])->findOrFail((int) $payload['invoice_id']);

        if ($invoice->customer->email === null) {
            return false;
        }

        Mail::to($invoice->customer->email)->send(new InvoiceIssued($invoice));

        return true;
    }
}
