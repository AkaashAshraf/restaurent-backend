<?php

namespace App\Services;

use App\Enums\MenuItemStatus;
use App\Models\Branch;
use App\Models\Restaurant;

/**
 * Resolves the "effective" menu for a restaurant, optionally scoped to a
 * single branch. This is the one place that combines a product's base
 * price/status with its branch_products override (spec: branch-level menu
 * availability & pricing) — both the admin API and the public/customer
 * `app/menu` endpoint go through this so the rule is enforced identically
 * everywhere, the same reasoning as FeatureService/PermissionService.
 */
class MenuService
{
    /**
     * @return array<int, array> nested categories -> products -> modifier groups -> modifiers
     */
    public function buildMenu(Restaurant $restaurant, ?Branch $branch = null, bool $onlyAvailable = true): array
    {
        $categories = $restaurant->categories()
            ->with([
                'products' => fn ($q) => $q->orderBy('display_order')->orderBy('id'),
                'products.branchOverrides' => fn ($q) => $branch ? $q->where('branch_id', $branch->id) : $q,
                'products.modifierGroups.modifiers' => fn ($q) => $q->where('status', MenuItemStatus::ACTIVE->value)->orderBy('display_order'),
            ])
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        if ($onlyAvailable) {
            $categories = $categories->where('status', MenuItemStatus::ACTIVE);
        }

        return $categories->map(function ($category) use ($branch, $onlyAvailable) {
            $products = $category->products
                ->map(fn ($product) => $this->serializeProduct($product, $branch))
                ->when($onlyAvailable, fn ($collection) => $collection->where('is_available', true))
                ->values();

            return [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'description' => $category->description,
                'image' => $category->image,
                'display_order' => $category->display_order,
                'products' => $products,
            ];
        })->values()->all();
    }

    public function serializeProduct(\App\Models\Product $product, ?Branch $branch = null): array
    {
        $isAvailable = $branch ? $product->isAvailableAtBranch($branch->id) : $product->status === MenuItemStatus::ACTIVE;
        $price = $branch ? $product->priceAtBranch($branch->id) : (float) $product->base_price;

        return [
            'id' => $product->id,
            'category_id' => $product->category_id,
            'name' => $product->name,
            'slug' => $product->slug,
            'description' => $product->description,
            'image' => $product->image,
            'price' => $price,
            'base_price' => (float) $product->base_price,
            'preparation_time_minutes' => $product->preparation_time_minutes,
            'is_available' => $isAvailable,
            'modifier_groups' => $product->modifierGroups->map(fn ($group) => [
                'id' => $group->id,
                'name' => $group->name,
                'selection_type' => $group->selection_type->value,
                'is_required' => $group->is_required,
                'min_selections' => $group->min_selections,
                'max_selections' => $group->max_selections,
                'modifiers' => $group->modifiers->map(fn ($modifier) => [
                    'id' => $modifier->id,
                    'name' => $modifier->name,
                    'price_adjustment' => (float) $modifier->price_adjustment,
                    'is_default' => $modifier->is_default,
                ])->values(),
            ])->values(),
        ];
    }
}
