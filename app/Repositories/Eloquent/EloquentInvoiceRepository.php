<?php

namespace App\Repositories\Eloquent;

use App\Data\InvoiceFilters;
use App\Data\InvoiceLineData;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Repositories\Contracts\InvoiceRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentInvoiceRepository implements InvoiceRepositoryInterface
{
    public function findForUpdate(int $id): Invoice
    {
        return Invoice::query()->with('customer')->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    public function loadDetails(Invoice $invoice): Invoice
    {
        return $invoice->load(['customer', 'lines', 'payments.bankTransaction', 'journalEntry']);
    }

    public function findWithDetails(int $id): Invoice
    {
        return $this->loadDetails(Invoice::query()->findOrFail($id));
    }

    public function paginate(InvoiceFilters $filters, int $perPage): LengthAwarePaginator
    {
        return Invoice::query()
            ->with('customer')
            ->when($filters->status, fn ($query) => $query->where('status', $filters->status))
            ->when($filters->customerId, fn ($query) => $query->where('customer_id', $filters->customerId))
            ->when($filters->number, fn ($query) => $query->where('number', $filters->number))
            ->when($filters->overdueOnly, fn (Builder $query) => $this->overdue($query))
            ->latest('id')
            ->paginate($perPage);
    }

    public function countOverdue(): int
    {
        return $this->overdue(Invoice::query())->count();
    }

    public function create(array $attributes): Invoice
    {
        $invoice = new Invoice;
        $invoice->forceFill($attributes)->save();

        return $invoice;
    }

    public function update(Invoice $invoice, array $attributes): Invoice
    {
        $invoice->forceFill($attributes)->save();

        return $invoice;
    }

    public function replaceLines(Invoice $invoice, array $lines): void
    {
        $invoice->lines()->delete();

        $invoice->lines()->createMany(array_map(fn (InvoiceLineData $line) => [
            'description' => $line->description,
            'quantity' => $line->quantity,
            'unit_price' => $line->unitPrice->minor,
            'tax_rate_bp' => $line->taxRateBasisPoints,
        ], $lines));

        $invoice->unsetRelation('lines');
    }

    public function delete(Invoice $invoice): void
    {
        $invoice->delete();
    }

    /**
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    private function overdue(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid])
            ->whereDate('due_date', '<', today());
    }
}
