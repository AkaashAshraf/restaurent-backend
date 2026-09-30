<?php

namespace Tests\Feature;

use Tests\TestCase;

class AuditLogTest extends TestCase
{
    public function test_branch_creation_is_recorded_in_the_audit_log(): void
    {
        [$restaurant, , $owner] = $this->makeRestaurantWithOwner('Audit Co');
        $token = $this->actingAsUser($owner);

        $this->withUserToken($token)->postJson('/api/v1/branches', [
            'name' => 'New Branch', 'branch_code' => 'NB',
        ])->assertStatus(201);

        $logs = $this->withUserToken($token)->getJson('/api/v1/audit-logs');

        $logs->assertOk();
        $actions = collect($logs->json('data.data'))->pluck('action');
        $this->assertTrue($actions->contains('branch.created'));
    }

    public function test_audit_logs_are_isolated_per_restaurant(): void
    {
        [$restaurantA, , $ownerA] = $this->makeRestaurantWithOwner('Audit A');
        [$restaurantB, , $ownerB] = $this->makeRestaurantWithOwner('Audit B');

        $tokenB = $this->actingAsUser($ownerB);
        $this->withUserToken($tokenB)->postJson('/api/v1/branches', [
            'name' => 'B Branch', 'branch_code' => 'BB',
        ])->assertStatus(201);

        $tokenA = $this->actingAsUser($ownerA);
        $logsA = $this->withUserToken($tokenA)->getJson('/api/v1/audit-logs');

        $this->assertEmpty($logsA->json('data.data'));
    }
}
