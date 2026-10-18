<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePaymentRequest;
use App\Http\Resources\V1\PaymentResource;
use App\Models\Invoice;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Services\PaymentService;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * @tags Payments
 */
class InvoicePaymentController extends Controller
{
    public function index(Invoice $invoice, PaymentRepositoryInterface $payments): AnonymousResourceCollection
    {
        return PaymentResource::collection($payments->forInvoice($invoice));
    }

    /**
     * Record a payment.
     *
     * Requires an `Idempotency-Key` header (e.g. the payment provider's
     * transaction id). The first request returns 201. A retry with the same
     * key and amount returns the same payment with 200 and the header
     * `Idempotent-Replayed: true`. The same key with a different amount is 409.
     */
    #[HeaderParameter('Idempotency-Key', 'Unique per payment, e.g. the payment provider transaction id.', required: true, type: 'string', example: 'psp-tx-8f3a91')]
    public function store(StorePaymentRequest $request, Invoice $invoice, PaymentService $service): JsonResponse
    {
        $payment = $service->record(
            $invoice,
            $request->amount(),
            $request->paidOn(),
            $request->paymentMethod(),
            $request->idempotencyKey(),
            $request->input('reference'),
        );

        $replayed = ! $payment->wasRecentlyCreated;

        return PaymentResource::make($payment)
            ->response()
            ->setStatusCode($replayed ? Response::HTTP_OK : Response::HTTP_CREATED)
            ->header('Idempotent-Replayed', $replayed ? 'true' : 'false');
    }
}
