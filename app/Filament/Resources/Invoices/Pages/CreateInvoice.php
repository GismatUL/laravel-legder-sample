<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Data\InvoiceData;
use App\Exceptions\DomainRuleException;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Services\InvoiceService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(InvoiceService::class)->createDraft(InvoiceData::fromInput($data));
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
