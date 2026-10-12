<?php

namespace App\Jobs;

use App\Models\ReportExport;
use App\Models\User;
use App\Services\ExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExportReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $exportId) {}

    public function handle(): void
    {
        $job = ReportExport::findOrFail($this->exportId);
        $user = User::findOrFail($job->user_id);
        $q = ExportService::query($user, $job->kind, $job->filters);
        $job->update(['status' => 'processing']);
        $path = 'reports/'.(string) Str::uuid().'.csv';
        Storage::disk('local')->makeDirectory('reports');
        $f = fopen(Storage::disk('local')->path($path), 'w');
        fwrite($f, "\xEF\xBB\xBF");
        $headers = null;
        $rows = 0;
        try {
            $q->chunkById(500, function ($records) use ($f, &$headers, &$rows) {
                foreach ($records as $record) {
                    $r = collect($record->toArray())->map(fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v)->all();
                    if (! $headers) {
                        $headers = array_keys($r);
                        fputcsv($f, $headers, ',', '"', '');
                    }fputcsv($f, array_map(fn ($v) => is_string($v) && preg_match('/^[=+@\-]/', $v) ? "'".$v : $v, array_values($r)), ',', '"', '');
                    $rows++;
                }
            });
            if (! $headers) {
                fputcsv($f, ['Tidak ada data'], ',', '"', '');
            }
        } finally {
            fclose($f);
        }
        $job->update(['path' => $path, 'rows' => $rows, 'status' => 'completed']);
    }

    public function failed(?\Throwable $e): void
    {
        ReportExport::whereKey($this->exportId)->update(['status' => 'failed']);
    }
}
