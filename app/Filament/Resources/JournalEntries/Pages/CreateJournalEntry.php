<?php

namespace App\Filament\Resources\JournalEntries\Pages;

use App\Data\EntryLine;
use App\Data\NewJournalEntry;
use App\Exceptions\LedgerException;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Models\Account;
use App\Services\LedgerService;
use App\Support\Money;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class CreateJournalEntry extends CreateRecord
{
    protected static string $resource = JournalEntryResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        $accounts = Account::query()->findMany(array_column($data['lines'], 'account_id'))->keyBy('id');

        $lines = array_map(fn (array $line) => new EntryLine(
            $accounts[$line['account_id']],
            debit: Money::parse($line['debit'] ?: '0')->minor,
            credit: Money::parse($line['credit'] ?: '0')->minor,
            memo: $line['memo'] ?: null,
        ), array_values($data['lines']));

        try {
            return app(LedgerService::class)->post(new NewJournalEntry(
                date: Carbon::parse($data['entry_date']),
                description: $data['description'],
                lines: $lines,
            ));
        } catch (LedgerException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $this->halt(shouldRollbackDatabaseTransaction: true);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
