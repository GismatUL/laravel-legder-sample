<?php

namespace App\Http\Resources\V1;

use App\Models\JournalLine;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin JournalLine
 */
class JournalLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'account' => [
                'id' => $this->account_id,
                'code' => $this->whenLoaded('account', fn () => $this->account->code),
                'name' => $this->whenLoaded('account', fn () => $this->account->name),
            ],
            'debit' => Money::of($this->debit),
            'credit' => Money::of($this->credit),
            'memo' => $this->memo,
        ];
    }
}
