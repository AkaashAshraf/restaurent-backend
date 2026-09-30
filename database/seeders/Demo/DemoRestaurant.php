<?php

namespace Database\Seeders\Demo;

use App\Models\Branch;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;

/** What RestaurantBuilder made, handed on to OrderHistory. */
class DemoRestaurant
{
    public Restaurant $restaurant;

    /** @var array<string, Branch> keyed by branch code */
    public array $branches = [];

    /** @var array<string, array<string, User[]>> [branch code][role slug] */
    public array $staff = [];

    /** @var array<string, Product> keyed by slug */
    public array $products = [];

    /** @var array<string, array<int, array{slug: string, weight: int}>> [tag] */
    public array $tagged = [];

    /** @var Coupon[] */
    public array $coupons = [];

    /** @var array<string, Customer[]> [branch code] */
    public array $customers = [];

    /** @var array<int, array{role: string, branch: ?string, name: string, email: string}> */
    public array $logins = [];

    public function __construct(public array $data)
    {
    }

    public function style(): string
    {
        return $this->data['style'];
    }
}
