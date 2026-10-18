<?php

namespace App\Http\Requests\Api\V1;

use App\Data\ManualEntryLineData;
use App\Rules\MoneyAmount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Shape validation only. Whether the entry balances is a business rule,
 * checked by LedgerService so the API and admin panel can't disagree.
 */
class StoreJournalEntryRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2', 'max:100'],
            'lines.*.account_id' => ['required', 'integer', 'exists:accounts,id'],
            'lines.*.debit' => ['nullable', new MoneyAmount, 'required_without:lines.*.credit'],
            'lines.*.credit' => ['nullable', new MoneyAmount, 'required_without:lines.*.debit'],
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function entryDate(): Carbon
    {
        return Carbon::parse($this->string('entry_date')->toString());
    }

    /**
     * @return list<ManualEntryLineData>
     */
    public function lines(): array
    {
        return array_map(ManualEntryLineData::fromInput(...), array_values($this->validated('lines')));
    }
}
