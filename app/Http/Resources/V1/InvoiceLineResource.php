<?php

namespace App\Http\Resources\V1;

use App\Models\InvoiceLine;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InvoiceLine
 */
class InvoiceLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_price' => Money::of($this->unit_price),
            'tax_rate_bp' => $this->tax_rate_bp,
            'net_amount' => Money::of($this->line_subtotal),
            'tax_amount' => Money::of($this->line_tax),
        ];
    }
}
