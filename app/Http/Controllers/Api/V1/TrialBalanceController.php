<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TrialBalanceRequest;
use App\Http\Resources\V1\TrialBalanceResource;
use App\Services\ReportService;

/**
 * @tags Reports
 */
class TrialBalanceController extends Controller
{
    /**
     * Trial balance.
     *
     * Net balance of every account with postings, up to `as_of` (inclusive).
     */
    public function __invoke(TrialBalanceRequest $request, ReportService $reports): TrialBalanceResource
    {
        return TrialBalanceResource::make($reports->trialBalance($request->asOf()));
    }
}
