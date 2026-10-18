<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ImportBankStatementRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'statement' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ];
    }
}
