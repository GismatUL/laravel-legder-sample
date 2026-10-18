<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\Paginates;
use Illuminate\Foundation\Http\FormRequest;

class ListBankTransactionsRequest extends FormRequest
{
    use Paginates;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reconciled' => ['sometimes', 'boolean'],
            ...$this->paginationRules(),
        ];
    }

    public function reconciled(): ?bool
    {
        return $this->has('reconciled') ? $this->boolean('reconciled') : null;
    }
}
