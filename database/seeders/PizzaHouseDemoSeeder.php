<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Table;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Dummy menu/table data for the "Pizza House" demo restaurant
 * (pizza-house@owner.test) — mirrors BurgerBarnDemoSeeder exactly, just
 * with a pizza-themed menu, since Pizza House otherwise has zero
 * products (unlike Burger Barn, which already has this). Every write is
 * keyed by the model's own unique constraint via updateOrCreate(), so
 * this is safe to run more than once — it will never create duplicates.
 *
 * Product/category images point at placehold.co (a plain text-on-color
 * placeholder image generator, not real photography) rather than any
 * real product photography, since this is throwaway demo data, not a
 * real menu.
 *
 * Run with: php artisan db:seed --class=PizzaHouseDemoSeeder
 */
class PizzaHouseDemoSeeder extends Seeder
{
    public function run(): void
    {
        // This runs as a console command, outside any HTTP request, so
        // nothing has set a TenantContext — every tenant-scoped model
        // (Category, Product, ModifierGroup, Modifier, Table, Branch) would
        // otherwise fail closed and see zero rows, exactly like a Super
        // Admin's requests do (see IdentifyTenant). Bypass it deliberately,
        // the same way Super Admin platform-management code paths do.
        app(TenantContext::class)->bypass(true);

        $restaurant = Restaurant::where('slug', 'pizza-house')->first();

        if (! $restaurant) {
            $this->command?->error('No restaurant with slug "pizza-house" found — nothing to seed.');
            return;
        }

        $branch = $restaurant->branches()->first();

        if (! $branch) {
            $this->command?->error('Pizza House has no branch yet — create one first.');
            return;
        }

        // ---- Categories --------------------------------------------------

        $categoryDefs = [
            ['name' => 'Pizzas', 'color' => 'b91c1c', 'order' => 10],
            ['name' => 'Sides', 'color' => 'ca8a04', 'order' => 20],
            ['name' => 'Drinks', 'color' => '0369a1', 'order' => 30],
            ['name' => 'Desserts', 'color' => '9333ea', 'order' => 40],
        ];

        $categories = [];
        foreach ($categoryDefs as $def) {
            $categories[$def['name']] = Category::updateOrCreate(
                ['restaurant_id' => $restaurant->id, 'slug' => Str::slug($def['name'])],
                [
                    'name' => $def['name'],
                    'description' => "{$def['name']} at Pizza House",
                    'image' => $this->placeholder($def['name'], $def['color']),
                    'display_order' => $def['order'],
                    'status' => 'ACTIVE',
                ]
            );
        }

        // ---- Modifier groups + modifiers ---------------------------------

        $sizeGroup = ModifierGroup::updateOrCreate(
            ['restaurant_id' => $restaurant->id, 'name' => 'Size'],
            ['selection_type' => 'SINGLE', 'is_required' => true, 'min_selections' => 1, 'max_selections' => 1, 'display_order' => 10]
        );
        $sizeModifiers = [
            ['name' => '10" Small', 'price' => 0, 'default' => true],
            ['name' => '14" Medium', 'price' => 3.00, 'default' => false],
            ['name' => '18" Large', 'price' => 6.00, 'default' => false],
        ];
        foreach ($sizeModifiers as $i => $m) {
            Modifier::updateOrCreate(
                ['modifier_group_id' => $sizeGroup->id, 'name' => $m['name']],
                ['restaurant_id' => $restaurant->id, 'price_adjustment' => $m['price'], 'is_default' => $m['default'], 'display_order' => ($i + 1) * 10, 'status' => 'ACTIVE']
            );
        }

        $crustGroup = ModifierGroup::updateOrCreate(
            ['restaurant_id' => $restaurant->id, 'name' => 'Crust'],
            ['selection_type' => 'SINGLE', 'is_required' => true, 'min_selections' => 1, 'max_selections' => 1, 'display_order' => 20]
        );
        $crustModifiers = [
            ['name' => 'Hand Tossed', 'price' => 0, 'default' => true],
            ['name' => 'Thin & Crispy', 'price' => 0, 'default' => false],
            ['name' => 'Stuffed Crust', 'price' => 2.50, 'default' => false],
        ];
        foreach ($crustModifiers as $i => $m) {
            Modifier::updateOrCreate(
                ['modifier_group_id' => $crustGroup->id, 'name' => $m['name']],
                ['restaurant_id' => $restaurant->id, 'price_adjustment' => $m['price'], 'is_default' => $m['default'], 'display_order' => ($i + 1) * 10, 'status' => 'ACTIVE']
            );
        }

        $toppingsGroup = ModifierGroup::updateOrCreate(
            ['restaurant_id' => $restaurant->id, 'name' => 'Extra Toppings'],
            ['selection_type' => 'MULTIPLE', 'is_required' => false, 'min_selections' => 0, 'max_selections' => null, 'display_order' => 30]
        );
        $toppingsModifiers = [
            ['name' => 'Extra Cheese', 'price' => 1.25],
            ['name' => 'Pepperoni', 'price' => 1.50],
            ['name' => 'Mushrooms', 'price' => 1.00],
            ['name' => 'Jalapenos', 'price' => 0.75],
            ['name' => 'Olives', 'price' => 1.00],
        ];
        foreach ($toppingsModifiers as $i => $m) {
            Modifier::updateOrCreate(
                ['modifier_group_id' => $toppingsGroup->id, 'name' => $m['name']],
                ['restaurant_id' => $restaurant->id, 'price_adjustment' => $m['price'], 'is_default' => false, 'display_order' => ($i + 1) * 10, 'status' => 'ACTIVE']
            );
        }

        // ---- Products ------------------------------------------------------

        $productDefs = [
            'Pizzas' => [
                ['name' => 'Margherita', 'price' => 9.99, 'desc' => 'San Marzano tomato, fresh mozzarella, basil.', 'color' => 'b91c1c', 'groups' => [$sizeGroup, $crustGroup, $toppingsGroup]],
                ['name' => 'Pepperoni Feast', 'price' => 11.99, 'desc' => 'Double pepperoni, mozzarella, tomato sauce.', 'color' => '991b1b', 'groups' => [$sizeGroup, $crustGroup, $toppingsGroup]],
                ['name' => 'BBQ Chicken', 'price' => 12.99, 'desc' => 'Grilled chicken, BBQ sauce, red onion, mozzarella.', 'color' => '7f1d1d', 'groups' => [$sizeGroup, $crustGroup, $toppingsGroup]],
                ['name' => 'Veggie Supreme', 'price' => 10.99, 'desc' => 'Peppers, onion, mushroom, olives, mozzarella.', 'color' => '15803d', 'groups' => [$sizeGroup, $crustGroup, $toppingsGroup]],
                ['name' => 'Four Cheese', 'price' => 11.49, 'desc' => 'Mozzarella, parmesan, gorgonzola, provolone.', 'color' => 'ca8a04', 'groups' => [$sizeGroup, $crustGroup, $toppingsGroup]],
            ],
            'Sides' => [
                ['name' => 'Garlic Bread', 'price' => 3.99, 'desc' => 'Toasted baguette, garlic butter, herbs.', 'color' => 'ca8a04', 'groups' => []],
                ['name' => 'Cheesy Breadsticks', 'price' => 4.49, 'desc' => 'Warm breadsticks, melted mozzarella, marinara dip.', 'color' => 'd97706', 'groups' => []],
                ['name' => 'Caesar Salad', 'price' => 4.99, 'desc' => 'Romaine, parmesan, croutons, Caesar dressing.', 'color' => 'a16207', 'groups' => []],
            ],
            'Drinks' => [
                ['name' => 'Cola', 'price' => 1.99, 'desc' => 'Ice-cold classic cola.', 'color' => '0369a1', 'groups' => [$sizeGroup]],
                ['name' => 'Iced Tea', 'price' => 2.29, 'desc' => 'Freshly brewed, lightly sweetened.', 'color' => '0284c7', 'groups' => [$sizeGroup]],
                ['name' => 'Sparkling Water', 'price' => 2.49, 'desc' => 'Chilled sparkling mineral water.', 'color' => '075985', 'groups' => []],
            ],
            'Desserts' => [
                ['name' => 'Tiramisu', 'price' => 4.99, 'desc' => 'Classic espresso-soaked tiramisu.', 'color' => '9333ea', 'groups' => []],
                ['name' => 'Cannoli', 'price' => 3.99, 'desc' => 'Crisp shell, sweet ricotta filling.', 'color' => '7e22ce', 'groups' => []],
            ],
        ];

        foreach ($productDefs as $categoryName => $products) {
            $category = $categories[$categoryName];
            foreach ($products as $i => $p) {
                /** @var Product $product */
                $product = Product::updateOrCreate(
                    ['restaurant_id' => $restaurant->id, 'slug' => Str::slug($p['name'])],
                    [
                        'category_id' => $category->id,
                        'name' => $p['name'],
                        'description' => $p['desc'],
                        'image' => $this->placeholder($p['name'], $p['color']),
                        'base_price' => $p['price'],
                        'preparation_time_minutes' => 15,
                        'display_order' => ($i + 1) * 10,
                        'status' => 'ACTIVE',
                    ]
                );

                if (! empty($p['groups'])) {
                    $product->modifierGroups()->sync(collect($p['groups'])->pluck('id'));
                }
            }
        }

        // ---- Tables ---------------------------------------------------------

        for ($n = 1; $n <= 6; $n++) {
            Table::updateOrCreate(
                ['branch_id' => $branch->id, 'table_number' => "T{$n}"],
                [
                    'restaurant_id' => $restaurant->id,
                    'capacity' => $n <= 4 ? 4 : 6,
                    'section' => $n <= 4 ? 'Main Hall' : 'Patio',
                    'status' => 'AVAILABLE',
                ]
            );
        }

        $this->command?->info('Pizza House demo data seeded: '
            .count($categories).' categories, '
            .Product::where('restaurant_id', $restaurant->id)->count().' products, '
            .ModifierGroup::where('restaurant_id', $restaurant->id)->count().' modifier groups, '
            .Table::where('branch_id', $branch->id)->count().' tables.');
    }

    private function placeholder(string $label, string $hexColor): string
    {
        $text = str_replace(' ', '+', $label);
        return "https://placehold.co/600x400/{$hexColor}/ffffff/png?text={$text}";
    }
}
