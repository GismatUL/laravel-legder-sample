<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AccountType;
use App\Http\Requests\Api\V1\Concerns\Paginates;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAccountsRequest extends FormRequest
{
    use Paginates;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['sometimes', Rule::enum(AccountType::class)],
            'active' => ['sometimes', 'boolean'],
            ...$this->paginationRules(),
        ];
    }

    public function type(): ?AccountType
    {
        return $this->enum('type', AccountType::class);
    }

    public function active(): ?bool
    {
        return $this->has('active') ? $this->boolean('active') : null;
    }
}
