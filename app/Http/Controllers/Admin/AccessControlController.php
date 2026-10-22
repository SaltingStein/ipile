<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Minimal admin screen for assigning roles and permissions directly from the app dashboard.
 */
class AccessControlController extends Controller
{
    /**
     * Display the role and permission management screen.
     */
    public function index(): Response
    {
        $roles = Role::with('permissions')->get()->map(fn (Role $role) => [
            'name' => $role->name,
            'permissions' => $role->permissions->pluck('name')->values()->all(),
        ])->values()->all();

        $permissions = Permission::query()->orderBy('name')->pluck('name')->values()->all();

        $users = User::with('roles')->orderBy('name')->get()->map(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames()->values()->all(),
        ])->values()->all();

        return Inertia::render('Admin/AccessControl', [
            'roles' => $roles,
            'permissions' => $permissions,
            'users' => $users,
        ]);
    }

    /**
     * Synchronize role and permission assignments using the current request payload.
     */
    public function updateRole(Request $request): RedirectResponse
    {
        $request->validate([
            'user_id' => ['nullable', 'exists:users,id'],
            'role' => ['nullable', 'string', 'max:255'],
            'permission' => ['nullable', 'string', 'max:255'],
            'revoke_permission' => ['nullable', 'boolean'],
            'revoke_role' => ['nullable', 'boolean'],
        ]);

        $roleName = trim((string) $request->input('role'));
        $permissionName = trim((string) $request->input('permission'));
        $user = $request->filled('user_id') ? User::findOrFail($request->input('user_id')) : null;

        if ($user !== null && $roleName !== '') {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);

            if ($request->boolean('revoke_role')) {
                $user->removeRole($role);
            } else {
                $user->assignRole($role);
            }
        }

        if ($roleName !== '') {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);

            if ($permissionName !== '') {
                $permission = Permission::firstOrCreate([
                    'name' => $permissionName,
                    'guard_name' => 'web',
                ]);

                if ($request->boolean('revoke_permission')) {
                    $role->revokePermissionTo($permission);
                } else {
                    $role->givePermissionTo($permission);
                }
            }
        }

        if ($user === null && $roleName === '' && $permissionName === '' && ! $request->boolean('revoke_permission') && ! $request->boolean('revoke_role')) {
            return redirect()->route('admin.access')->withErrors(['role' => 'Choose a role or user update to apply.']);
        }

        return redirect()->route('admin.access')->with('status', 'Access policy updated successfully.');
    }
}
