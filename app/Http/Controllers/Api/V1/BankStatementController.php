<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ImportBankStatementRequest;
use App\Services\BankStatementImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * @tags Bank reconciliation
 */
class BankStatementController extends Controller
{
    /**
     * Import a bank statement.
     *
     * Multipart upload of a CSV with the columns `date,amount,reference`.
     * Rows already imported are skipped. Matching against payments runs in
     * a queued job, so the response is 202 Accepted.
     */
    public function store(ImportBankStatementRequest $request, BankStatementImporter $importer): JsonResponse
    {
        try {
            $imported = $importer->import((string) $request->file('statement')?->getRealPath());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['statement' => $e->getMessage()]);
        }

        return response()->json([
            'imported' => $imported,
            'reconciliation_queued' => $imported > 0,
        ], Response::HTTP_ACCEPTED);
    }
}
