<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceActions;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Repositories\Contracts\InvoiceRepositoryInterface;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return app(InvoiceRepositoryInterface::class)->findWithDetails((int) $key);
    }

    protected function getHeaderActions(): array
    {
        return [
            InvoiceActions::issue(),
            InvoiceActions::recordPayment(),
            InvoiceActions::void(),
            EditAction::make(),
        ];
    }
}
