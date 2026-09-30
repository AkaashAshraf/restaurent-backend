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
 * Dummy menu/table data for the "Burger Barn" demo restaurant
 * (burger-barn@owner.test), so the admin panel has something real to look
 * at beyond a single empty branch. Every write is keyed by the model's own
 * unique constraint via updateOrCreate(), so this is safe to run more than
 * once — it will never create duplicates.
 *
 * Product/category images point at placehold.co (a plain text-on-color
 * placeholder image generator, not real photography) rather than any real
 * product photography, since this is throwaway demo data, not a real menu.
 *
 * Run with: php artisan db:seed --class=BurgerBarnDemoSeeder
 */
class BurgerBarnDemoSeeder extends Seeder
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

        $restaurant = Restaurant::where('slug', 'burger-barn')->first();

        if (! $restaurant) {
            $this->command?->error('No restaurant with slug "burger-barn" found — nothing to seed.');
            return;
        }

        $branch = $restaurant->branches()->first();

        if (! $branch) {
            $this->command?->error('Burger Barn has no branch yet — create one first.');
            return;
        }

        // ---- Categories --------------------------------------------------

        $categoryDefs = [
            ['name' => 'Burgers', 'color' => 'b91c1c', 'order' => 10],
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
                    'description' => "{$def['name']} at Burger Barn",
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
            ['name' => 'Regular', 'price' => 0, 'default' => true],
            ['name' => 'Large', 'price' => 1.50, 'default' => false],
        ];
        foreach ($sizeModifiers as $i => $m) {
            Modifier::updateOrCreate(
                ['modifier_group_id' => $sizeGroup->id, 'name' => $m['name']],
                ['restaurant_id' => $restaurant->id, 'price_adjustment' => $m['price'], 'is_default' => $m['default'], 'display_order' => ($i + 1) * 10, 'status' => 'ACTIVE']
            );
        }

        $extrasGroup = ModifierGroup::updateOrCreate(
            ['restaurant_id' => $restaurant->id, 'name' => 'Extras'],
            ['selection_type' => 'MULTIPLE', 'is_required' => false, 'min_selections' => 0, 'max_selections' => null, 'display_order' => 20]
        );
        $extrasModifiers = [
            ['name' => 'Extra Cheese', 'price' => 0.75],
            ['name' => 'Bacon', 'price' => 1.25],
            ['name' => 'Avocado', 'price' => 1.00],
            ['name' => 'Fried Egg', 'price' => 1.00],
        ];
        foreach ($extrasModifiers as $i => $m) {
            Modifier::updateOrCreate(
                ['modifier_group_id' => $extrasGroup->id, 'name' => $m['name']],
                ['restaurant_id' => $restaurant->id, 'price_adjustment' => $m['price'], 'is_default' => false, 'display_order' => ($i + 1) * 10, 'status' => 'ACTIVE']
            );
        }

        $spiceGroup = ModifierGroup::updateOrCreate(
            ['restaurant_id' => $restaurant->id, 'name' => 'Spice Level'],
            ['selection_type' => 'SINGLE', 'is_required' => false, 'min_selections' => 0, 'max_selections' => 1, 'display_order' => 30]
        );
        $spiceModifiers = [
            ['name' => 'Mild', 'price' => 0, 'default' => true],
            ['name' => 'Medium', 'price' => 0, 'default' => false],
            ['name' => 'Hot', 'price' => 0, 'default' => false],
        ];
        foreach ($spiceModifiers as $i => $m) {
            Modifier::updateOrCreate(
                ['modifier_group_id' => $spiceGroup->id, 'name' => $m['name']],
                ['restaurant_id' => $restaurant->id, 'price_adjustment' => $m['price'], 'is_default' => $m['default'], 'display_order' => ($i + 1) * 10, 'status' => 'ACTIVE']
            );
        }

        // ---- Products ------------------------------------------------------

        $productDefs = [
            'Burgers' => [
                ['name' => 'Classic Cheeseburger', 'price' => 6.99, 'desc' => 'Beef patty, cheddar, lettuce, tomato, house sauce.', 'color' => 'b91c1c', 'groups' => [$sizeGroup, $extrasGroup, $spiceGroup]],
                ['name' => 'Bacon BBQ Burger', 'price' => 8.49, 'desc' => 'Beef patty, bacon, BBQ sauce, crispy onions.', 'color' => '991b1b', 'groups' => [$sizeGroup, $extrasGroup, $spiceGroup]],
                ['name' => 'Double Stack Burger', 'price' => 9.99, 'desc' => 'Two beef patties, double cheese, pickles, onion.', 'color' => '7f1d1d', 'groups' => [$sizeGroup, $extrasGroup, $spiceGroup]],
                ['name' => 'Veggie Burger', 'price' => 7.49, 'desc' => 'Plant-based patty, lettuce, tomato, vegan mayo.', 'color' => '15803d', 'groups' => [$sizeGroup, $extrasGroup]],
            ],
            'Sides' => [
                ['name' => 'French Fries', 'price' => 3.49, 'desc' => 'Crispy golden fries, lightly salted.', 'color' => 'ca8a04', 'groups' => [$sizeGroup]],
                ['name' => 'Onion Rings', 'price' => 3.99, 'desc' => 'Beer-battered onion rings, served with dip.', 'color' => 'd97706', 'groups' => [$sizeGroup]],
                ['name' => 'Coleslaw', 'price' => 2.49, 'desc' => 'Creamy house-made coleslaw.', 'color' => 'a16207', 'groups' => []],
            ],
            'Drinks' => [
                ['name' => 'Cola', 'price' => 1.99, 'desc' => 'Ice-cold classic cola.', 'color' => '0369a1', 'groups' => [$sizeGroup]],
                ['name' => 'Lemonade', 'price' => 2.49, 'desc' => 'Freshly squeezed lemonade.', 'color' => '0284c7', 'groups' => [$sizeGroup]],
                ['name' => 'Chocolate Milkshake', 'price' => 4.49, 'desc' => 'Thick chocolate milkshake, whipped cream.', 'color' => '075985', 'groups' => [$sizeGroup]],
            ],
            'Desserts' => [
                ['name' => 'Chocolate Brownie', 'price' => 3.99, 'desc' => 'Warm fudge brownie, vanilla drizzle.', 'color' => '9333ea', 'groups' => []],
                ['name' => 'Apple Pie', 'price' => 3.49, 'desc' => 'Classic baked apple pie slice.', 'color' => '7e22ce', 'groups' => []],
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
                        'preparation_time_minutes' => 10,
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

        $this->command?->info('Burger Barn demo data seeded: '
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
