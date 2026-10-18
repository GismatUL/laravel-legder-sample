<?php

/**
 * Child process for Tests\Feature\MySql\ConcurrencyTest.
 *
 * Boots the application, waits until a shared start time so that all
 * workers hit the database at the same moment, runs one operation and
 * prints the result as JSON. Usage:
 *
 *   php worker.php <start-at-unix-float> <operation> <json-args>
 */

use App\Data\ManualEntryLineData;
use App\Enums\PaymentMethod;
use App\Exceptions\DomainRuleException;
use App\Models\Invoice;
use App\Services\InvoiceService;
use App\Services\LedgerService;
use App\Services\PaymentService;
use App\Support\Money;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $startAt, $operation, $json] = $argv;
/** @var array<string, mixed> $args */
$args = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

// Connect before the barrier, so connection setup doesn't spread the start times.
DB::connection()->getPdo();

while (microtime(true) < (float) $startAt) {
    usleep(1000);
}

try {
    $result = match ($operation) {
        'pay' => app(PaymentService::class)->record(
            Invoice::query()->findOrFail((int) $args['invoice_id']),
            Money::parse((string) $args['amount']),
            today(),
            PaymentMethod::BankTransfer,
            (string) $args['key'],
        )->getKey(),
        'issue' => app(InvoiceService::class)->issue(Invoice::query()->findOrFail((int) $args['invoice_id']))->number,
        'post_manual' => app(LedgerService::class)->postManual(today(), 'Concurrent entry', [
            new ManualEntryLineData((int) $args['debit_account_id'], Money::parse('10.00'), Money::zero()),
            new ManualEntryLineData((int) $args['credit_account_id'], Money::zero(), Money::parse('10.00')),
        ], (string) $args['key'])->getKey(),
        default => throw new InvalidArgumentException("Unknown operation {$operation}"),
    };

    echo json_encode(['ok' => true, 'result' => $result]);
} catch (DomainRuleException $e) {
    echo json_encode(['ok' => false, 'code' => $e->errorCode]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'code' => 'exception', 'class' => $e::class, 'message' => $e->getMessage()]);
}
