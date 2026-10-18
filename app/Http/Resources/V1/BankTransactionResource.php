<?php

namespace App\Http\Resources\V1;

use App\Models\BankTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BankTransaction
 */
class BankTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booked_on' => $this->booked_on->toDateString(),
            'amount' => $this->amount,
            'reference' => $this->reference,
            'payment_id' => $this->payment_id,
            'invoice_number' => $this->whenLoaded('payment', fn () => $this->payment?->invoice->number),
            'reconciled_at' => $this->reconciled_at?->toIso8601String(),
        ];
    }
}
