<?php

namespace App\Http\Controllers\Admin;

use App\Models\Permission;
use App\Models\Role;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    public function index()
    {
        $permissions = Permission::paginate(20);
        return view('pages.admin.permissions.index', compact('permissions'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|unique:permissions,name|max:255',
            'label'       => 'nullable|string|max:255',
            'description' => 'nullable|string',
        ]);

        $permission = Permission::create([
            'name'        => $validated['name'],
            'label'       => $validated['label'] ?? null,
            'description' => $validated['description'] ?? null,
            'guard_name'  => 'web',
        ]);

        return redirect()->route('admin.roles.index')
            ->with('success', "Permission '{$permission->name}' créée avec succès !");
    }

    public function update(Request $request, Permission $permission)
    {
        $validated = $request->validate([
            'name'        => 'required|string|unique:permissions,name,' . $permission->id . '|max:255',
            'label'       => 'nullable|string|max:255',
            'description' => 'nullable|string',
        ]);

        $permission->update($validated);

        return redirect()->route('admin.roles.index')
            ->with('success', "Permission '{$permission->name}' modifiée avec succès !");
    }

    public function destroy(Permission $permission)
    {
        $name = $permission->name;
        $permission->delete();

        return redirect()->route('admin.roles.index')
            ->with('success', "Permission '{$name}' supprimée avec succès !");
    }

    public function getByRole(Role $role)
    {
        return response()->json([
            'permissions' => $role->permissions()->pluck('id'),
        ]);
    }

    public function getData(Permission $permission)
    {
        return response()->json([
            'id'          => $permission->id,
            'name'        => $permission->name,
            'label'       => $permission->label,
            'description' => $permission->description,
        ]);
    }
}
