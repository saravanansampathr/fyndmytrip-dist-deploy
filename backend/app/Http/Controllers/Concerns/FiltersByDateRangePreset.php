<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * One shared "date range" filter vocabulary for every list/report screen in
 * the admin dashboard — "filters need... today, week, month quarterly,
 * half-yearly, annually and custom date" (per the backlog request), instead
 * of each controller inventing its own from/to handling. A `range` query
 * param picks a preset; `range=custom` (or an explicit from/to with no
 * range at all, for older callers) uses the given from/to verbatim.
 */
trait FiltersByDateRangePreset
{
    public const RANGE_PRESETS = ['today', 'week', 'month', 'quarter', 'half_year', 'year', 'custom'];

    /**
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    protected function resolveDateRangePreset(Request $request, string $default = 'month'): array
    {
        $preset = $request->filled('range') ? $request->string('range')->toString() : null;

        if ($preset === null) {
            // Back-compat: a caller that only ever sent from/to (no concept
            // of presets yet) is treated as an explicit custom range rather
            // than snapping to a preset it never asked for.
            $preset = $request->filled('from') || $request->filled('to') ? 'custom' : $default;
        }

        if (! in_array($preset, self::RANGE_PRESETS, true)) {
            $preset = $default;
        }

        $now = Carbon::now();

        if ($preset === 'custom') {
            $to = $request->filled('to') ? Carbon::parse($request->string('to'))->endOfDay() : $now->copy()->endOfDay();
            $from = $request->filled('from') ? Carbon::parse($request->string('from'))->startOfDay() : $to->copy()->subDays(29)->startOfDay();

            return [$from, $to, $preset];
        }

        $to = $now->copy()->endOfDay();

        $from = match ($preset) {
            'today' => $now->copy()->startOfDay(),
            'week' => $now->copy()->startOfWeek(),
            'month' => $now->copy()->startOfMonth(),
            'quarter' => $now->copy()->startOfQuarter(),
            'half_year' => $now->month <= 6
                ? $now->copy()->startOfYear()
                : $now->copy()->startOfYear()->addMonths(6),
            'year' => $now->copy()->startOfYear(),
            default => $now->copy()->startOfMonth(),
        };

        return [$from, $to, $preset];
    }
}
