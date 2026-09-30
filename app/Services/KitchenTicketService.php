<?php

namespace App\Services;

use App\Enums\KitchenTicketStatus;
use App\Enums\OrderStatus;
use App\Exceptions\OrderValidationException;
use App\Models\KitchenTicket;
use App\Models\User;

/**
 * Moving kitchen tickets along: New -> Accepted -> Cooking -> Ready ->
 * Picked up, plus the kitchen's undo (one step back, until pickup).
 *
 * The main ticket (sequence 1) is the order's own kitchen progress, so
 * its moves up to READY go through OrderService::updateStatus() /
 * undoStatus() — the order's status, the waiter's tiles, table state and
 * the "order ready" notification all stay exactly as they were before
 * tickets existed, and OrderService moves the ticket to match. Add-on
 * tickets (sequence 2+) only ever change themselves: the order has
 * usually been served already, and its status stays put.
 */
class KitchenTicketService
{
    public function __construct(private OrderService $orders)
    {
    }

    public function advance(KitchenTicket $ticket, KitchenTicketStatus $next, User $by): KitchenTicket
    {
        if ($ticket->status->next() !== $next) {
            throw new OrderValidationException(
                "Cannot move this ticket from {$ticket->status->value} to {$next->value}."
            );
        }

        $order = $ticket->order;
        if ($order->status === OrderStatus::CANCELLED) {
            throw new OrderValidationException('This order has been cancelled.');
        }

        $orderStatus = $next->toOrderStatus();
        if ($ticket->isMain() && $orderStatus !== null && $order->status !== $orderStatus) {
            // Moves the ticket too (OrderService keeps ticket 1 in step).
            $this->orders->updateStatus($order, $orderStatus);
            $ticket->refresh();
        }

        if ($ticket->status !== $next) {
            $ticket->moveTo($next, $by);
        }

        return $ticket->fresh();
    }

    public function undo(KitchenTicket $ticket): KitchenTicket
    {
        $previous = $ticket->status->previous();

        if ($previous === null) {
            throw new OrderValidationException(match ($ticket->status) {
                KitchenTicketStatus::PENDING => 'This ticket has not moved yet, so there is nothing to undo.',
                KitchenTicketStatus::PICKED_UP => 'This ticket has already been picked up and can no longer be moved back.',
                default => 'This ticket has been cancelled.',
            });
        }

        $order = $ticket->order;
        if ($ticket->isMain() && $order->status->value === $ticket->status->value) {
            // Moves the ticket back too.
            $this->orders->undoStatus($order);
            $ticket->refresh();
        }

        if ($ticket->status !== $previous) {
            $ticket->moveTo($previous);
        }

        return $ticket->fresh();
    }
}
