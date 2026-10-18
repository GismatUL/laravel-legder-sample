<?php

namespace App\Providers;

use App\Repositories\Contracts\AccountRepositoryInterface;
use App\Repositories\Contracts\BankTransactionRepositoryInterface;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Repositories\Contracts\InvoiceRepositoryInterface;
use App\Repositories\Contracts\JournalEntryRepositoryInterface;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Eloquent\EloquentAccountRepository;
use App\Repositories\Eloquent\EloquentBankTransactionRepository;
use App\Repositories\Eloquent\EloquentCustomerRepository;
use App\Repositories\Eloquent\EloquentInvoiceRepository;
use App\Repositories\Eloquent\EloquentJournalEntryRepository;
use App\Repositories\Eloquent\EloquentPaymentRepository;
use App\Repositories\Eloquent\EloquentUserRepository;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        AccountRepositoryInterface::class => EloquentAccountRepository::class,
        BankTransactionRepositoryInterface::class => EloquentBankTransactionRepository::class,
        CustomerRepositoryInterface::class => EloquentCustomerRepository::class,
        InvoiceRepositoryInterface::class => EloquentInvoiceRepository::class,
        JournalEntryRepositoryInterface::class => EloquentJournalEntryRepository::class,
        PaymentRepositoryInterface::class => EloquentPaymentRepository::class,
        UserRepositoryInterface::class => EloquentUserRepository::class,
    ];
}
