<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Data\InvoiceData;
use App\Exceptions\DomainRuleException;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Services\InvoiceService;
use App\Support\Money;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/**
 * @property Invoice $record
 */
class EditInvoice extends EditRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->using(fn (Invoice $record) => app(InvoiceService::class)->deleteDraft($record)),
        ];
    }

    /**
     * Only the fields the form edits. The model's Money attributes can't be
     * Livewire state, and totals are recalculated by InvoiceService anyway.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [
            'customer_id' => $this->record->customer_id,
            'due_date' => $this->record->due_date?->toDateString(),
            'notes' => $this->record->notes,
            'lines' => $this->record->lines->map(fn (InvoiceLine $line) => [
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_price' => Money::of($line->unit_price)->toDecimal(),
                'tax_rate_bp' => $line->tax_rate_bp,
            ])->all(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Invoice $record */
        try {
            return app(InvoiceService::class)->updateDraft($record, InvoiceData::fromInput($data));
        } catch (DomainRuleException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
