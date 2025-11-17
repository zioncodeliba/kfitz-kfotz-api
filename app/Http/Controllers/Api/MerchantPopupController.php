<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MerchantPopup;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MerchantPopupController extends Controller
{
    use ApiResponse;

    public function show(Request $request)
    {
        $popup = $this->scopedQuery($request)->first();

        return $this->successResponse($popup, 'Popup settings fetched successfully');
    }

    public function update(Request $request)
    {
        $data = $this->validatePayload($request);
        $popup = $this->scopedQuery($request)->first();

        if ($popup) {
            $popup->update($data);
        } else {
            $popup = MerchantPopup::create($data);
        }

        return $this->successResponse($popup->fresh(), 'Popup settings saved successfully');
    }

    public function active(Request $request)
    {
        $popup = $this->scopedQuery($request)
            ->where('is_active', true)
            ->orderByDesc('updated_at')
            ->first();

        if (!$popup || !$this->isWithinSchedule($popup)) {
            return $this->successResponse(null, 'No popup configured');
        }

        if ($popup->display_once === false) {
            $popup->forceFill(['display_once' => true])->save();
            $popup->refresh();
        }

        return $this->successResponse($popup, 'Popup loaded successfully');
    }

    protected function validatePayload(Request $request): array
    {
        $rules = [
            'merchant_id' => ['nullable', 'integer', 'exists:merchants,id'],
            'is_active' => ['required', 'boolean'],
            'display_once' => ['sometimes', 'boolean'],
            'title' => ['nullable', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'uploaded_image' => ['nullable', 'string'],
            'button_text' => ['nullable', 'string', 'max:100'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
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

        if (!array_key_exists('button_text', $validated) || $validated['button_text'] === null || $validated['button_text'] === '') {
            $validated['button_text'] = 'הבנתי';
        }

        if (!array_key_exists('display_once', $validated)) {
            $validated['display_once'] = true;
        }

        return $validated;
    }

    protected function scopedQuery(Request $request)
    {
        return MerchantPopup::query()
            ->when(
                $request->filled('merchant_id'),
                fn ($query) => $query->where('merchant_id', $request->integer('merchant_id')),
                fn ($query) => $query->whereNull('merchant_id')
            )
            ->orderByDesc('id');
    }

    protected function isWithinSchedule(MerchantPopup $popup): bool
    {
        $now = Carbon::now();
        $startValid = $popup->start_date ? $popup->start_date->lte($now) : true;
        $endValid = $popup->end_date ? $popup->end_date->gte($now) : true;

        return $startValid && $endValid;
    }
}
