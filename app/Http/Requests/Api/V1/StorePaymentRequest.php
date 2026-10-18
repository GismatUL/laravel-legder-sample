<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Money;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

class StorePaymentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'string', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'method' => ['required', 'string', 'in:bank_transfer,card,cash'],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * The Idempotency-Key is a header, not a body field, so it's checked here.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $key = $this->idempotencyKey();

                if ($key === '' || mb_strlen($key) > 100) {
                    $validator->errors()->add('idempotency_key', 'The Idempotency-Key header is required (max 100 characters).');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.regex' => 'The amount must be a decimal string with up to 2 decimals, e.g. "118.00".',
        ];
    }

    public function idempotencyKey(): string
    {
        return trim((string) $this->header('Idempotency-Key'));
    }

    public function amount(): Money
    {
        return Money::parse($this->string('amount')->toString());
    }

    public function paidOn(): Carbon
    {
        return Carbon::parse($this->string('paid_on')->toString());
    }
}
