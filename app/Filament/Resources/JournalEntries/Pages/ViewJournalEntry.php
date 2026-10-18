<?php

namespace App\Filament\Resources\JournalEntries\Pages;

use App\Exceptions\LedgerException;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Models\JournalEntry;
use App\Repositories\Contracts\JournalEntryRepositoryInterface;
use App\Services\LedgerService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class ViewJournalEntry extends ViewRecord
{
    protected static string $resource = JournalEntryResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return app(JournalEntryRepositoryInterface::class)->findWithDetails((int) $key);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reverse')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->authorize('reverse')
                // Hidden where LedgerService::reverseManual() would refuse anyway.
                ->visible(fn (JournalEntry $record) => $record->source_type === null
                    && $record->reversal_of_id === null
                    && ! $record->reversedBy()->exists())
                ->requiresConfirmation()
                ->modalDescription('A new entry with the opposite amounts will be posted today. The original stays in the ledger.')
                ->action(function (JournalEntry $record) {
                    try {
                        $reversal = app(LedgerService::class)->reverseManual($record, today());
                    } catch (LedgerException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        throw new Halt;
                    }

                    Notification::make()->success()->title("Posted {$reversal->reference}")->send();
                    $this->redirect(JournalEntryResource::getUrl('view', ['record' => $reversal]));
                }),
        ];
    }
}
