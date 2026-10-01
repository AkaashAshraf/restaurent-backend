<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PermissionDeniedException;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Services\PermissionService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Staff-side payment recording/confirmation/refund for an order. CASH and
 * CARD are recorded and settle in one call (a staff member is physically
 * handling the money/terminal right now); ONLINE is additionally gated
 * on the ONLINE_PAYMENTS feature inside PaymentService (not here — the
 * method is a body field on one shared endpoint, not a separate route).
 */
class PaymentController extends Controller
{
    public function __construct(
        private PaymentService $payments,
        private PermissionService $permissions,
    ) {
    }

    public function index(Request $request, int $order)
    {
        $order = Order::findOrFail($order);
        $this->assertBranchAccess($request, $order->branch_id);

        return ApiResponse::success($order->payments()->latest()->get());
    }

    public function store(Request $request, int $order)
    {
        $order = Order::findOrFail($order);
        $this->assertBranchAccess($request, $order->branch_id);

        $data = $request->validate([
            'method' => ['required', Rule::in(['CASH', 'CARD', 'ONLINE'])],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'transaction_reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
        ]);

        $payment = $this->payments->record($order, $data, $request->user());

        AuditLog::create([
            'restaurant_id' => $order->restaurant_id,
            'branch_id' => $order->branch_id,
            'user_id' => $request->user()->id,
            'action' => 'payment.recorded',
            'subject_type' => Payment::class,
            'subject_id' => $payment->id,
            'changes' => ['new' => [
                'method' => $data['method'], 'amount' => (string) $payment->amount, 'status' => $payment->status->value,
            ]],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success($payment, 201);
    }

    public function confirm(Request $request, int $payment)
    {
        $payment = Payment::findOrFail($payment);
        $this->assertBranchAccess($request, $payment->branch_id);

        $updated = $this->payments->confirm($payment);

        AuditLog::create([
            'restaurant_id' => $updated->restaurant_id,
            'branch_id' => $updated->branch_id,
            'user_id' => $request->user()->id,
            'action' => 'payment.confirmed',
            'subject_type' => Payment::class,
            'subject_id' => $updated->id,
            'changes' => ['old' => 'PENDING', 'new' => 'PAID'],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success($updated);
    }

    public function refund(Request $request, int $payment)
    {
        $payment = Payment::findOrFail($payment);
        $this->assertBranchAccess($request, $payment->branch_id);

        $updated = $this->payments->refund($payment);

        AuditLog::create([
            'restaurant_id' => $updated->restaurant_id,
            'branch_id' => $updated->branch_id,
            'user_id' => $request->user()->id,
            'action' => 'payment.refunded',
            'subject_type' => Payment::class,
            'subject_id' => $updated->id,
            'changes' => ['old' => 'PAID', 'new' => 'REFUNDED'],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success($updated);
    }

    /**
     * Same check `EnsureBranchAccess` performs, applied manually — the
     * route parameter here is an order or payment id, not a branch id.
     */
    private function assertBranchAccess(Request $request, int $branchId): void
    {
        if (! $this->permissions->userCanAccessBranch($request->user(), $branchId)) {
            throw new PermissionDeniedException('You do not have access to this branch.');
        }
    }
}
