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
use App\Support\TokenAbility;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('auth/tokens', [AuthTokenController::class, 'store'])
        ->middleware('throttle:api-auth')
        ->name('auth.tokens.store');

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::delete('auth/tokens/current', [AuthTokenController::class, 'destroy'])->name('auth.tokens.destroy');

        Route::middleware('abilities:'.TokenAbility::READ)->group(function () {
            Route::apiResource('accounts', AccountController::class)->only(['index', 'show']);
            Route::apiResource('customers', CustomerController::class)->only(['index', 'show']);
            Route::apiResource('invoices', InvoiceController::class)->only(['index', 'show']);
            Route::get('invoices/{invoice}/payments', [InvoicePaymentController::class, 'index'])->name('invoices.payments.index');
            Route::apiResource('journal-entries', JournalEntryController::class)->only(['index', 'show']);
            Route::get('reports/trial-balance', TrialBalanceController::class)->name('reports.trial-balance');
            Route::get('bank-transactions', [BankTransactionController::class, 'index'])->name('bank-transactions.index');
        });

        Route::middleware('abilities:'.TokenAbility::WRITE)->group(function () {
            Route::apiResource('accounts', AccountController::class)->only(['store', 'update']);
            Route::apiResource('customers', CustomerController::class)->only(['store', 'update']);
            Route::apiResource('invoices', InvoiceController::class)->only(['store', 'update', 'destroy']);
            Route::post('invoices/{invoice}/issue', IssueInvoiceController::class)->name('invoices.issue');
            Route::post('invoices/{invoice}/void', VoidInvoiceController::class)->name('invoices.void');
            Route::post('invoices/{invoice}/payments', [InvoicePaymentController::class, 'store'])->name('invoices.payments.store');
            Route::post('journal-entries', [JournalEntryController::class, 'store'])->name('journal-entries.store');
            Route::post('journal-entries/{journal_entry}/reversal', ReverseJournalEntryController::class)->name('journal-entries.reversal');
            Route::post('bank-statements', [BankStatementController::class, 'store'])->name('bank-statements.store');
        });
    });
});
