<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::query()
            ->with('roles')
            ->withCount('orders')
            ->whereDoesntHave('roles', function ($roleQuery) {
                $roleQuery->whereIn('name', ['merchant', 'seller']);
            })
            ->whereDoesntHave('merchant');

        $roleFilterParam = $request->query('roles', '*');
        if ($roleFilterParam !== '*') {
            $roleNames = array_filter(array_map('trim', explode(',', $roleFilterParam)));
            if (empty($roleNames)) {
                $roleNames = ['user'];
            }

            $query->whereHas('roles', function ($roleQuery) use ($roleNames) {
                $roleQuery->whereIn('name', $roleNames);
            });
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($searchQuery) use ($search) {
                $searchQuery->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $allowedSorts = ['name', 'email', 'created_at', 'orders_count'];
        $sort = $request->query('sort', 'created_at');
        $direction = strtolower($request->query('direction', 'desc')) === 'asc' ? 'asc' : 'desc';
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'created_at';
        }

        $perPage = (int) $request->query('per_page', 0);
        if ($perPage > 0) {
            $users = $query->orderBy($sort, $direction)->paginate(min($perPage, 100));
            $users->appends($request->only(['search', 'sort', 'direction', 'per_page']));

            return response()->json($users);
        }

        $users = $query->orderBy($sort, $direction)->get();

        return response()->json($users);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|min:2',
            'email' => 'required|email|unique:users,email|max:255',
            'phone' => 'nullable|string|max:20',
            'password' => 'required|string|min:8|regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/',
            'password_confirmation' => 'required|same:password',
        ], [
            'password.regex' => 'Password must contain at least one uppercase letter, one lowercase letter, and one number.',
        ]);

        $validated['password'] = bcrypt($validated['password']);
        $user = User::create($validated);
        
        // הקצאת תפקיד ברירת מחדל
        $defaultRole = \App\Models\Role::where('name', 'user')->first();
        if ($defaultRole) {
            $user->roles()->attach($defaultRole->id);
        }

        return response()->json([
            'message' => 'User created successfully',
            'user' => $user->load('roles')
        ], 201);
    }

    public function show($id)
    {
        $user = User::with([
                'roles',
                'orders' => function ($ordersQuery) {
                    $ordersQuery
                        ->with(['items.product:id,name,sku'])
                        ->orderBy('created_at', 'desc');
                },
            ])
            ->withCount('orders')
            ->findOrFail($id);

        if ($user->merchant || $user->roles->contains('name', 'merchant') || $user->roles->contains('name', 'seller')) {
            return response()->json([
                'message' => 'Requested user is a merchant and cannot be displayed in the customers list.'
            ], 404);
        }

        return response()->json($user);
    }

    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);
        
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255|min:2',
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users')->ignore($id),
            ],
            'phone' => 'nullable|string|max:20',
            'password' => 'nullable|string|min:8|regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/',
            'password_confirmation' => 'required_with:password|same:password',
        ], [
            'password.regex' => 'Password must contain at least one uppercase letter, one lowercase letter, and one number.',
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = bcrypt($validated['password']);
        }

        $user->update($validated);
        
        return response()->json([
            'message' => 'User updated successfully',
            'user' => $user->load('roles')
        ]);
    }

    public function destroy($id)
    {
        $user = User::findOrFail($id);
        
        // מניעת מחיקת המשתמש האחרון עם תפקיד admin
        if ($user->hasRole('admin') && User::whereHas('roles', function($query) {
            $query->where('name', 'admin');
        })->count() <= 1) {
            return response()->json(['message' => 'Cannot delete the last admin user'], 400);
        }
        
        $user->delete();
        return response()->json(['message' => 'User deleted successfully']);
    }

    public function assignRole(Request $request, $userId)
    {
        $request->validate([
            'role' => 'required|exists:roles,name',
        ]);

        $user = User::findOrFail($userId);
        $role = \App\Models\Role::where('name', $request->role)->first();

        $user->roles()->syncWithoutDetaching([$role->id]);

        return response()->json([
            'message' => 'Role assigned successfully',
            'user' => $user->load('roles')
        ]);
    }

    public function removeRole(Request $request, $userId)
    {
        $request->validate([
            'role' => 'required|exists:roles,name',
        ]);

        $user = User::findOrFail($userId);
        $role = \App\Models\Role::where('name', $request->role)->first();

        // מניעת הסרת התפקיד האחרון של משתמש
        if ($user->roles()->count() <= 1) {
            return response()->json(['message' => 'Cannot remove the last role from user'], 400);
        }

        $user->roles()->detach($role->id);

        return response()->json([
            'message' => 'Role removed successfully',
            'user' => $user->load('roles')
        ]);
    }
}
