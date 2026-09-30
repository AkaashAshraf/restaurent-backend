<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $modules = [
            'restaurants' => ['view', 'create', 'update', 'delete'],
            'branches' => ['view', 'create', 'update', 'delete'],
            'users' => ['view', 'create', 'update', 'delete'],
            'roles' => ['view', 'create', 'update', 'delete'],
            'subscriptions' => ['view', 'manage'],
            'reports' => ['view'],
            'settings' => ['view', 'update'],
            'menu' => ['view', 'create', 'update', 'delete'],
            'tables' => ['view', 'create', 'update', 'delete'],
            'orders' => ['view', 'create', 'update', 'assign_rider'],
            'delivery-zones' => ['view', 'create', 'update', 'delete'],
            'coupons' => ['view', 'create', 'update', 'delete'],
            'payments' => ['view', 'create', 'confirm', 'refund'],
        ];

        foreach ($modules as $module => $actions) {
            foreach ($actions as $action) {
                Permission::updateOrCreate(
                    ['key' => "{$module}.{$action}"],
                    ['module' => $module, 'name' => Str::of($action)->replace('_', ' ')->ucfirst()
                        .' '.Str::of($module)->replace('-', ' ')->ucfirst()]
                );
            }
        }
    }
}
