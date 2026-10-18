<?php

namespace App\Filament\Resources\BankTransactions\Pages;

use App\Filament\Resources\BankTransactions\BankTransactionResource;
use App\Jobs\ReconcileBankTransactions;
use App\Services\BankStatementImporter;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class ListBankTransactions extends ListRecords
{
    protected static string $resource = BankTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Hidden actions are also refused server-side by Filament.
            Action::make('import')
                ->label('Import statement')
                ->icon('heroicon-o-arrow-up-tray')
                ->visible(fn () => BankTransactionResource::canCreate())
                ->schema([
                    FileUpload::make('statement')
                        ->helperText('CSV with the columns: date, amount, reference')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                        ->disk('local')
                        ->directory('statements')
                        ->required(),
                ])
                ->action(function (array $data) {
                    $path = Storage::disk('local')->path($data['statement']);

                    try {
                        $count = app(BankStatementImporter::class)->import($path);

                        Notification::make()->success()
                            ->title($count > 0 ? "Imported {$count} transactions" : 'Nothing new in this statement')
                            ->body($count > 0 ? 'Automatic matching is running in the background.' : null)
                            ->send();
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        throw new Halt;
                    } finally {
                        Storage::disk('local')->delete($data['statement']);
                    }
                }),
            Action::make('reconcile')
                ->label('Run matching')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn () => BankTransactionResource::canCreate())
                ->action(function () {
                    ReconcileBankTransactions::dispatch();

                    Notification::make()->success()->title('Matching queued')->send();
                }),
        ];
    }
}
