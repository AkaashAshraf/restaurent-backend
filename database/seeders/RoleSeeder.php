<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Global system role templates (restaurant_id = null), usable by every
     * restaurant. Restaurants can additionally define their own custom
     * roles (spec #25).
     */
    public function run(): void
    {
        $allPermissions = Permission::pluck('id', 'key');

        $roles = [
            ['slug' => 'restaurant-owner', 'name' => 'Restaurant Owner', 'scope' => 'RESTAURANT', 'permissions' => '*'],
            ['slug' => 'restaurant-admin', 'name' => 'Restaurant Admin', 'scope' => 'RESTAURANT', 'permissions' => '*'],
            ['slug' => 'branch-manager', 'name' => 'Branch Manager', 'scope' => 'BRANCH', 'permissions' => [
                'branches.view', 'users.view', 'reports.view', 'settings.view',
                'menu.view', 'menu.update',
                'tables.view', 'tables.create', 'tables.update',
                'orders.view', 'orders.create', 'orders.update', 'orders.assign_rider',
                'delivery-zones.view', 'delivery-zones.create', 'delivery-zones.update',
                'coupons.view',
                'payments.view', 'payments.create', 'payments.confirm', 'payments.refund',
            ]],
            ['slug' => 'cashier', 'name' => 'Cashier', 'scope' => 'BRANCH', 'permissions' => [
                'branches.view', 'menu.view',
                'tables.view',
                'orders.view', 'orders.create', 'orders.update',
                'payments.view', 'payments.create',
            ]],
            ['slug' => 'kitchen', 'name' => 'Kitchen Staff', 'scope' => 'BRANCH', 'permissions' => [
                'branches.view', 'menu.view',
                'orders.view', 'orders.update',
            ]],
            ['slug' => 'waiter', 'name' => 'Waiter', 'scope' => 'BRANCH', 'permissions' => [
                'branches.view', 'menu.view',
                'tables.view', 'tables.update',
                'orders.view', 'orders.create', 'orders.update',
                'payments.view', 'payments.create',
            ]],
            ['slug' => 'rider', 'name' => 'Rider', 'scope' => 'BRANCH', 'permissions' => [
                'branches.view',
                'orders.view', 'orders.update',
            ]],
        ];

        foreach ($roles as $r) {
            $role = Role::updateOrCreate(
                ['restaurant_id' => null, 'slug' => $r['slug']],
                ['name' => $r['name'], 'scope' => $r['scope'], 'is_system' => true]
            );

            $permissionIds = $r['permissions'] === '*'
                ? $allPermissions->values()
                : $allPermissions->only($r['permissions'])->values();

            $role->permissions()->sync($permissionIds);
        }
    }
}
