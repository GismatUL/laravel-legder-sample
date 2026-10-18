<?php

namespace App\Repositories\Contracts;

use App\Data\InvoiceFilters;
use App\Data\InvoiceLineData;
use App\Models\Invoice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface InvoiceRepositoryInterface
{
    /**
     * Re-read the invoice with a row lock (SELECT ... FOR UPDATE).
     * Must be called inside a transaction.
     */
    public function findForUpdate(int $id): Invoice;

    public function loadDetails(Invoice $invoice): Invoice;

    public function findWithDetails(int $id): Invoice;

    /**
     * @return LengthAwarePaginator<int, Invoice>
     */
    public function paginate(InvoiceFilters $filters, int $perPage): LengthAwarePaginator;

    public function countOverdue(): int;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Invoice;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Invoice $invoice, array $attributes): Invoice;

    /**
     * @param  list<InvoiceLineData>  $lines
     */
    public function replaceLines(Invoice $invoice, array $lines): void;

    public function delete(Invoice $invoice): void;
}
