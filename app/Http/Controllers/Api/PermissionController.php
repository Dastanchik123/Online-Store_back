<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RolePermission;
use Illuminate\Http\Request;

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
        $request->validate([
            'permissions'   => 'required|array',
            'permissions.*' => 'string',
        ]);

        RolePermission::where('role', $role)->delete();

        foreach ($request->permissions as $perm) {
            RolePermission::create([
                'role'       => $role,
                'permission' => $perm,
            ]);
        }

        return response()->json(['message' => 'Permissions updated']);
    }

    public function myPermissions(Request $request)
    {
        $role        = $request->user()->role;
        $permissions = RolePermission::where('role', $role)->pluck('permission');
        return response()->json($permissions);
    }
}
