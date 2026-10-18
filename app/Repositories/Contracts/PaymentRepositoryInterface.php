<?php

namespace App\Repositories\Contracts;

use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Support\Collection;

interface PaymentRepositoryInterface
{
    public function findByIdempotencyKey(string $key): ?Payment;

    /**
     * Payments on the invoice with this number and exact amount that are
     * not matched to a bank transaction yet, locked for update.
     *
     * @return Collection<int, Payment>
     */
    public function unmatchedForUpdate(string $invoiceNumber, Money $amount): Collection;

    /**
     * @return Collection<int, Payment>
     */
    public function forInvoice(Invoice $invoice): Collection;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(Invoice $invoice, array $attributes): Payment;
}
