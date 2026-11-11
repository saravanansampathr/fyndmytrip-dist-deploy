<?php

namespace App\Domains\Settings\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Health of the running site: database, cache, storage, whether the
 * scheduler (cron) is running, and when the last backup was made. Used by
 * the public /health check (for an uptime monitor) and the admin page.
 */
class SystemStatus
{
    public const HEARTBEAT_KEY = 'fyndmytrip:scheduler:last_run';

    public const BACKUP_KEY = 'fyndmytrip:backup:last';

    public function report(bool $detailed = false): array
    {
        $checks = [];

        try {
            DB::select('select 1');
            $checks['database'] = ['ok' => true];
        } catch (Throwable $e) {
            $checks['database'] = ['ok' => false, 'message' => $detailed ? $e->getMessage() : 'unreachable'];
        }

        try {
            Cache::put('fyndmytrip:health', 1, 60);
            $checks['cache'] = ['ok' => Cache::get('fyndmytrip:health') === 1];
        } catch (Throwable $e) {
            $checks['cache'] = ['ok' => false, 'message' => $detailed ? $e->getMessage() : 'unavailable'];
        }

        $checks['storage'] = ['ok' => is_writable(storage_path('app')) && is_writable(storage_path('logs'))];

        $beat = Cache::get(self::HEARTBEAT_KEY);
        $checks['scheduler'] = [
            'ok' => $beat && now()->diffInMinutes(\Carbon\Carbon::parse($beat), true) <= 10,
            'last_run' => $beat,
            'message' => $beat ? null : 'The cron job for "php artisan schedule:run" has not run yet. Add it in cPanel → Cron Jobs to run every minute.',
        ];

        $backup = Cache::get(self::BACKUP_KEY);
        $checks['backup'] = [
            'ok' => $backup && ($backup['ok'] ?? false) && now()->diffInHours(\Carbon\Carbon::parse($backup['at']), true) <= 30,
            'last' => $backup,
        ];

        $critical = $checks['database']['ok'] && $checks['storage']['ok'];
        $report = ['status' => $critical ? (collect($checks)->every(fn ($c) => $c['ok']) ? 'ok' : 'degraded') : 'down', 'checks' => $checks, 'time' => now()->toIso8601String()];

        if ($detailed) {
            $report['app'] = [
                'environment' => app()->environment(),
                'debug' => (bool) config('app.debug'),
                'php' => PHP_VERSION,
                'queue' => config('queue.default'),
                'backups' => collect(File::exists(storage_path('app/backups')) ? File::files(storage_path('app/backups')) : [])
                    ->sortByDesc(fn ($f) => $f->getMTime())->take(10)
                    ->map(fn ($f) => ['file' => $f->getFilename(), 'size_kb' => (int) round($f->getSize() / 1024), 'at' => date('c', $f->getMTime())])->values(),
            ];
            if (config('app.debug') && app()->environment('production')) {
                $report['warnings'][] = 'APP_DEBUG is on in production: error pages can show secrets. Set APP_DEBUG=false in .env.';
            }
        }

        return $report;
    }
}
