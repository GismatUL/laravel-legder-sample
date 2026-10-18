<?php

namespace App\Http\Resources\V1;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'amount' => $this->amount,
            'paid_on' => $this->paid_on->toDateString(),
            'method' => $this->method,
            'reference' => $this->reference,
            'journal_entry_id' => $this->journal_entry_id,
            'reconciled' => $this->when(
                $this->resource->relationLoaded('bankTransaction'),
                fn () => $this->bankTransaction !== null,
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
