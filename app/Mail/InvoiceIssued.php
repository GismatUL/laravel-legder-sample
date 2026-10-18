<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class InvoiceIssued extends Mailable
{
    public function __construct(public readonly Invoice $invoice) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Invoice {$this->invoice->number}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.invoice-issued');
    }
}
