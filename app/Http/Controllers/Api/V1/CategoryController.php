<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Category;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $categories = Category::query()
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderBy('display_order')
            ->get();

        return ApiResponse::success($categories);
    }

    public function store(Request $request)
    {
        $restaurant = $request->user()->restaurant;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'image' => ['nullable', 'string', 'max:2048'],
            'display_order' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
        ]);

        $data['slug'] = $this->uniqueSlug($restaurant->id, $data['name']);
        $data['status'] = $data['status'] ?? 'ACTIVE';

        $category = Category::create($data + ['restaurant_id' => $restaurant->id]);

        AuditLog::create([
            'restaurant_id' => $restaurant->id,
            'user_id' => $request->user()->id,
            'action' => 'category.created',
            'subject_type' => Category::class,
            'subject_id' => $category->id,
            'changes' => ['new' => $data],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success($category, 201);
    }

    /**
     * Raw int id, not implicit route-model-binding — same reasoning as
     * BranchController: SubstituteBindings runs before the `tenant`
     * middleware sets TenantContext, so implicit binding on a tenant-scoped
     * model would always 404.
     */
    public function show(Request $request, int $category)
    {
        $category = Category::findOrFail($category);

        return ApiResponse::success($category->load('products'));
    }

    public function update(Request $request, int $category)
    {
        $category = Category::findOrFail($category);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'image' => ['nullable', 'string', 'max:2048'],
            'display_order' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
        ]);

        $before = $category->only(array_keys($data));
        $category->update($data);

        AuditLog::create([
            'restaurant_id' => $category->restaurant_id,
            'user_id' => $request->user()->id,
            'action' => 'category.updated',
            'subject_type' => Category::class,
            'subject_id' => $category->id,
            'changes' => ['old' => $before, 'new' => $data],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success($category->fresh());
    }

    public function destroy(Request $request, int $category)
    {
        $category = Category::findOrFail($category);
        $category->delete();

        AuditLog::create([
            'restaurant_id' => $category->restaurant_id,
            'user_id' => $request->user()->id,
            'action' => 'category.deleted',
            'subject_type' => Category::class,
            'subject_id' => $category->id,
            'changes' => [],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success(['deleted' => true]);
    }

    private function uniqueSlug(int $restaurantId, string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 1;

        while (Category::where('restaurant_id', $restaurantId)->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
