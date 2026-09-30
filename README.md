# Restaurant Management Platform — Backend (Phases 1–10)

Laravel 11 + Sanctum API implementing the multi-tenant, multi-branch
foundation described in the platform spec (Phase 1: Super Admin,
restaurants, subscriptions/features, branches, staff/roles/permissions,
settings, audit logging), the menu catalog (Phase 2: categories, products,
modifier groups/modifiers, branch-level availability/price overrides),
the ordering core (Phase 3: tables, order creation across
dine-in/takeaway/delivery, pricing, and the order status lifecycle),
staff workflows (Phase 4: a delivery-only `OUT_FOR_DELIVERY` status step,
rider assignment, a rider's own scoped view of their orders, a
multi-status queue filter for kitchen/waiter apps, and a table's active
order surfaced for the waiter app), delivery zones/geofencing
(Phase 5: per-branch delivery zones — circular or polygon-shaped —
that a `DELIVERY` order's coordinates are checked against, with a
per-zone delivery-fee override), the customer-facing app/website
(Phase 6: customer registration/login, saved addresses, and placing and
tracking their own orders — the same `OrderService`/geofencing every
staff-placed order already goes through, just with a `Customer` as the
ordering party instead of staff), and payments/coupons (Phase 7:
restaurant-wide coupon codes applied at order-creation time with
percentage/fixed discounts, usage/per-customer limits, and validity
windows; and a `Payment` record per order supporting cash/card recorded
by staff and an online method that starts pending and needs a separate
confirm step, standing in for a real gateway's webhook), and reporting/
notifications (Phase 8, the last phase in the originally agreed plan:
branch-scoped sales/payments/top-products/coupon-usage reports over
`Order`/`Payment`/`CouponRedemption` directly rather than a separate
summary table, and an in-app staff notification inbox — Laravel's own
database-notifications feature, fed by three events already flowing
through `OrderService`: an order placed, an order reaching a meaningful
status, and a rider being assigned). Phases 9 and 10 are add-ons beyond that original plan, both built at
the user's request: Phase 9 layered real mail/SMS/push notification
channels on top of Phase 8's in-app inbox, added a `payment.received`
event fired by `PaymentService`, and gave every staff member a per-user
opt-in preference matrix so nobody's real inbox/phone starts getting
messages they didn't ask for. Phase 10 extends that same channel/
preference/device-token machinery to the customer who placed an
order — the exact same `OrderPlacedNotification`/
`OrderStatusChangedNotification` classes, reused rather than
duplicated, now also reach the order's own customer (worded from their
point of view), with their own independent opt-in preferences and
device tokens.



## What's actually here

- **Tenant isolation** (`app/Support/TenantContext.php`,
  `app/Models/Scopes/TenantScope.php`, `app/Models/Concerns/BelongsToTenant.php`):
  a global Eloquent scope that automatically constrains every tenant model's
  queries to the current restaurant. Developers don't add
  `where('restaurant_id', ...)` by hand — models that `use BelongsToTenant`
  get it for free, and it fails *closed* (returns nothing) if no tenant
  context was established, rather than failing open.
- **Authorization pipeline** (`app/Http/Middleware/`): mirrors the spec's
  chain — `auth:sanctum` → `tenant` → `restaurant.active` →
  `subscription.active` → `feature:KEY` → `permission:KEY` →
  `branch.access`. Each is a small, composable route middleware.
- **FeatureService** / **PermissionService** (`app/Services/`): single
  source of truth for "is feature X enabled" and "can user Y do Z",
  resolving restaurant-level overrides → subscription-level overrides →
  plan defaults. The `feature` and `permission` middleware call these —
  the same checks the frontend would use to hide UI are enforced again
  here, server-side, per spec #9.
- **BranchService**: centralizes the subscription branch-limit check so
  every branch-creation path enforces it identically.
- **Super Admin API** (`app/Http/Controllers/Api/V1/SuperAdmin/`):
  restaurant onboarding (with owner account creation), subscription plans,
  subscription assignment, feature overrides, platform dashboard.
- **Restaurant Admin API** (`app/Http/Controllers/Api/V1/`): branches,
  users, roles/permissions, settings, audit logs, and the central
  `/config` / `/app/config` endpoints generic apps and the white-label
  customer app/website are meant to read branding/theme/features from.
- **Menu catalog** (Phase 2 — `CategoryController`, `ProductController`,
  `ModifierGroupController`, `ModifierController`): categories, products
  (with base price/status), modifier groups + modifiers (e.g. "Size":
  Small/Large, "Toppings": multi-select add-ons), and which modifier
  groups apply to which product.
- **MenuService** (`app/Services/MenuService.php`): the one place that
  resolves a product's *effective* price/availability for a given branch
  (an explicit `branch_products` override wins over the product's own
  `base_price`/`status`) and assembles the nested
  categories → products → modifier groups → modifiers structure. Both the
  admin-facing `/menu` endpoint and the public `/app/menu` endpoint go
  through it, the same "one source of truth" reasoning as
  FeatureService/PermissionService.
- **Ordering core** (Phase 3 — `Table`, `Order`, `OrderItem`,
  `OrderItemModifier`, `OrderService`, `OrderNumberService`,
  `TableController`, `OrderController`): tables (with a status of
  AVAILABLE/OCCUPIED/RESERVED/UNAVAILABLE), and orders across all three
  order types (DINE_IN/TAKEAWAY/DELIVERY) with a linear status lifecycle
  (PENDING → CONFIRMED → PREPARING → READY → COMPLETED, or CANCELLED from
  anywhere before that). `OrderService` is the single place that: checks
  the order type is both feature-enabled (DINE_IN/TAKEAWAY/DELIVERY
  Features) and allowed by the branch's own `order_types` setting;
  resolves/locks a table for dine-in; validates each line item's product
  availability, per-branch price, and modifier-group selection rules
  (required, SINGLE vs MULTIPLE, min/max selections); computes tax and
  delivery fee (with the free-delivery threshold) from
  `RestaurantSetting`/`BranchSetting`; and persists the whole order
  transactionally. It reuses `Product::isAvailableAtBranch()` /
  `priceAtBranch()` from Phase 2 rather than re-deriving either, exactly
  the seam Phase 2's own notes flagged for this. `OrderNumberService`
  finally puts the `order_number_scheme` / `order_number_daily_reset`
  columns Phase 1 added to `restaurant_settings` — but never read — to
  use.
- **Payments & coupons** (Phase 7 — `Coupon`, `CouponRedemption`,
  `Payment`, `CouponService`, `PaymentService`, `CouponController`,
  `PaymentController`, `CustomerPaymentController`): a restaurant-wide
  coupon code (`PERCENTAGE` or `FIXED`, with an optional minimum order
  amount, a percentage's own optional discount cap, and optional overall/
  per-customer usage limits) applied via `coupon_code` on the same
  `POST /orders` / `POST /customer/orders` every order already goes
  through; and a `Payment` row per order — `CASH`/`CARD` recorded by
  staff settle immediately, `ONLINE` (staff- or customer-initiated)
  starts `PENDING` and needs a separate confirm call, standing in for a
  real gateway's webhook/redirect. `Order::outstandingBalance()` is the
  one place "how much of this order is still unpaid" gets computed, so
  recording a payment, confirming one, and refunding one all reason
  about the same number.
- **Reporting & notifications** (Phase 8 — `ReportService`,
  `ReportController`, `NotificationService`, `NotificationController`,
  `app/Notifications/`): branch-scoped `GET /reports/sales`,
  `/reports/payments`, `/reports/top-products`, `/reports/coupons`,
  querying `orders`/`payments`/`order_items`/`coupon_redemptions`
  directly with the query builder rather than a denormalized summary
  table (see "Notable design decisions"). Every order-lifecycle event
  that's actually worth telling someone about — an order placed, an
  order reaching a meaningful status, a rider assigned — creates a
  database notification via `NotificationService`, called from the
  three `OrderService` methods both the staff and customer ordering
  paths already funnel through, so nothing has to be wired up twice.
  Staff read their own inbox at `GET /notifications`.
- **Real notification channels** (Phase 9, an add-on beyond the original
  8-phase plan — `SmsGateway`/`PushGateway` contracts, `SmsChannel`/
  `PushChannel`, `NotificationPreferenceService`, `PaymentReceivedNotification`,
  `NotificationPreferenceController`, `DeviceTokenController`): every
  Phase 8 notification (`order.placed`, `order.status_changed`,
  `order.rider_assigned`) plus a new `payment.received` event (fired by
  `PaymentService` whenever a payment settles as `PAID`, to staff with
  `payments.view`, not the whole `orders.view` audience) can now go out
  over mail, SMS, and push, on top of the always-on `database` channel.
  Mail rides Laravel's built-in `mail` channel (so `MAIL_MAILER=log`
  already "sends" real emails into the log, exactly as it does for the
  rest of Laravel); SMS/push are custom channels backed by a
  `SmsGateway`/`PushGateway` contract with a `log`-driver default
  implementation, standing in for a real provider (Twilio, FCM, ...)
  the same way `PaymentService` stands in for a real payment gateway in
  Phase 7 — see "Notable design decisions". mail/sms/push are opt-in
  *per user, per event, per channel* via `GET`/`PUT
  /notification-preferences`, defaulting to entirely off, so turning
  this phase on for an existing restaurant never starts emailing,
  texting, or push-notifying anyone who hasn't explicitly asked for it.
  Push additionally needs a device token on file — `POST`/`DELETE
  /device-tokens` register/unregister one for the authenticated user.
- **Customer-facing notifications** (Phase 10, a further add-on beyond
  the original plan — `Customer` gains `Notifiable`;
  `NotificationPreferenceService`/`NotificationPreferenceController`/
  `DeviceTokenController` all generalized from `User`-only to
  `Illuminate\Contracts\Auth\Authenticatable`): a customer who placed an
  order (or was attached to a staff-placed one via `customer_id`) now
  receives `order.placed`/`order.status_changed` the same way staff do —
  `database` inbox always, plus mail/sms/push once they opt in — through
  `GET`/`PUT /customer/notification-preferences` and `POST`/`DELETE
  /customer/device-tokens`. These are *the same controllers* the staff
  routes use, just mounted under the customer-guarded, `CUSTOMER_APP`-
  gated group — neither controller nor `NotificationPreferenceService`
  hardcodes which model it's serving. A customer only ever sees
  `order.placed`/`order.status_changed` in their own preference matrix
  (never `order.rider_assigned`/`payment.received`, which stay
  staff/rider-only); the notification body text itself is personalized
  per audience ("Your order is now READY." vs. "Moved from PREPARING to
  READY.") from inside the same `OrderPlacedNotification`/
  `OrderStatusChangedNotification` classes Phase 8 shipped, not a
  parallel set of customer-specific ones.

## Requirements

- PHP 8.3+ with the usual Laravel extensions (pdo, mbstring, xml, curl,
  zip, bcmath, intl, openssl, tokenizer, fileinfo, ctype) — or just use
  Docker (see below).
- Composer 2.
- MySQL 8 for real use; SQLite works out of the box for local dev/tests
  (no schema differences were introduced — no DB-specific column types).

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate

# Point .env at MySQL for real usage, e.g.:
#   DB_CONNECTION=mysql
#   DB_HOST=127.0.0.1
#   DB_DATABASE=restaurant_platform
#   DB_USERNAME=root
#   DB_PASSWORD=secret
# ...or for a quick local try, SQLite works with no changes:
touch database/database.sqlite
# and set DB_CONNECTION=sqlite in .env (remove/comment the other DB_* lines)

php artisan migrate --seed
php artisan serve
```

The seeders create:
- Permission/feature catalogs and system role templates (Owner, Admin,
  Branch Manager, Cashier, Kitchen, Waiter, Rider).
- Three example subscription plans (Basic/Standard/Premium) from the spec.
- Demo data: a Super Admin (`superadmin@platform.test` / `password`) and
  two independent demo restaurants, each with an Owner, one branch, and an
  active Standard subscription — enough to click through tenant/branch
  isolation by hand. Demo owner logins are printed by
  `php artisan tinker --execute="dd(\App\Models\User::where('is_super_admin', false)->pluck('email'))"`
  or just re-read `database/seeders/DemoDataSeeder.php`.

### Running without PHP installed locally

```bash
docker run --rm -it -v "$PWD":/app -w /app -p 8000:8000 php:8.3-cli bash -c \
  "apt-get update -qq && apt-get install -y -qq unzip git libzip-dev >/dev/null && \
   docker-php-ext-install zip pdo_mysql >/dev/null && \
   curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer && \
   composer install && cp .env.example .env && php artisan key:generate && \
   touch database/database.sqlite && sed -i 's/DB_CONNECTION=mysql/DB_CONNECTION=sqlite/' .env && \
   php artisan migrate --seed && php artisan serve --host=0.0.0.0"
```

## Tests

```bash
php artisan test
```

208 feature tests, all passing (34 from Phase 1 + 20 from Phase 2 + 27 from
Phase 3's ordering core + 12 from Phase 4's staff workflows + 14 from
Phase 5's delivery zones/geofencing + 17 + 1 for Phase 6's customer
app/website (including one cross-tenant regression test) + 29 for
Phase 7's coupons/payments + 18 for Phase 8's reports/notifications + 20
for Phase 9's real notification channels + 16 new for Phase 10's
customer-facing notifications):
tenant isolation, branch isolation, feature gating (both directions), permission
enforcement, branch-limit enforcement, restaurant/subscription status
gating, the Super Admin onboarding flow end-to-end,
category/product/modifier-group CRUD, menu tenant isolation, branch-level
price/availability overrides, the public `/app/menu` endpoint, table CRUD
gated by the TABLE_MANAGEMENT feature, and order creation/lifecycle:
correct subtotal/tax/delivery-fee math including the free-delivery
threshold, order-number sequencing (and reset per scheme), dine-in
requiring and occupying a table (and rejecting a second order on an
already-occupied one), delivery requiring an address, an order type being
rejected both when the restaurant-level feature is off and when the
branch's own `order_types` setting narrows it out even with the feature
on, minimum-order-amount enforcement, product/branch availability and
modifier-selection validation (required groups, SINGLE vs MULTIPLE,
min/max), status transitions (forward-one-step-only, no change once
terminal, releasing a dine-in table on completion or cancellation), and
branch-scoped access enforcement and tenant isolation for both tables and
orders. Phase 4 adds: a delivery order passing through
`OUT_FOR_DELIVERY` (and being unable to skip it), a non-delivery order
never reaching that status, rider assignment succeeding and being
rejected for a non-delivery order / a non-rider user / a rider without
branch access, the `orders.assign_rider` permission and `RIDER_APP`
feature gates on that endpoint, a rider's `/orders` listing being scoped
to only their own assigned orders, the comma-separated `?status=` queue
filter, and a table's `active_order` appearing and then clearing once its
order is completed/cancelled. Phase 5 adds: delivery-zone CRUD
(branch/feature/permission gating, tenant isolation), a RADIUS zone and a
POLYGON zone each correctly accepting a point inside and rejecting one
outside, coordinates being required on a `DELIVERY` order only once the
branch actually has an active zone (a branch with none configured stays
exactly as permissive as Phase 3), an inactive zone never being matched,
zones on one branch never leaking into another, and a zone's
`delivery_fee_override` beating the branch default while still losing to
the free-delivery threshold. Phase 6 adds: a customer registering,
logging in, fetching their profile, and logging out; a phone number
being unique per restaurant but reusable across two different
restaurants; a deactivated customer being unable to log in; a staff
bearer token being unable to reach any `/customer/*` route and a
customer's token being unable to reach any staff route (`staff.guard`/
`customer.guard`); saved-address CRUD, `is_default` exclusivity, and one
customer never being able to touch another's address; a customer placing
an order and seeing it in their own history but never another
customer's; placing an order being gated by `ONLINE_ORDERING` on top of
the broader `CUSTOMER_APP` gate; ordering delivery via a saved address
(and being unable to use another customer's saved address); and — a real
bug this phase's own testing caught (see "Notable design decisions") — a
forged cross-tenant `customer_id` on a staff-placed order no longer being
silently attachable. Phase 7 adds: coupon CRUD (feature/permission
gating, tenant isolation, duplicate-code rejection, a percentage value
over 100 rejected); a `PERCENTAGE` coupon's discount being capped by
`max_discount_amount` and a `FIXED` coupon never discounting more than
the subtotal itself; a coupon below its own `min_order_amount`, inactive,
expired, or unknown being rejected; `usage_limit` enforced across
separate orders; `per_customer_limit` blocking a repeat customer but not
a different one at the same restaurant; a staff-placed order with no
customer only ever counting against `usage_limit`, never a
`per_customer_limit` it has no customer to check; applying a coupon
requiring the `COUPONS` feature; and one restaurant's coupon code never
being usable on another's order. Payments: a cash payment settling
immediately; a split cash+card payment fully settling an order (and a
third payment beyond that being rejected); an amount over the order's
outstanding balance being rejected; an online payment starting `PENDING`
and needing a separate confirm call (and rejecting a second confirm);
online requiring the `ONLINE_PAYMENTS` feature; a refund reopening the
outstanding balance (and rejecting a second refund of the same payment);
the `payments.create` permission and branch-access both being enforced
independently; a customer initiating and confirming their own order's
online payment but never another customer's; and a cancelled order
rejecting any new payment. Phase 8 adds: a sales report correctly
excluding a cancelled order from revenue/average-order-value while still
counting it in `orders_by_status`/`total_orders`; a date range filtering
orders outside its window (and an inverted `from`/`to` range being
rejected); a branch-scoped user's report numbers being narrowed to their
own branch (and a `branch_id` outside their access being rejected); both
the `REPORTS` feature and the `reports.view` permission being enforced
independently (and the pre-existing `audit-logs` endpoint now requiring
`REPORTS` too — see "Notable design decisions"); a payments report
breaking totals down by method and status; a top-products report
ordering by quantity sold and excluding a cancelled order's units
entirely; a coupon-usage report totaling redemptions and discount given
per coupon; a staff-placed *and* a customer-placed order both notifying
every branch staff member with `orders.view` access to that branch (and
staff at a different branch getting nothing); only the "meaningful
handoff" status transitions (READY/OUT_FOR_DELIVERY/COMPLETED/CANCELLED)
notifying staff, not every internal kitchen step; a rider assignment
notifying only that one rider, never the rest of the branch; marking one
notification (and all of them) read; and one user never being able to
mark — or even see — another's notification. Three of the tests are
regression tests for real bugs caught during Phase 1 and Phase 6
development (see "Notable design decisions" below). Phase 9 adds: the
default preference matrix coming back entirely `false` for a brand-new
user; updating preferences persisting (not just echoing back in the
same response) and toggling a row rather than duplicating it; an
unknown event key or the `database` channel itself being rejected by
`PUT /notification-preferences`; preferences being scoped to the
authenticated user, never leaking between two staff at the same
restaurant; registering, re-registering (reassigning a shared device to
its new owner), and unregistering a device token, and a user being
unable to unregister someone else's; an `order.placed` notification
going out as `database`-only until the recipient opts into `mail` (and
two recipients with different opt-ins getting different channel sets
for the very same event); the `SmsGateway` being called with the
recipient's own phone number once they opt in, and never called at all
when they have no phone on file; the `PushGateway` fanning out to every
device token a recipient has registered (two tokens, two calls); a
`payment.received` notification reaching staff with `payments.view`
(owner, cashier) but never staff with only `orders.view` (kitchen); and
an `ONLINE` payment only notifying once `confirm()` actually settles it,
not at the moment it's first recorded `PENDING`. Phase 10 adds: a
customer-placed order notifying the customer as well as staff, worded
from their point of view (`"Your ... order ... has been placed."`); a
staff-placed order with a known `customer_id` also notifying that
customer, not just whoever placed it; a status change notifying both
audiences with their own worded body, still silent for internal
kitchen steps for the customer too; `order.rider_assigned`/
`payment.received` never reaching a customer no matter what; one
customer never seeing another's notifications; a customer's
`SmsGateway` call using their own phone once opted in; a customer's
channel opt-in having zero effect on a staff member who opted into
nothing; a customer's default preference matrix listing only the two
events that apply to them (not the staff-only two); a customer being
rejected for setting a preference on a staff-only event; staff and
customer preferences never colliding in the shared table; two
customers' preferences never leaking into each other; registering a
customer device token; a token handed from a staff member to a
customer (or vice versa) reassigning cleanly with the old owner column
cleared; a customer unregistering their own token; and a customer being
unable to unregister a staff member's token.

## API shape

Every response is `{"success": true, "data": {...}}` or
`{"success": false, "code": "SOME_CODE", "message": "..."}` per spec
#123. Routes live in `routes/api.php`, prefixed `/api/v1`.

Public: `POST /auth/login`, `GET /app/config?restaurant=<slug>` (or
resolved by `Host` header against `restaurant_domains`).

Authenticated, restaurant-scoped: `/config`, `/branches`, `/users`,
`/roles`, `/permissions`, `/settings`, `/audit-logs`, `/menu`,
`/categories`, `/products`, `/modifier-groups`, `/modifiers` — each gated
by the `permission:` middleware for the relevant `module.action` key
(`menu.view` / `menu.create` / `menu.update` / `menu.delete`).

Menu-specific routes: `PATCH /products/{product}/modifier-groups` syncs
which modifier groups apply to a product; `PATCH
/products/{product}/branches/{branch}` sets that product's
availability/price override for one branch (gated by `menu.update` +
`branch.access`, so a branch-scoped user — e.g. `branch-manager`, who gets
`menu.update` but not `menu.create`/`menu.delete` — can only touch a
branch they're assigned to, never the restaurant-wide catalog); `GET
/menu` returns the *resolved* menu (branch overrides applied) rather than
raw catalog rows.

Public: `GET /app/menu?restaurant=<slug>[&branch=<id>]` (or resolved by
`Host` header) — same resolution pattern as `/app/config`, for the
customer app/website and kitchen/waiter apps to read the live menu from
before any login exists on screen. Only `ACTIVE` categories/products that
are available at the given branch are included.

Ordering (Phase 3), all authenticated and restaurant-scoped: `GET/POST
/tables`, `GET/PATCH/DELETE /tables/{table}` (gated by both `menu`-style
`tables.*` permissions and the `TABLE_MANAGEMENT` feature); `GET
/orders`, `GET /orders/{order}`, `POST /orders`, `PATCH
/orders/{order}/status` (gated by `orders.*` permissions). `branch_id` on
`POST /tables` and `POST /orders` is a body field rather than a route
segment, so both rely on the `branch.access` middleware's existing
fallback to `input('branch_id')` (see `EnsureBranchAccess`) instead of a
`{branch}` route parameter. `POST /orders` expects:

```json
{
  "branch_id": 1,
  "order_type": "DINE_IN",
  "table_id": 5,
  "items": [
    {"product_id": 12, "quantity": 2, "modifier_ids": [7, 9], "notes": "no onions"}
  ]
}
```

`table_id` is required (and must be an AVAILABLE/RESERVED table at that
branch) when `order_type` is `DINE_IN`; `delivery_address` is required
when it's `DELIVERY`. `delivery_latitude`/`delivery_longitude` are
optional unless the branch has an active delivery zone (Phase 5, see
below), in which case they're required and must fall inside one. The
response includes the computed `subtotal`, `tax_amount`, `delivery_fee`,
`total_amount`, `delivery_zone_id` (null unless a zone matched), and the
generated `order_number`.

Staff workflows (Phase 4): `GET /orders` accepts a comma-separated
`?status=PENDING,CONFIRMED,PREPARING` filter (kitchen/waiter queue
views need "everything still active" in one call, not one request per
status), plus the existing `branch_id`/`order_type` filters. A user with
the `rider` role gets that same endpoint automatically scoped to
`assigned_rider_id = their own id`, on top of (not instead of) the usual
branch-access restriction. `PATCH /orders/{order}/rider` (body:
`{"rider_id": 5}`) assigns a rider to a `DELIVERY` order; gated by a new
`orders.assign_rider` permission (granted to `branch-manager`, not to
`rider` itself) *and* the `RIDER_APP` feature, and rejects (422) a
non-`DELIVERY` order, a target user without the `rider` role, or a rider
who doesn't have access to the order's branch. `PATCH
/orders/{order}/status` now also accepts `OUT_FOR_DELIVERY`, a step that
only a `DELIVERY` order's sequence includes (see "Notable design
decisions"). `GET /tables` and `GET /tables/{table}` now include an
`active_order` relation — the table's own current non-terminal order, if
any — for the waiter app to show at a glance without a second request.

Delivery zones/geofencing (Phase 5), gated behind the `DELIVERY` feature:
`GET/POST /delivery-zones`, `GET/PATCH/DELETE /delivery-zones/{deliveryZone}`
(gated by `delivery-zones.*` permissions — `branch-manager` gets
view/create/update but not delete, same pattern as `tables.*`).
`branch_id` is a body field on `POST`, reusing the same
`branch.access`-fallback pattern as tables/orders. A zone is either:

```json
{"branch_id": 1, "name": "Inner city", "type": "RADIUS",
 "center_latitude": 24.8607, "center_longitude": 67.0011, "radius_km": 5}
```

or

```json
{"branch_id": 1, "name": "Downtown", "type": "POLYGON",
 "polygon": [{"lat": 24.80, "lng": 66.95}, {"lat": 24.80, "lng": 67.05},
             {"lat": 24.90, "lng": 67.05}, {"lat": 24.90, "lng": 66.95}]}
```

(`polygon` needs at least 3 points). An optional `delivery_fee_override`
replaces the branch/restaurant default delivery fee for an order that
resolves into that zone (still overridden itself by a free-delivery
threshold — see "Notable design decisions"). A branch with zero active
zones is unrestricted, exactly like before Phase 5 — zones are how a
restaurant *opts into* restricting delivery to specific areas, not a
requirement to set any up at all.

Customer app/website (Phase 6) — its own actor type, authenticated
through the same Sanctum bearer-token mechanism as staff but never mixed
into the staff pipeline (see "Notable design decisions"). Public:
`POST /app/auth/register`, `POST /app/auth/login` — resolved by the same
Host-header/`?restaurant=slug` mechanism as `/app/config`/`/app/menu`,
since a `Customer` isn't tenant-known ahead of login any more than a
`User` is (body: `name?`, `phone`, `email?`, `password`; a phone number
is unique per restaurant, not globally). Authenticated, under
`/customer/*`: `POST auth/logout`, `GET me`; then, gated by the
`CUSTOMER_APP` feature: `GET/POST addresses`, `PATCH/DELETE
addresses/{address}` (a customer's own saved addresses only — never
another customer's, even within the same restaurant); `GET orders`,
`GET orders/{order}` (that customer's own order history only); and,
additionally gated by `ONLINE_ORDERING`, `POST orders` — the exact same
`OrderService::createOrder()` every staff-placed order goes through
(Phase 3's validation, Phase 5's geofencing, all of it), just with the
authenticated `Customer` as the ordering party instead of a staff
`placedBy` user. `POST orders` accepts an optional `customer_address_id`
in place of typing `delivery_address`/`delivery_latitude`/
`delivery_longitude` out again — when given, that saved address's fields
are used (and its ownership is checked) instead of whatever was
submitted in those fields directly.

Coupons (Phase 7), restaurant-wide (not per-branch — a promo code is a
marketing decision made once for the whole restaurant), gated behind the
`COUPONS` feature: `GET/POST /coupons`, `GET/PATCH/DELETE
/coupons/{coupon}` (gated by `coupons.*` permissions — `branch-manager`
gets `view` only; creating/editing/deleting a coupon is reserved for
Owner/Admin). A coupon's `code` is normalized to uppercase on save, so
`welcome10` and `WELCOME10` are the same coupon:

```json
{"code": "WELCOME10", "type": "PERCENTAGE", "value": 10,
 "min_order_amount": 20, "max_discount_amount": 15,
 "usage_limit": 100, "per_customer_limit": 1}
```

`type` is `PERCENTAGE` (`value` ≤ 100, optionally capped further in
absolute terms by `max_discount_amount`) or `FIXED` (`value` is the flat
amount off, never discounting more than the order's own subtotal).
`usage_limit` caps total redemptions across every customer;
`per_customer_limit` additionally caps redemptions by one customer (only
ever checked when the order has a customer attached — a staff-placed
order with no `customer_id` only counts against `usage_limit`).
`POST /orders` and `POST /customer/orders` both accept an optional
`coupon_code`; when present and valid, the response's `discount_amount`
and `total_amount` reflect it, and `coupon_id` is set. An invalid,
expired, exhausted, or below-minimum code is rejected with
`INVALID_COUPON` (422) rather than silently ignored.

Payments (Phase 7): `GET/POST /orders/{order}/payments` (gated by
`payments.view`/`payments.create`), `PATCH /payments/{payment}/confirm`
(`payments.confirm`), `PATCH /payments/{payment}/refund`
(`payments.refund`) — all branch-access-checked against the payment's/
order's own branch, same manual check `OrderController` already uses.
`POST /orders/{order}/payments` body:

```json
{"method": "CASH", "amount": 25.00, "transaction_reference": null, "notes": null}
```

`method` is `CASH`, `CARD`, or `ONLINE`. CASH/CARD are recorded already
`PAID` (a staff member is handling the money/terminal right now); ONLINE
is recorded `PENDING` and needs a follow-up `PATCH
/payments/{payment}/confirm` to become `PAID` — standing in for a real
gateway's webhook/redirect callback, since no gateway is integrated in
this phase (see "Notable design decisions"). ONLINE additionally
requires the `ONLINE_PAYMENTS` feature. An `amount` above the order's
current outstanding balance (`total_amount` minus everything already
`PAID`) is rejected with `PAYMENT_ERROR` (422), so cash+card can be split
across two calls but never overpay the order. `PATCH .../refund` only
accepts a `PAID` payment, moving it to `REFUNDED` and reopening that
much of the order's outstanding balance.

A customer pays for their own order online only — never cash/card, which
stay staff-only: `GET /customer/orders/{order}/payments`, `POST
/customer/orders/{order}/payments` (body: `{"amount": 25.00}`, `method`
is always `ONLINE`, gated by `ONLINE_PAYMENTS`), `PATCH
/customer/payments/{payment}/confirm` — the exact same `PaymentService`
as the staff pipeline, just with no staff `recorded_by_user_id` and every
lookup additionally scoped to that customer's own orders (never another
customer's, even at the same restaurant).

Reports (Phase 8), gated behind both the `REPORTS` feature and
`reports.view` permission (the same pairing now enforced on the
pre-existing `GET /audit-logs` too): `GET /reports/sales`, `GET
/reports/payments`, `GET /reports/top-products[?limit=10]`, `GET
/reports/coupons`. All but `/coupons` (coupons have no branch dimension,
see `ReportService::couponUsage()`) accept an optional `?branch_id=`,
validated against the caller's own branch access exactly like
`OrderController::index()`'s existing filter — a branch-scoped user gets
their own branch(es) automatically with no query param needed, and a
`branch_id` outside what they can already see is rejected (403), not
silently ignored or narrowed. Every endpoint accepts `?from=YYYY-MM-DD
&to=YYYY-MM-DD`, defaulting to the trailing 30 days (today inclusive)
when omitted; `from` after `to` is rejected (422). `GET /reports/sales`
returns:

```json
{
  "from": "2026-08-30", "to": "2026-09-29",
  "total_orders": 12, "orders_by_status": {"PENDING": 1, "COMPLETED": 10, "CANCELLED": 1},
  "order_count": 11, "subtotal": 480.00, "tax_amount": 48.00,
  "delivery_fee": 15.00, "discount_amount": 20.00, "net_revenue": 523.00,
  "average_order_value": 47.55,
  "by_order_type": {"TAKEAWAY": {"count": 8, "revenue": 400.00}, "DELIVERY": {"count": 3, "revenue": 123.00}}
}
```

`order_count`/`subtotal`/.../`net_revenue`/`average_order_value` exclude
`CANCELLED` orders (see "Notable design decisions"); `total_orders`/
`orders_by_status` don't, for a complete picture of what came through.
`GET /reports/payments` returns totals grouped `by_method` then by
status (`{"CASH": {"PAID": {"count": 5, "total": 120.00}}, ...}`) plus
`total_collected`/`total_pending`/`total_refunded` across every method.
`GET /reports/top-products` returns an array sorted by `quantity_sold`
descending. `GET /reports/coupons` returns each redeemed coupon's
`redemptions_count`/`total_discount` in the window.

Notifications (Phase 8) — a staff member's own inbox, no permission gate
beyond being authenticated staff (same as `GET /auth/me`): `GET
/notifications[?unread_only=1]` (paginated), `GET
/notifications/unread-count`, `PATCH /notifications/{notification}/read`,
`PATCH /notifications/read-all`. `{notification}` is Laravel's own uuid
notification id, looked up through the authenticated user's own
`notifications()` relation — never a bare global lookup — so one user
can't mark, or even see, another's. Three events create one:
`order.placed` (every order, staff- or customer-placed, notifies every
branch staff member with `orders.view` access to that branch — kitchen,
waiter, branch-manager, owner/admin), `order.status_changed` (only for
`READY`/`OUT_FOR_DELIVERY`/`COMPLETED`/`CANCELLED` — the "meaningful
handoff" points, not every internal step), and `order.rider_assigned`
(only the rider just assigned, nobody else). Each notification's `data`
column carries a `type`/`title`/`body` plus the relevant order fields.

Notification preferences & device tokens (Phase 9), same no-gate-beyond-
auth reasoning as `/notifications` above: `GET /notification-preferences`
returns the caller's full opt-in matrix —

```json
{
  "order.placed": {"mail": false, "sms": false, "push": false},
  "order.status_changed": {"mail": false, "sms": false, "push": false},
  "order.rider_assigned": {"mail": false, "sms": false, "push": false},
  "payment.received": {"mail": false, "sms": false, "push": false}
}
```

— every combination defaulting `false` for a brand-new user; `database`
isn't listed since it's always on and isn't settable here. `PUT
/notification-preferences` takes `{"preferences": [{"event_key":
"order.placed", "channel": "mail", "enabled": true}, ...]}` (each
`event_key`/`channel` validated against the same fixed lists the GET
returns) and responds with the updated matrix. `POST /device-tokens`
(body: `{"token": "...", "platform": "ANDROID|IOS|WEB"}`) registers a
push target for the authenticated user — re-registering an existing
token reassigns it to whoever just registered it, so a shared or reset
device never keeps notifying its previous owner. `DELETE /device-tokens`
(body: `{"token": "..."}`) unregisters one, scoped to the caller's own
tokens only.

Every Phase 8 notification (`order.placed`/`order.status_changed`/
`order.rider_assigned`) plus a new `payment.received` (fired by
`PaymentService` when a payment settles `PAID`, to staff with
`payments.view`) now goes out over whichever of mail/SMS/push the
recipient has opted into, on top of the always-on `database` channel —
nothing changes about the `/notifications` inbox itself, this phase
only adds where else the same event can land.

Customer-facing notifications (Phase 10), all under the existing
customer group's `CUSTOMER_APP`-gated block, and *literally the same
controllers* the staff routes above use: `GET
/customer/notifications[?unread_only=1]`, `GET
/customer/notifications/unread-count`, `PATCH
/customer/notifications/{notification}/read`, `PATCH
/customer/notifications/read-all`, `GET`/`PUT
/customer/notification-preferences`, `POST`/`DELETE
/customer/device-tokens` — request/response shapes identical to their
staff equivalents. The one real difference: `GET
/customer/notification-preferences` returns a matrix with only two
event keys (`order.placed`, `order.status_changed`) instead of four,
and `PUT` rejects any other event key with `VALIDATION_ERROR` (422) —
`NotificationPreferenceService::eventsFor()` decides the list based on
whether the authenticated caller is a `User` or a `Customer`. A
customer who placed an order, or was attached to a staff-placed one via
`customer_id`, receives `order.placed`/`order.status_changed` the same
way branch staff do, just with the notification body worded from their
own point of view (see "Notable design decisions").

Super Admin (`/super-admin/*`): `dashboard`, `restaurants` (CRUD +
`/status` + `/features/{feature}`), `subscription-plans`, and
`restaurants/{restaurant}/subscription`.

## Notable design decisions (and one real bug caught by testing)

- **`User` is deliberately not tenant-scoped.** Every other tenant model
  gets the automatic global scope, but looking up a user by email during
  login has to happen *before* any tenant context exists — a global scope
  there would make login itself impossible to satisfy. Every place that
  lists/manages users instead goes through an explicit `ofRestaurant()`
  scope. This is documented in `app/Models/User.php`.
- **`Role` is also not auto-scoped**, because system role templates
  (`restaurant_id = null`) must stay visible alongside a restaurant's own
  custom roles — an `availableTo()` scope handles both.
- **Route model binding is avoided for tenant-scoped models** (`Branch`,
  in particular). Laravel resolves `{branch} → Branch $branch` via
  `SubstituteBindings`, which runs as part of the framework's global `api`
  middleware group — *before* the app's own `tenant` middleware has set
  the `TenantContext`. Implicit binding would therefore always evaluate
  the tenant scope with no restaurant set and 404 on every request. Routes
  take a raw `int` id and load the model inside the controller instead,
  after tenant scoping is guaranteed to be in place.
- **`TenantContext` always resets at the top of `IdentifyTenant`,
  unconditionally**, rather than only overwriting the fields relevant to
  the current request. It's a container singleton; on classic PHP
  (php-fpm, `artisan serve`) that's moot since every request gets a fresh
  container, but under a worker-reuse runtime (Laravel Octane) a stale
  `bypass = true` left over from a Super Admin request could otherwise
  leak into the very next request and skip tenant scoping entirely. Caught
  this while writing `ConfigEndpointTest` and `TenantIsolationTest`
  regression tests for it — both simulate "Super Admin request, then a
  different request in the same process" and assert no leakage.
- A related, real bug surfaced during testing itself (not a production
  bug): Laravel's `AuthManager` caches the guard it resolves for the
  lifetime of the application container. Multiple simulated HTTP requests
  in one PHPUnit test method share that container, so a second request
  with a *different* user's bearer token would silently keep resolving to
  whichever user authenticated first. `tests/TestCase::withUserToken()`
  calls `forgetGuards()` before attaching each token to work around it —
  worth knowing about if you add tests that switch users mid-test.
- **Menu entities (`Category`, `Product`, `ModifierGroup`, `Modifier`)
  avoid implicit route-model-binding too**, for the exact same reason as
  `Branch` — routes take a raw `int` id and load the model inside the
  controller after tenant scoping is guaranteed to be active.
- **A missing `branch_products` row means "inherit the product's own
  status/base_price"** rather than every branch needing an explicit copy
  of every product. `Product::isAvailableAtBranch()` /
  `priceAtBranch()` are the only two places that read this table, and
  `MenuService` is the only caller — so there's exactly one implementation
  of "what does this cost/does this exist here" to keep correct as the
  platform grows (order creation in Phase 3 will call the same two
  methods rather than re-deriving price/availability itself).
- **`ProductController::updateModifierGroups()` silently drops any id
  that doesn't resolve** under the tenant scope, rather than erroring —
  the same "forged id from another tenant" defense as
  `TenantIsolationTest::test_creating_a_branch_always_attaches_the_current_tenant_even_if_forged`,
  just applied to a sync() call instead of a foreign key column.
- **Which order type a request is validated against is decided inside
  `OrderService`, not by route middleware.** Every other feature gate in
  this app (`feature:KEY`) is a static route-level check because the
  route already implies the feature. An order's type is a field in the
  request body, so `OrderService::assertOrderTypeAllowed()` calls
  `FeatureService::isEnabled()` directly with the *submitted* order
  type — the same service the `feature` middleware itself calls, just
  invoked dynamically instead of declaratively.
- **A branch's `order_types` setting can only ever narrow, never widen,
  what the restaurant-level feature flags already allow.** Enabling the
  `DELIVERY` feature for a restaurant doesn't mean every branch accepts
  delivery — `OrderService` checks both, in that order (feature first,
  then the branch/restaurant `order_types` list), so a branch can opt out
  of an order type the restaurant otherwise supports, but never opt into
  one the subscription doesn't.
- **A cross-tenant `branch_id` on `POST /orders` or `POST /tables`
  currently surfaces as 404, not 403.** `User::canAccessBranch()` returns
  `true` unconditionally for a restaurant-wide user without checking the
  branch's `restaurant_id`, so the `branch.access` middleware alone
  wouldn't catch a forged id from another tenant — but every store()
  method immediately re-loads that id through `Branch::findOrFail()`,
  which *is* tenant-scoped, and that's what actually rejects it (matching
  the 404-for-cross-tenant convention every other isolation test in this
  suite already uses, e.g. `TenantIsolationTest`). This is safe today
  because that re-load happens everywhere `branch_id` is accepted as
  input, but it's a backstop, not a first line of defense — a future
  endpoint that trusted `canAccessBranch()` alone without also re-loading
  the branch through a tenant-scoped query would reopen this. Tightening
  `canAccessBranch()` itself to check `restaurant_id` too is a reasonable
  follow-up hardening item, tracked here rather than silently fixed, since
  it doesn't change behavior anywhere the app actually relies on it today.
- **`OrderStatus::canTransitionTo()` takes the order's `OrderType` as a
  second argument now**, because the valid sequence itself depends on the
  order type: only `DELIVERY` passes through `OUT_FOR_DELIVERY` between
  `READY` and `COMPLETED`; `DINE_IN`/`TAKEAWAY` go straight from `READY` to
  `COMPLETED`, same as before Phase 4. The private `sequenceFor()` helper
  builds the right sequence per type; `CANCELLED` remains reachable from
  any non-terminal status regardless of type.
- **Rider assignment is its own method (`OrderService::assignRider()`),
  not folded into `updateStatus()`**, because it isn't a status
  transition at all — reassigning a delivery order to a different rider
  doesn't change (and shouldn't require changing) the order's status, and
  treating it as one would have forced an artificial status value for
  "who's carrying this."
- **`orders.assign_rider` is a separate permission from `orders.update`**,
  and additionally gated by the `RIDER_APP` feature at the route level —
  a restaurant that doesn't run delivery/riders at all shouldn't expose
  this action even to staff who can otherwise freely update order status.
  It's granted to `branch-manager` by default, deliberately *not* to
  `rider` (a rider shouldn't be able to reassign deliveries to themselves
  or others) or to `cashier`.
- **A rider's own order visibility is an additional filter layered on top
  of the existing branch-access scoping, not a replacement for it** —
  `User::hasRoleSlug('rider')` narrows `OrderController::index()` to
  `assigned_rider_id = $user->id` *in addition to* the branch restriction
  every other branch-scoped role already gets, so a rider who somehow
  lost branch access loses order visibility too, rather than the rider
  check alone deciding the outcome.
- **`Table::activeOrder()` reuses `OrderStatus::occupiesTable()`** — the
  exact same source of truth `OrderService::updateStatus()` already uses
  to decide whether completing/cancelling an order should release its
  table — rather than a second, potentially-drifting definition of
  "active" living in the relation itself.
- **A branch with zero active delivery zones is unrestricted, not
  blocked.** `GeofencingService::branchHasZonesConfigured()` gates
  everything: no zones at all means Phase 5 is a no-op and Phase 3's
  original delivery behavior (any address, no coordinates needed)
  applies unchanged. Coordinates only become required — and only then
  get checked against anything — once a branch has opted in by creating
  its first active zone. This was a deliberate choice to keep Phase 5
  additive rather than a breaking change to every existing delivery
  order flow.
- **Zone shape math lives entirely on `DeliveryZone::containsPoint()`**,
  not in `GeofencingService` or `OrderService` — a `RADIUS` zone is a
  standard haversine-distance-vs-radius check, a `POLYGON` zone is a
  standard ray-casting point-in-polygon test, and both are private
  implementation details behind one public method so callers never need
  to know (or grow a matching `if ($type === ...)`) which shape they're
  dealing with.
- **A zone's `delivery_fee_override` sits between the branch/restaurant
  default and the free-delivery threshold in `resolveDeliveryFee()`'s
  precedence, not above it.** A big enough order is free to deliver
  regardless of which zone (if any) it resolved into; below that
  threshold, a zone's own override (if it set one) wins over the
  branch/restaurant default, same "most specific override wins" pattern
  `resolveOrderTypes()`/`resolveMinOrderAmount()` already use.
- **Overlapping zones aren't validated against each other** — if two
  zones on the same branch both contain a point, `GeofencingService::
  resolveZone()` returns whichever was created first (`orderBy('id')`).
  Restaurants are expected to keep their own zones non-overlapping;
  detecting/rejecting overlap at creation time is a reasonable follow-up,
  not required for this MVP.
- **A real bug caught by Phase 6's own testing, not by inspection:**
  `Customer` used the automatic `BelongsToTenant` global scope, exactly
  like every other tenant-owned model — except `User` deliberately
  doesn't, for a documented reason (see `User`'s own docblock):
  authenticating a bearer token happens *before* the `tenant` middleware
  runs, and `TenantScope` fails closed (`whereRaw('1 = 0')`) with no
  `TenantContext` set yet. The result: every customer's token silently
  resolved to no user at all — 401 on literally every authenticated
  `/customer/*` request, discovered the moment `CustomerAuthController`
  was actually exercised end-to-end rather than assumed to work "because
  it's the same trait every other model uses." Fixed by removing the
  trait from `Customer` (mirroring `User`'s existing pattern exactly,
  including its own `ofRestaurant()` scope) and auditing every direct
  `Customer::` lookup for one that had been silently relying on the
  now-removed scope — there was exactly one,
  `OrderService::createOrder()`'s `customer_id` lookup (a staff member
  attaching a known customer to an order), now explicitly scoped and
  covered by a regression test
  (`OrderIsolationTest::test_cannot_attach_another_restaurants_customer_id_to_an_order`).
  `CustomerAddress` was checked too and left as-is: it's never queried
  before a `TenantContext` exists (only after an already-authenticated
  customer's own routes), so its global scope is safe.
- **`staff.guard`/`customer.guard` exist because `auth:sanctum` alone
  authenticates *any* `HasApiTokens` model a bearer token belongs to** —
  as of Phase 6 that's `User` or `Customer`, and the staff pipeline calls
  methods (`hasPermission()`, `canAccessBranch()`, `isActive()`, ...)
  that don't exist on `Customer` at all. Without an explicit check, a
  customer's token reaching a staff route wouldn't get a clean 403 — it
  would crash with an uncaught "call to undefined method" Error. Both
  guards are two lines long and run before anything persona-specific
  does.
- **`IdentifyTenant` (the `tenant` middleware) is shared by both
  pipelines rather than duplicated.** It already special-cases Super
  Admin; Phase 6 adds one more branch — `! $user instanceof User` — that
  gives a `Customer` the same restaurant-scoping treatment (just no
  branch-list derivation, since customers have no staff branch
  assignments to derive one from). One centralized place decides "what
  restaurant is this actor scoped to," matching the same instinct behind
  `BelongsToTenant` itself.
- **`RestaurantResolver` is an extraction, not new logic** — the
  Host-header-then-`?restaurant=slug` resolution was already duplicated
  verbatim in `ConfigController` and `MenuController`; adding
  `CustomerAuthController` as a third near-identical copy was the natural
  threshold to pull it into its own service instead, with the first two
  controllers refactored to use it too (behavior unchanged, confirmed by
  the existing `ConfigEndpointTest`/`PublicMenuEndpointTest` suites still
  passing untouched).
- **`OrderService::createOrder()`'s `$placedBy` parameter is nullable
  and gained an optional trailing `$orderingCustomer`** rather than
  becoming two separate methods for "staff places an order" vs.
  "customer places their own order" — the pricing/validation/geofencing
  logic is identical either way; only *who* it's attributed to differs.
  `$orderingCustomer`, when given, always wins over any `customer_id` in
  the request body, since the acting customer is who they're
  authenticated as, never a client-supplied id.
- **Coupon limits are checked twice, on purpose.** `CouponService::
  resolve()` validates a submitted code optimistically (no row lock)
  right after `OrderService` computes the subtotal, so a bad/exhausted
  code fails fast with a clear message before any further pricing work
  happens. `CouponService::redeem()` re-validates the *same* coupon
  again, under `lockForUpdate()`, from inside the DB transaction,
  immediately before writing the `CouponRedemption` row — closing the
  race two concurrent requests could otherwise exploit against the very
  last remaining use of a limited coupon. `resolve()` alone can't
  prevent that; only a lock held for the duration of the write can.
- **`Coupon::where('code', ...)` is filtered by an explicit
  `restaurant_id` in `CouponService`, even though `Coupon` is
  tenant-scoped and would filter itself correctly anyway.** Unlike
  `Customer`/`User`, a `Coupon` is never looked up during Sanctum
  authentication, so `TenantContext` is always already established by
  the time this runs — the global scope alone *is* safe here. The
  explicit filter is added anyway, as a deliberate belt-and-suspenders
  choice: a promo code is money, and Phase 6's `Customer` bug (below) is
  a standing reminder not to trust a global scope alone twice on
  anything that touches it.
- **`discount_amount`/`coupon_id` on `orders` existed since Phase 3**
  (the orders migration's own comment said a customer-placed variant was
  coming; the columns anticipated this the same way) — Phase 7 is the
  first phase to actually populate either, and does so additively:
  `total_amount = subtotal + tax + delivery_fee - discount_amount`,
  floored at zero, with `discount_amount` defaulting to `0` exactly like
  before when no `coupon_code` is submitted, so every pre-Phase-7 order
  and every order that never uses a coupon computes identically to
  before this phase existed.
- **No real payment gateway is integrated — deliberately, for now.**
  There's no gateway account to key a real integration off yet, so
  `PaymentService` instead models the API shape one would plug into:
  `CASH`/`CARD` settle in the same call that records them (a staff
  member is physically handling the money or terminal *right now*, so
  there's nothing to wait on); `ONLINE` starts `PENDING` and needs a
  separate `confirm()` call, standing in for a gateway's webhook or
  redirect callback. Wiring in a real gateway later means calling
  `confirm()`/`fail()` from its webhook handler instead of a
  client-triggered endpoint — `PaymentService`'s own contract doesn't
  need to change, only who calls it.
- **`Order::outstandingBalance()` is the one place "how much of this
  order is still unpaid" gets computed** (`total_amount` minus the sum of
  every `PAID` payment — `PENDING`/`FAILED`/`REFUNDED` never count).
  Recording a new payment, confirming a pending one, and refunding a paid
  one all reason about this same number rather than each re-deriving
  their own notion of "what's left to pay," which is also why a refund
  automatically reopens exactly that much balance with no extra code.
- **CASH/CARD payments are never feature-gated; ONLINE alone requires
  `ONLINE_PAYMENTS`.** Collecting money at the till is core POS
  bookkeeping every plan needs, not a paid add-on — the same reasoning
  `DINE_IN`/`TAKEAWAY` get as core order types while `DELIVERY` is a
  feature. All three methods still share one endpoint (`method` is a
  body field), so the feature check lives inside `PaymentService`
  itself rather than as route middleware — identical reasoning to why
  `order_type` gating lives inside `OrderService` rather than the route.
- **A customer's own payment endpoints never accept a `method` field at
  all** (`CustomerPaymentController::store()` hardcodes `'ONLINE'` before
  calling the shared `PaymentService`) — a customer has no till to hand
  cash to and no terminal to swipe a card on, so there's no legitimate
  value that field could ever take from that side of the API.
- **`ReportService` queries `DB::table(...)` directly, never an Eloquent
  model.** Two reasons, not one: a report has no use for a hydrated
  model, only aggregate numbers; and going through `Order`/`Payment`
  would apply their own enum casts (`OrderType`, `OrderStatus`,
  `PaymentMethod`, `PaymentStatus`) to a `selectRaw()` grouping column,
  turning e.g. a grouped `status` value into an enum *instance* — which
  can't be used as a PHP array key at all (`$byMethod[$row->method]`
  would throw). `DB::table()` sidesteps this entirely by never
  hydrating anything, at the cost of filtering by `restaurant_id`
  explicitly in every method rather than getting it for free from
  `BelongsToTenant` — the same trade `CouponService` already made for a
  different reason (see its own entry above).
- **A cancelled order counts in `total_orders`/`orders_by_status` but
  is excluded from every revenue figure** (`order_count`, `subtotal`,
  `net_revenue`, `average_order_value`, `by_order_type`, and
  top-products' `quantity_sold`) — a cancelled order generated no real
  revenue and shipped no real food, so folding it into "how much did we
  make" or "what sold" would understate neither number so much as
  answer a different question than the one being asked. Counting it in
  the status breakdown at all is what makes that breakdown complete
  rather than silently missing orders that existed.
- **The `REPORTS` feature key has existed since Phase 1** (seeded onto
  Standard/Premium in `SubscriptionPlanSeeder` from the very first
  commit) **but nothing ever actually checked it** — `GET /audit-logs`
  was reachable by the `reports.view` permission alone, with the
  feature flag sitting in the catalog unused. Phase 8 is the natural
  place to close that, since it's the phase that gives `REPORTS` a
  real meaning: every report endpoint, *including* the pre-existing
  `audit-logs` one, now requires both `feature:REPORTS` and
  `permission:reports.view`. Confirmed safe against the existing test
  suite first — every test that already exercised `/audit-logs` used
  the Standard plan, which has always included `REPORTS`, so nothing
  regressed.
- **Coupon usage is never branch-filtered, on purpose** — `Coupon` has
  no `branch_id` column at all (Phase 7's own design: a promo code is a
  restaurant-wide marketing decision, not a per-location one), so
  `ReportController::coupons()` skips the branch-access dance every
  other report method goes through; there is nothing for a `branch_id`
  to narrow.
- **Notification firing lives inside `OrderService`'s three existing
  methods (`createOrder`/`updateStatus`/`assignRider`), not duplicated
  in `OrderController` and `CustomerOrderController`.** Both
  controllers already funnel order creation through the same
  `OrderService::createOrder()` (that's precisely what let Phase 6 add
  customer ordering without duplicating Phase 3's validation) — firing
  `NotificationService::orderPlaced()` there once, right after the
  DB transaction commits rather than from inside it, covers a
  staff-placed and a customer-placed order identically for free.
- **Only `READY`/`OUT_FOR_DELIVERY`/`COMPLETED`/`CANCELLED` fire an
  `order.status_changed` notification** — the "meaningful handoff"
  points a human actually needs to react to — while
  `PENDING -> CONFIRMED -> PREPARING` stay silent. Notifying on every
  single internal kitchen step would bury the ones that matter in noise
  nobody reads.
- **This app's `notifications` table is Laravel's own stock
  database-notifications shape**, not a bespoke one — `User` already
  had the framework's `Notifiable` trait from its default scaffolding
  (present since Phase 1, unused until now), so Phase 8 supplies the one
  migration that trait was always missing rather than inventing a
  parallel notification system next to it. `notifiable` is polymorphic
  (`morphs`), so a future phase could notify a `Customer` too with no
  schema change — Phase 8 itself only ever targets `User`.
- **No notification implements `ShouldQueue`.** This app's
  `QUEUE_CONNECTION` defaults to `database`, and nothing here runs a
  queue worker — a queued notification would sit in the `jobs` table
  forever, never actually landing in `notifications`. Every
  notification class sends synchronously in the same request that
  triggered it instead, exactly like every other side effect in this
  codebase (`AuditLog::create()`, etc.), so a test (or a real request)
  sees the row immediately with no queue-processing dependency.
- **`SmsGateway`/`PushGateway` are contracts, not concrete providers** —
  the same "model the API shape now, swap the driver later" move
  `PaymentService` made for a payment gateway in Phase 7. Only a `log`
  driver is bound (`AppServiceProvider`, keyed off
  `config('notifications.sms.driver')`/`.push.driver`, both defaulting
  to `log` in `.env.example`), so this phase runs correctly with zero
  external accounts — swapping in Twilio/FCM/etc. later means writing
  one new class per contract and changing one config value, never
  touching `SmsChannel`/`PushChannel` or any `Notification` subclass.
- **mail/sms/push default to entirely OFF, per user, per event, per
  channel** (`NotificationPreferenceService`) — deliberately the
  opposite default from `database`, which stays unconditional. Nothing
  about turning Phase 9 on for an existing restaurant should start
  emailing, texting, or push-notifying staff who never asked for it;
  every non-database channel requires an explicit `enabled: true` row
  first. `HasChannelPreferences` (a trait shared by all four
  notification classes) is the one place that reads those preferences
  and decides `via()` — same "single source of truth" instinct as
  `FeatureService`/`PermissionService`.
- **Preferences and device tokens are user-scoped, not tenant-scoped** —
  `NotificationPreference`/`DeviceToken` deliberately skip
  `BelongsToTenant`, the same way Laravel's own `notifications` table
  (Phase 8) is keyed by `notifiable_id` rather than `restaurant_id`. A
  staff member's own opt-in choices and registered devices aren't a
  restaurant-owned resource; they belong to the user regardless of
  which restaurant currently employs them.
- **Re-registering an existing device token reassigns it** rather than
  rejecting the second registration or leaving two rows — `token` is
  globally unique, and `DeviceTokenController::store()` is an
  `updateOrCreate` keyed on it. A phone shared between shifts (or wiped
  and re-logged-in) should always push-notify whoever's holding it now,
  never whoever registered it first.
- **`payment.received`'s audience is `payments.view`, deliberately
  narrower than `orders.view`.** Every other notification in this app
  reuses `orders.view` as "can see this branch's activity," but kitchen
  staff can see an order without any business seeing money move on it —
  `NotificationService::staffVisibleTo()` was generalized to accept the
  permission key as a parameter specifically so `payments.view` could
  reuse the same branch-access + permission filter without duplicating
  it.
- **`payment.received` fires from two different points in
  `PaymentService`** — inside `record()` when a CASH/CARD payment
  settles immediately, and inside `confirm()` when a previously-PENDING
  ONLINE payment finally settles — rather than from one place, because
  those are genuinely the two moments a payment actually becomes PAID.
  `refund()`/`fail()` don't fire anything in this phase; a
  `payment.refunded` event would follow the identical pattern if a
  future phase wants one.
- **The Phase 9 tables were rebuilt (create-copy-drop-rename), not
  altered in place**, to add `customer_id` alongside `user_id` and make
  `user_id` nullable — this app doesn't have `doctrine/dbal` installed,
  which Laravel's `->nullable()->change()` column-modification API
  requires under the hood, and pulling in a whole new dependency for one
  column change wasn't worth it. The rebuild runs identically on SQLite
  (tests) and MySQL (production) with nothing beyond what every other
  migration here already uses. Two *separate* unique indexes
  (`user_id`+event+channel, `customer_id`+event+channel) were required
  instead of one combined one — SQL treats `NULL` as distinct from
  `NULL` for uniqueness, so a single combined index would have let every
  customer row (whose `user_id` is always `NULL`) collide-check against
  nothing and silently allow duplicates.
- **`NotificationPreferenceService`/`NotificationPreferenceController`/
  `DeviceTokenController` were generalized, not duplicated, to add
  customer support** — typed against
  `Illuminate\Contracts\Auth\Authenticatable` (the interface both `User`
  and `Customer` already implement) instead of `User` specifically, with
  one private `ownerColumn()` helper deciding `user_id` vs `customer_id`
  and one `eventsFor()` deciding which event list applies. The
  controllers and the `/customer/*` routes needed zero new code beyond
  registering the existing classes under the customer-guarded group —
  the same "don't build a second copy when the first one doesn't
  actually assume anything about who's calling it" instinct
  `NotificationController` already demonstrated back in Phase 8/9.
- **`OrderPlacedNotification`/`OrderStatusChangedNotification` are
  reused for the customer audience, not duplicated into
  `Customer*Notification` classes.** Both already received `$notifiable`
  as a parameter on every method Laravel calls (`toArray`/`toMail`/
  `toSms`/`toPush`) purely to satisfy the interface; Phase 10 just
  started actually reading it to decide wording (`Customer` → "Your
  order...", anyone else → "A new order..."). `order.rider_assigned`/
  `payment.received` weren't touched at all — `NotificationService`
  simply never calls `$order->customer->notify(...)` for either, so no
  audience check was even needed on those two classes.

## What's intentionally not built yet

Per the spec's own phasing (`Development Order`, `MVP Priority Order`),
Phase 8 was the last phase in the *originally agreed* plan — every phase
scoped there is built. Phase 9 (real notification channels) is an
add-on built afterward at the user's own request, not part of that
original scope, and the gaps it closes are called out below rather than
left in the Phase 8 paragraph where they used to live. Phase 4 covers
the kitchen/waiter/rider staff-workflow
surface, Phase 5 covers delivery zones/geofencing, Phase 6 covers the
customer-facing app/website (auth, saved addresses, ordering), Phase 7
covers coupons and payments, and Phase 8 covers reporting and
notifications — all on top of Phase 3's generic `/orders`/`OrderService`
core; a dedicated per-app UI/view still belongs to the frontend, not
this backend. Not yet built in Phase 6 specifically: phone
verification/OTP (Customer has a `phone_verified_at` column from
Phase 1, unused so far — password-based auth was enough to prove the
flow out), password reset, and a staff-facing view of a restaurant's
customers (no `CustomerController` for staff exists yet; staff can
already attach a known `customer_id` to an order they place, from
Phase 3). Not yet built in Phase 7 specifically: an actual payment
gateway integration (Stripe/PayPal/a local processor) — `PaymentService`
models the shape one would plug into (see "Notable design decisions")
but nothing calls out to a real one yet, so `ONLINE` payments are
confirmed by an explicit API call rather than a webhook; partial
refunds (a refund is all-or-nothing per `Payment` row — refunding part
of a larger cash payment means the frontend records two separate
payments to begin with); a coupon scoped to specific branches, products,
or order types (a coupon is restaurant-wide and item-agnostic, applying
a flat/percentage discount to the whole order); and coupon "stacking"
(only one `coupon_code` per order). Not yet built in Phase 8
specifically: CSV/PDF export of any report (every report is a JSON
aggregate over a date range, read once, not a downloadable file); and a
report scheduled/emailed on a recurring basis (there's no scheduler
wired up to call these endpoints automatically). Phase 9 resolved the
rest of what used to be listed here — real mail/SMS/push channels,
per-user preferences, and a `payment.received` event all now exist (see
"What's actually here" and "Notable design decisions").

Not yet built in Phase 9 specifically: an actual SMS/push *provider*
integration (Twilio, Vonage, FCM, APNs, ...) — `SmsGateway`/
`PushGateway` model the shape one would plug into, exactly like
`PaymentService` did for a payment gateway in Phase 7, but the only
bound implementation is the `log` driver, so "sent" currently means "a
line in the application log," not "a phone actually buzzed." No
notification implements `ShouldQueue` here either (same "no queue
worker running" reasoning as Phase 8), so a slow real SMS/push/mail call
in production would block the request that triggered it — swapping in
a real provider should come with a queue worker, at which point
`ShouldQueue` becomes worth adding. And there's no "quiet hours" or
rate-limiting on any channel — an opted-in recipient gets every
matching event as it happens, with no batching or digesting. Phase 10
resolved the "customers still never receive a notification" gap that
used to be listed here — see Phase 10's own section above.

Not yet built in Phase 10 specifically: `order.rider_assigned`/
`payment.received` staying staff/rider-only isn't a gap so much as a
deliberate boundary, but a customer-facing equivalent of either ("your
rider is on the way", a payment receipt) would be genuinely new scope,
not just extending what's there; a *new* status-worthy event tailored
to the customer relationship specifically (e.g. a "your order was
refunded" notification tied to `PaymentService::refund()`) doesn't
exist — Phase 10 only extended the two events that already existed for
staff. There's still no `CustomerController` for staff to browse/manage
a restaurant's customers (called out since Phase 6) — a customer
opting into SMS/push notifications is invisible to staff today, visible
only through the customer's own `GET /customer/notification-preferences`.
And a customer's device token still needs the *customer's own app* to
register it via `POST /customer/device-tokens` — there's no admin-side
way to see which customers have push enabled.

The database schema, services, and middleware pipeline throughout this
backend were built so each of these could plug in without changing the
foundation — e.g. any new tenant-owned table just uses `BelongsToTenant`,
any new route just adds `feature:`/`permission:` middleware, a new
notification type is a new `Illuminate\Notifications\Notification`
subclass plus one call site in `OrderService`/`PaymentService` (or
wherever the triggering event actually happens), and a new delivery
channel is a new `SmsGateway`/`PushGateway`-style contract bound in
`AppServiceProvider` — exactly the pattern `ReportService`,
`NotificationService`, and now `NotificationPreferenceService`
themselves followed to get here.
# restaurent-backend
