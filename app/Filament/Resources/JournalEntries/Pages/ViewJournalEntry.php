<?php

namespace App\Filament\Resources\JournalEntries\Pages;

use App\Exceptions\LedgerException;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Models\JournalEntry;
use App\Services\LedgerService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewJournalEntry extends ViewRecord
{
    protected static string $resource = JournalEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reverse')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                // Entries created by invoices and payments are reversed through
                // their own workflow (e.g. voiding the invoice), so the documents stay in sync.
                ->visible(fn (JournalEntry $record) => $record->source_type === null
                    && $record->reversal_of_id === null
                    && ! $record->reversedBy()->exists())
                ->requiresConfirmation()
                ->modalDescription('A new entry with the opposite amounts will be posted today. The original stays in the ledger.')
                ->action(function (JournalEntry $record, Action $action) {
                    try {
                        $reversal = app(LedgerService::class)->reverse($record, today());
                    } catch (LedgerException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                        $action->halt();
                    }

                    Notification::make()->success()->title("Posted {$reversal->reference}")->send();
                    $this->redirect(JournalEntryResource::getUrl('view', ['record' => $reversal]));
                }),
        ];
    }
}
