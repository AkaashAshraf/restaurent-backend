<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\BranchHour;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * Opening hours of a branch, in the restaurant's timezone.
 *
 * A branch has its normal (GENERAL) hours and may carry separate hours for
 * DINE_IN, TAKEAWAY and DELIVERY. A service without its own hours follows the
 * general hours; one with its own hours is open only when both the branch and
 * the service window allow it (delivery 14:00–23:00 inside a 12:00–00:30 day
 * is open 14:00–23:00; a takeaway window starting before the branch opens
 * still waits for the branch to open).
 *
 * One window per day (an overnight window such as 12:00–02:00 runs into the
 * next morning). A branch with no general hours at all has not set them up
 * yet and is treated as always open.
 */
class BranchHoursService
{
    public const GENERAL = 'GENERAL';

    public const SERVICES = ['GENERAL', 'DINE_IN', 'TAKEAWAY', 'DELIVERY'];

    /** @return array<int,array{day_of_week:int,is_closed:bool,is_24_hours:bool,open_time:?string,close_time:?string}> 7 entries, Sunday first */
    public function days(Branch $branch, string $service): array
    {
        $rows = BranchHour::where('branch_id', $branch->id)->where('service', $service)->get()->keyBy('day_of_week');

        $days = [];
        foreach (range(0, 6) as $dow) {
            $row = $rows->get($dow);
            $days[] = [
                'day_of_week' => $dow,
                'is_closed' => $row ? (bool) $row->is_closed : true,
                'is_24_hours' => $row ? (bool) $row->is_24_hours : false,
                'open_time' => $row && $row->open_time ? substr($row->open_time, 0, 5) : null,
                'close_time' => $row && $row->close_time ? substr($row->close_time, 0, 5) : null,
            ];
        }

        return $days;
    }

    public function hasHours(Branch $branch, string $service): bool
    {
        return BranchHour::where('branch_id', $branch->id)->where('service', $service)->exists();
    }

    /**
     * Everything a client needs: per service its days, whether it has its own
     * hours (`custom`), and whether it is open right now.
     *
     * @param  string[]  $services  which services to include (GENERAL always is)
     */
    public function describe(Branch $branch, string $timezone, ?CarbonInterface $now = null, array $services = self::SERVICES): array
    {
        $now = $this->now($now, $timezone);
        $generalConfigured = $this->hasHours($branch, self::GENERAL);
        $generalDays = $this->days($branch, self::GENERAL);

        $out = [];
        foreach (self::SERVICES as $service) {
            if ($service !== self::GENERAL && ! in_array($service, $services, true)) {
                continue;
            }

            $custom = $service !== self::GENERAL && $this->hasHours($branch, $service);
            $out[$service] = [
                'custom' => $custom,
                'days' => $custom ? $this->days($branch, $service) : $generalDays,
                'status' => $this->status($branch, $service, $timezone, $now),
            ];
        }

        return [
            'timezone' => $timezone,
            'hours_configured' => $generalConfigured,
            'services' => $out,
        ];
    }

    /**
     * @return array{is_open:bool,opens_at:?string,closes_at:?string}
     */
    public function status(Branch $branch, string $service, string $timezone, ?CarbonInterface $now = null): array
    {
        $now = $this->now($now, $timezone);
        $intervals = $this->effectiveIntervals($branch, $service, $now);

        // null = no limits configured at all.
        if ($intervals === null) {
            return ['is_open' => true, 'opens_at' => null, 'closes_at' => null];
        }

        $ts = $now->getTimestamp();
        foreach ($intervals as [$start, $end]) {
            if ($start <= $ts && $ts < $end) {
                return ['is_open' => true, 'opens_at' => null, 'closes_at' => $this->iso($end, $timezone)];
            }
        }
        foreach ($intervals as [$start]) {
            if ($start > $ts) {
                return ['is_open' => false, 'opens_at' => $this->iso($start, $timezone), 'closes_at' => null];
            }
        }

        return ['is_open' => false, 'opens_at' => null, 'closes_at' => null];
    }

    public function isOpen(Branch $branch, string $service, string $timezone, ?CarbonInterface $now = null): bool
    {
        return $this->status($branch, $service, $timezone, $now)['is_open'];
    }

    /**
     * Replace hours. Keys present in $schedule are replaced: an array of seven
     * day entries sets that service's hours, null (not for GENERAL) removes the
     * service's own hours so it follows the general ones. Keys left out are
     * untouched.
     *
     * @param  array<string,?array<int,array>>  $schedule
     */
    public function replace(Branch $branch, array $schedule): void
    {
        DB::transaction(function () use ($branch, $schedule) {
            foreach ($schedule as $service => $days) {
                BranchHour::where('branch_id', $branch->id)->where('service', $service)->delete();

                if ($days === null) {
                    continue;
                }

                foreach ($days as $day) {
                    $closed = (bool) ($day['is_closed'] ?? false);
                    $all = ! $closed && (bool) ($day['is_24_hours'] ?? false);
                    $timed = ! $closed && ! $all;

                    BranchHour::create([
                        'branch_id' => $branch->id,
                        'restaurant_id' => $branch->restaurant_id,
                        'service' => $service,
                        'day_of_week' => (int) $day['day_of_week'],
                        'is_closed' => $closed,
                        'is_24_hours' => $all,
                        'open_time' => $timed ? $day['open_time'] : null,
                        'close_time' => $timed ? $day['close_time'] : null,
                    ]);
                }
            }
        });
    }

    // ------------------------------------------------------------------

    /** @return array<int,array{0:int,1:int}>|null null = unrestricted */
    private function effectiveIntervals(Branch $branch, string $service, CarbonImmutable $now): ?array
    {
        $general = $this->hasHours($branch, self::GENERAL) ? $this->intervals($branch, self::GENERAL, $now) : null;

        if ($service === self::GENERAL || ! $this->hasHours($branch, $service)) {
            return $general;
        }

        $own = $this->intervals($branch, $service, $now);

        return $general === null ? $own : $this->intersect($general, $own);
    }

    /** Open windows from yesterday through the next week, as unix timestamps, merged and sorted. */
    private function intervals(Branch $branch, string $service, CarbonImmutable $now): array
    {
        $days = collect($this->days($branch, $service))->keyBy('day_of_week');
        $today = $now->startOfDay();

        $list = [];
        for ($offset = -1; $offset <= 7; $offset++) {
            $date = $today->addDays($offset);
            $day = $days->get($date->dayOfWeek);
            if (! $day || $day['is_closed']) {
                continue;
            }

            if ($day['is_24_hours']) {
                $list[] = [$date->getTimestamp(), $date->addDay()->getTimestamp()];

                continue;
            }
            if (! $day['open_time'] || ! $day['close_time']) {
                continue;
            }

            $start = $date->setTimeFromTimeString($day['open_time']);
            $end = $date->setTimeFromTimeString($day['close_time']);
            if ($end <= $start) {
                $end = $end->addDay();   // runs past midnight
            }
            $list[] = [$start->getTimestamp(), $end->getTimestamp()];
        }

        return $this->merge($list);
    }

    private function merge(array $list): array
    {
        usort($list, fn ($a, $b) => $a[0] <=> $b[0]);

        $merged = [];
        foreach ($list as [$start, $end]) {
            if ($merged && $start <= $merged[count($merged) - 1][1]) {
                $merged[count($merged) - 1][1] = max($merged[count($merged) - 1][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        return $merged;
    }

    private function intersect(array $a, array $b): array
    {
        $out = [];
        $i = $j = 0;
        while ($i < count($a) && $j < count($b)) {
            $start = max($a[$i][0], $b[$j][0]);
            $end = min($a[$i][1], $b[$j][1]);
            if ($start < $end) {
                $out[] = [$start, $end];
            }
            $a[$i][1] < $b[$j][1] ? $i++ : $j++;
        }

        return $out;
    }

    private function now(?CarbonInterface $now, string $timezone): CarbonImmutable
    {
        $tz = new DateTimeZone($timezone ?: 'UTC');

        return ($now ? CarbonImmutable::instance($now) : CarbonImmutable::now())->setTimezone($tz);
    }

    private function iso(int $timestamp, string $timezone): string
    {
        return CarbonImmutable::createFromTimestamp($timestamp, $timezone ?: 'UTC')->toIso8601String();
    }
}
