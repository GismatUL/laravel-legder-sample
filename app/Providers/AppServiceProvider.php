<?php

namespace App\Providers;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Reports\ReportCache;
use App\Support\OpenApi\MoneyToSchema;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ReportCache::class, fn ($app) => new ReportCache(
            $app['cache']->store(),
            (int) config('ledger.report_cache_seconds'),
        ));
    }

    public function boot(): void
    {
        // Outside production: fail on lazy loading (N+1), silently discarded
        // attributes and missing attributes, instead of finding them later.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Store "invoice" instead of a PHP class name in polymorphic columns.
        Relation::enforceMorphMap([
            'account' => Account::class,
            'bank_transaction' => BankTransaction::class,
            'customer' => Customer::class,
            'invoice' => Invoice::class,
            'user' => User::class,
        ]);

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        // Slows down password guessing on the token endpoint.
        RateLimiter::for('api-auth', fn (Request $request) => Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()));

        Scramble::registerExtension(MoneyToSchema::class);

        Scramble::configure()
            ->routes(fn ($route) => str_starts_with($route->uri, 'api/v1'))
            ->withDocumentTransformers(function (OpenApi $openApi) {
                $openApi->secure(SecurityScheme::http('bearer'));
            });

        // API docs are public in local development only.
        Gate::define('viewApiDocs', fn (?User $user) => $this->app->environment('local'));
    }
}
