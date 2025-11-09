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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

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

    public function store(Request $request)
    {
        $user = $request->user();

        if (!$user->hasRole('merchant') && !$user->hasRole('admin')) {
            return $this->forbiddenResponse('Insufficient permissions');
        }

        $validated = $request->validate([
            'merchant_user_id' => $user->hasRole('admin') ? 'required|integer|exists:users,id' : 'nullable',
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'required|string|max:50',
            'notes' => 'nullable|string',
            'address' => 'nullable|array',
            'address.line1' => 'nullable|string|max:255',
            'address.line2' => 'nullable|string|max:255',
            'address.city' => 'nullable|string|max:255',
            'address.state' => 'nullable|string|max:255',
            'address.zip' => 'nullable|string|max:50',
            'address.country' => 'nullable|string|max:255',
        ]);

        $merchantUserId = $user->hasRole('merchant')
            ? $user->id
            : (int) ($validated['merchant_user_id'] ?? 0);

        if ($merchantUserId <= 0) {
            return $this->validationErrorResponse([
                'merchant_user_id' => ['Unable to resolve merchant user id'],
            ]);
        }

        $customer = $this->upsertMerchantCustomer(
            $merchantUserId,
            Arr::only($validated, ['name', 'email', 'phone', 'notes', 'address'])
        );

        $customer->loadCount('orders')
            ->loadSum('orders as total_spent', 'total');

        return $this->successResponse(
            [
                'customer' => $this->transformCustomerDetail($customer, []),
            ],
            'Customer created successfully'
        );
    }

    public function import(Request $request)
    {
        $user = $request->user();

        if (!$user->hasRole('merchant') && !$user->hasRole('admin')) {
            return $this->forbiddenResponse('Insufficient permissions');
        }

        $validated = $request->validate([
            'merchant_user_id' => $user->hasRole('admin') ? 'required|integer|exists:users,id' : 'nullable',
            'file' => 'required|file|mimes:csv,txt,xls,xlsx',
        ]);

        $merchantUserId = $user->hasRole('merchant')
            ? $user->id
            : (int) ($validated['merchant_user_id'] ?? 0);

        if ($merchantUserId <= 0) {
            return $this->validationErrorResponse([
                'merchant_user_id' => ['Unable to resolve merchant user id'],
            ]);
        }

        /** @var UploadedFile $file */
        $file = $validated['file'];

        try {
            $rows = $this->extractCustomerRowsFromFile($file);
        } catch (\Throwable $exception) {
            return $this->errorResponse('Failed to parse the uploaded file: ' . $exception->getMessage(), 422);
        }

        if (empty($rows)) {
            return $this->errorResponse('לא נמצאו נתונים שניתן לייבא בקובץ שסופק', 422);
        }

        $summary = [
            'total_rows' => count($rows),
            'imported' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        foreach ($rows as $row) {
            $line = $row['_line'] ?? null;
            $data = $row['data'] ?? [];

            $name = trim((string) ($data['name'] ?? ''));
            $phone = trim((string) ($data['phone'] ?? ''));

            if ($name === '' || $phone === '') {
                $summary['skipped']++;
                $summary['errors'][] = $this->buildRowError($line, 'שם וטלפון הם שדות חובה');
                continue;
            }

            try {
                $this->upsertMerchantCustomer($merchantUserId, $data);
                $summary['imported']++;
            } catch (\Throwable $exception) {
                $summary['skipped']++;
                $summary['errors'][] = $this->buildRowError(
                    $line,
                    $exception->getMessage() ?: 'שגיאה בעת שמירת הלקוח'
                );
            }
        }

        return $this->successResponse($summary, 'Customers imported successfully');
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

    protected function upsertMerchantCustomer(int $merchantUserId, array $customerData): MerchantCustomer
    {
        $name = trim((string) ($customerData['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Customer name is required');
        }

        $email = isset($customerData['email']) ? strtolower(trim((string) $customerData['email'])) : null;
        $email = $email === '' ? null : $email;

        $phone = $this->normalizePhone($customerData['phone'] ?? null);

        if (!$email && !$phone) {
            throw new \InvalidArgumentException('Customer phone or email is required');
        }

        $query = MerchantCustomer::where('merchant_user_id', $merchantUserId);

        if ($email && $phone) {
            $query->where('email', $email)->where('phone', $phone);
        } elseif ($email) {
            $query->where('email', $email);
        } elseif ($phone) {
            $query->where('phone', $phone);
        }

        $payload = [
            'merchant_user_id' => $merchantUserId,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'notes' => $customerData['notes'] ?? null,
            'address' => $this->normalizeAddressPayload($customerData['address'] ?? null),
        ];

        $existingCustomer = $query->first();

        if ($existingCustomer) {
            $updates = [];

            foreach (['name', 'email', 'phone'] as $attribute) {
                $value = $payload[$attribute];
                if ($value !== null && $existingCustomer->{$attribute} !== $value) {
                    $updates[$attribute] = $value;
                }
            }

            if (isset($payload['notes']) && $payload['notes'] !== null && $existingCustomer->notes !== $payload['notes']) {
                $updates['notes'] = $payload['notes'];
            }

            if ($payload['address'] !== null && $existingCustomer->address != $payload['address']) {
                $updates['address'] = $payload['address'];
            }

            if (!empty($updates)) {
                $existingCustomer->fill($updates)->save();
            }

            return $existingCustomer->refresh();
        }

        return MerchantCustomer::create($payload);
    }

    protected function extractCustomerRowsFromFile(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $path = $file->getRealPath();

        if (!$path) {
            throw new \RuntimeException('Unable to read the uploaded file');
        }

        if (in_array($extension, ['csv', 'txt'])) {
            return $this->parseCsvFile($path);
        }

        return $this->parseSpreadsheetFile($path);
    }

    protected function parseCsvFile(string $path): array
    {
        $handle = fopen($path, 'r');

        if (!$handle) {
            throw new \RuntimeException('Unable to open the uploaded file');
        }

        $rows = [];
        $headerMap = null;
        $lineNumber = 0;

        while (($row = fgetcsv($handle, 0, ',')) !== false) {
            $lineNumber++;

            if ($lineNumber === 1) {
                $headerMap = $this->mapHeaders($row);
                continue;
            }

            if (!$headerMap) {
                continue;
            }

            $mapped = $this->mapRowToCustomer($headerMap, $row);

            if ($this->rowIsEmpty($mapped)) {
                continue;
            }

            $rows[] = [
                '_line' => $lineNumber,
                'data' => $mapped,
            ];
        }

        fclose($handle);

        return $rows;
    }

    protected function parseSpreadsheetFile(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();

        $rows = [];
        $headerMap = null;
        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = $sheet->getHighestDataColumn();

        for ($rowIndex = 1; $rowIndex <= $highestRow; $rowIndex++) {
            $row = $sheet->rangeToArray("A{$rowIndex}:{$highestColumn}{$rowIndex}", null, true, false)[0] ?? [];

            if ($rowIndex === 1) {
                $headerMap = $this->mapHeaders($row);
                continue;
            }

            if (!$headerMap) {
                continue;
            }

            $mapped = $this->mapRowToCustomer($headerMap, $row);

            if ($this->rowIsEmpty($mapped)) {
                continue;
            }

            $rows[] = [
                '_line' => $rowIndex,
                'data' => $mapped,
            ];
        }

        return $rows;
    }

    protected function mapHeaders(array $rawHeaders): array
    {
        $mapped = [];

        foreach ($rawHeaders as $header) {
            $mapped[] = $this->normalizeHeaderKey($header);
        }

        return $mapped;
    }

    protected function mapRowToCustomer(array $headerMap, array $row): array
    {
        $data = [
            'name' => null,
            'email' => null,
            'phone' => null,
            'notes' => null,
            'address' => [],
        ];

        foreach ($row as $index => $value) {
            $key = $headerMap[$index] ?? null;

            if (!$key) {
                continue;
            }

            $stringValue = is_string($value) ? trim($value) : $value;
            if ($stringValue === '' || $stringValue === null) {
                continue;
            }

            if (Str::startsWith($key, 'address.')) {
                $addressKey = Str::after($key, 'address.');
                $data['address'][$addressKey] = $stringValue;
                continue;
            }

            $data[$key] = $stringValue;
        }

        if (empty($data['address'])) {
            unset($data['address']);
        }

        return $data;
    }

    protected function normalizeHeaderKey(?string $header): ?string
    {
        if ($header === null) {
            return null;
        }

        $normalized = strtolower(trim((string) $header));
        $normalized = str_replace([' ', '-', '.'], '_', $normalized);

        $map = [
            'name' => 'name',
            'full_name' => 'name',
            'customer_name' => 'name',
            'email' => 'email',
            'mail' => 'email',
            'phone' => 'phone',
            'phone_number' => 'phone',
            'mobile' => 'phone',
            'notes' => 'notes',
            'note' => 'notes',
            'comments' => 'notes',
            'address' => 'address.line1',
            'address_line1' => 'address.line1',
            'line1' => 'address.line1',
            'street' => 'address.line1',
            'address_line2' => 'address.line2',
            'line2' => 'address.line2',
            'city' => 'address.city',
            'town' => 'address.city',
            'state' => 'address.state',
            'region' => 'address.state',
            'zip' => 'address.zip',
            'postal_code' => 'address.zip',
            'postcode' => 'address.zip',
            'country' => 'address.country',
        ];

        return $map[$normalized] ?? null;
    }

    protected function rowIsEmpty(array $row): bool
    {
        $hasPrimaryField = collect(Arr::only($row, ['name', 'email', 'phone', 'notes']))
            ->filter(fn ($value) => filled($value))
            ->isNotEmpty();

        if ($hasPrimaryField) {
            return false;
        }

        if (!empty($row['address']) && is_array($row['address'])) {
            return !collect($row['address'])->contains(fn ($value) => filled($value));
        }

        return true;
    }

    protected function normalizeAddressPayload($address): ?array
    {
        if (!is_array($address)) {
            return null;
        }

        $fields = ['line1', 'line2', 'city', 'state', 'zip', 'country'];
        $normalized = [];

        foreach ($fields as $field) {
            if (!array_key_exists($field, $address)) {
                continue;
            }

            $value = trim((string) $address[$field]);
            if ($value !== '') {
                $normalized[$field] = $value;
            }
        }

        return empty($normalized) ? null : $normalized;
    }

    protected function buildRowError(?int $line, string $message): array
    {
        return [
            'line' => $line,
            'message' => $message,
        ];
    }

    protected function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);

        return $digits !== '' ? $digits : null;
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
