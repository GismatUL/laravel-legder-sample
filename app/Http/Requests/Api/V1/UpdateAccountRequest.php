<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AccountType;
use App\Models\Account;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Account $account */
        $account = $this->route('account');

        return [
            'code' => ['sometimes', 'string', 'max:20', Rule::unique('accounts', 'code')->ignore($account)],
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', Rule::enum(AccountType::class)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
