<?php

namespace Tests\Feature;

use App\Models\RolePermission;
use App\Models\SelfServiceDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SelfServiceStaffOverrideTest extends TestCase
{
    use RefreshDatabase;

    private string $deviceToken = 'test-device-token';

    protected function setUp(): void
    {
        parent::setUp();

        SelfServiceDevice::create([
            'terminal_id' => 'SS1',
            'token_hash'  => hash('sha256', $this->deviceToken),
            'paired_at'   => now(),
        ]);
    }

    private function staff(array $permissions, array $attrs = []): User
    {
        RolePermission::where('role', 'cashier')->delete();
        foreach ($permissions as $permission) {
            RolePermission::create(['role' => 'cashier', 'permission' => $permission]);
        }

        return User::factory()->create(array_merge(['role' => 'cashier'], $attrs));
    }

    private function override(array $body)
    {
        return $this->withHeader('Authorization', "Bearer {$this->deviceToken}")
            ->postJson('/api/self-service/staff-override', array_merge(['action' => 'remove'], $body));
    }

    public function test_correct_pin_is_accepted_and_logged()
    {
        $staff = $this->staff(['self_service.override'], ['staff_pin_hash' => User::staffCredentialHash('4821')]);

        $response = $this->override([
            'method'     => 'pin',
            'credential' => '4821',
            'cart'       => [['product_id' => 5, 'quantity' => 2]],
        ]);

        $response->assertOk()->assertJsonPath('staff.name', $staff->name);
        $log = DB::table('self_service_staff_overrides')->first();
        $this->assertEquals('SS1', $log->terminal_id);
        $this->assertEquals($staff->id, $log->user_id);
        $this->assertEquals('remove', $log->action);
    }

    public function test_badge_is_accepted()
    {
        $this->staff(['self_service.override'], ['badge_code_hash' => User::staffCredentialHash('EMP-0007')]);

        $this->override(['method' => 'badge', 'credential' => 'EMP-0007'])->assertOk();
    }

    public function test_wrong_pin_is_rejected()
    {
        $this->staff(['self_service.override'], ['staff_pin_hash' => User::staffCredentialHash('4821')]);

        $this->override(['method' => 'pin', 'credential' => '1111'])->assertStatus(422);
        $this->assertEquals(0, DB::table('self_service_staff_overrides')->count());
    }

    public function test_staff_without_permission_is_rejected()
    {
        $this->staff(['pos.access'], ['staff_pin_hash' => User::staffCredentialHash('4821')]);

        $this->override(['method' => 'pin', 'credential' => '4821'])->assertStatus(403);
    }

    public function test_requires_device_token()
    {
        $this->postJson('/api/self-service/staff-override', [
            'method' => 'pin', 'credential' => '4821', 'action' => 'remove',
        ])->assertStatus(401);
    }

    public function test_admin_sets_pin_and_duplicate_pin_is_rejected()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $first = User::factory()->create(['role' => 'cashier']);
        $other = User::factory()->create(['role' => 'cashier']);

        $this->actingAs($admin)->putJson("/api/users/{$first->id}", ['staff_pin' => '4821'])
            ->assertOk()
            ->assertJsonPath('has_staff_pin', true)
            ->assertJsonMissing(['staff_pin_hash' => User::staffCredentialHash('4821')]);

        $this->actingAs($admin)->putJson("/api/users/{$other->id}", ['staff_pin' => '4821'])
            ->assertStatus(422);

        $this->actingAs($admin)->putJson("/api/users/{$first->id}", ['staff_pin' => ''])
            ->assertOk()
            ->assertJsonPath('has_staff_pin', false);
    }
}
