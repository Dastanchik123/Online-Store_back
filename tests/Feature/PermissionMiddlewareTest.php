<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_to_staff_route_is_rejected()
    {
        // /api/categories (POST) требует auth:sanctum + staff + permission:categories.manage
        $response = $this->postJson('/api/categories', ['name' => 'Test']);

        $response->assertStatus(401);
    }

    public function test_authenticated_non_staff_user_is_forbidden_from_staff_route()
    {
        $user = User::factory()->create(['role' => 'user']);

        $response = $this->actingAs($user)->postJson('/api/categories', ['name' => 'Test']);

        $response->assertStatus(403);
    }

    public function test_staff_without_permission_gets_forbidden()
    {
        // Роль staff-типа (не 'user'), но без permission categories.manage в role_permissions
        $staff = User::factory()->create(['role' => 'cashier']);

        $response = $this->actingAs($staff)->postJson('/api/categories', ['name' => 'Test']);

        $response->assertStatus(403);
        $response->assertJsonFragment(['message' => 'Forbidden. Missing permission: categories.manage']);
    }

    public function test_staff_with_permission_can_access_protected_route()
    {
        $staff = User::factory()->create(['role' => 'manager']);
        RolePermission::create(['role' => 'manager', 'permission' => 'categories.manage']);

        $response = $this->actingAs($staff)->postJson('/api/categories', [
            'name' => 'Новая категория',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('categories', ['name' => 'Новая категория']);
    }

    public function test_admin_role_bypasses_permission_check()
    {
        // hasPermission() всегда true для role === 'admin', без записи в role_permissions
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->postJson('/api/categories', [
            'name' => 'Категория админа',
        ]);

        $response->assertStatus(201);
    }

    public function test_superadmin_middleware_rejects_non_admin_role()
    {
        $staff = User::factory()->create(['role' => 'manager']);
        RolePermission::create(['role' => 'manager', 'permission' => 'reports.view']);

        // /api/roles защищён именно middleware('superadmin'), не permission-based
        $response = $this->actingAs($staff)->getJson('/api/roles');

        $response->assertStatus(403);
    }
}
