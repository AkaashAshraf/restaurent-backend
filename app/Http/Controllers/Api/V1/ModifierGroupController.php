<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ModifierGroup;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class ModifierGroupController extends Controller
{
    public function index(Request $request)
    {
        return ApiResponse::success(
            ModifierGroup::with('modifiers')->orderBy('display_order')->get()
        );
    }

    public function store(Request $request)
    {
        $restaurant = $request->user()->restaurant;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'selection_type' => ['required', 'in:SINGLE,MULTIPLE'],
            'is_required' => ['nullable', 'boolean'],
            'min_selections' => ['nullable', 'integer', 'min:0'],
            'max_selections' => ['nullable', 'integer', 'min:0'],
            'display_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $group = ModifierGroup::create($data + ['restaurant_id' => $restaurant->id]);

        AuditLog::create([
            'restaurant_id' => $restaurant->id,
            'user_id' => $request->user()->id,
            'action' => 'modifier_group.created',
            'subject_type' => ModifierGroup::class,
            'subject_id' => $group->id,
            'changes' => ['new' => $data],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success($group, 201);
    }

    public function show(Request $request, int $modifierGroup)
    {
        $group = ModifierGroup::findOrFail($modifierGroup);

        return ApiResponse::success($group->load('modifiers', 'products'));
    }

    public function update(Request $request, int $modifierGroup)
    {
        $group = ModifierGroup::findOrFail($modifierGroup);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'selection_type' => ['sometimes', 'in:SINGLE,MULTIPLE'],
            'is_required' => ['nullable', 'boolean'],
            'min_selections' => ['nullable', 'integer', 'min:0'],
            'max_selections' => ['nullable', 'integer', 'min:0'],
            'display_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $before = $group->only(array_keys($data));
        $group->update($data);

        AuditLog::create([
            'restaurant_id' => $group->restaurant_id,
            'user_id' => $request->user()->id,
            'action' => 'modifier_group.updated',
            'subject_type' => ModifierGroup::class,
            'subject_id' => $group->id,
            'changes' => ['old' => $before, 'new' => $data],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success($group->fresh());
    }

    public function destroy(Request $request, int $modifierGroup)
    {
        $group = ModifierGroup::findOrFail($modifierGroup);
        $group->delete();

        AuditLog::create([
            'restaurant_id' => $group->restaurant_id,
            'user_id' => $request->user()->id,
            'action' => 'modifier_group.deleted',
            'subject_type' => ModifierGroup::class,
            'subject_id' => $group->id,
            'changes' => [],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success(['deleted' => true]);
    }
}
