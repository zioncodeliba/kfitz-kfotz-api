<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use App\Models\Product;
use App\Services\ShippingSettingsService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class MerchantController extends Controller
{
    use ApiResponse;

    /**
     * Order statuses counted toward monthly_orders metric.
     *
     * Pending/confirmed/processing cover "open" orders,
     * and shipped covers "waiting/being delivered".
     */
    private array $monthlyOrderStatuses = [
        Order::STATUS_PENDING,
        Order::STATUS_CONFIRMED,
        Order::STATUS_PROCESSING,
        Order::STATUS_SHIPPED,
    ];

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
        $query = Merchant::with(['user', 'agent', 'pluginSites']);

        if ($user->hasRole('admin')) {
            // Admin can see all merchants
        } elseif ($user->hasRole('agent')) {
            $query->where('agent_id', $user->id);
        } elseif ($user->hasRole('merchant')) {
            $query->where('user_id', $user->id);
        } else {
            return $this->errorResponse('Unauthorized', 403);
        }

        if ($user->hasRole('admin') && $request->filled('agent_id')) {
            $query->where('agent_id', $request->input('agent_id'));
        }

        if ($user->hasRole('admin') && $request->boolean('unassigned', false)) {
            $query->whereNull('agent_id');
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

        $merchants->getCollection()->transform(function (Merchant $merchant) {
            if ($merchant->relationLoaded('user') && $merchant->user) {
                $merchant->setAttribute('credit_limit', $merchant->user->order_limit);
                $merchant->setAttribute('balance', $merchant->user->order_balance);
            }

            return $merchant;
        });

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

        $data = $request->validate([
            'user_id' => 'required|exists:users,id|unique:merchants,user_id',
            'agent_id' => 'nullable|exists:users,id',
            'business_name' => 'required|string|max:255',
            'business_id' => 'required|string|unique:merchants,business_id',
            'phone' => 'required|string|max:20',
            'website' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'address' => 'required|array',
            'address.name' => 'required|string',
            'address.address' => 'required|string',
            'address.city' => 'required|string',
            'address.zip' => 'required|string',
            'address.phone' => 'required|string',
            'commission_rate' => 'numeric|min:0|max:100',
            'monthly_fee' => 'numeric|min:0',
            'order_limit' => 'nullable|numeric|min:0',
            'plugin_sites' => 'nullable|array',
            'plugin_sites.*.site_url' => 'required|string|max:255',
            'plugin_sites.*.name' => 'nullable|string|max:255',
            'plugin_sites.*.contact_name' => 'nullable|string|max:255',
            'plugin_sites.*.contact_phone' => 'nullable|string|max:50',
            'plugin_sites.*.platform' => 'nullable|string|max:100',
            'plugin_sites.*.plugin_installed_at' => 'nullable|date',
            'plugin_sites.*.status' => 'nullable|string|max:50',
            'plugin_sites.*.balance' => 'nullable|numeric',
            'plugin_sites.*.credit_limit' => 'nullable|numeric',
        ]);

        $merchantUser = User::findOrFail($data['user_id']);
        if ($merchantUser->role === 'admin') {
            return $this->errorResponse('Cannot convert an admin user into a merchant', 422);
        }

        if (!$merchantUser->hasRole('merchant')) {
            $merchantUser->update(['role' => 'merchant']);
        }

        $agentId = $data['agent_id'] ?? null;
        if ($agentId) {
            $agent = User::where('id', $agentId)->where('role', 'agent')->first();
            if (!$agent) {
                return $this->errorResponse('Assigned agent must be a valid agent user', 422);
            }
        }

        $orderLimit = Arr::pull($data, 'order_limit', null);

        $pluginSites = collect($data['plugin_sites'] ?? [])
            ->map(function (array $site) {
                return [
                    'site_url' => trim($site['site_url']),
                    'name' => isset($site['name']) ? trim((string) $site['name']) : null,
                    'contact_name' => isset($site['contact_name']) ? trim((string) $site['contact_name']) : null,
                    'contact_phone' => isset($site['contact_phone']) ? trim((string) $site['contact_phone']) : null,
                    'platform' => isset($site['platform']) ? trim((string) $site['platform']) : null,
                    'plugin_installed_at' => $site['plugin_installed_at'] ?? null,
                    'status' => isset($site['status']) && trim((string) $site['status']) !== ''
                        ? trim((string) $site['status'])
                        : 'active',
                    'balance' => isset($site['balance']) ? (float) $site['balance'] : 0,
                    'credit_limit' => isset($site['credit_limit']) ? (float) $site['credit_limit'] : 0,
                ];
            })
            ->filter(fn ($site) => !empty($site['site_url']))
            ->values();

        unset($data['plugin_sites']);

        $merchant = Merchant::create($data);

        if ($orderLimit !== null) {
            $merchantUser->order_limit = (float) $orderLimit;
            $merchantUser->save();
        }

        $merchantUser->refreshOrderFinancials();

        $merchant->refresh();

        if ($pluginSites->isNotEmpty()) {
            $merchant->pluginSites()->createMany(
                $pluginSites
                    ->map(function (array $site) use ($merchant) {
                        $site['user_id'] = $merchant->user_id;
                        return $site;
                    })
                    ->all()
            );
        }

        $merchant->setAttribute('credit_limit', $merchant->user?->order_limit);
        $merchant->setAttribute('balance', $merchant->user?->order_balance);

        return $this->createdResponse(
            $merchant->load(['user', 'agent', 'pluginSites']),
            'Merchant created successfully'
        );
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, string $id)
    {
        $user = $request->user();
        $merchant = Merchant::with(['user', 'agent', 'pluginSites', 'orders'])->findOrFail($id);

        // Check permissions
        if ($user->hasRole('admin')) {
            // allowed
        } elseif ($user->hasRole('agent')) {
            if ($merchant->agent_id !== $user->id) {
                return $this->errorResponse('Unauthorized', 403);
            }
        } elseif ($merchant->user_id !== $user->id) {
            return $this->errorResponse('Unauthorized', 403);
        }

        // Add financial statistics
        $merchant->monthly_revenue = $merchant->getMonthlyRevenue();
        $merchant->monthly_orders = $merchant->getMonthlyOrders($this->monthlyOrderStatuses);
        $merchant->previous_month_revenue = $merchant->getPreviousMonthRevenue();
        $merchant->previous_month_orders = $merchant->getPreviousMonthOrders();
        $merchant->outstanding_balance = $merchant->getOutstandingBalance();
        $merchant->back_in_stock_products = $this->getBackInStockCount($merchant->id);

        $merchant->setAttribute('credit_limit', $merchant->user?->order_limit);
        $merchant->setAttribute('balance', $merchant->user?->order_balance);

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

        $data = $request->validate([
            'business_name' => 'sometimes|required|string|max:255',
            'business_id' => 'sometimes|required|string|unique:merchants,business_id,' . $id,
            'phone' => 'sometimes|required|string|max:20',
            'website' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'address' => 'sometimes|required|array',
            'status' => 'sometimes|in:active,suspended,pending',
            'verification_status' => 'sometimes|in:pending,verified,rejected',
            'commission_rate' => 'numeric|min:0|max:100',
            'monthly_fee' => 'numeric|min:0',
            'order_limit' => 'nullable|numeric|min:0',
            'payment_methods' => 'nullable|array',
            'shipping_settings' => 'nullable|array',
            'banner_settings' => 'nullable|array',
            'popup_settings' => 'nullable|array',
            'agent_id' => 'sometimes|nullable|exists:users,id',
            'plugin_sites' => 'sometimes|array',
            'plugin_sites.*.site_url' => 'required_with:plugin_sites|string|max:255',
            'plugin_sites.*.name' => 'nullable|string|max:255',
            'plugin_sites.*.contact_name' => 'nullable|string|max:255',
            'plugin_sites.*.contact_phone' => 'nullable|string|max:50',
            'plugin_sites.*.platform' => 'nullable|string|max:100',
            'plugin_sites.*.plugin_installed_at' => 'nullable|date',
            'plugin_sites.*.status' => 'nullable|string|max:50',
            'plugin_sites.*.balance' => 'nullable|numeric',
            'plugin_sites.*.credit_limit' => 'nullable|numeric',
        ]);

        if (array_key_exists('agent_id', $data)) {
            $agentId = $data['agent_id'];

            if ($agentId) {
                $agent = User::where('id', $agentId)->where('role', 'agent')->first();
                if (!$agent) {
                    return $this->errorResponse('Assigned agent must be a valid agent user', 422);
                }
            }
        }

        $orderLimit = Arr::pull($data, 'order_limit', null);

        $pluginSites = null;
        if (array_key_exists('plugin_sites', $data)) {
            $pluginSites = collect($data['plugin_sites'] ?? [])
                ->map(function (array $site) {
                    return [
                        'site_url' => trim($site['site_url']),
                        'name' => isset($site['name']) ? trim((string) $site['name']) : null,
                        'contact_name' => isset($site['contact_name']) ? trim((string) $site['contact_name']) : null,
                        'contact_phone' => isset($site['contact_phone']) ? trim((string) $site['contact_phone']) : null,
                        'platform' => isset($site['platform']) ? trim((string) $site['platform']) : null,
                        'plugin_installed_at' => $site['plugin_installed_at'] ?? null,
                        'status' => isset($site['status']) && trim((string) $site['status']) !== ''
                            ? trim((string) $site['status'])
                            : 'active',
                        'balance' => isset($site['balance']) ? (float) $site['balance'] : 0,
                        'credit_limit' => isset($site['credit_limit']) ? (float) $site['credit_limit'] : 0,
                    ];
                })
                ->filter(fn ($site) => !empty($site['site_url']))
                ->values();
            unset($data['plugin_sites']);
        }

        $merchant->update($data);

        if ($orderLimit !== null && $merchant->user) {
            $merchant->user->forceFill([
                'order_limit' => (float) $orderLimit,
            ])->save();
            $merchant->user->refreshOrderFinancials();
        }

        // Update verification timestamp if status changed to verified
        if (
            array_key_exists('verification_status', $data) &&
            $data['verification_status'] === 'verified' &&
            !$merchant->verified_at
        ) {
            $merchant->update(['verified_at' => now()]);
        }

        if ($pluginSites !== null) {
            $merchant->pluginSites()->delete();
            if ($pluginSites->isNotEmpty()) {
                $merchant->pluginSites()->createMany(
                    $pluginSites
                        ->map(function (array $site) use ($merchant) {
                            $site['user_id'] = $merchant->user_id;
                            return $site;
                        })
                        ->all()
                );
            }
        }

        $merchant->setAttribute('credit_limit', $merchant->user?->order_limit);
        $merchant->setAttribute('balance', $merchant->user?->order_balance);

        return $this->successResponse(
            $merchant->load(['user', 'agent', 'pluginSites']),
            'Merchant updated successfully'
        );
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

        $startOfCurrentMonth = Carbon::now()->startOfMonth();
        $endOfCurrentMonth = $startOfCurrentMonth->copy()->endOfMonth();

        $currentMonthOutstandingQuery = $merchant->orders()
            ->whereBetween('created_at', [$startOfCurrentMonth, $endOfCurrentMonth])
            ->where(function ($query) {
                $query->whereNull('payment_status')
                    ->orWhere('payment_status', '!=', 'paid');
            })
            ->whereNotIn('status', ['cancelled', 'canceled']);

        $currentMonthOutstanding = (clone $currentMonthOutstandingQuery)->sum('total');
        $currentMonthOutstandingCount = $currentMonthOutstandingQuery->count();

        $currentMonthPaidQuery = $merchant->orders()
            ->whereBetween('created_at', [$startOfCurrentMonth, $endOfCurrentMonth])
            ->where('payment_status', 'paid');

        $currentMonthPaidTotal = (clone $currentMonthPaidQuery)->sum('total');
        $currentMonthPaidCount = $currentMonthPaidQuery->count();

        $lowStockCount = Product::lowStock()->count();
        $availableProductsCount = Product::inStock()->count();
        $backInStockCount = $this->getBackInStockCount($merchant->id);

        $stats = [
            'monthly_revenue' => $merchant->getMonthlyRevenue(),
            'monthly_orders' => $merchant->getMonthlyOrders($this->monthlyOrderStatuses),
            'previous_month_revenue' => $merchant->getPreviousMonthRevenue(),
            'previous_month_orders' => $merchant->getPreviousMonthOrders(),
            'outstanding_balance' => $merchant->getOutstandingBalance(),
            'current_month_outstanding' => round($currentMonthOutstanding, 2),
            'current_month_unpaid_total' => round($currentMonthOutstanding, 2),
            'total_orders' => $merchant->orders()->count(),
            'pending_orders' => $merchant->orders()->where('status', 'pending')->count(),
            'processing_orders' => $merchant->orders()->where('status', 'processing')->count(),
            'shipped_orders' => $merchant->orders()->where('status', 'shipped')->count(),
            'delivered_orders' => $merchant->orders()->where('status', 'delivered')->count(),
            'balance' => $merchant->balance,
            'credit_limit' => $merchant->credit_limit,
            'low_stock_products' => $lowStockCount,
            'available_products' => $availableProductsCount,
            'current_month_unpaid_orders' => $currentMonthOutstandingCount,
            'back_in_stock_products' => $backInStockCount,
        ];

        $historicalOrdersQuery = $merchant->orders()->where('created_at', '<', $startOfCurrentMonth);

        $historicalPaidQuery = (clone $historicalOrdersQuery)->where('payment_status', 'paid');
        $historicalPaidTotal = (clone $historicalPaidQuery)->sum('total');
        $historicalPaidCount = $historicalPaidQuery->count();

        $historicalUnpaidQuery = (clone $historicalOrdersQuery)->where('payment_status', '!=', 'paid');
        $historicalUnpaidTotal = (clone $historicalUnpaidQuery)->sum('total');
        $historicalUnpaidCount = $historicalUnpaidQuery->count();

        $outstandingOrdersQuery = $merchant->orders()->where('payment_status', 'pending');
        $outstandingOrdersCount = $outstandingOrdersQuery->count();

        $paidOrdersQuery = $merchant->orders()->where('payment_status', 'paid');
        $paidOrdersTotal = (clone $paidOrdersQuery)->sum('total');
        $paidOrdersCount = $paidOrdersQuery->count();

        $stats['historical_orders_summary'] = [
            'paid_total' => round($historicalPaidTotal, 2),
            'paid_count' => $historicalPaidCount,
            'unpaid_total' => round($historicalUnpaidTotal, 2),
            'unpaid_count' => $historicalUnpaidCount,
        ];

        $stats['payment_overview'] = [
            'outstanding_total' => round($currentMonthOutstanding, 2),
            'outstanding_count' => $currentMonthOutstandingCount,
            'paid_total' => round($currentMonthPaidTotal, 2),
            'paid_count' => $currentMonthPaidCount,
            'all_time_outstanding_total' => round($stats['outstanding_balance'], 2),
            'all_time_outstanding_count' => $outstandingOrdersCount,
            'all_time_paid_total' => round($paidOrdersTotal, 2),
            'all_time_paid_count' => $paidOrdersCount,
            'period' => [
                'label' => 'current_month',
                'start' => $startOfCurrentMonth->toDateString(),
                'end' => $endOfCurrentMonth->toDateString(),
            ],
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
            return $this->successResponse([
                'user' => $user->only(['id', 'name', 'email', 'phone']),
                'agent' => null,
                'profile' => null,
                'plugin_sites' => [],
            ], 'Merchant profile not found');
        }

        return $this->successResponse($this->formatMerchantProfile($user, $merchant));
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

            $contactName = $request->input('contact_name', $request->input('contact_person', $user->name));
            $emailForOrders = $request->input('email_for_orders', $request->input('email', $user->email));

            $merchant = Merchant::create([
                'user_id' => $user->id,
                'business_name' => $validated['business_name'],
                'contact_name' => $contactName,
                'business_id' => $validated['business_id'],
                'phone' => $validated['phone'],
                'email_for_orders' => $emailForOrders,
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

            return $this->successResponse(
                $this->formatMerchantProfile($user, $merchant->refresh()),
                'Profile created successfully'
            );
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

        $contactName = $request->input('contact_name', $request->input('contact_person'));
        if ($contactName !== null) {
            $validated['contact_name'] = $contactName ?: $user->name;
        }

        $emailForOrders = $request->input('email_for_orders', $request->input('email'));
        if ($emailForOrders !== null) {
            $validated['email_for_orders'] = $emailForOrders ?: $user->email;
        }

        $merchant->update($validated);

        return $this->successResponse(
            $this->formatMerchantProfile($user, $merchant->refresh()),
            'Profile updated successfully'
        );
    }

    protected function formatMerchantProfile(User $user, Merchant $merchant): array
    {
        $merchant->loadMissing(['user', 'pluginSites', 'agent']);

        $billingAddress = is_array($merchant->address) ? $merchant->address : null;
        $shippingSettings = is_array($merchant->shipping_settings) ? $merchant->shipping_settings : null;
        $shippingAddress = null;
        $bankDetails = null;

        if ($shippingSettings) {
            $candidate = data_get($shippingSettings, 'default_shipping_address');
            $shippingAddress = is_array($candidate) ? $candidate : null;
            $bankCandidate = data_get($shippingSettings, 'bank_details');
            $bankDetails = is_array($bankCandidate) ? $bankCandidate : null;
        }

        $normalizeAddress = static function (?array $address): array {
            if (!is_array($address)) {
                return [
                    'street' => null,
                    'city' => null,
                    'zip' => null,
                    'phone' => null,
                ];
            }

            $street = $address['street'] ?? $address['address'] ?? null;
            $zip = $address['zip'] ?? $address['postal_code'] ?? null;
            $phone = $address['phone'] ?? $address['contact_phone'] ?? null;

            return [
                'street' => is_string($street) ? trim($street) : null,
                'city' => isset($address['city']) && is_string($address['city']) ? trim($address['city']) : null,
                'zip' => is_string($zip) ? trim($zip) : null,
                'phone' => is_string($phone) ? trim($phone) : null,
            ];
        };

        $normalizeBankDetails = static function (?array $details): array {
            if (!is_array($details)) {
                return [
                    'bank_name' => null,
                    'branch_number' => null,
                    'account_number' => null,
                    'account_name' => null,
                ];
            }

            $bankName = $details['bank_name'] ?? $details['name'] ?? null;
            $branchNumber = $details['branch_number'] ?? $details['branch'] ?? null;
            $accountNumber = $details['account_number'] ?? $details['number'] ?? null;
            $accountName = $details['account_name'] ?? $details['owner'] ?? null;

            return [
                'bank_name' => is_string($bankName) ? trim($bankName) : null,
                'branch_number' => is_string($branchNumber) ? trim($branchNumber) : null,
                'account_number' => is_string($accountNumber) ? trim($accountNumber) : null,
                'account_name' => is_string($accountName) ? trim($accountName) : null,
            ];
        };

        $normalizedBilling = $normalizeAddress($billingAddress);
        $normalizedShipping = $normalizeAddress($shippingAddress);
        $normalizedBank = $normalizeBankDetails($bankDetails);

        $shippingSame = data_get($shippingSettings, 'use_billing_for_shipping');
        $platform = $shippingSettings ? data_get($shippingSettings, 'platform') : null;
        $platform = is_string($platform) ? trim($platform) : null;

        if ($shippingSame === null) {
            $shippingSame = empty(array_filter($normalizedShipping)) ||
                $normalizedBilling === $normalizedShipping;
        } else {
            $shippingSame = (bool) $shippingSame;
        }

        $hasBillingData = !empty(array_filter($normalizedBilling));
        $hasShippingData = !empty(array_filter($normalizedShipping));
        $hasBankData = !empty(array_filter($normalizedBank));

        return [
            'user' => $user->only(['id', 'name', 'email', 'phone']),
            'agent' => $merchant->agent ? $merchant->agent->only(['id', 'name', 'email']) : null,
            'profile' => array_merge(
                $merchant->only(['business_name', 'phone', 'business_id']),
                [
                    'contact_name' => $merchant->contact_name ?? $user->name,
                    'contact_person' => $merchant->contact_name ?? $user->name,
                    'email_for_orders' => $merchant->email_for_orders ?? $user->email,
                    'email' => $merchant->email_for_orders ?? $user->email,
                    'tax_id' => $merchant->business_id,
                    'address' => $billingAddress,
                    'shipping_address_original' => $shippingAddress,
                    'billing_address' => $hasBillingData ? $normalizedBilling : null,
                    'shipping_address' => $hasShippingData ? $normalizedShipping : null,
                    'shipping_same_as_billing' => $shippingSame,
                    'bank_details' => $hasBankData ? $normalizedBank : null,
                    'platform' => $platform,
                    'website' => $merchant->website ? trim($merchant->website) : null,
                    'description' => $merchant->description ? trim($merchant->description) : null,
                ]
            ),
            'plugin_sites' => $merchant->pluginSites->map(function ($site) {
                return [
                    'id' => $site->id,
                    'user_id' => $site->user_id,
                    'site_url' => $site->site_url,
                    'name' => $site->name,
                    'contact_name' => $site->contact_name,
                    'contact_phone' => $site->contact_phone,
                    'platform' => $site->platform,
                    'plugin_installed_at' => $site->plugin_installed_at,
                    'status' => $site->status,
                    'balance' => $site->balance,
                    'credit_limit' => $site->credit_limit,
                ];
            }),
        ];
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

    protected function getBackInStockCount(?int $merchantUserId = null): int
    {
        // Currently products are not scoped per merchant; when ownership is added we can filter here.
        return Product::query()
            ->whereNotNull('restocked_at')
            ->where('restocked_initial_stock', '>', 0)
            ->where('stock_quantity', '>', 0)
            ->whereRaw(
                '(restocked_initial_stock - stock_quantity) < (CASE WHEN restocked_initial_stock < 10 THEN 1 ELSE CEIL(restocked_initial_stock * 0.1) END)'
            )
            ->count();
    }
}
