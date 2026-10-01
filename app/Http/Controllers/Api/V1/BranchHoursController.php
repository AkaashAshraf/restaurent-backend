<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Services\BranchHoursService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * A branch's opening hours: the general hours plus optional separate hours for
 * dine-in, takeaway and delivery. See BranchHoursService for how they combine.
 */
class BranchHoursController extends Controller
{
    public function __construct(private BranchHoursService $hours)
    {
    }

    public function show(Request $request, int $branch)
    {
        $branch = Branch::findOrFail($branch);

        return ApiResponse::success($this->payload($request, $branch));
    }

    /**
     * Body: { "GENERAL": [7 days], "DINE_IN": [7 days] | null, "TAKEAWAY": ..., "DELIVERY": ... }.
     * Keys left out stay as they are; null removes that service's own hours
     * (it then follows the general hours). A day is
     * { day_of_week 0=Sunday..6, is_closed, is_24_hours, open_time "HH:MM", close_time "HH:MM" };
     * a close time earlier than the open time means "after midnight".
     */
    public function update(Request $request, int $branch)
    {
        $branch = Branch::findOrFail($branch);

        $rules = [];
        foreach (BranchHoursService::SERVICES as $service) {
            $rules[$service] = $service === BranchHoursService::GENERAL
                ? ['sometimes', 'required', 'array', 'size:7']
                : ['sometimes', 'nullable', 'array', 'size:7'];
            $rules["$service.*.day_of_week"] = ['required', 'integer', 'between:0,6'];
            $rules["$service.*.is_closed"] = ['sometimes', 'boolean'];
            $rules["$service.*.is_24_hours"] = ['sometimes', 'boolean'];
            $rules["$service.*.open_time"] = ['nullable', 'date_format:H:i'];
            $rules["$service.*.close_time"] = ['nullable', 'date_format:H:i'];
        }
        $data = $request->validate($rules);

        $errors = [];
        foreach ($data as $service => $days) {
            if ($days === null) {
                continue;
            }

            if (count(array_unique(array_column($days, 'day_of_week'))) !== 7) {
                $errors[$service] = ['Give each day of the week exactly once.'];

                continue;
            }

            foreach ($days as $i => $day) {
                $needsTimes = ! ($day['is_closed'] ?? false) && ! ($day['is_24_hours'] ?? false);
                if (! $needsTimes) {
                    continue;
                }
                if (empty($day['open_time']) || empty($day['close_time'])) {
                    $errors["$service.$i.open_time"] = ['Opening and closing time are required for an open day.'];
                } elseif ($day['open_time'] === $day['close_time']) {
                    $errors["$service.$i.close_time"] = ['Closing time must differ from the opening time (use "open 24 hours" instead).'];
                }
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $before = $this->describe($request, $branch)['services'];
        $this->hours->replace($branch, $data);

        AuditLog::create([
            'restaurant_id' => $branch->restaurant_id,
            'branch_id' => $branch->id,
            'user_id' => $request->user()->id,
            'action' => 'branch.hours_updated',
            'subject_type' => Branch::class,
            'subject_id' => $branch->id,
            'changes' => ['old' => array_map(fn ($s) => $s['days'], $before), 'new' => $data],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success($this->payload($request, $branch));
    }

    private function describe(Request $request, Branch $branch): array
    {
        return $this->hours->describe($branch, $request->user()->restaurant->timezone ?: 'UTC');
    }

    private function payload(Request $request, Branch $branch): array
    {
        return ['branch_id' => $branch->id] + $this->describe($request, $branch);
    }
}
