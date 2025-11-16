<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MerchantBanner;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class MerchantBannerController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $query = MerchantBanner::query()->orderBy('display_order')->orderByDesc('created_at');

        if ($request->filled('merchant_id')) {
            $query->where('merchant_id', $request->integer('merchant_id'));
        }

        return $this->successResponse($query->get());
    }

    public function active(Request $request)
    {
        $query = MerchantBanner::query()
            ->where('is_enabled', true)
            ->orderBy('display_order')
            ->orderByDesc('created_at');

        if ($request->filled('merchant_id')) {
            $query->where('merchant_id', $request->integer('merchant_id'));
        }

        $now = Carbon::now();
        Log::info('MerchantBannerController@active: loading banners', [
            'count_before_filter' => $query->count(),
            'requested_merchant_id' => $request->get('merchant_id')
        ]);
        $banners = $query->get()->filter(function (MerchantBanner $banner) use ($now) {
            $parsedStart = $this->parseBannerDate($banner->start_date);
            $parsedEnd = $this->parseBannerDate($banner->end_date);

            Log::info('MerchantBannerController@active: evaluating banner', [
                'id' => $banner->id,
                'start_raw' => $banner->start_date,
                'end_raw' => $banner->end_date,
                'parsed_start' => $parsedStart ? $parsedStart->toDateTimeString() : null,
                'parsed_end' => $parsedEnd ? $parsedEnd->toDateTimeString() : null,
                'now' => $now->toDateTimeString()
            ]);

            $startValid = $parsedStart ? $parsedStart->lte($now) : true;
            $endValid = $parsedEnd ? $parsedEnd->gte($now) : true;

            return $startValid && $endValid;
        })->values();

        Log::info('MerchantBannerController@active: response payload', [
            'count' => $banners->count(),
            'ids' => $banners->pluck('id')
        ]);

        return $this->successResponse($banners);
    }

    public function store(Request $request)
    {
        $data = $this->validatePayload($request);
        $banner = MerchantBanner::create($data);

        return $this->createdResponse($banner->fresh(), 'Banner created successfully');
    }

    public function update(Request $request, MerchantBanner $merchantBanner)
    {
        $data = $this->validatePayload($request, true);
        $merchantBanner->update($data);

        return $this->successResponse($merchantBanner->fresh(), 'Banner updated successfully');
    }

    public function destroy(MerchantBanner $merchantBanner)
    {
        $merchantBanner->delete();

        return $this->successResponse(null, 'Banner deleted successfully');
    }

    protected function validatePayload(Request $request, bool $isUpdate = false): array
    {
        $rules = [
            'merchant_id' => ['nullable', 'integer', 'exists:merchants,id'],
            'text' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'link' => ['nullable', 'string', 'max:500'],
            'image_url' => ['nullable', 'string'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'is_enabled' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'integer'],
        ];

        $validated = $request->validate($rules);

        if (
            isset($validated['start_date'], $validated['end_date']) &&
            strtotime((string) $validated['end_date']) < strtotime((string) $validated['start_date'])
        ) {
            throw ValidationException::withMessages([
                'end_date' => ['End date must be greater than or equal to start date'],
            ]);
        }

        return $validated;
    }

    protected function parseBannerDate($value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        $candidates = [
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'H:i:s Y-m-d',
            'H:i Y-m-d',
            'd/m/Y H:i:s',
            'd/m/Y H:i',
        ];

        foreach ($candidates as $format) {
            try {
                return Carbon::createFromFormat($format, $value, config('app.timezone'));
            } catch (\Throwable $e) {
                // continue
            }
        }

        try {
            return Carbon::parse($value, config('app.timezone'));
        } catch (\Throwable $e) {
            Log::warning('Failed to parse banner date', [
                'value' => $value,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
