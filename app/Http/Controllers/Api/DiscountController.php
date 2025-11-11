<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Discount;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DiscountController extends Controller
{
    use ApiResponse;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Discount::with([
            'product:id,name',
            'category:id,name',
            'targetMerchant:id,name',
            'targetMerchantProfile:id,user_id,business_name',
        ]);

        if ($user->isMerchant()) {
            $query->where(function ($q) use ($user) {
                $q->where('created_by', $user->id)
                    ->orWhereNull('target_merchant_id')
                    ->orWhere('target_merchant_id', $user->id);
            });
        } elseif ($request->filled('created_by')) {
            $query->where('created_by', $request->integer('created_by'));
        }

        if ($request->filled('type')) {
            $types = array_filter(array_map('trim', explode(',', $request->input('type'))));
            $types = array_intersect($types, [
                Discount::TYPE_QUANTITY,
                Discount::TYPE_STOREWIDE,
                Discount::TYPE_MERCHANT,
            ]);

            if (!empty($types)) {
                $query->whereIn('type', $types);
            }
        }

        if ($request->filled('scope')) {
            $scopes = array_filter(array_map('trim', explode(',', $request->input('scope'))));
            $scopes = array_intersect($scopes, [
                Discount::SCOPE_STORE,
                Discount::SCOPE_CATEGORY,
                Discount::SCOPE_PRODUCT,
            ]);

            if (!empty($scopes)) {
                $query->whereIn('apply_scope', $scopes);
            }
        }

        if ($request->filled('status')) {
            $statuses = array_filter(array_map('trim', explode(',', $request->input('status'))));
            $statuses = array_intersect($statuses, [
                Discount::STATUS_SCHEDULED,
                Discount::STATUS_ACTIVE,
                Discount::STATUS_EXPIRED,
            ]);

            if (!empty($statuses)) {
                $query->where(function ($q) use ($statuses) {
                    foreach ($statuses as $status) {
                        $q->orWhere(function ($inner) use ($status) {
                            $today = now()->toDateString();

                            if ($status === Discount::STATUS_ACTIVE) {
                                $inner->whereDate('start_date', '<=', $today)
                                    ->whereDate('end_date', '>=', $today);
                            } elseif ($status === Discount::STATUS_SCHEDULED) {
                                $inner->whereDate('start_date', '>', $today);
                            } elseif ($status === Discount::STATUS_EXPIRED) {
                                $inner->whereDate('end_date', '<', $today);
                            }
                        });
                    }
                });
            }
        }

        if ($request->filled('target_merchant_id')) {
            $query->where('target_merchant_id', $request->integer('target_merchant_id'));
        }

        if ($request->filled('starts_after')) {
            $query->whereDate('start_date', '>=', $request->input('starts_after'));
        }

        if ($request->filled('ends_before')) {
            $query->whereDate('end_date', '<=', $request->input('ends_before'));
        }

        $perPage = (int) $request->input('per_page', 15);
        $perPage = max(1, min(100, $perPage));

        $discounts = $query
            ->orderByDesc('start_date')
            ->orderBy('name')
            ->paginate($perPage);

        $discounts->getCollection()->transform(function (Discount $discount) {
            $discount->refreshStatus();
            return $discount;
        });

        return $this->successResponse($discounts);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $this->validateDiscount($request);

        $discount = new Discount($validated);
        $discount->created_by = $request->user()->id;
        $discount->status = $discount->computeStatus();
        $discount->save();
        $discount->load([
            'product:id,name',
            'category:id,name',
            'targetMerchant:id,name',
            'targetMerchantProfile:id,user_id,business_name',
        ]);

        return $this->createdResponse($discount, 'Discount created successfully');
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Discount $discount)
    {
        if (!$this->canAccess($request->user(), $discount)) {
            return $this->forbiddenResponse('אין לך הרשאה לצפות במבצע הזה');
        }

        $discount->refreshStatus();
        $discount->load([
            'product:id,name',
            'category:id,name',
            'targetMerchant:id,name',
            'targetMerchantProfile:id,user_id,business_name',
        ]);

        return $this->successResponse($discount);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Discount $discount)
    {
        if (!$this->canAccess($request->user(), $discount)) {
            return $this->forbiddenResponse('אין לך הרשאה לערוך את המבצע הזה');
        }

        $validated = $this->validateDiscount($request, $discount);

        $discount->fill($validated);
        $discount->status = $discount->computeStatus();
        $discount->save();
        $discount->refreshStatus();

        $discount->load([
            'product:id,name',
            'category:id,name',
            'targetMerchant:id,name',
            'targetMerchantProfile:id,user_id,business_name',
        ]);

        return $this->successResponse($discount, 'Discount updated successfully');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Discount $discount)
    {
        if (!$this->canAccess($request->user(), $discount)) {
            return $this->forbiddenResponse('אין לך הרשאה למחוק את המבצע הזה');
        }

        $discount->delete();

        return $this->successResponse(null, 'Discount deleted successfully');
    }

    protected function validateDiscount(Request $request, ?Discount $discount = null): array
    {
        $type = $request->input('type', $discount?->type);

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'type' => [$discount ? 'sometimes' : 'required', Rule::in([
                Discount::TYPE_QUANTITY,
                Discount::TYPE_STOREWIDE,
                Discount::TYPE_MERCHANT,
            ])],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'buy_quantity' => ['nullable', 'integer', 'min:1'],
            'get_quantity' => ['nullable', 'integer', 'min:1'],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')],
            'discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'apply_scope' => ['nullable', Rule::in([
                Discount::SCOPE_STORE,
                Discount::SCOPE_CATEGORY,
                Discount::SCOPE_PRODUCT,
            ])],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'target_merchant_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where(function ($query) {
                $query->where('role', 'merchant');
            })],
        ]);

        $validator->after(function ($validator) use ($request, $type, $discount) {
            if (!$type) {
                $validator->errors()->add('type', 'Discount type is required.');
                return;
            }

            $scope = $request->input('apply_scope', $discount?->apply_scope);

            if ($type === Discount::TYPE_QUANTITY) {
                if (!$request->filled('product_id') && !$discount?->product_id) {
                    $validator->errors()->add('product_id', 'Product is required for quantity discounts.');
                }
                if (!$request->filled('buy_quantity') && !$discount?->buy_quantity) {
                    $validator->errors()->add('buy_quantity', 'Buy quantity is required for quantity discounts.');
                }
                if (!$request->filled('get_quantity') && !$discount?->get_quantity) {
                    $validator->errors()->add('get_quantity', 'Get quantity is required for quantity discounts.');
                }
            }

            if (in_array($type, [Discount::TYPE_STOREWIDE, Discount::TYPE_MERCHANT], true)) {
                if (!$request->filled('discount_percentage') && !$discount?->discount_percentage) {
                    $validator->errors()->add('discount_percentage', 'Percentage discount is required.');
                }

                if (!$scope) {
                    $validator->errors()->add('apply_scope', 'Scope is required for this discount type.');
                } elseif ($scope === Discount::SCOPE_CATEGORY) {
                    if (!$request->filled('category_id') && !$discount?->category_id) {
                        $validator->errors()->add('category_id', 'Category is required when scope is category.');
                    }
                } elseif ($scope === Discount::SCOPE_PRODUCT) {
                    if (!$request->filled('product_id') && !$discount?->product_id) {
                        $validator->errors()->add('product_id', 'Product is required when scope is product.');
                    }
                }

                if ($type === Discount::TYPE_MERCHANT) {
                    if (!$request->filled('target_merchant_id') && !$discount?->target_merchant_id) {
                        $validator->errors()->add('target_merchant_id', 'Target merchant is required for merchant discounts.');
                    }
                }
            }
        });

        $data = $validator->validate();
        $data['type'] = $type;

        if ($type === Discount::TYPE_QUANTITY) {
            $data['apply_scope'] = null;
            $data['discount_percentage'] = null;
            $data['category_id'] = null;
            $data['target_merchant_id'] = null;
        } else {
            $data['buy_quantity'] = null;
            $data['get_quantity'] = null;

            if (($data['apply_scope'] ?? null) !== Discount::SCOPE_CATEGORY) {
                $data['category_id'] = null;
            }

            if (($data['apply_scope'] ?? null) !== Discount::SCOPE_PRODUCT) {
                $data['product_id'] = null;
            }

            if ($type === Discount::TYPE_STOREWIDE) {
                $data['target_merchant_id'] = null;
            }
        }

        if (array_key_exists('discount_percentage', $data) && $data['discount_percentage'] !== null) {
            $data['discount_percentage'] = round((float) $data['discount_percentage'], 2);
        }

        foreach (['buy_quantity', 'get_quantity', 'product_id', 'category_id', 'target_merchant_id'] as $numericField) {
            if (array_key_exists($numericField, $data) && $data[$numericField] !== null) {
                $data[$numericField] = (int) $data[$numericField];
            }
        }

        return $data;
    }

    protected function canAccess($user, Discount $discount): bool
    {
        if (!$user) {
            return false;
        }

        if (method_exists($user, 'hasRole') && $user->hasRole('admin')) {
            return true;
        }

        return $discount->created_by === $user->id;
    }
}
