<?php

namespace App\Http\Requests\Api\V1;

use App\Support\TokenAbility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IssueTokenRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
            // Defaults to everything the user's role allows. Ask for ["ledger:read"] to get a read-only token.
            'abilities' => ['sometimes', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in(TokenAbility::all())],
        ];
    }

    /**
     * @return list<string>|null
     */
    public function abilities(): ?array
    {
        return $this->has('abilities') ? array_values(array_unique($this->input('abilities'))) : null;
    }
}
