<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\KitchenTicketStatus;
use App\Exceptions\PermissionDeniedException;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\KitchenTicket;
use App\Services\KitchenTicketService;
use App\Services\PermissionService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/**
 * The kitchen screen works with tickets, not whole orders: each ticket is
 * one round of items (the original order, or items a waiter added later),
 * together with the order details the kitchen needs — table, order
 * number, waiter, notes. See App\Models\KitchenTicket.
 */
class KitchenTicketController extends Controller
{
    /** What a kitchen ticket response always carries. */
    private const WITH = ['items.modifiers', 'order.table', 'order.placedBy:id,name', 'order.customer:id,name'];

    public function __construct(
        private KitchenTicketService $tickets,
        private PermissionService $permissions,
    ) {
    }

    /**
     * ?status=PENDING,CONFIRMED,PREPARING,READY (the default: everything
     * still in the kitchen; add PICKED_UP for the last 12 hours of collected
     * tickets). Oldest first — the order the kitchen works in.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = KitchenTicket::query()->with(self::WITH);

        if (! $user->is_super_admin && ! $user->hasRestaurantWideAccess()) {
            $query->whereIn('branch_id', $user->accessibleBranchIds() ?: [0]);
        }

        if ($branchId = $request->query('branch_id')) {
            $query->where('branch_id', $branchId);
        }

        $statuses = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $request->query('status', 'PENDING,CONFIRMED,PREPARING,READY'))
        )));
        // Picked-up tickets are history — only the last few hours, so the
        // screen's "Picked up" tab stays short.
        $query->where(function ($q) use ($statuses) {
            $q->whereIn('status', array_values(array_diff($statuses, [KitchenTicketStatus::PICKED_UP->value])));
            if (in_array(KitchenTicketStatus::PICKED_UP->value, $statuses, true)) {
                $q->orWhere(fn ($w) => $w->where('status', KitchenTicketStatus::PICKED_UP->value)
                    ->where('picked_up_at', '>=', now()->subHours(12)));
            }
        });

        // The restaurant decides which kinds of orders reach the kitchen
        // (e.g. only dine-in, with takeaway and delivery handled elsewhere).
        $kitchenTypes = $user->restaurant?->settings?->kitchenOrderTypes();
        if ($kitchenTypes !== null && count($kitchenTypes) < 3) {
            $query->whereHas('order', fn ($o) => $o->whereIn('order_type', $kitchenTypes));
        }

        return ApiResponse::success($query->orderBy('created_at')->orderBy('id')->get());
    }

    /**
     * One step forward. The kitchen uses CONFIRMED / PREPARING / READY;
     * the waiter uses PICKED_UP when collecting the food.
     */
    public function updateStatus(Request $request, int $ticket)
    {
        $ticket = KitchenTicket::findOrFail($ticket);
        $this->assertBranchAccess($request, $ticket->branch_id);

        $data = $request->validate([
            'status' => ['required', 'in:CONFIRMED,PREPARING,READY,PICKED_UP'],
        ]);

        $before = $ticket->status->value;
        $updated = $this->tickets->advance($ticket, KitchenTicketStatus::from($data['status']), $request->user());

        $this->audit($request, $ticket, 'kitchen_ticket.status_changed', $before, $updated->status->value);

        return ApiResponse::success($updated->load(self::WITH));
    }

    /** The kitchen's undo: one step back, only until pickup. */
    public function undo(Request $request, int $ticket)
    {
        $ticket = KitchenTicket::findOrFail($ticket);
        $this->assertBranchAccess($request, $ticket->branch_id);

        $before = $ticket->status->value;
        $updated = $this->tickets->undo($ticket);

        $this->audit($request, $ticket, 'kitchen_ticket.status_undone', $before, $updated->status->value);

        return ApiResponse::success($updated->load(self::WITH));
    }

    private function audit(Request $request, KitchenTicket $ticket, string $action, string $old, string $new): void
    {
        AuditLog::create([
            'restaurant_id' => $ticket->restaurant_id,
            'branch_id' => $ticket->branch_id,
            'user_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => KitchenTicket::class,
            'subject_id' => $ticket->id,
            'changes' => ['old' => $old, 'new' => $new, 'order_id' => $ticket->order_id, 'sequence' => $ticket->sequence],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);
    }

    private function assertBranchAccess(Request $request, int $branchId): void
    {
        if (! $this->permissions->userCanAccessBranch($request->user(), $branchId)) {
            throw new PermissionDeniedException('You do not have access to this branch.');
        }
    }
}
