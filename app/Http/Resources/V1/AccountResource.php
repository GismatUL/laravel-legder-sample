<?php

namespace App\Http\Resources\V1;

use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Account
 */
class AccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'is_active' => $this->is_active,
            // Only present when the repository loaded the debit/credit totals.
            'balance' => $this->when(
                array_key_exists('lines_sum_debit', $this->resource->getAttributes()),
                fn () => $this->resource->loadedBalance(),
            ),
        ];
    }
}
