<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::query()
            ->with('modifierGroups')
            ->when($request->query('category_id'), fn ($q, $id) => $q->where('category_id', $id))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderBy('display_order')
            ->get();

        return ApiResponse::success($products);
    }

    public function store(Request $request)
    {
        $restaurant = $request->user()->restaurant;

        $data = $request->validate([
            'category_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'image' => ['nullable', 'string', 'max:2048'],
            'base_price' => ['required', 'numeric', 'min:0'],
            'preparation_time_minutes' => ['nullable', 'integer', 'min:0'],
            'display_order' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
        ]);

        // Belongs-to-tenant on Category too, so this 404s (rather than
        // silently succeeding) if the category is another restaurant's.
        Category::findOrFail($data['category_id']);

        $data['slug'] = $this->uniqueSlug($restaurant->id, $data['name']);
        $data['status'] = $data['status'] ?? 'ACTIVE';

        $product = Product::create($data + ['restaurant_id' => $restaurant->id]);

        AuditLog::create([
            'restaurant_id' => $restaurant->id,
            'user_id' => $request->user()->id,
            'action' => 'product.created',
            'subject_type' => Product::class,
            'subject_id' => $product->id,
            'changes' => ['new' => $data],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success($product, 201);
    }

    public function show(Request $request, int $product)
    {
        $product = Product::findOrFail($product);

        return ApiResponse::success($product->load('category', 'modifierGroups.modifiers', 'branchOverrides'));
    }

    public function update(Request $request, int $product)
    {
        $product = Product::findOrFail($product);

        $data = $request->validate([
            'category_id' => ['sometimes', 'integer'],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'image' => ['nullable', 'string', 'max:2048'],
            'base_price' => ['sometimes', 'numeric', 'min:0'],
            'preparation_time_minutes' => ['nullable', 'integer', 'min:0'],
            'display_order' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
        ]);

        if (isset($data['category_id'])) {
            Category::findOrFail($data['category_id']);
        }

        $before = $product->only(array_keys($data));
        $product->update($data);

        AuditLog::create([
            'restaurant_id' => $product->restaurant_id,
            'user_id' => $request->user()->id,
            'action' => 'product.updated',
            'subject_type' => Product::class,
            'subject_id' => $product->id,
            'changes' => ['old' => $before, 'new' => $data],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success($product->fresh());
    }

    public function destroy(Request $request, int $product)
    {
        $product = Product::findOrFail($product);
        $product->delete();

        AuditLog::create([
            'restaurant_id' => $product->restaurant_id,
            'user_id' => $request->user()->id,
            'action' => 'product.deleted',
            'subject_type' => Product::class,
            'subject_id' => $product->id,
            'changes' => [],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success(['deleted' => true]);
    }

    /** Sync which modifier groups apply to this product (e.g. Pizza -> Size, Toppings). */
    public function updateModifierGroups(Request $request, int $product)
    {
        $product = Product::findOrFail($product);

        $data = $request->validate([
            'modifier_group_ids' => ['required', 'array'],
            'modifier_group_ids.*' => ['integer'],
        ]);

        // findOrFail-per-id via the tenant-scoped query, so a forged id
        // belonging to another restaurant is silently dropped, not attached.
        $validIds = ModifierGroup::whereIn('id', $data['modifier_group_ids'])->pluck('id');

        $product->modifierGroups()->sync($validIds);

        return ApiResponse::success($product->fresh()->load('modifierGroups.modifiers'));
    }

    /**
     * Branch-level availability/price override (spec: menu availability and
     * pricing can differ per branch). branch.access:branch middleware
     * ensures a branch-scoped user can only touch their own branch(es).
     */
    public function updateBranchOverride(Request $request, int $product, int $branch)
    {
        $product = Product::findOrFail($product);
        $branchModel = Branch::findOrFail($branch);

        $data = $request->validate([
            'is_available' => ['sometimes', 'boolean'],
            'price_override' => ['nullable', 'numeric', 'min:0'],
        ]);

        $override = BranchProduct::updateOrCreate(
            ['branch_id' => $branchModel->id, 'product_id' => $product->id],
            $data + ['restaurant_id' => $product->restaurant_id]
        );

        AuditLog::create([
            'restaurant_id' => $product->restaurant_id,
            'branch_id' => $branchModel->id,
            'user_id' => $request->user()->id,
            'action' => 'product.branch_override_updated',
            'subject_type' => Product::class,
            'subject_id' => $product->id,
            'changes' => ['new' => $data],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success($override);
    }

    private function uniqueSlug(int $restaurantId, string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 1;

        while (Product::where('restaurant_id', $restaurantId)->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
