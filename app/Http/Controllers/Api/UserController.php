<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Arr;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::query()
            ->with([
                'merchant.agent',
                'merchant.pluginSites',
            ])
            ->withCount('orders')
            ->withCount(['agentMerchants as managed_merchants_count']);

        $roleFilterParam = $request->query('roles', '*');
        if ($roleFilterParam !== '*') {
            $roleNames = array_filter(array_map('trim', explode(',', $roleFilterParam)));
            if (!empty($roleNames)) {
                $query->whereIn('role', $roleNames);
            }
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($searchQuery) use ($search) {
                $searchQuery->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $allowedSorts = ['name', 'email', 'created_at', 'orders_count', 'role'];
        $sort = $request->query('sort', 'created_at');
        $direction = strtolower($request->query('direction', 'desc')) === 'asc' ? 'asc' : 'desc';
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'created_at';
        }

        $perPage = (int) $request->query('per_page', 0);
        if ($perPage > 0) {
            $users = $query->orderBy($sort, $direction)->paginate(min($perPage, 100));
            $users->appends($request->only(['search', 'sort', 'direction', 'per_page', 'roles']));

            return UserResource::collection($users);
        }

        $users = $query->orderBy($sort, $direction)->get();

        return UserResource::collection($users);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|min:2',
            'email' => 'required|email|unique:users,email|max:255',
            'phone' => 'nullable|string|max:20',
            'password' => 'required|string|min:8|regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/',
            'password_confirmation' => 'required|same:password',
            'role' => 'sometimes|in:admin,agent,merchant',
            'merchant_ids' => 'nullable|array',
            'merchant_ids.*' => 'integer|exists:merchants,id',
            'order_limit' => 'nullable|numeric|min:0',
        ], [
            'password.regex' => 'Password must contain at least one uppercase letter, one lowercase letter, and one number.',
        ]);

        $role = $validated['role'] ?? 'merchant';
        $orderLimit = isset($validated['order_limit']) ? (float) $validated['order_limit'] : null;
        unset($validated['password_confirmation'], $validated['role'], $validated['order_limit']);
        $validated['password'] = bcrypt($validated['password']);
        $validated['role'] = $role;

        $user = User::create($validated);

        if ($orderLimit !== null) {
            $user->order_limit = $orderLimit;
            $user->save();
        }

        $user->refreshOrderFinancials();

        return response()->json([
            'message' => 'User created successfully',
            'user' => new UserResource(
                $user->loadMissing(['merchant.agent', 'merchant.pluginSites'])
                    ->loadCount(['orders', 'agentMerchants as managed_merchants_count'])
            ),
        ], 201);
    }

    public function show($id)
    {
        $user = User::with([
                'merchant.agent',
                'merchant.pluginSites',
                'agentMerchants',
                'orders' => function ($ordersQuery) {
                    $ordersQuery
                        ->with(['items.product:id,name,sku'])
                        ->orderBy('created_at', 'desc');
                },
            ])
            ->withCount('orders')
            ->withCount(['agentMerchants as managed_merchants_count'])
            ->findOrFail($id);

        return new UserResource($user);
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
            'role' => 'sometimes|in:admin,agent,merchant',
            'merchant_ids' => 'nullable|array',
            'merchant_ids.*' => 'integer|exists:merchants,id',
            'order_limit' => 'nullable|numeric|min:0',
        ], [
            'password.regex' => 'Password must contain at least one uppercase letter, one lowercase letter, and one number.',
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = bcrypt($validated['password']);
        }
        unset($validated['password_confirmation']);

        $merchantIds = $validated['merchant_ids'] ?? null;
        $orderLimitProvided = array_key_exists('order_limit', $validated);
        $orderLimit = $orderLimitProvided ? (float) $validated['order_limit'] : null;
        $updateData = Arr::except($validated, ['merchant_ids', 'order_limit']);

        if (
            isset($updateData['role']) &&
            $user->hasRole('admin') &&
            $updateData['role'] !== 'admin' &&
            User::where('role', 'admin')->count() <= 1
        ) {
            return response()->json(['message' => 'Cannot remove the last admin role from the system'], 400);
        }

        $user->update($updateData);

        if ($orderLimitProvided) {
            $user->order_limit = $orderLimit ?? 0;
            $user->save();
        }

        $finalRole = $user->role;

        if ($finalRole === 'agent') {
            $merchantIds = collect($merchantIds ?? [])
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->values()
                ->all();

            Merchant::where('agent_id', $user->id)
                ->whereNotIn('id', $merchantIds)
                ->update(['agent_id' => null]);

            if (!empty($merchantIds)) {
                Merchant::whereIn('id', $merchantIds)->update(['agent_id' => $user->id]);
            }
        } else {
            Merchant::where('agent_id', $user->id)->update(['agent_id' => null]);
        }

        if ($orderLimitProvided) {
            $user->refreshOrderFinancials();
        }

        return response()->json([
            'message' => 'User updated successfully',
            'user' => new UserResource(
                $user->loadMissing(['merchant.agent', 'merchant.pluginSites', 'agentMerchants'])
                    ->loadCount(['orders', 'agentMerchants as managed_merchants_count'])
            ),
        ]);
    }

    public function destroy($id)
    {
        $user = User::findOrFail($id);

        if ($user->hasRole('admin') && User::where('role', 'admin')->count() <= 1) {
            return response()->json(['message' => 'Cannot delete the last admin user'], 400);
        }

        $user->delete();

        return response()->json(['message' => 'User deleted successfully']);
    }
}
