<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RolePermission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PermissionController extends Controller
{
    public function index()
    {
        return response()->json(RolePermission::all());
    }

    public function getByRole($role)
    {
        return response()->json(RolePermission::where('role', $role)->get());
    }

    public function updateRolePermissions(Request $request, $role)
    {
        abort_unless(\App\Models\Role::where('name', $role)->exists(), 404, 'Роль не найдена');

        // present, а не required: снять с роли все права — законное действие,
        // а required отклонял пустой массив с 422
        $request->validate([
            'permissions'   => 'present|array',
            'permissions.*' => 'string',
        ]);

        DB::transaction(function () use ($role, $request) {
            RolePermission::where('role', $role)->delete();

            foreach ($request->permissions as $perm) {
                RolePermission::create([
                    'role'       => $role,
                    'permission' => $perm,
                ]);
            }
        });

        return response()->json(['message' => 'Permissions updated']);
    }

    public function myPermissions(Request $request)
    {
        $role        = $request->user()->role;
        $permissions = RolePermission::where('role', $role)->pluck('permission');
        return response()->json($permissions);
    }
}
