<?php

namespace App\Filament\Resources\Invoices;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Support\Money;
use Closure;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Invoice workflow actions. The UI only collects input; every state change
 * goes through InvoiceService / PaymentService, the same code the tests cover.
 */
class InvoiceActions
{
    public static function issue(): Action
    {
        return Action::make('issue')
            ->icon('heroicon-o-paper-airplane')
            ->visible(fn (Invoice $record) => $record->status === InvoiceStatus::Draft)
            ->requiresConfirmation()
            ->modalDescription('Issuing assigns an invoice number and posts the invoice to the ledger. It can no longer be edited after this.')
            ->action(self::guarded(fn (Invoice $record) => app(InvoiceService::class)->issue($record), 'Invoice issued'));
    }

    public static function recordPayment(): Action
    {
        return Action::make('recordPayment')
            ->label('Record payment')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->visible(fn (Invoice $record) => $record->status->acceptsPayments())
            ->schema(fn (Invoice $record) => [
                TextInput::make('amount')
                    ->required()
                    ->regex('/^\d+(\.\d{1,2})?$/')
                    ->default($record->outstanding()->toDecimal())
                    ->helperText("Outstanding: {$record->outstanding()}"),
                DatePicker::make('paid_on')
                    ->required()
                    ->default(today()),
                Select::make('method')
                    ->options(['bank_transfer' => 'Bank transfer', 'card' => 'Card', 'cash' => 'Cash'])
                    ->default('bank_transfer')
                    ->required(),
                TextInput::make('reference')
                    ->maxLength(100),
                // Generated when the modal opens, so a double submit records one payment.
                Hidden::make('idempotency_key')
                    ->default(fn () => (string) Str::uuid()),
            ])
            ->action(self::guarded(fn (Invoice $record, array $data) => app(PaymentService::class)->record(
                $record,
                Money::parse($data['amount']),
                Carbon::parse($data['paid_on']),
                $data['method'],
                $data['idempotency_key'],
                $data['reference'] ?? null,
            ), 'Payment recorded'));
    }

    public static function void(): Action
    {
        return Action::make('void')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Invoice $record) => $record->status === InvoiceStatus::Issued && $record->amount_paid->isZero())
            ->requiresConfirmation()
            ->modalDescription('A reversing journal entry will cancel the original posting. The invoice number stays used.')
            ->action(self::guarded(fn (Invoice $record) => app(InvoiceService::class)->void($record), 'Invoice voided'));
    }

    /**
     * Turn business-rule violations into a notification instead of an error page.
     */
    private static function guarded(Closure $callback, string $success): Closure
    {
        return function (Invoice $record, Action $action, array $data = []) use ($callback, $success) {
            try {
                $callback($record, $data);
            } catch (DomainException $e) {
                Notification::make()->danger()->title($e->getMessage())->send();
                $action->halt();
            }

            $record->refresh();
            Notification::make()->success()->title($success)->send();
        };
    }
}
