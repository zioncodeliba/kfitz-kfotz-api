<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MerchantCustomer;
use App\Models\Order;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class MerchantCustomerController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $user = $request->user();

        if (!$user->hasRole('merchant') && !$user->hasRole('admin')) {
            return $this->forbiddenResponse('Insufficient permissions');
        }

        $perPage = (int) $request->integer('per_page', 15);
        $perPage = $perPage > 0 ? min($perPage, 100) : 15;

        $merchantUserId = $user->hasRole('merchant')
            ? $user->id
            : (int) $request->integer('merchant_user_id', 0);

        if ($user->hasRole('admin') && $merchantUserId <= 0) {
            return $this->validationErrorResponse([
                'merchant_user_id' => ['Merchant user id is required for admin requests'],
            ]);
        }

        if ($merchantUserId <= 0) {
            return $this->validationErrorResponse([
                'merchant_user_id' => ['Unable to resolve merchant user id'],
            ]);
        }

        $query = MerchantCustomer::query()
            ->where('merchant_user_id', $merchantUserId)
            ->select(['id', 'merchant_user_id', 'name', 'email', 'phone', 'notes', 'address', 'created_at', 'updated_at'])
            ->addSelect([
                'last_order_at' => Order::select(DB::raw('MAX(created_at)'))
                    ->whereColumn('merchant_customers.id', 'orders.merchant_customer_id'),
            ])
            ->withCount('orders')
            ->withSum('orders as total_spent', 'total');

        if ($search = trim((string) $request->get('search', ''))) {
            $query->where(function ($searchQuery) use ($search) {
                $searchQuery
                    ->where('name', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . $search . '%')
                    ->orWhere('phone', 'like', '%' . $search . '%');
            });
        }

        if ($request->filled('sort')) {
            $direction = strtolower((string) $request->get('direction', 'desc')) === 'asc' ? 'asc' : 'desc';
            $allowedSorts = ['name', 'created_at', 'last_order_at', 'orders_count', 'total_spent'];
            $sort = in_array($request->get('sort'), $allowedSorts, true) ? $request->get('sort') : 'created_at';
            $query->orderBy($sort, $direction);
        } else {
            $query->orderByDesc('last_order_at')->orderByDesc('created_at');
        }

        $paginator = $query->paginate($perPage)->appends($request->query());

        $paginator->getCollection()->transform(function (MerchantCustomer $customer) {
            return $this->transformCustomerSummary($customer);
        });

        return $this->successResponse($paginator);
    }

    public function show(Request $request, MerchantCustomer $customer)
    {
        $user = $request->user();

        if (!$this->userCanAccessCustomer($user, $customer)) {
            return $this->forbiddenResponse('Insufficient permissions');
        }

        $customer->loadCount('orders')
            ->loadSum('orders as total_spent', 'total');

        $ordersLimit = (int) $request->integer('orders_limit', 100);
        $ordersLimit = $ordersLimit > 0 ? min($ordersLimit, 200) : 100;

        $orders = $customer->orders()
            ->withCount('items')
            ->orderByDesc('created_at')
            ->limit($ordersLimit)
            ->get();

        $response = $this->transformCustomerDetail($customer, $orders->all());

        return $this->successResponse($response);
    }

    public function update(Request $request, MerchantCustomer $customer)
    {
        $user = $request->user();

        if (!$this->userCanAccessCustomer($user, $customer)) {
            return $this->forbiddenResponse('Insufficient permissions');
        }

        $data = $request->validate([
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
            'address' => 'nullable|array',
            'address.line1' => 'nullable|string|max:255',
            'address.line2' => 'nullable|string|max:255',
            'address.city' => 'nullable|string|max:255',
            'address.state' => 'nullable|string|max:255',
            'address.zip' => 'nullable|string|max:50',
            'address.country' => 'nullable|string|max:255',
        ]);

        $payload = Arr::only($data, ['name', 'email', 'phone', 'notes']);

        if (array_key_exists('address', $data)) {
            $address = Arr::wrap($data['address']);
            $payload['address'] = empty(array_filter($address, fn ($value) => filled($value)))
                ? null
                : $address;
        }

        $customer->fill($payload);
        $customer->save();

        $customer->loadCount('orders')
            ->loadSum('orders as total_spent', 'total');

        $orders = $customer->orders()
            ->withCount('items')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return $this->successResponse([
            'customer' => $this->transformCustomerDetail($customer, $orders->all()),
        ], 'Customer updated successfully');
    }

    protected function transformCustomerSummary(MerchantCustomer $customer): array
    {
        return [
            'id' => $customer->id,
            'merchant_user_id' => $customer->merchant_user_id,
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'notes' => $customer->notes,
            'address' => $customer->address,
            'orders_count' => (int) ($customer->orders_count ?? 0),
            'total_spent' => (float) ($customer->total_spent ?? 0),
            'created_at' => optional($customer->created_at)->toIso8601String(),
            'updated_at' => optional($customer->updated_at)->toIso8601String(),
            'last_order_at' => $customer->last_order_at
                ? Carbon::parse($customer->last_order_at)->toIso8601String()
                : null,
        ];
    }

    protected function transformCustomerDetail(MerchantCustomer $customer, array $orders): array
    {
        return array_merge(
            $this->transformCustomerSummary($customer),
            [
                'orders' => array_map(function (Order $order) {
                    return [
                        'id' => $order->id,
                        'order_number' => $order->order_number,
                        'status' => $order->status,
                        'payment_status' => $order->payment_status,
                        'total' => (float) $order->total,
                        'subtotal' => (float) $order->subtotal,
                        'tax' => (float) $order->tax,
                        'discount' => (float) $order->discount,
                        'shipping_cost' => (float) $order->shipping_cost,
                        'created_at' => optional($order->created_at)->toIso8601String(),
                        'items_count' => (int) ($order->items_count ?? $order->items()->count()),
                        'shipping_address' => $order->shipping_address,
                        'billing_address' => $order->billing_address,
                        'plugin_site' => $this->extractPluginSiteInfo($order),
                    ];
                }, $orders),
            ]
        );
    }

    protected function userCanAccessCustomer($user, MerchantCustomer $customer): bool
    {
        if (!$user) {
            return false;
        }

        if ($user->hasRole('admin')) {
            return true;
        }

        if ($customer->merchant_user_id === $user->id) {
            return true;
        }

        return false;
    }

    protected function extractPluginSiteInfo(Order $order): ?array
    {
        $site = $order->merchantSite;
        if ($site) {
            return [
                'id' => $site->id,
                'name' => $site->name,
                'site_url' => $site->site_url,
                'platform' => $site->platform,
            ];
        }

        $metadata = $order->source_metadata;
        if (!is_array($metadata) || empty($metadata)) {
            return null;
        }

        $pluginSite = $metadata['plugin_site'] ?? null;
        if ($pluginSite && !is_array($pluginSite)) {
            $pluginSite = (array) $pluginSite;
        }

        $name = $pluginSite['name'] ?? $metadata['site_name'] ?? null;
        $siteUrl = $pluginSite['site_url'] ?? $metadata['site_url'] ?? null;
        $platform = $pluginSite['platform'] ?? $metadata['platform'] ?? null;
        $id = $pluginSite['id'] ?? null;

        if (!$name && !$siteUrl) {
            return null;
        }

        return [
            'id' => $id !== null && is_numeric($id) ? (int) $id : null,
            'name' => $name,
            'site_url' => $siteUrl,
            'platform' => $platform,
        ];
    }
}
