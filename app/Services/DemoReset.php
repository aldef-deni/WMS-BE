<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Demo account data reset.
 *
 * A baseline snapshot of all application tables is stored in `demo_baselines`.
 * The first demo login after a reset starts a 24-hour timer; when it expires the
 * database is restored to the baseline (scheduler `aldef:demo-reset`, or lazily on
 * the next demo login if the scheduler is not running).
 */
class DemoReset
{
    public const EMAIL = 'demo@aldeftech.com';

    public const PASSWORD = 'demo';

    public const RESET_HOURS = 24;

    /** Framework/runtime tables that are never snapshotted or restored. */
    private const EXCLUDED = ['migrations', 'demo_baselines', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens', 'personal_access_tokens'];

    /** Private storage keys whose files belong to these tables. */
    private const FILE_TABLES = ['attachments' => 'path', 'report_exports' => 'path', 'users' => 'avatar_path'];

    /** Account columns of real (non-demo) users that survive a reset. */
    private const KEEP_USER_COLUMNS = ['name', 'password', 'remember_token', 'phone', 'job_title', 'avatar_path', 'last_login_at'];

    public static function isDemo(?string $email): bool
    {
        return strtolower((string) $email) === self::EMAIL;
    }

    public function tables(): array
    {
        return collect(Schema::getTables())->pluck('name')->reject(fn ($t) => in_array($t, self::EXCLUDED, true))->sort()->values()->all();
    }

    public function state(): ?object
    {
        return DB::table('demo_baselines')->orderByDesc('id')->first();
    }

    public function hasBaseline(): bool
    {
        return $this->state() !== null;
    }

    /** Store the current database content as the default demo data. */
    public function capture(): array
    {
        $data = [];
        foreach ($this->tables() as $table) {
            $data[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        }
        $now = now();
        DB::table('demo_baselines')->delete();
        DB::table('demo_baselines')->insert(['payload' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'reset_due_at' => null, 'last_reset_at' => null, 'created_at' => $now, 'updated_at' => $now]);

        return array_map('count', $data);
    }

    /** Start the reset timer on the first demo login after a reset; restore first when already overdue. */
    public function onDemoLogin(): void
    {
        $state = $this->state();
        if (! $state) {
            $this->capture();
            $state = $this->state();
        }
        if ($state->reset_due_at && now()->gte($state->reset_due_at)) {
            $this->restore();
            $state = $this->state();
        }
        if (! $state->reset_due_at) {
            DB::table('demo_baselines')->where('id', $state->id)->update(['reset_due_at' => now()->addHours(self::RESET_HOURS), 'updated_at' => now()]);
        }
    }

    /** Restore when the timer expired. Returns true when a reset was performed. */
    public function resetIfDue(): bool
    {
        $state = $this->state();
        if (! $state || ! $state->reset_due_at || now()->lt($state->reset_due_at)) {
            return false;
        }
        $this->restore();

        return true;
    }

    /** Replace all application data with the baseline snapshot. */
    public function restore(): void
    {
        $state = $this->state();
        if (! $state) {
            throw new \RuntimeException('Baseline demo belum tersedia. Jalankan php artisan aldef:demo-baseline.');
        }
        $data = json_decode($state->payload, true, 512, JSON_THROW_ON_ERROR);
        $tables = array_values(array_intersect($this->tables(), array_keys($data)));
        $currentFiles = $this->files(collect(self::FILE_TABLES)->filter(fn ($c, $t) => Schema::hasColumn($t, $c))->map(fn ($c, $t) => DB::table($t)->get([$c])->map(fn ($r) => (array) $r)->all())->all());
        // Profile and password changes of real (non-demo) accounts survive the reset.
        // Every row gets the same keys so the bulk insert stays aligned.
        $keep = array_values(array_intersect(self::KEEP_USER_COLUMNS, Schema::getColumnListing('users')));
        $accounts = DB::table('users')->where('email', '!=', self::EMAIL)->get(array_merge(['id', 'email'], $keep))->keyBy('id');
        if (isset($data['users'])) {
            $data['users'] = array_map(function ($u) use ($accounts, $keep) {
                $current = $accounts[$u['id']] ?? null;
                $matched = $current && $current->email === $u['email'];
                foreach ($keep as $column) {
                    $u[$column] = $matched ? $current->{$column} : ($u[$column] ?? null);
                }

                return $u;
            }, $data['users']);
        }
        $baselineFiles = $this->files($data);
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            DB::transaction(function () use ($tables, $data, $state) {
                foreach ($tables as $table) {
                    DB::table($table)->delete();
                    foreach (array_chunk($data[$table], 200) as $chunk) {
                        DB::table($table)->insert($chunk);
                    }
                }
                DB::table('demo_baselines')->where('id', $state->id)->update(['reset_due_at' => null, 'last_reset_at' => now(), 'updated_at' => now()]);
            });
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
        $orphans = array_diff($currentFiles, $baselineFiles);
        foreach ($orphans as $path) {
            Storage::disk('local')->delete($path);
        }
        Log::info('Demo data reset to baseline.', ['tables' => count($tables), 'files_removed' => count($orphans)]);
    }

    private function files(array $data): array
    {
        $paths = [];
        foreach (self::FILE_TABLES as $table => $column) {
            foreach ($data[$table] ?? [] as $row) {
                if (! empty($row[$column])) {
                    $paths[] = $row[$column];
                }
            }
        }

        return array_values(array_unique($paths));
    }
}
