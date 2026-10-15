<?php

use App\Jobs\AccrueStorage;
use App\Jobs\GenerateMonthlyInvoices;
use App\Services\DemoReset;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('aldef:storage {date?}', function () {
    $date = $this->argument('date') ?? now('Asia/Jakarta')->subDay()->toDateString();
    AccrueStorage::dispatch($date);
    $this->info('Storage accrual queued: '.$date);
});
Artisan::command('aldef:demo-baseline', function (DemoReset $demo) {
    foreach ($demo->capture() as $table => $rows) {
        $this->line($table.': '.$rows);
    }
    $this->info('Data saat ini disimpan sebagai data default akun demo.');
})->purpose('Simpan data saat ini sebagai data default akun demo');
Artisan::command('aldef:demo-reset {--force : Reset sekarang tanpa menunggu 24 jam}', function (DemoReset $demo) {
    if ($this->option('force')) {
        $demo->restore();
        $this->info('Data demo dikembalikan ke data default.');
    } else {
        $this->info($demo->resetIfDue() ? 'Data demo dikembalikan ke data default.' : 'Belum waktunya reset data demo.');
    }
})->purpose('Kembalikan data ke default 24 jam setelah akun demo login');
Schedule::command('aldef:demo-reset')->everyMinute()->withoutOverlapping();
Schedule::call(fn () => AccrueStorage::dispatch(now('Asia/Jakarta')->subDay()->toDateString()))->dailyAt('00:10')->timezone('Asia/Jakarta');
Schedule::job(new GenerateMonthlyInvoices)->monthlyOn(1, '00:30')->timezone('Asia/Jakarta');
