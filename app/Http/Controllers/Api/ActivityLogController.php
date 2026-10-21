<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * GET /api/activity?user_id=&subject_type=vehicle&subject_id=&from=2026-10-01&to=2026-10-31&per_page=
 * Owner only, newest first.
 */
class ActivityLogController extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', ActivityLog::class);

        $filters = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'subject_type' => ['nullable', 'string', 'max:50'],
            'subject_id' => ['nullable', 'integer', 'required_with:subject_type'],
            'action' => ['nullable', 'string', 'max:50'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        // "from" / "to" are days in the agency's timezone; created_at is stored in UTC.
        $timezone = $request->user()->agency->timezone;

        $logs = ActivityLog::query()
            ->with('user')
            ->when($filters['user_id'] ?? null, fn ($query, $userId) => $query->where('user_id', $userId))
            ->when($filters['subject_type'] ?? null, fn ($query, $type) => $query
                ->where('subject_type', $type)
                ->where('subject_id', $filters['subject_id']))
            ->when($filters['action'] ?? null, fn ($query, $action) => $query->where('action', $action))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query
                ->where('created_at', '>=', Carbon::parse($from, $timezone)->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query
                ->where('created_at', '<', Carbon::parse($to, $timezone)->addDay()->startOfDay()->utc()))
            ->latest('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return ActivityLogResource::collection($logs);
    }
}
