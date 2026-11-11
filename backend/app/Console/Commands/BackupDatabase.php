<?php

namespace App\Console\Commands;

use App\Domains\Notifications\Services\NotificationService;
use App\Domains\Settings\Services\SettingsService;
use App\Domains\Settings\Services\SystemStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Daily backup of the database (and uploaded files) to
 * storage/app/backups, keeping the number of days set in Admin settings →
 * Monitoring. Uses mysqldump when the server has it, otherwise dumps
 * table by table in PHP. Download the files from cPanel File Manager or
 * copy them off the server for safe keeping.
 */
class BackupDatabase extends Command
{
    protected $signature = 'backup:run {--no-files : Skip uploaded files}';

    protected $description = 'Back up the database and uploaded files to storage/app/backups';

    public function handle(SettingsService $settings): int
    {
        $dir = storage_path('app/backups');
        File::ensureDirectoryExists($dir);
        $stamp = now()->format('Y-m-d_His');

        try {
            $dbFile = $this->dumpDatabase($dir, $stamp);
            $files = [$dbFile];

            if (! $this->option('no-files') && class_exists(\ZipArchive::class) && File::isDirectory(storage_path('app/public'))) {
                $zipPath = "{$dir}/uploads_{$stamp}.zip";
                $zip = new \ZipArchive();
                if ($zip->open($zipPath, \ZipArchive::CREATE) === true) {
                    foreach (File::allFiles(storage_path('app/public')) as $f) {
                        $zip->addFile($f->getRealPath(), $f->getRelativePathname());
                    }
                    $zip->close();
                    $files[] = $zipPath;
                }
            }

            $keep = max(1, (int) $settings->value('monitoring', 'backup_keep_days', 14));
            foreach (File::files($dir) as $f) {
                if ($f->getMTime() < now()->subDays($keep)->getTimestamp()) {
                    File::delete($f->getRealPath());
                }
            }

            Cache::forever(SystemStatus::BACKUP_KEY, ['ok' => true, 'at' => now()->toIso8601String(), 'files' => array_map('basename', $files)]);
            $this->info('Backup written: '.implode(', ', array_map('basename', $files)));

            return self::SUCCESS;
        } catch (Throwable $e) {
            Cache::forever(SystemStatus::BACKUP_KEY, ['ok' => false, 'at' => now()->toIso8601String(), 'error' => $e->getMessage()]);
            app(NotificationService::class)->alertStaff('staff_booking_attention', [
                'booking_code' => 'BACKUP', 'service' => 'System', 'trip' => 'Daily backup', 'amount' => '-',
                'reason' => 'The daily backup failed: '.$e->getMessage(),
            ]);
            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    protected function dumpDatabase(string $dir, string $stamp): string
    {
        $conn = config('database.default');
        $cfg = config("database.connections.{$conn}");

        if (($cfg['driver'] ?? '') === 'sqlite') {
            $target = "{$dir}/database_{$stamp}.sqlite";
            File::copy($cfg['database'], $target);

            return $target;
        }

        $target = "{$dir}/database_{$stamp}.sql.gz";
        $mysqldump = trim((string) @shell_exec('command -v mysqldump 2>/dev/null'));
        if ($mysqldump && function_exists('proc_open')) {
            $cmd = sprintf('%s --single-transaction --quick --routines --no-tablespaces -h %s -P %s -u %s %s | gzip > %s',
                escapeshellarg($mysqldump), escapeshellarg($cfg['host'] ?? '127.0.0.1'), escapeshellarg((string) ($cfg['port'] ?? 3306)),
                escapeshellarg($cfg['username'] ?? ''), escapeshellarg($cfg['database']), escapeshellarg($target));
            $proc = proc_open(['bash', '-o', 'pipefail', '-c', $cmd], [2 => ['pipe', 'w']], $pipes, null, ['MYSQL_PWD' => (string) ($cfg['password'] ?? '')] + getenv());
            $err = stream_get_contents($pipes[2]);
            if (proc_close($proc) === 0 && filesize($target) > 100) {
                return $target;
            }
            $this->warn('mysqldump failed, falling back to PHP dump: '.trim($err));
        }

        // PHP dump: structure + data, table by table.
        $gz = gzopen($target, 'w6');
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=0;\n");
        foreach (DB::select('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"') as $row) {
            $table = array_values((array) $row)[0];
            $create = array_values((array) DB::selectOne("SHOW CREATE TABLE `{$table}`"))[1];
            gzwrite($gz, "\nDROP TABLE IF EXISTS `{$table}`;\n{$create};\n");
            DB::table($table)->orderByRaw('1')->chunk(500, function ($rows) use ($gz, $table) {
                $values = [];
                foreach ($rows as $r) {
                    $values[] = '('.implode(',', array_map(fn ($v) => $v === null ? 'NULL' : DB::getPdo()->quote((string) $v), (array) $r)).')';
                }
                gzwrite($gz, "INSERT INTO `{$table}` VALUES\n".implode(",\n", $values).";\n");
            });
        }
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($gz);

        return $target;
    }
}
