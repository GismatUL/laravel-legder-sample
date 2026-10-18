<?php

namespace App\Http\Requests\Api\V1;

use App\Data\InvoiceData;
use App\Rules\MoneyAmount;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create or replace a draft invoice. Lines are always sent in full:
 * an update replaces all lines, so there is no partial line merge to get wrong.
 */
class InvoiceRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'lines.*.unit_price' => ['required', new MoneyAmount],
            'lines.*.tax_rate_bp' => ['required', 'integer', 'in:0,1800'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.*.tax_rate_bp.in' => 'The VAT rate must be 0 or 1800 basis points (0% or 18%).',
        ];
    }

    public function toData(): InvoiceData
    {
        return InvoiceData::fromInput($this->validated());
    }
}
