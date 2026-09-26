<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Identity\Application\Actions\DeleteRole;
use App\Domains\Identity\Domain\ReferenceCatalog;
use App\Models\Role;
use App\Shared\Http\ApiException;
use App\Models\Permission;
use App\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RoleController extends Controller
{
    public function index()
    {

        $roles       = Role::withCount('permissions')->get();
        $permissions = Permission::get();

        return view('pages.admin.roles-permissions.index', compact('roles', 'permissions'));
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate(
                [
                    'name'        => 'string|unique:roles,name|max:255',
                    'label'       => 'required|string|max:255',
                    'description' => 'nullable|string',
                    'permissions' => 'nullable|array',
                    'permissions.*' => 'exists:permissions,id',
                ],
                [
                    'name.unique' => 'Le nom du rôle doit être unique.',
                    'permissions.*.exists' => 'La permission sélectionnée est invalide.',
                ]
            );

            $role = Role::create([
                'name'        => Str::slug($validated['label']),
                'label'       => $validated['label'] ?? null,
                'description' => $validated['description'] ?? null,
                'guard_name'  => 'web', // toujours explicite
            ]);

            if (!empty($validated['permissions'])) {
                $permissions = Permission::whereIn('id', $validated['permissions'])->get();
                $role->syncPermissions($permissions);
            }

            return redirect()->route('admin.roles.index')->with('success', "Rôle créé avec succès !");
        } catch (\Exception $e) {
            Log::error('Erreur lors de la création du rôle : ' . $e->getMessage(), ['exception' => $e]);
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function show(Role $role)
    {
        $role->load('permissions');
        return view('pages.admin.roles-permissions.show-role', compact('role'));
    }

    public function edit(Role $role)
    {
        $role->load('permissions');
        $permissions = Permission::all();
        return view('pages.admin.roles.edit', compact('role', 'permissions'));
    }

    public function update(Request $request, Role $role)
    {
        // Les rôles de référence sont en lecture seule depuis le 2026-09-26 (U2) : le
        // seeder, rejoué au déploiement, effacerait ce qu'on y changerait.
        if (ReferenceCatalog::isReferenceRole($role->name)) {
            return redirect()->route('admin.roles.index')->with('error', "Le rôle « {$role->label} » est géré par l'application : il ne se modifie pas depuis cet écran.");
        }

        $validated = $request->validate([
            'name'          => 'string|unique:roles,name,' . $role->id . '|max:255',
            'label'         => 'required|string|max:255',
            'description'   => 'nullable|string',
            'permissions'   => 'nullable|array',
            'permissions.*' => 'exists:permissions,id',
        ]);

        // ⚠️ Le nom technique ne bouge plus : le recalculer depuis le libellé cassait
        // tout `hasRole()` qui le désignait (corrigé le 2026-09-26).
        $role->update([
            'label'       => $validated['label'] ?? null,
            'description' => $validated['description'] ?? null,
        ]);

        $permissions = Permission::whereIn('id', $validated['permissions'] ?? [])->get();

        $role->syncPermissions($permissions);

        return redirect()->route('admin.roles.index')->with('success', "Rôle modifié avec succès !");
    }

    public function destroy(Role $role, DeleteRole $delete)
    {
        // Même règle que l'API : rôles de référence protégés — le Blade oubliait
        // `proprietaire` et `utilisateur` —, et pas de suppression d'un rôle porté.
        try {
            $delete(auth()->user(), $role->load('permissions'));
        } catch (ApiException $e) {
            return redirect()->route('admin.roles.index')->with('error', $e->getMessage());
        }

        return redirect()->route('admin.roles.index')->with('success', "Rôle supprimé avec succès !");
    }

    public function assignUsers(Request $request, Role $role)
    {
        $validated = $request->validate([
            'user_ids'   => 'required|array',
            'user_ids.*' => 'exists:users,id',
        ]);

        foreach ($validated['user_ids'] as $userId) {
            User::find($userId)?->assignRole($role);
        }

        return redirect()->route('admin.roles.show', $role)->with('success', "Utilisateurs assignés au rôle '{$role->name}' avec succès !");
    }

    public function removeUsers(Request $request, Role $role)
    {
        $validated = $request->validate([
            'user_ids'   => 'required|array',
            'user_ids.*' => 'exists:users,id',
        ]);

        foreach ($validated['user_ids'] as $userId) {
            User::find($userId)?->removeRole($role);
        }

        return redirect()->route('admin.roles.show', $role)->with('success', "Utilisateurs retirés du rôle '{$role->name}' avec succès !");
    }

    public function getData(Role $role)
    {
        return response()->json([
            'id'          => $role->id,
            'name'        => $role->name,
            'label'       => $role->label,
            'description' => $role->description,
            'permissions' => $role->permissions()->pluck('id'),
        ]);
    }
}
