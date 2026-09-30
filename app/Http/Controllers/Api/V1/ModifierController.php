<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class ModifierController extends Controller
{
    /** POST /modifier-groups/{modifierGroup}/modifiers */
    public function store(Request $request, int $modifierGroup)
    {
        $group = ModifierGroup::findOrFail($modifierGroup);
        $restaurant = $request->user()->restaurant;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price_adjustment' => ['nullable', 'numeric'],
            'is_default' => ['nullable', 'boolean'],
            'display_order' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
        ]);

        $data['status'] = $data['status'] ?? 'ACTIVE';

        $modifier = Modifier::create($data + [
            'restaurant_id' => $restaurant->id,
            'modifier_group_id' => $group->id,
        ]);

        AuditLog::create([
            'restaurant_id' => $restaurant->id,
            'user_id' => $request->user()->id,
            'action' => 'modifier.created',
            'subject_type' => Modifier::class,
            'subject_id' => $modifier->id,
            'changes' => ['new' => $data],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success($modifier, 201);
    }

    public function update(Request $request, int $modifier)
    {
        $modifier = Modifier::findOrFail($modifier);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'price_adjustment' => ['nullable', 'numeric'],
            'is_default' => ['nullable', 'boolean'],
            'display_order' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
        ]);

        $before = $modifier->only(array_keys($data));
        $modifier->update($data);

        AuditLog::create([
            'restaurant_id' => $modifier->restaurant_id,
            'user_id' => $request->user()->id,
            'action' => 'modifier.updated',
            'subject_type' => Modifier::class,
            'subject_id' => $modifier->id,
            'changes' => ['old' => $before, 'new' => $data],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success($modifier->fresh());
    }

    public function destroy(Request $request, int $modifier)
    {
        $modifier = Modifier::findOrFail($modifier);
        $modifier->delete();

        AuditLog::create([
            'restaurant_id' => $modifier->restaurant_id,
            'user_id' => $request->user()->id,
            'action' => 'modifier.deleted',
            'subject_type' => Modifier::class,
            'subject_id' => $modifier->id,
            'changes' => [],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success(['deleted' => true]);
    }
}
