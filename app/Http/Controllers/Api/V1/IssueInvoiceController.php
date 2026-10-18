<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\InvoiceResource;
use App\Models\Invoice;
use App\Repositories\Contracts\InvoiceRepositoryInterface;
use App\Services\InvoiceService;

/**
 * @tags Invoices
 */
class IssueInvoiceController extends Controller
{
    /**
     * Issue a draft invoice.
     *
     * Assigns the next invoice number and posts the invoice to the ledger.
     * Safe to retry: issuing twice returns 409 and posts nothing.
     */
    public function __invoke(Invoice $invoice, InvoiceService $service, InvoiceRepositoryInterface $invoices): InvoiceResource
    {
        return InvoiceResource::make($invoices->loadDetails($service->issue($invoice)));
    }
}
