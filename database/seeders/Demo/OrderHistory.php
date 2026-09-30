<?php

namespace Database\Seeders\Demo;

use App\Enums\KitchenTicketStatus;
use App\Enums\MenuItemStatus;
use App\Enums\OrderStatus;
use App\Exceptions\CouponException;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\Product;
use App\Models\Table;
use App\Models\User;
use App\Services\KitchenTicketService;
use App\Services\OrderService;
use App\Services\PaymentService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Throwable;

/**
 * Plays out a few weeks of trading for one demo restaurant — every order
 * goes through the real OrderService / KitchenTicketService /
 * PaymentService, exactly as the apps would drive them, with the clock
 * (Carbon::setTestNow) moved to each step's moment. So order numbers,
 * totals, tax, delivery fees, coupons, kitchen tickets, table status and
 * payments are all internally consistent, and every report works.
 *
 * Steps that would happen after the real "now" are simply not played, so
 * orders from the last half hour are left mid-flight (new, cooking,
 * ready, out for delivery) — the kitchen and waiter screens have
 * something live to show.
 */
class OrderHistory
{
    private Randomizer $random;

    private CarbonImmutable $realNow;

    private string $appTimezone;

    /** @var array<int, Product> */
    private array $productCache = [];

    public array $stats = ['orders' => 0, 'completed' => 0, 'cancelled' => 0, 'live' => 0, 'add_ons' => 0, 'returns' => 0, 'failed' => 0, 'revenue' => 0.0];

    public array $errors = [];

    public function __construct(
        private OrderService $orders,
        private KitchenTicketService $tickets,
        private PaymentService $payments,
        private int $days,
        int $seed,
    ) {
        $this->random = new Randomizer(new Mt19937($seed));
        $this->realNow = CarbonImmutable::now();
        $this->appTimezone = config('app.timezone');
    }

    public function run(DemoRestaurant $demo): void
    {
        $plan = [];
        foreach ($demo->branches as $code => $branch) {
            foreach ($this->schedule($demo) as $createdAt) {
                $plan[] = [$createdAt, $code];
            }
        }
        usort($plan, fn ($a, $b) => $a[0] <=> $b[0]);

        try {
            foreach ($plan as [$createdAt, $code]) {
                $this->play($demo, $code, $createdAt);
            }

            foreach (array_keys($demo->branches) as $code) {
                $this->topUpLiveBoard($demo, $code);
            }
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }
    }

    // ------------------------------------------------------------------
    // When orders happen
    // ------------------------------------------------------------------

    /** @return CarbonImmutable[] order times (app timezone), oldest first */
    private function schedule(DemoRestaurant $demo): array
    {
        $tz = $demo->restaurant->timezone;
        $today = $this->realNow->setTimezone($tz)->startOfDay();
        $perDay = $demo->data['orders_per_day'];

        // Minutes after local midnight: [from, to, weight]. Past 1440 = after midnight.
        $windows = $demo->style() === 'burger'
            ? [[720, 900, 24], [900, 1140, 14], [1140, 1440, 46], [1440, 1530, 16]]
            : [[750, 930, 36], [930, 1140, 11], [1140, 1410, 53]];

        $times = [];
        for ($d = $this->days; $d >= 0; $d--) {
            $day = $today->subDays($d);
            $weekend = in_array($day->dayOfWeek, [0, 5, 6], true);
            [$min, $max] = $weekend ? $perDay['weekend'] : $perDay['weekday'];
            // A little growth over the period, so the trend chart isn't flat.
            $growth = 0.9 + 0.15 * (1 - $d / max(1, $this->days));
            $count = (int) round($this->random->getInt($min, $max) * $growth);

            for ($i = 0; $i < $count; $i++) {
                $w = $this->weighted(array_map(fn ($win) => $win[2], $windows));
                $minute = $this->random->getInt($windows[$w][0], $windows[$w][1]);
                $local = $day->addMinutes($minute)->addSeconds($this->random->getInt(0, 59));
                $at = $local->setTimezone($this->appTimezone);

                if ($at->lessThan($this->realNow)) {
                    $times[] = $at;
                }
            }
        }

        sort($times);

        return $times;
    }

    private function play(DemoRestaurant $demo, string $code, CarbonImmutable $createdAt, ?string $forceType = null, ?array $profile = null): ?Order
    {
        $type = $forceType ?? $this->pickKey($demo->data['mix']);

        try {
            return DB::transaction(fn () => match ($type) {
                'DINE_IN' => $this->dineIn($demo, $code, $createdAt, $profile),
                'TAKEAWAY' => $this->takeaway($demo, $code, $createdAt, $profile),
                'DELIVERY' => $this->delivery($demo, $code, $createdAt, $profile),
            });
        } catch (Throwable $e) {
            $this->stats['failed']++;
            if (count($this->errors) < 10) {
                $this->errors[] = "{$demo->restaurant->name} {$code} {$type} @ {$createdAt}: {$e->getMessage()}";
            }

            return null;
        }
    }

    // ------------------------------------------------------------------
    // The three kinds of order
    // ------------------------------------------------------------------

    private function dineIn(DemoRestaurant $demo, string $code, CarbonImmutable $t0, ?array $p): ?Order
    {
        $branch = $demo->branches[$code];
        $party = $demo->style() === 'burger'
            ? $this->pickKey([1 => 14, 2 => 42, 3 => 20, 4 => 16, 5 => 5, 6 => 3])
            : $this->pickKey([1 => 6, 2 => 30, 3 => 18, 4 => 24, 5 => 10, 6 => 8, 8 => 4]);

        $table = Table::where('branch_id', $branch->id)->where('status', 'AVAILABLE')
            ->where('capacity', '>=', min($party, 8))->inRandomOrder()->first()
            ?? Table::where('branch_id', $branch->id)->where('status', 'AVAILABLE')->inRandomOrder()->first();

        if (! $table) {
            // Full house — they take it away instead.
            return $this->takeaway($demo, $code, $t0, $p);
        }

        $waiter = $this->staff($demo, $code, 'waiter');
        $kitchen = $this->staff($demo, $code, 'kitchen');
        $p ??= $this->profile($demo, 'DINE_IN');

        $this->at($t0);
        $order = $this->orders->createOrder($demo->restaurant, $branch, $waiter, [
            'order_type' => 'DINE_IN',
            'table_id' => $table->id,
            'items' => $this->basket($demo, $branch, $party, 'DINE_IN'),
            'notes' => $this->maybeNote($demo, 'DINE_IN'),
        ]);
        $this->stats['orders']++;

        if ($p['cancel'] !== null) {
            return $this->cancel($order, $t0, $p['cancel']);
        }

        $main = $this->orders->mainKitchenTicket($order);
        foreach ([['confirm', KitchenTicketStatus::CONFIRMED], ['prepare', KitchenTicketStatus::PREPARING], ['ready', KitchenTicketStatus::READY]] as [$key, $status]) {
            if (! $this->step($t0, $p[$key])) {
                return $this->live($order);
            }
            $this->tickets->advance($main->fresh(), $status, $kitchen);
        }

        if (! $this->step($t0, $p['pickup'])) {
            return $this->live($order);
        }
        $this->tickets->advance($main->fresh(), KitchenTicketStatus::PICKED_UP, $waiter);

        if ($p['addon'] !== null) {
            if (! $this->step($t0, $p['addon'])) {
                return $this->live($order);
            }
            $this->orders->addItems($order->fresh(), $this->addOnBasket($demo, $branch), $waiter);
            $this->stats['add_ons']++;
            $addOn = KitchenTicket::where('order_id', $order->id)->orderByDesc('sequence')->first();

            $offset = $p['addon'];
            foreach ([[2, KitchenTicketStatus::CONFIRMED, $kitchen], [2, KitchenTicketStatus::PREPARING, $kitchen], [9, KitchenTicketStatus::READY, $kitchen], [3, KitchenTicketStatus::PICKED_UP, $waiter]] as [$gap, $status, $by]) {
                $offset += $gap + $this->random->getInt(0, $gap);
                if (! $this->step($t0, $offset)) {
                    return $this->live($order);
                }
                $this->tickets->advance($addOn->fresh(), $status, $by);
            }
        }

        if ($p['return'] !== null) {
            if (! $this->step($t0, $p['return'])) {
                return $this->live($order);
            }
            $this->returnSomething($demo, $order, $this->staff($demo, $code, 'branch-manager'));
        }

        if (! $this->step($t0, $p['checkout'])) {
            return $this->live($order);
        }
        $this->pay($order, $this->random->getInt(1, 100) <= 55 ? 'CASH' : 'CARD', $waiter);

        return $this->complete($order);
    }

    private function takeaway(DemoRestaurant $demo, string $code, CarbonImmutable $t0, ?array $p): ?Order
    {
        $branch = $demo->branches[$code];
        $cashier = $this->staff($demo, $code, 'cashier');
        $kitchen = $this->staff($demo, $code, 'kitchen');
        $customer = $this->random->getInt(1, 100) <= 35 ? $this->customer($demo, $code) : null;
        $p ??= $this->profile($demo, 'TAKEAWAY');

        $this->at($t0);
        $order = $this->orders->createOrder($demo->restaurant, $branch, $cashier, array_filter([
            'order_type' => 'TAKEAWAY',
            'customer_id' => $customer?->id,
            'items' => $this->basket($demo, $branch, $this->pickKey([1 => 40, 2 => 35, 3 => 15, 4 => 10]), 'TAKEAWAY'),
            'notes' => $this->maybeNote($demo, 'TAKEAWAY'),
        ]));
        $this->stats['orders']++;

        if ($p['cancel'] !== null) {
            return $this->cancel($order, $t0, $p['cancel']);
        }

        // Paid at the counter when ordering.
        $this->pay($order, $this->random->getInt(1, 100) <= 68 ? 'CASH' : 'CARD', $cashier);

        $main = $this->orders->mainKitchenTicket($order);
        foreach ([['confirm', KitchenTicketStatus::CONFIRMED], ['prepare', KitchenTicketStatus::PREPARING], ['ready', KitchenTicketStatus::READY]] as [$key, $status]) {
            if (! $this->step($t0, $p[$key])) {
                return $this->live($order);
            }
            $this->tickets->advance($main->fresh(), $status, $kitchen);
        }

        if (! $this->step($t0, $p['checkout'])) {
            return $this->live($order);
        }

        return $this->complete($order);
    }

    private function delivery(DemoRestaurant $demo, string $code, CarbonImmutable $t0, ?array $p): ?Order
    {
        $branch = $demo->branches[$code];
        $kitchen = $this->staff($demo, $code, 'kitchen');
        $rider = $this->staff($demo, $code, 'rider');
        $customer = $this->customer($demo, $code);
        $address = $customer->addresses->count() > 1 && $this->random->getInt(1, 100) <= 25
            ? $customer->addresses->firstWhere('is_default', false)
            : $customer->addresses->firstWhere('is_default', true) ?? $customer->addresses->first();
        $online = $this->random->getInt(1, 100) <= 60;
        $placedBy = $online ? null : $this->staff($demo, $code, 'cashier');
        $p ??= $this->profile($demo, 'DELIVERY');

        $data = [
            'order_type' => 'DELIVERY',
            'customer_id' => $customer->id,
            'delivery_address' => $address->full_address,
            'delivery_latitude' => (float) $address->latitude,
            'delivery_longitude' => (float) $address->longitude,
            'items' => $this->basket($demo, $branch, $this->pickKey([1 => 25, 2 => 35, 3 => 20, 4 => 14, 6 => 6]), 'DELIVERY'),
            'notes' => $address->delivery_instructions ?? $this->maybeNote($demo, 'DELIVERY'),
        ];

        $coupon = $this->pickCoupon($demo);
        if ($coupon) {
            $data['coupon_code'] = $coupon;
        }

        $this->at($t0);
        try {
            $order = $this->orders->createOrder($demo->restaurant, $branch, $placedBy, $data, $online ? $customer : null);
        } catch (CouponException) {
            unset($data['coupon_code']);
            $order = $this->orders->createOrder($demo->restaurant, $branch, $placedBy, $data, $online ? $customer : null);
        }
        $this->stats['orders']++;

        if ($p['cancel'] !== null) {
            return $this->cancel($order, $t0, $p['cancel']);
        }

        // Online orders are mostly prepaid by card through the app.
        $prepaid = $online && $this->random->getInt(1, 100) <= 35;
        if ($prepaid) {
            $payment = $this->payments->record($order->fresh(), ['method' => 'ONLINE', 'amount' => $order->fresh()->outstandingBalance()]);
            $this->payments->confirm($payment);
        }

        $main = $this->orders->mainKitchenTicket($order);
        foreach ([['confirm', KitchenTicketStatus::CONFIRMED], ['prepare', KitchenTicketStatus::PREPARING], ['ready', KitchenTicketStatus::READY]] as [$key, $status]) {
            if (! $this->step($t0, $p[$key])) {
                return $this->live($order);
            }
            $this->tickets->advance($main->fresh(), $status, $kitchen);

            if ($status === KitchenTicketStatus::PREPARING) {
                $this->orders->assignRider($order->fresh(), $rider);
            }
        }

        if (! $this->step($t0, $p['dispatch'])) {
            return $this->live($order);
        }
        $this->orders->updateStatus($order->fresh(), OrderStatus::OUT_FOR_DELIVERY);

        if (! $this->step($t0, $p['checkout'])) {
            return $this->live($order);
        }
        if (! $prepaid) {
            // Cash on delivery, collected by the rider.
            $this->pay($order, $this->random->getInt(1, 100) <= 92 ? 'CASH' : 'CARD', $rider);
        }

        return $this->complete($order);
    }

    // ------------------------------------------------------------------
    // Timing
    // ------------------------------------------------------------------

    /** Minutes after the order was placed at which each step happens; null = never. */
    private function profile(DemoRestaurant $demo, string $type): array
    {
        $burger = $demo->style() === 'burger';
        $confirm = $this->random->getInt(1, 4);
        $prepare = $confirm + $this->random->getInt(1, 4);
        $ready = $prepare + ($burger ? $this->random->getInt(8, 16) : $this->random->getInt(14, 28));
        $pickup = $ready + $this->random->getInt(1, 5);

        $p = [
            'cancel' => $this->random->getInt(1, 1000) <= 28 ? $this->random->getInt(2, 9) : null,
            'confirm' => $confirm,
            'prepare' => $prepare,
            'ready' => $ready,
            'pickup' => $pickup,
            'addon' => null,
            'return' => null,
            'dispatch' => null,
            'checkout' => null,
        ];

        switch ($type) {
            case 'DINE_IN':
                $p['addon'] = $this->random->getInt(1, 100) <= 12 ? $pickup + $this->random->getInt(8, 22) : null;
                $p['return'] = $this->random->getInt(1, 1000) <= 18 ? $pickup + $this->random->getInt(6, 14) : null;
                $p['checkout'] = max($pickup, $p['addon'] ?? 0, $p['return'] ?? 0) + $this->random->getInt(20, 50);
                break;
            case 'TAKEAWAY':
                $p['checkout'] = $ready + $this->random->getInt(3, 15);
                break;
            case 'DELIVERY':
                $p['dispatch'] = $ready + $this->random->getInt(2, 8);
                $p['checkout'] = $p['dispatch'] + $this->random->getInt(12, 32);
                break;
        }

        return $p;
    }

    /** Moves the clock to $t0 + $minutes, unless that's still in the future. */
    private function step(CarbonImmutable $t0, ?int $minutes): bool
    {
        if ($minutes === null) {
            return false;
        }

        $at = $t0->addMinutes($minutes)->addSeconds($this->random->getInt(0, 59));
        if ($at->greaterThan($this->realNow)) {
            return false;
        }

        $this->at($at);

        return true;
    }

    private function at(CarbonImmutable $moment): void
    {
        Carbon::setTestNow($moment);
        CarbonImmutable::setTestNow($moment);
    }

    // ------------------------------------------------------------------
    // Endings
    // ------------------------------------------------------------------

    private function pay(Order $order, string $method, User $by): void
    {
        $order = $order->fresh();
        $amount = $order->outstandingBalance();
        if ($amount > 0) {
            $this->payments->record($order, ['method' => $method, 'amount' => $amount], $by);
        }
    }

    private function complete(Order $order): Order
    {
        $order = $this->orders->updateStatus($order->fresh(), OrderStatus::COMPLETED);
        $this->stats['completed']++;
        $this->stats['revenue'] += (float) $order->total_amount;

        return $order;
    }

    private function cancel(Order $order, CarbonImmutable $t0, int $afterMinutes): Order
    {
        if (! $this->step($t0, $afterMinutes)) {
            return $this->live($order);
        }
        $this->stats['cancelled']++;

        return $this->orders->updateStatus($order->fresh(), OrderStatus::CANCELLED);
    }

    private function live(Order $order): Order
    {
        $this->stats['live']++;

        return $order;
    }

    private function returnSomething(DemoRestaurant $demo, Order $order, User $by): void
    {
        $item = $order->items()->inRandomOrder()->first();
        $reasons = $demo->style() === 'burger'
            ? ['Patty was overcooked — customer refused', 'Fries were soggy', 'Wrong sauce on the burger', 'Shake was too thin', 'Customer found a hair — replaced free']
            : ['Naan was cold — customer refused', 'Too spicy for the customer', 'Karahi was too oily', 'Drink was flat', 'Wrong item served'];

        $this->orders->returnItem($order->fresh(), $item, 1, $reasons[$this->random->getInt(0, count($reasons) - 1)], $by);
        $this->stats['returns']++;
    }

    // ------------------------------------------------------------------
    // Keep something on the kitchen & waiter screens
    // ------------------------------------------------------------------

    /**
     * If the seeder runs outside opening hours (say, 10am) the last real
     * orders were hours ago and every screen would be empty. Place a
     * small, fixed set of orders from the last ~40 minutes so each
     * stage has something in it.
     */
    private function topUpLiveBoard(DemoRestaurant $demo, string $code): void
    {
        $branch = $demo->branches[$code];
        $active = Order::where('branch_id', $branch->id)
            ->whereNotIn('status', [OrderStatus::COMPLETED->value, OrderStatus::CANCELLED->value])
            ->count();

        if ($active >= 4) {
            return;
        }

        $never = null;
        $live = [
            // [minutes ago, type, confirm, prepare, ready, pickup, addon, dispatch]
            [2, 'DINE_IN', $never, $never, $never, $never, $never, $never],
            [5, 'DELIVERY', 1, $never, $never, $never, $never, $never],
            [9, 'TAKEAWAY', 1, 3, $never, $never, $never, $never],
            [16, 'DINE_IN', 2, 4, $never, $never, $never, $never],
            [19, 'DELIVERY', 2, 4, $never, $never, $never, $never],
            [24, 'DINE_IN', 2, 5, 18, $never, $never, $never],
            [41, 'DINE_IN', 2, 4, 20, 23, 34, $never],
            [38, 'DELIVERY', 2, 4, 19, 21, $never, 24],
        ];

        foreach ($live as [$ago, $type, $confirm, $prepare, $ready, $pickup, $addon, $dispatch]) {
            $this->play($demo, $code, $this->realNow->subMinutes($ago), $type, [
                'cancel' => null, 'confirm' => $confirm, 'prepare' => $prepare, 'ready' => $ready,
                'pickup' => $pickup, 'addon' => $addon, 'return' => null, 'dispatch' => $dispatch, 'checkout' => null,
            ]);
        }
    }

    // ------------------------------------------------------------------
    // What people order
    // ------------------------------------------------------------------

    /** @return array<int, array{product_id: int, quantity: int, modifier_ids: int[]}> */
    private function basket(DemoRestaurant $demo, Branch $branch, int $party, string $type): array
    {
        $lines = [];
        $add = function (string $tag, int $qty = 1) use ($demo, $branch, &$lines) {
            $slug = $this->pickProduct($demo, $branch, $tag);
            if ($slug) {
                $lines[$slug] = ($lines[$slug] ?? 0) + $qty;
            }
        };

        if ($demo->style() === 'desi') {
            $dishes = max(1, (int) round($party * $this->random->getFloat(0.45, 0.85)));
            for ($i = 0; $i < $dishes; $i++) {
                $add($this->pickKey(['curry' => 45, 'bbq' => 32, 'rice' => 23]));
            }
            $eatsBread = collect(array_keys($lines))->contains(fn ($slug) => in_array($slug, $this->slugsTagged($demo, ['curry', 'bbq']), true));
            if ($eatsBread) {
                $breads = $party + $this->random->getInt(0, 2);
                $first = $this->random->getInt(1, $breads);
                $add('bread', $first);
                if ($breads > $first) {
                    $add('bread', $breads - $first);
                }
            }
            if ($type === 'DINE_IN' && $this->random->getInt(1, 100) <= 35) {
                $add('starter');
            }
            $drinks = $type === 'DINE_IN' ? $this->random->getInt(0, $party) : ($this->random->getInt(1, 100) <= 40 ? $this->random->getInt(1, 2) : 0);
            for ($i = 0; $i < $drinks; $i++) {
                $add('drink');
            }
            if ($this->random->getInt(1, 100) <= ($type === 'DINE_IN' ? 30 : 12)) {
                $add('dessert', $this->random->getInt(1, 2));
            }
        } else {
            if ($party >= 2 && $this->random->getInt(1, 100) <= 14) {
                $add('deal');
                $party = max(0, $party - ($party >= 4 ? 4 : 2));
            }
            for ($i = 0; $i < $party; $i++) {
                $add('main');
            }
            if ($this->random->getInt(1, 100) <= ($party >= 2 ? 28 : 10)) {
                $add('share');
            }
            $sides = max(1, (int) round($party * 0.4));
            for ($i = 0; $i < $sides; $i++) {
                $add('side');
            }
            for ($i = 0; $i < $party; $i++) {
                $roll = $this->random->getInt(1, 100);
                if ($roll <= 16) {
                    $add('shake');
                } elseif ($roll <= 62) {
                    $add('drink');
                }
            }
            if (empty($lines)) {
                $add('main');
            }
        }

        $items = [];
        foreach ($lines as $slug => $qty) {
            $product = $this->product($demo->products[$slug]);
            // Same product, different choices: split a line in two now and then.
            if ($qty >= 2 && $product->modifierGroups->isNotEmpty() && $this->random->getInt(1, 100) <= 40) {
                $items[] = $this->line($product, 1);
                $qty--;
            }
            $items[] = $this->line($product, $qty);
        }

        return $this->topUpToMinimum($demo, $branch, $items);
    }

    /** Waiters adding a second round: drinks, bread, dessert, another side. */
    private function addOnBasket(DemoRestaurant $demo, Branch $branch): array
    {
        $tags = $demo->style() === 'desi' ? ['bread' => 40, 'drink' => 30, 'dessert' => 30] : ['shake' => 35, 'side' => 35, 'drink' => 30];
        $items = [];
        $count = $this->random->getInt(1, 2);
        for ($i = 0; $i < $count; $i++) {
            $slug = $this->pickProduct($demo, $branch, $this->pickKey($tags));
            if ($slug) {
                $items[] = $this->line($this->product($demo->products[$slug]), $this->random->getInt(1, 2));
            }
        }

        return $items ?: [$this->line($this->product($demo->products[$this->pickProduct($demo, $branch, 'drink')]), 1)];
    }

    private function topUpToMinimum(DemoRestaurant $demo, Branch $branch, array $items): array
    {
        $min = (float) ($branch->settings?->min_order_amount ?? $demo->restaurant->settings->min_order_amount ?? 0);
        $estimate = fn () => array_sum(array_map(
            fn ($i) => $this->productCache[$i['product_id']]->priceAtBranch($branch->id) * $i['quantity'],
            $items
        ));

        $guard = 0;
        while ($estimate() < $min && $guard++ < 4) {
            $tag = $demo->style() === 'desi' ? 'rice' : 'main';
            $items[] = $this->line($this->product($demo->products[$this->pickProduct($demo, $branch, $tag)]), 1);
        }

        return $items;
    }

    private function line(Product $product, int $quantity): array
    {
        $ids = [];
        foreach ($product->modifierGroups as $group) {
            $modifiers = $group->modifiers->filter(fn ($m) => $m->status === MenuItemStatus::ACTIVE)->values();
            if ($modifiers->isEmpty()) {
                continue;
            }
            $isSingle = $group->selection_type->value === 'SINGLE';

            if ($group->is_required) {
                $default = $modifiers->firstWhere('is_default', true);
                $ids[] = ($default && $this->random->getInt(1, 100) <= 65)
                    ? $default->id
                    : $modifiers[$this->random->getInt(0, $modifiers->count() - 1)]->id;
            } elseif ($isSingle) {
                if ($this->random->getInt(1, 100) <= 28) {
                    $ids[] = $modifiers[$this->random->getInt(0, $modifiers->count() - 1)]->id;
                }
            } else {
                $roll = $this->random->getInt(1, 100);
                $take = $roll <= 7 ? 2 : ($roll <= 25 ? 1 : 0);
                $take = min($take, $group->max_selections ?? $take, $modifiers->count());
                foreach ($this->random->pickArrayKeys($modifiers->all(), max(1, $take)) as $k) {
                    if ($take-- > 0) {
                        $ids[] = $modifiers[$k]->id;
                    }
                }
            }
        }

        return ['product_id' => $product->id, 'quantity' => $quantity, 'modifier_ids' => $ids];
    }

    private function maybeNote(DemoRestaurant $demo, string $type): ?string
    {
        if ($this->random->getInt(1, 100) > 14) {
            return null;
        }

        $notes = match (true) {
            $type === 'DELIVERY' => ['Please call on arrival.', 'Extra ketchup sachets please.', "Don't ring the bell — baby sleeping.", 'Send cutlery and tissues.'],
            $demo->style() === 'desi' => ['Less oil in the karahi please.', 'Medium spicy — kids at the table.', 'Serve the naan hot, a few at a time.', 'Birthday table — bring the kheer with a candle.', 'Salad without onions.', 'Extra raita please.'],
            default => ['No pickles on one burger.', 'Cut the burgers in half please.', 'Sauce on the side.', 'Extra napkins.', 'One burger without onions.'],
        };

        return $notes[$this->random->getInt(0, count($notes) - 1)];
    }

    private function pickCoupon(DemoRestaurant $demo): ?string
    {
        if ($this->random->getInt(1, 100) > 9) {
            return null;
        }
        $active = array_values(array_filter($demo->coupons, fn ($c) => $c->is_active));

        return $active ? $active[$this->random->getInt(0, count($active) - 1)]->code : null;
    }

    // ------------------------------------------------------------------
    // Small helpers
    // ------------------------------------------------------------------

    private function pickProduct(DemoRestaurant $demo, Branch $branch, string $tag): ?string
    {
        $options = array_values(array_filter(
            $demo->tagged[$tag] ?? [],
            fn ($o) => $this->product($demo->products[$o['slug']])->isAvailableAtBranch($branch->id)
        ));
        if (! $options) {
            return null;
        }

        return $options[$this->weighted(array_column($options, 'weight'))]['slug'];
    }

    private function slugsTagged(DemoRestaurant $demo, array $tags): array
    {
        return collect($tags)->flatMap(fn ($t) => array_column($demo->tagged[$t] ?? [], 'slug'))->all();
    }

    private function product(Product $product): Product
    {
        return $this->productCache[$product->id] ??= $product->fresh(['modifierGroups.modifiers', 'branchOverrides']);
    }

    private function staff(DemoRestaurant $demo, string $code, string $role): User
    {
        $people = $demo->staff[$code][$role];

        return $people[$this->random->getInt(0, count($people) - 1)];
    }

    private function customer(DemoRestaurant $demo, string $code): Customer
    {
        // A few regulars order far more often than everyone else.
        $people = $demo->customers[$code];
        $index = $this->random->getInt(1, 100) <= 40
            ? $this->random->getInt(0, min(4, count($people) - 1))
            : $this->random->getInt(0, count($people) - 1);

        return $people[$index];
    }

    /** @param array<array-key, int> $weights  @return array-key */
    private function pickKey(array $weights): int|string
    {
        $keys = array_keys($weights);

        return $keys[$this->weighted(array_values($weights))];
    }

    /** @param int[] $weights  @return int index */
    private function weighted(array $weights): int
    {
        $roll = $this->random->getInt(1, array_sum($weights));
        foreach (array_values($weights) as $i => $w) {
            $roll -= $w;
            if ($roll <= 0) {
                return $i;
            }
        }

        return count($weights) - 1;
    }
}
