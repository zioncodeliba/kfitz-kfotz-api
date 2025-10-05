<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\Merchant;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;

class MerchantController extends Controller
{
    use ApiResponse;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Merchant::with(['user']);

        // Only admin can see all merchants
        if (!$user->hasRole('admin')) {
            return $this->errorResponse('Unauthorized', 403);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by verification status
        if ($request->has('verification_status')) {
            $query->where('verification_status', $request->verification_status);
        }

        // Search by business name or phone
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('business_name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('business_id', 'like', "%{$search}%");
            });
        }

        $merchants = $query->orderBy('created_at', 'desc')->paginate($request->get('per_page', 15));

        return $this->successResponse($merchants);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        // Only admin can create merchants
        if (!$user->hasRole('admin')) {
            return $this->errorResponse('Unauthorized', 403);
        }

        $request->validate([
            'user_id' => 'required|exists:users,id|unique:merchants,user_id',
            'business_name' => 'required|string|max:255',
            'business_id' => 'required|string|unique:merchants,business_id',
            'phone' => 'required|string|max:20',
            'website' => 'nullable|url',
            'description' => 'nullable|string',
            'address' => 'required|array',
            'address.name' => 'required|string',
            'address.address' => 'required|string',
            'address.city' => 'required|string',
            'address.zip' => 'required|string',
            'address.phone' => 'required|string',
            'commission_rate' => 'numeric|min:0|max:100',
            'monthly_fee' => 'numeric|min:0',
            'credit_limit' => 'numeric|min:0',
        ]);

        // Assign merchant role to user
        $user = User::findOrFail($request->user_id);
        $merchantRole = Role::where('name', 'merchant')->first();
        $user->roles()->attach($merchantRole->id);

        $merchant = Merchant::create($request->all());

        return $this->createdResponse($merchant->load('user'), 'Merchant created successfully');
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, string $id)
    {
        $user = $request->user();
        $merchant = Merchant::with(['user', 'orders'])->findOrFail($id);

        // Check permissions
        if (!$user->hasRole('admin') && $merchant->user_id !== $user->id) {
            return $this->errorResponse('Unauthorized', 403);
        }

        // Add financial statistics
        $merchant->monthly_revenue = $merchant->getMonthlyRevenue();
        $merchant->monthly_orders = $merchant->getMonthlyOrders();
        $merchant->previous_month_revenue = $merchant->getPreviousMonthRevenue();
        $merchant->previous_month_orders = $merchant->getPreviousMonthOrders();
        $merchant->outstanding_balance = $merchant->getOutstandingBalance();

        return $this->successResponse($merchant);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $merchant = Merchant::findOrFail($id);
        $user = $request->user();

        // Check permissions
        if (!$user->hasRole('admin') && $merchant->user_id !== $user->id) {
            return $this->errorResponse('Unauthorized', 403);
        }

        $request->validate([
            'business_name' => 'sometimes|required|string|max:255',
            'business_id' => 'sometimes|required|string|unique:merchants,business_id,' . $id,
            'phone' => 'sometimes|required|string|max:20',
            'website' => 'nullable|url',
            'description' => 'nullable|string',
            'address' => 'sometimes|required|array',
            'status' => 'sometimes|in:active,suspended,pending',
            'verification_status' => 'sometimes|in:pending,verified,rejected',
            'commission_rate' => 'numeric|min:0|max:100',
            'monthly_fee' => 'numeric|min:0',
            'credit_limit' => 'numeric|min:0',
            'payment_methods' => 'nullable|array',
            'shipping_settings' => 'nullable|array',
            'banner_settings' => 'nullable|array',
            'popup_settings' => 'nullable|array',
        ]);

        $merchant->update($request->all());

        // Update verification timestamp if status changed to verified
        if ($request->has('verification_status') && $request->verification_status === 'verified' && !$merchant->verified_at) {
            $merchant->update(['verified_at' => now()]);
        }

        return $this->successResponse($merchant->load('user'), 'Merchant updated successfully');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, string $id)
    {
        $merchant = Merchant::findOrFail($id);
        $user = $request->user();

        // Only admin can delete merchants
        if (!$user->hasRole('admin')) {
            return $this->errorResponse('Unauthorized', 403);
        }

        $merchant->delete();

        return $this->successResponse(null, 'Merchant deleted successfully');
    }

    /**
     * Get merchant dashboard statistics
     */
    public function dashboard(Request $request)
    {
        $user = $request->user();
        
        if (!$user->hasRole('merchant')) {
            return $this->errorResponse('Unauthorized', 403);
        }

        $merchant = $user->merchant;
        if (!$merchant) {
            return $this->errorResponse('Merchant profile not found', 404);
        }

        $stats = [
            'monthly_revenue' => $merchant->getMonthlyRevenue(),
            'monthly_orders' => $merchant->getMonthlyOrders(),
            'previous_month_revenue' => $merchant->getPreviousMonthRevenue(),
            'previous_month_orders' => $merchant->getPreviousMonthOrders(),
            'outstanding_balance' => $merchant->getOutstandingBalance(),
            'total_orders' => $merchant->orders()->count(),
            'pending_orders' => $merchant->orders()->where('status', 'pending')->count(),
            'processing_orders' => $merchant->orders()->where('status', 'processing')->count(),
            'shipped_orders' => $merchant->orders()->where('status', 'shipped')->count(),
            'delivered_orders' => $merchant->orders()->where('status', 'delivered')->count(),
            'balance' => $merchant->balance,
            'credit_limit' => $merchant->credit_limit,
        ];

        return $this->successResponse($stats);
    }

    /**
     * Get merchant profile
     */
    public function profile(Request $request)
    {
        $user = $request->user();
        
        if (!$user->hasRole('merchant')) {
            return $this->errorResponse('Unauthorized', 403);
        }

        $merchant = $user->merchant;
        if (!$merchant) {
            return $this->errorResponse('Merchant profile not found', 404);
        }

        return $this->successResponse($merchant->load('user'));
    }

    /**
     * Update merchant profile
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();
        
        if (!$user->hasRole('merchant')) {
            return $this->errorResponse('Unauthorized', 403);
        }

        $merchant = $user->merchant;
        if (!$merchant) {
            return $this->errorResponse('Merchant profile not found', 404);
        }

        $request->validate([
            'business_name' => 'sometimes|required|string|max:255',
            'phone' => 'sometimes|required|string|max:20',
            'website' => 'nullable|url',
            'description' => 'nullable|string',
            'address' => 'sometimes|required|array',
            'payment_methods' => 'nullable|array',
            'shipping_settings' => 'nullable|array',
            'banner_settings' => 'nullable|array',
            'popup_settings' => 'nullable|array',
        ]);

        $merchant->update($request->all());

        return $this->successResponse($merchant->load('user'), 'Profile updated successfully');
    }
}
