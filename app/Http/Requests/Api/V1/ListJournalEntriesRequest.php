<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\Paginates;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class ListJournalEntriesRequest extends FormRequest
{
    use Paginates;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'account_id' => ['sometimes', 'integer'],
            ...$this->paginationRules(),
        ];
    }

    public function from(): ?Carbon
    {
        return $this->date('from', 'Y-m-d');
    }

    public function to(): ?Carbon
    {
        return $this->date('to', 'Y-m-d');
    }

    public function accountId(): ?int
    {
        return $this->has('account_id') ? $this->integer('account_id') : null;
    }
}
