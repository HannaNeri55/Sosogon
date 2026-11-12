<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/** How many times a public page was opened on a day (see PageViewController). */
class PageView extends Model
{
    protected $fillable = ['date', 'path', 'views'];

    /** Today's date on Sorsogon's calendar, which page views are grouped by. */
    public static function today(): string
    {
        return now(config('app.local_timezone'))->toDateString();
    }

    /**
     * Page views per day for the $days days ending today, oldest first, with days that had none
     * as 0: [['date' => 'Y-m-d', 'views' => int], …].
     */
    public static function daily(int $days): Collection
    {
        $today = now(config('app.local_timezone'))->startOfDay();
        $dates = collect(range($days - 1, 0))->map(fn ($back) => $today->copy()->subDays($back)->toDateString());
        $counts = static::query()
            ->whereBetween('date', [$dates->first(), $dates->last()])
            ->selectRaw('date, SUM(views) as total')
            ->groupBy('date')
            ->pluck('total', 'date')
            ->mapWithKeys(fn ($total, $date) => [substr((string) $date, 0, 10) => (int) $total]);

        return $dates->map(fn ($date) => ['date' => $date, 'views' => $counts[$date] ?? 0])->values();
    }

    /** Total page views from $from (a Y-m-d date) up to today. */
    public static function totalSince(string $from): int
    {
        return (int) static::where('date', '>=', $from)->sum('views');
    }
}
