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
            // CSV with the columns: date, amount, reference. Max 2 MB.
            'statement' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ];
    }
}
