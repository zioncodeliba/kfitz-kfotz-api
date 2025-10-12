<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemAlert;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class SystemAlertController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $user = $request->user();

        if (!$user->hasRole('admin')) {
            return $this->errorResponse('Unauthorized', 403);
        }

        $limit = (int) $request->query('limit', 5);
        $limit = $limit > 0 ? min($limit, 50) : 5;

        $audience = $request->query('audience');
        if (!$audience) {
            $audience = 'admin';
        }

        $includeArchived = $request->boolean('include_archived', false);
        $severities = array_filter(Arr::wrap($request->query('severity', [])));
        $categories = array_filter(Arr::wrap($request->query('category', [])));

        $query = SystemAlert::query()->forAudience($audience);

        if (!$includeArchived) {
            $query->active();
        }

        if (!empty($severities)) {
            $query->whereIn('severity', $severities);
        }

        if (!empty($categories)) {
            $query->whereIn('category', $categories);
        }

        $alerts = $query->mostRecent()->limit($limit)->get();

        $data = $alerts->map(function (SystemAlert $alert) {
            $timestamp = $alert->published_at ?? $alert->created_at;

            return [
                'id' => $alert->id,
                'title' => $alert->title,
                'message' => $alert->message,
                'severity' => $alert->severity,
                'category' => $alert->category,
                'icon' => $alert->icon,
                'action_label' => $alert->action_label,
                'action_url' => $alert->action_url,
                'status' => $alert->status,
                'is_sticky' => $alert->is_sticky,
                'is_dismissible' => $alert->is_dismissible,
                'published_at' => optional($alert->published_at)->toIso8601String(),
                'expires_at' => optional($alert->expires_at)->toIso8601String(),
                'relative_time' => $timestamp ? $timestamp->diffForHumans() : null,
                'metadata' => $alert->metadata,
            ];
        })->values();

        $meta = [
            'count' => $data->count(),
            'limit' => $limit,
            'audience' => $audience,
            'include_archived' => $includeArchived,
            'total_active' => SystemAlert::query()->forAudience($audience)->active()->count(),
        ];

        return $this->successResponse([
            'alerts' => $data,
            'meta' => $meta,
        ]);
    }
}
