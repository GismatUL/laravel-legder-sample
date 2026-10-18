<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class TrialBalanceRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'as_of' => ['sometimes', 'date_format:Y-m-d'],
        ];
    }

    public function asOf(): ?Carbon
    {
        return $this->date('as_of', 'Y-m-d');
    }
}
