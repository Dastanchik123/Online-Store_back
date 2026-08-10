<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function index()
    {
        return response()->json(
            Role::orderBy('is_system', 'desc')->orderBy('label')->get()
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'  => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('roles', 'name')],
            'label' => ['required', 'string', 'max:100'],
        ]);

        $role = Role::create([
            'name'      => $validated['name'],
            'label'     => $validated['label'],
            'is_system' => false,
        ]);

        return response()->json($role, 201);
    }

    public function update(Request $request, Role $role)
    {
        $validated = $request->validate([
            'label' => ['sometimes', 'string', 'max:100'],
            'name'  => ['sometimes', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('roles', 'name')->ignore($role->id)],
        ]);

        if ($role->is_system && isset($validated['name']) && $validated['name'] !== $role->name) {
            return response()->json(['message' => 'Системную роль нельзя переименовывать.'], 403);
        }

        DB::transaction(function () use ($role, $validated) {
            if (isset($validated['name']) && $validated['name'] !== $role->name) {
                $oldName = $role->name;
                $newName = $validated['name'];
                User::where('role', $oldName)->update(['role' => $newName]);
                RolePermission::where('role', $oldName)->update(['role' => $newName]);
            }

            $role->update($validated);
        });

        return response()->json($role->fresh());
    }

    public function destroy(Role $role)
    {
        if ($role->is_system) {
            return response()->json(['message' => 'Системную роль нельзя удалить.'], 403);
        }

        if (User::where('role', $role->name)->exists()) {
            return response()->json(['message' => 'Роль назначена пользователям — сначала переназначьте их роль.'], 409);
        }

        RolePermission::where('role', $role->name)->delete();
        $role->delete();

        return response()->json(['message' => 'Роль удалена']);
    }
}
