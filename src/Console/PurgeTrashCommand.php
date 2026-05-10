<?php

namespace FileBrowser\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Hard-delete trashed entries older than the configured retention
 * window (default 7 days). Walks every customer's home directory
 * found via the Laravel hosting accounts table — when not present
 * (or schema differs), gracefully scans /home/* as a fallback.
 *
 * Configured via:
 *   filebrowser.trash_retention_days (default 7)
 *   filebrowser.trash_scan_root      (default '/home')
 *
 * Schedule with: Schedule::command('filebrowser:purge-trash')->daily();
 */
class PurgeTrashCommand extends Command
{
    protected $signature = 'filebrowser:purge-trash {--dry : List candidates without deleting}';
    protected $description = 'Hard-delete trashed entries older than the retention window.';

    public function handle(): int
    {
        $days = (int) config('filebrowser.trash_retention_days', 7);
        $cutoff = time() - ($days * 86400);
        $dry = (bool) $this->option('dry');

        $purged = 0;
        $kept = 0;
        $errors = 0;

        foreach ($this->discoverHomes() as $home) {
            $trashRoot = rtrim($home, '/') . '/.trash';
            if (!is_dir($trashRoot)) continue;

            foreach (scandir($trashRoot) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                $entryDir = $trashRoot . '/' . $entry;
                if (!is_dir($entryDir)) continue;

                $deletedAt = $this->deletedAtUnix($entryDir);
                if ($deletedAt > $cutoff) {
                    $kept++;
                    continue;
                }

                $this->line(sprintf('  %s  (deleted %s ago)',
                    $entryDir, $this->humanDuration(time() - $deletedAt)));

                if ($dry) { $purged++; continue; }

                try {
                    $this->rmRecursive($entryDir);
                    $purged++;
                } catch (\Throwable $e) {
                    $errors++;
                    Log::warning("filebrowser:purge-trash error on {$entryDir}: " . $e->getMessage());
                }
            }
        }

        $this->info(($dry ? '[DRY] ' : '') . "Done. purged={$purged} kept={$kept} errors={$errors} (retention={$days}d)");
        return self::SUCCESS;
    }

    /** @return iterable<string> Customer home dirs to scan. */
    private function discoverHomes(): iterable
    {
        // Prefer the panel's source of truth when present.
        if (class_exists(\App\Models\HostingAccount::class)) {
            try {
                foreach (\App\Models\HostingAccount::query()->cursor() as $a) {
                    if ($a->home_dir && is_dir($a->home_dir)) yield $a->home_dir;
                }
                return;
            } catch (\Throwable $e) {
                // table missing or schema mismatch — fall through to scan
            }
        }

        $base = config('filebrowser.trash_scan_root', '/home');
        if (!is_dir($base)) return;
        foreach (scandir($base) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $p = $base . '/' . $name;
            if (is_dir($p)) yield $p;
        }
    }

    private function deletedAtUnix(string $entryDir): int
    {
        $metaPath = $entryDir . '/.meta.json';
        if (is_file($metaPath)) {
            $meta = json_decode((string) @file_get_contents($metaPath), true);
            if (is_array($meta) && isset($meta['deleted_at_unix'])) {
                return (int) $meta['deleted_at_unix'];
            }
        }
        // Sidecar missing — fall back to dir mtime.
        return @filemtime($entryDir) ?: 0;
    }

    private function rmRecursive(string $dir): void
    {
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    private function humanDuration(int $seconds): string
    {
        if ($seconds < 60) return $seconds . 's';
        if ($seconds < 3600) return floor($seconds / 60) . 'm';
        if ($seconds < 86400) return floor($seconds / 3600) . 'h';
        return floor($seconds / 86400) . 'd';
    }
}
