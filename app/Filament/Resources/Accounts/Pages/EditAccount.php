<?php

namespace App\Filament\Resources\Accounts\Pages;

use App\Exceptions\DomainRuleException;
use App\Filament\Resources\Accounts\AccountResource;
use App\Models\Account;
use App\Services\AccountService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditAccount extends EditRecord
{
    protected static string $resource = AccountResource::class;

    /**
     * Same write path as the API, so the "code and type are locked once the
     * account has postings" rule doesn't depend on the form disabling fields.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Account $record */
        try {
            return app(AccountService::class)->update($record, $data);
        } catch (DomainRuleException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }
}
