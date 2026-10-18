<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AuthTokenController;
use App\Http\Controllers\Api\V1\BankStatementController;
use App\Http\Controllers\Api\V1\BankTransactionController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\InvoicePaymentController;
use App\Http\Controllers\Api\V1\IssueInvoiceController;
use App\Http\Controllers\Api\V1\JournalEntryController;
use App\Http\Controllers\Api\V1\ReverseJournalEntryController;
use App\Http\Controllers\Api\V1\TrialBalanceController;
use App\Http\Controllers\Api\V1\VoidInvoiceController;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Support\TokenAbility;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('auth/tokens', [AuthTokenController::class, 'store'])
        ->middleware('throttle:api-auth')
        ->name('auth.tokens.store');

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::delete('auth/tokens/current', [AuthTokenController::class, 'destroy'])->name('auth.tokens.destroy');

        // Every role can read; see App\Enums\UserRole.
        Route::middleware('abilities:'.TokenAbility::READ)->group(function () {
            Route::apiResource('accounts', AccountController::class)->only(['index', 'show']);
            Route::apiResource('customers', CustomerController::class)->only(['index', 'show']);
            Route::apiResource('invoices', InvoiceController::class)->only(['index', 'show']);
            Route::get('invoices/{invoice}/payments', [InvoicePaymentController::class, 'index'])->name('invoices.payments.index');
            Route::apiResource('journal-entries', JournalEntryController::class)->only(['index', 'show']);
            Route::get('reports/trial-balance', TrialBalanceController::class)->name('reports.trial-balance');
            Route::get('bank-transactions', [BankTransactionController::class, 'index'])->name('bank-transactions.index');
        });

        // Two checks: the token must carry ledger:write, and the user's role
        // (checked by the policy on every request) must allow the action.
        Route::middleware('abilities:'.TokenAbility::WRITE)->group(function () {
            Route::post('accounts', [AccountController::class, 'store'])->name('accounts.store')->can('create', Account::class);
            Route::match(['put', 'patch'], 'accounts/{account}', [AccountController::class, 'update'])->name('accounts.update')->can('update', 'account');

            Route::post('customers', [CustomerController::class, 'store'])->name('customers.store')->can('create', Customer::class);
            Route::match(['put', 'patch'], 'customers/{customer}', [CustomerController::class, 'update'])->name('customers.update')->can('update', 'customer');

            Route::post('invoices', [InvoiceController::class, 'store'])->name('invoices.store')->can('create', Invoice::class);
            Route::match(['put', 'patch'], 'invoices/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update')->can('update', 'invoice');
            Route::delete('invoices/{invoice}', [InvoiceController::class, 'destroy'])->name('invoices.destroy')->can('delete', 'invoice');
            Route::post('invoices/{invoice}/issue', IssueInvoiceController::class)->name('invoices.issue')->can('issue', 'invoice');
            Route::post('invoices/{invoice}/void', VoidInvoiceController::class)->name('invoices.void')->can('void', 'invoice');
            Route::post('invoices/{invoice}/payments', [InvoicePaymentController::class, 'store'])->name('invoices.payments.store')->can('recordPayment', 'invoice');

            Route::post('journal-entries', [JournalEntryController::class, 'store'])->name('journal-entries.store')->can('create', JournalEntry::class);
            Route::post('journal-entries/{journal_entry}/reversal', ReverseJournalEntryController::class)->name('journal-entries.reversal')->can('reverse', 'journal_entry');

            Route::post('bank-statements', [BankStatementController::class, 'store'])->name('bank-statements.store')->can('create', BankTransaction::class);
            Route::post('bank-transactions/{bank_transaction}/match', [BankTransactionController::class, 'match'])->name('bank-transactions.match')->can('reconcile', 'bank_transaction');
        });
    });
});
