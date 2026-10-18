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
class VoidInvoiceController extends Controller
{
    /**
     * Void an issued invoice.
     *
     * Posts a reversing entry; the original posting stays in the ledger.
     * Invoices with payments can't be voided (409).
     */
    public function __invoke(Invoice $invoice, InvoiceService $service, InvoiceRepositoryInterface $invoices): InvoiceResource
    {
        return InvoiceResource::make($invoices->loadDetails($service->void($invoice)));
    }
}
