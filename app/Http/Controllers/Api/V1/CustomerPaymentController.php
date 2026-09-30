<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/**
 * A customer paying for their own order — online only; a customer never
 * hands staff cash or a card to record themselves, so this never accepts
 * a `method` field the way PaymentController does. Reuses the exact same
 * PaymentService as the staff pipeline, just with no `$recordedBy` (no
 * staff member recorded this — the customer initiated it themselves).
 */
class CustomerPaymentController extends Controller
{
    public function __construct(private PaymentService $payments)
    {
    }

    public function index(Request $request, int $order)
    {
        $order = Order::where('customer_id', $request->user()->id)->findOrFail($order);

        return ApiResponse::success($order->payments()->latest()->get());
    }

    public function store(Request $request, int $order)
    {
        $order = Order::where('customer_id', $request->user()->id)->findOrFail($order);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);
        $data['method'] = 'ONLINE';

        $payment = $this->payments->record($order, $data);

        return ApiResponse::success($payment, 201);
    }

    /**
     * A customer can only confirm a payment on their own order — the
     * tenant scope alone isn't enough (another customer at the same
     * restaurant would otherwise be able to confirm it too).
     */
    public function confirm(Request $request, int $payment)
    {
        $payment = Payment::findOrFail($payment);
        Order::where('customer_id', $request->user()->id)->findOrFail($payment->order_id);

        $updated = $this->payments->confirm($payment);

        return ApiResponse::success($updated);
    }
}
