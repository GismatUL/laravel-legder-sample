<?php

namespace App\Http\Requests\Api\V1;

use App\Data\InvoiceFilters;
use App\Enums\InvoiceStatus;
use App\Http\Requests\Api\V1\Concerns\Paginates;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListInvoicesRequest extends FormRequest
{
    use Paginates;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(InvoiceStatus::class)],
            'customer_id' => ['sometimes', 'integer'],
            'number' => ['sometimes', 'string', 'max:30'],
            'overdue' => ['sometimes', 'boolean'],
            ...$this->paginationRules(),
        ];
    }

    public function filters(): InvoiceFilters
    {
        return new InvoiceFilters(
            status: $this->enum('status', InvoiceStatus::class),
            customerId: $this->has('customer_id') ? $this->integer('customer_id') : null,
            overdueOnly: $this->boolean('overdue'),
            number: $this->string('number')->toString() ?: null,
        );
    }
}
