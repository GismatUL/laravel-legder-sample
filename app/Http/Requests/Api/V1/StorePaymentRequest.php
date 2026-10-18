<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\PaymentMethod;
use App\Rules\MoneyAmount;
use App\Support\Money;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePaymentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', new MoneyAmount],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
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

    public function paymentMethod(): PaymentMethod
    {
        return $this->enum('method', PaymentMethod::class) ?? PaymentMethod::BankTransfer;
    }
}
