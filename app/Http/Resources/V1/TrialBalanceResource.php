<?php

namespace App\Http\Resources\V1;

use App\Reports\TrialBalance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property TrialBalance $resource
 */
class TrialBalanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'as_of' => $this->resource->asOf?->toDateString(),
            'balanced' => $this->resource->isBalanced(),
            'total_debit' => $this->resource->totalDebit,
            'total_credit' => $this->resource->totalCredit,
            'accounts' => $this->resource->rows->map(fn (array $row) => [
                'code' => $row['code'],
                'name' => $row['name'],
                'type' => $row['type'],
                'debit' => $row['debit'],
                'credit' => $row['credit'],
            ])->values(),
        ];
    }
}
