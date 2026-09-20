<?php

namespace App\Http\Controllers;

use App\Repository\PaymentRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Resources\PaymentResource;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentRepository $paymentRepository
    ) {}

    /**
     * GET /api/admin/payments
     * Admin — view global payment history across all users.
     */
    public function index(Request $request): JsonResponse
    {
        $payments = $this->paymentRepository->getAllPayments($request->input('per_page', 50));

        return response()->json([
            'success' => true,
            'history' => $payments->through(fn ($payment) => new PaymentResource($payment)),
        ]);
    }
}
