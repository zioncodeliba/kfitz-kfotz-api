<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MerchantSite;
use App\Models\Order;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PluginSiteController extends Controller
{
    use ApiResponse;

    /**
     * Display a listing of plugin sites with aggregated statistics.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if (!$user->hasRole('admin')) {
            return $this->errorResponse('Unauthorized', 403);
        }

        $perPage = (int) $request->query('per_page', 15);
        $perPage = max(1, min($perPage, 100));

        $search = trim((string) $request->query('search', ''));

        $query = MerchantSite::query()
            ->with([
                'user:id,name,phone',
                'user.merchant:id,user_id,business_name,contact_name,phone',
            ])
            ->orderBy('created_at', 'desc');

        if ($search !== '') {
            $query->where(function ($searchQuery) use ($search) {
                $searchQuery
                    ->where('site_url', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('contact_name', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where(function ($userInner) use ($search) {
                            $userInner->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                    })
                    ->orWhereHas('user.merchant', function ($merchantQuery) use ($search) {
                        $merchantQuery->where('business_name', 'like', "%{$search}%");
                    });
            });
        }

        $sites = $query->paginate($perPage);

        $stats = $this->collectOrderStats($sites->getCollection());

        $sites->getCollection()->transform(function (MerchantSite $site) use ($stats) {
            $user = $site->user;
            $merchant = optional($user)->merchant;
            $merchantId = $merchant?->id;
            $key = $merchantId . '|' . $site->site_url;
            $stat = $stats[$key] ?? [
                'current_month_orders' => 0,
                'current_month_revenue' => 0,
                'previous_month_orders' => 0,
                'previous_month_revenue' => 0,
            ];

            return [
                'id' => $site->id,
                'user_id' => $site->user_id,
                'merchant_id' => $merchantId,
                'site_url' => $site->site_url,
                'name' => $site->name,
                'platform' => $site->platform,
                'contact_name' => $site->contact_name ?? ($merchant->contact_name ?? $user?->name ?? null),
                'contact_phone' => $site->contact_phone ?? $user?->phone ?? $merchant?->phone,
                'status' => $site->status ?? 'active',
                'balance' => $site->balance,
                'credit_limit' => $site->credit_limit,
                'current_month_orders' => (int) ($stat['current_month_orders'] ?? 0),
                'current_month_revenue' => (float) ($stat['current_month_revenue'] ?? 0),
                'previous_month_orders' => (int) ($stat['previous_month_orders'] ?? 0),
                'previous_month_revenue' => (float) ($stat['previous_month_revenue'] ?? 0),
                'merchant' => $merchant ? [
                    'id' => $merchant->id,
                    'business_name' => $merchant->business_name,
                    'contact_name' => $merchant->contact_name,
                    'contact_phone' => $merchant->phone,
                ] : null,
                'created_at' => $site->created_at,
                'updated_at' => $site->updated_at,
            ];
        });

        return $this->successResponse($sites);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if (!$user->hasRole('admin')) {
            return $this->errorResponse('Unauthorized', 403);
        }

        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'site_url' => [
                'required',
                'string',
                'max:255',
                Rule::unique('merchant_sites', 'site_url')->where(function ($query) use ($request) {
                    return $query->where('user_id', $request->input('user_id'));
                }),
            ],
            'name' => 'nullable|string|max:255',
            'platform' => 'nullable|string|max:100',
            'contact_name' => 'nullable|string|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'status' => 'nullable|string|max:50',
            'balance' => 'nullable|numeric',
            'credit_limit' => 'nullable|numeric',
        ]);

        $targetUser = User::findOrFail($data['user_id']);
        if (!$targetUser->hasRole('merchant')) {
            return $this->errorResponse('ניתן להוסיף אתר רק למשתמש בעל תפקיד סוחר.', 422);
        }

        $attributes = [
            'user_id' => $data['user_id'],
            'site_url' => trim($data['site_url']),
            'name' => isset($data['name']) ? trim((string) $data['name']) : null,
            'platform' => isset($data['platform']) ? trim((string) $data['platform']) : null,
            'contact_name' => isset($data['contact_name']) ? trim((string) $data['contact_name']) : null,
            'contact_phone' => isset($data['contact_phone']) ? trim((string) $data['contact_phone']) : null,
            'status' => isset($data['status']) && trim((string) $data['status']) !== ''
                ? trim((string) $data['status'])
                : 'active',
            'balance' => isset($data['balance']) ? (float) $data['balance'] : 0,
            'credit_limit' => isset($data['credit_limit']) ? (float) $data['credit_limit'] : 0,
        ];

        $site = MerchantSite::create($attributes);

        return $this->createdResponse(
            $site->load(['user:id,name,phone', 'user.merchant:id,user_id,business_name,contact_name,phone']),
            'Plugin site created successfully'
        );
    }

    /**
     * Collect aggregate order statistics for the provided sites.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\MerchantSite>  $sites
     * @return array<string, array<string, int|float>>
     */
    protected function collectOrderStats(Collection $sites): array
    {
        if ($sites->isEmpty()) {
            return [];
        }

        $currentMonth = now()->format('Y-m');
        $previousMonth = now()->subMonth()->format('Y-m');

        $sitePairs = $sites
            ->map(function (MerchantSite $site) {
                $merchantId = optional(optional($site->user)->merchant)->id;

                if (!$merchantId) {
                    return null;
                }

                return [
                    'merchant_id' => $merchantId,
                    'site_url' => $site->site_url,
                ];
            })
            ->filter()
            ->values();

        if ($sitePairs->isEmpty()) {
            return [];
        }

        $keys = $sitePairs->map(function (array $pair) {
            return $pair['merchant_id'] . '|' . $pair['site_url'];
        })->values();

        $statsQuery = Order::query()
            ->selectRaw("
                CAST(JSON_UNQUOTE(JSON_EXTRACT(source_metadata, '$.merchant_id')) AS UNSIGNED) AS merchant_id,
                JSON_UNQUOTE(JSON_EXTRACT(source_metadata, '$.site_url')) AS site_url,
                SUM(CASE WHEN DATE_FORMAT(created_at, '%Y-%m') = ? THEN 1 ELSE 0 END) AS current_month_orders,
                SUM(CASE WHEN DATE_FORMAT(created_at, '%Y-%m') = ? THEN total ELSE 0 END) AS current_month_revenue,
                SUM(CASE WHEN DATE_FORMAT(created_at, '%Y-%m') = ? THEN 1 ELSE 0 END) AS previous_month_orders,
                SUM(CASE WHEN DATE_FORMAT(created_at, '%Y-%m') = ? THEN total ELSE 0 END) AS previous_month_revenue
            ", [
                $currentMonth,
                $currentMonth,
                $previousMonth,
                $previousMonth,
            ])
            ->whereNotNull('source_metadata')
            ->whereRaw("JSON_EXTRACT(source_metadata, '$.merchant_id') IS NOT NULL")
            ->whereRaw("JSON_EXTRACT(source_metadata, '$.site_url') IS NOT NULL")
            ->whereIn(
                DB::raw("CONCAT(CAST(JSON_UNQUOTE(JSON_EXTRACT(source_metadata, '$.merchant_id')) AS UNSIGNED), '|', JSON_UNQUOTE(JSON_EXTRACT(source_metadata, '$.site_url')))"),
                $keys->all()
            )
            ->groupByRaw("
                CAST(JSON_UNQUOTE(JSON_EXTRACT(source_metadata, '$.merchant_id')) AS UNSIGNED),
                JSON_UNQUOTE(JSON_EXTRACT(source_metadata, '$.site_url'))
            ");

        $results = $statsQuery->get()->keyBy(function ($row) {
            return $row->merchant_id . '|' . $row->site_url;
        });

        return $results->map(function ($row) {
            return [
                'current_month_orders' => (int) $row->current_month_orders,
                'current_month_revenue' => (float) $row->current_month_revenue,
                'previous_month_orders' => (int) $row->previous_month_orders,
                'previous_month_revenue' => (float) $row->previous_month_revenue,
            ];
        })->all();
    }
}
