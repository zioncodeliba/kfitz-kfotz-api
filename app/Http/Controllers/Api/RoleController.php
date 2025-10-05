<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Role;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function index()
    {
        $roles = Role::withCount('users')->get();
        return response()->json($roles);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:roles,name|max:50|min:2|regex:/^[a-zA-Z0-9_-]+$/',
        ], [
            'name.regex' => 'Role name can only contain letters, numbers, underscores, and hyphens.',
        ]);

        $role = Role::create($validated);
        
        return response()->json([
            'message' => 'Role created successfully',
            'role' => $role
        ], 201);
    }

    public function show($id)
    {
        $role = Role::with('users')->findOrFail($id);
        return response()->json($role);
    }

    public function update(Request $request, $id)
    {
        $role = Role::findOrFail($id);
        
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:50',
                'min:2',
                'regex:/^[a-zA-Z0-9_-]+$/',
                Rule::unique('roles')->ignore($id),
            ],
        ], [
            'name.regex' => 'Role name can only contain letters, numbers, underscores, and hyphens.',
        ]);

        $role->update($validated);
        
        return response()->json([
            'message' => 'Role updated successfully',
            'role' => $role
        ]);
    }

    public function destroy($id)
    {
        $role = Role::findOrFail($id);
        
        // מניעת מחיקת תפקידים בסיסיים
        $protectedRoles = ['admin', 'user'];
        if (in_array($role->name, $protectedRoles)) {
            return response()->json(['message' => 'Cannot delete protected role: ' . $role->name], 400);
        }
        
        // בדיקה אם יש משתמשים עם התפקיד הזה
        if ($role->users()->count() > 0) {
            return response()->json(['message' => 'Cannot delete role that is assigned to users'], 400);
        }
        
        $role->delete();
        return response()->json(['message' => 'Role deleted successfully']);
    }
}
