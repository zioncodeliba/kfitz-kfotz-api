<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\Merchant;
use App\Models\User;
use App\Models\Role;
use App\Models\Product;
use App\Services\ShippingSettingsService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MerchantController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected ShippingSettingsService $shippingSettingsService
    ) {
    }

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

        $currentMonthOutstanding = $merchant->orders()
            ->whereIn('status', ['pending', 'processing', 'shipped'])
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('total');

        $lowStockCount = Product::lowStock()->count();
        $availableProductsCount = Product::inStock()->count();

        $stats = [
            'monthly_revenue' => $merchant->getMonthlyRevenue(),
            'monthly_orders' => $merchant->getMonthlyOrders(),
            'previous_month_revenue' => $merchant->getPreviousMonthRevenue(),
            'previous_month_orders' => $merchant->getPreviousMonthOrders(),
            'outstanding_balance' => $merchant->getOutstandingBalance(),
            'current_month_outstanding' => $currentMonthOutstanding,
            'total_orders' => $merchant->orders()->count(),
            'pending_orders' => $merchant->orders()->where('status', 'pending')->count(),
            'processing_orders' => $merchant->orders()->where('status', 'processing')->count(),
            'shipped_orders' => $merchant->orders()->where('status', 'shipped')->count(),
            'delivered_orders' => $merchant->orders()->where('status', 'delivered')->count(),
            'balance' => $merchant->balance,
            'credit_limit' => $merchant->credit_limit,
            'low_stock_products' => $lowStockCount,
            'available_products' => $availableProductsCount,
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
            $validated = $request->validate([
                'business_name' => 'required|string|max:255',
                'business_id' => 'required|string|unique:merchants,business_id',
                'phone' => 'required|string|max:20',
                'website' => 'nullable|url',
                'description' => 'nullable|string',
                'address' => 'required|array',
                'address.name' => 'nullable|string|max:255',
                'address.address' => 'sometimes|required|string|max:255',
                'address.street' => 'sometimes|required|string|max:255',
                'address.city' => 'required|string|max:255',
                'address.zip' => 'required|string|max:20',
                'address.phone' => 'required|string|max:20',
                'shipping_address' => 'nullable|array',
                'shipping_address.address' => 'sometimes|required|string|max:255',
                'shipping_address.street' => 'sometimes|required|string|max:255',
                'shipping_address.city' => 'sometimes|required|string|max:255',
                'shipping_address.zip' => 'sometimes|required|string|max:20',
                'shipping_address.phone' => 'nullable|string|max:20',
                'bank_details' => 'nullable|array',
                'bank_details.bank_name' => 'nullable|string|max:255',
                'bank_details.branch_number' => 'nullable|string|max:50',
                'bank_details.account_number' => 'nullable|string|max:50',
                'bank_details.account_name' => 'nullable|string|max:255',
            ]);

            $billingAddress = $validated['address'];
            if (!empty($billingAddress['street']) && empty($billingAddress['address'])) {
                $billingAddress['address'] = $billingAddress['street'];
            }

            $merchant = Merchant::create([
                'user_id' => $user->id,
                'business_name' => $validated['business_name'],
                'business_id' => $validated['business_id'],
                'phone' => $validated['phone'],
                'website' => $validated['website'] ?? null,
                'description' => $validated['description'] ?? null,
                'address' => $billingAddress,
                'status' => 'active',
                'verification_status' => 'pending',
                'commission_rate' => 10,
                'monthly_fee' => 0,
                'balance' => 0,
                'credit_limit' => 0,
            ]);

            $shippingSettings = [];
            if (!empty($validated['bank_details'])) {
                $shippingSettings['bank_details'] = $validated['bank_details'];
            }

            if (!empty($validated['shipping_address'])) {
                $shippingAddress = $validated['shipping_address'];
                if (!empty($shippingAddress['street']) && empty($shippingAddress['address'])) {
                    $shippingAddress['address'] = $shippingAddress['street'];
                }
                $shippingSettings['default_shipping_address'] = $shippingAddress;
            }

            if (!empty($shippingSettings)) {
                $merchant->update(['shipping_settings' => $shippingSettings]);
            }

            return $this->successResponse($merchant->load('user'), 'Profile created successfully');
        }

        $validated = $request->validate([
            'business_name' => 'sometimes|required|string|max:255',
            'business_id' => [
                'sometimes',
                'required',
                'string',
                Rule::unique('merchants', 'business_id')->ignore($merchant->id),
            ],
            'phone' => 'sometimes|required|string|max:20',
            'website' => 'nullable|url',
            'description' => 'nullable|string',
            'address' => 'sometimes|required|array',
            'address.name' => 'nullable|string|max:255',
            'address.address' => 'sometimes|required|string|max:255',
            'address.city' => 'sometimes|required|string|max:255',
            'address.zip' => 'sometimes|required|string|max:20',
            'address.phone' => 'sometimes|required|string|max:20',
            'payment_methods' => 'nullable|array',
            'shipping_settings' => 'nullable|array',
            'banner_settings' => 'nullable|array',
            'popup_settings' => 'nullable|array',
        ]);

        $merchant->update($validated);

        return $this->successResponse($merchant->load('user'), 'Profile updated successfully');
    }

    /**
     * Get shipping settings for a merchant (admin only).
     */
    public function getShippingSettings(Request $request)
    {
        $user = $request->user();

        if (!$user->hasRole('admin')) {
            return $this->errorResponse('Unauthorized', 403);
        }
        
        $validated = $request->validate([
            'merchant_id' => 'required|exists:merchants,id',
        ]);

        $merchant = Merchant::find($validated['merchant_id']);
        if (!$merchant) {
            return $this->notFoundResponse('Merchant not found');
        }

        return $this->successResponse(
            $this->shippingSettingsService->buildPayload($merchant),
            'Shipping settings retrieved successfully'
        );
    }

    /**
     * Update shipping settings for a merchant (admin only).
     */
    public function updateShippingSettings(Request $request)
    {
        $user = $request->user();

        if (!$user->hasRole('admin')) {
            return $this->errorResponse('Unauthorized', 403);
        }
        
        $sizeOptions = $this->shippingSettingsService->shippingSizeOptions();

        $validated = $request->validate([
            'merchant_id' => 'required|exists:merchants,id',
            'default_destination' => 'nullable|string|max:100',
            'default_service_type' => 'nullable|string|max:100',
            'default_shipping_size' => ['nullable', Rule::in($sizeOptions)],
            'default_package_type' => ['nullable', Rule::in($sizeOptions)],
            'shipping_units' => 'nullable|array',
            'shipping_units.*.destination' => 'required|string|max:100',
            'shipping_units.*.service_type' => 'required|string|max:100',
            'shipping_units.*.carrier_id' => 'nullable|exists:shipping_carriers,id',
            'shipping_units.*.carrier_code' => 'nullable|string|max:191|exists:shipping_carriers,code',
            'shipping_units.*.shipping_size' => ['nullable', Rule::in($sizeOptions)],
            'shipping_units.*.package_type' => ['nullable', Rule::in($sizeOptions)],
            'shipping_units.*.quantity' => 'required|integer|min:1|max:999',
            'shipping_units.*.price' => 'nullable|numeric|min:0',
            'shipping_units.*.notes' => 'nullable|string',
        ]);

        $merchant = Merchant::find($validated['merchant_id']);
        if (!$merchant) {
            return $this->notFoundResponse('Merchant not found');
        }

        $merchant->update([
            'shipping_settings' => $this->shippingSettingsService->prepareForStorage(
                $validated,
                [
                    'merchant_id',
                    'dispatch_shipment',
                    'origin_address',
                    'destination_address',
                    'carrier_name',
                    'shipping_cost',
                    'cod_payment',
                    'cod_amount',
                    'shipment_notes',
                ]
            ),
        ]);

        return $this->successResponse(
            $this->shippingSettingsService->buildPayload($merchant->refresh()),
            'Shipping settings updated successfully'
        );
    }
}
