<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\Paginates;
use Illuminate\Foundation\Http\FormRequest;

class ListCustomersRequest extends FormRequest
{
    use Paginates;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:100'],
            ...$this->paginationRules(),
        ];
    }
}
