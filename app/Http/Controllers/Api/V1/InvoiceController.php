<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\InvoiceRequest;
use App\Http\Requests\Api\V1\ListInvoicesRequest;
use App\Http\Resources\V1\InvoiceResource;
use App\Models\Invoice;
use App\Repositories\Contracts\InvoiceRepositoryInterface;
use App\Services\InvoiceService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * @tags Invoices
 */
class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceRepositoryInterface $invoices,
        private readonly InvoiceService $service,
    ) {}

    public function index(ListInvoicesRequest $request): AnonymousResourceCollection
    {
        return InvoiceResource::collection($this->invoices->paginate($request->filters(), $request->perPage()));
    }

    /**
     * Create a draft invoice.
     *
     * Totals and VAT are calculated by the server from the lines.
     */
    public function store(InvoiceRequest $request): InvoiceResource
    {
        return InvoiceResource::make($this->invoices->loadDetails($this->service->createDraft($request->toData())));
    }

    public function show(Invoice $invoice): InvoiceResource
    {
        return InvoiceResource::make($this->invoices->loadDetails($invoice));
    }

    /**
     * Replace a draft invoice.
     *
     * Only drafts can be changed (409 otherwise). All lines are replaced.
     */
    public function update(InvoiceRequest $request, Invoice $invoice): InvoiceResource
    {
        return InvoiceResource::make($this->invoices->loadDetails($this->service->updateDraft($invoice, $request->toData())));
    }

    /**
     * Delete a draft invoice.
     *
     * Issued invoices can't be deleted; void them instead (409).
     */
    public function destroy(Invoice $invoice): Response
    {
        $this->service->deleteDraft($invoice);

        return response()->noContent();
    }
}
