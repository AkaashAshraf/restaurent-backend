<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderReview;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Ratings and reviews. A customer rates a completed order once (they can edit it
 * afterwards): the food, the rider (delivery orders) and the app. The
 * restaurant's staff read them back with the averages.
 */
class ReviewController extends Controller
{
    // ------------------------------------------------------------ customer

    /** POST /customer/orders/{order}/review — create or update this order's review. */
    public function store(Request $request, int $order)
    {
        $order = Order::where('customer_id', $request->user()->id)->findOrFail($order);

        if ($order->status !== OrderStatus::COMPLETED) {
            throw ValidationException::withMessages(['order' => 'You can review an order once it is completed.']);
        }

        $data = $request->validate([
            'food_rating' => ['nullable', 'integer', 'between:1,5'],
            'food_review' => ['nullable', 'string', 'max:1000'],
            'rider_rating' => ['nullable', 'integer', 'between:1,5'],
            'rider_review' => ['nullable', 'string', 'max:1000'],
            'app_rating' => ['nullable', 'integer', 'between:1,5'],
            'app_review' => ['nullable', 'string', 'max:1000'],
        ]);

        // Only a delivery has a rider to rate.
        if ($order->order_type !== OrderType::DELIVERY) {
            $data['rider_rating'] = null;
            $data['rider_review'] = null;
        }

        if (empty($data['food_rating']) && empty($data['rider_rating']) && empty($data['app_rating'])) {
            throw ValidationException::withMessages(['food_rating' => 'Please give at least one star rating.']);
        }

        $review = OrderReview::updateOrCreate(
            ['order_id' => $order->id],
            $data + [
                'restaurant_id' => $order->restaurant_id,
                'customer_id' => $order->customer_id,
                'branch_id' => $order->branch_id,
                'rider_id' => $order->assigned_rider_id,
            ]
        );

        return ApiResponse::success($review, $review->wasRecentlyCreated ? 201 : 200);
    }

    // --------------------------------------------------------------- staff

    /** GET /reviews?branch_id=&max_rating= — the restaurant's reviews, newest first, with averages. */
    public function index(Request $request)
    {
        $request->validate(['branch_id' => ['nullable', 'integer'], 'max_rating' => ['nullable', 'integer', 'between:1,5']]);

        $base = OrderReview::query()
            ->when($request->query('branch_id'), fn ($q, $id) => $q->where('branch_id', $id));

        $avg = fn (string $col) => [
            'average' => ($v = (clone $base)->whereNotNull($col)->avg($col)) === null ? null : round((float) $v, 2),
            'count' => (clone $base)->whereNotNull($col)->count(),
        ];

        $reviews = (clone $base)
            ->when($request->query('max_rating'), function ($q, $max) {
                $q->where(function ($w) use ($max) {
                    $w->where('food_rating', '<=', $max)->orWhere('rider_rating', '<=', $max)->orWhere('app_rating', '<=', $max);
                });
            })
            ->with(['order:id,order_number,order_type', 'customer:id,name', 'branch:id,name', 'rider:id,name'])
            ->latest()
            ->limit(200)
            ->get()
            ->map(fn (OrderReview $r) => [
                'id' => $r->id,
                'order_id' => $r->order_id,
                'order_number' => $r->order?->order_number,
                'order_type' => $r->order?->order_type?->value,
                'customer' => $r->customer?->name,
                'branch' => $r->branch?->name,
                'rider' => $r->rider?->name,
                'food_rating' => $r->food_rating,
                'food_review' => $r->food_review,
                'rider_rating' => $r->rider_rating,
                'rider_review' => $r->rider_review,
                'app_rating' => $r->app_rating,
                'app_review' => $r->app_review,
                'created_at' => $r->created_at?->toIso8601String(),
            ]);

        return ApiResponse::success([
            'summary' => [
                'total' => (clone $base)->count(),
                'food' => $avg('food_rating'),
                'rider' => $avg('rider_rating'),
                'app' => $avg('app_rating'),
            ],
            'reviews' => $reviews,
        ]);
    }
}
