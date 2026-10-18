<?php

namespace App\Repositories\Eloquent;

use App\Models\Invoice;
use App\Models\Payment;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Support\Money;
use Illuminate\Support\Collection;

class EloquentPaymentRepository implements PaymentRepositoryInterface
{
    public function findByIdempotencyKey(string $key): ?Payment
    {
        return Payment::query()->where('idempotency_key', $key)->first();
    }

    public function unmatchedForUpdate(string $invoiceNumber, Money $amount): Collection
    {
        return Payment::query()
            ->whereHas('invoice', fn ($query) => $query->where('number', $invoiceNumber))
            ->where('amount', $amount->minor)
            ->whereDoesntHave('bankTransaction')
            ->lockForUpdate()
            ->get();
    }

    public function forInvoice(Invoice $invoice): Collection
    {
        return $invoice->payments()->with('bankTransaction')->orderBy('paid_on')->get();
    }

    public function create(Invoice $invoice, array $attributes): Payment
    {
        return $invoice->payments()->create($attributes);
    }
}
