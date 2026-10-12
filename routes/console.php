<?php

use App\Jobs\AccrueStorage;
use App\Jobs\GenerateMonthlyInvoices;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('aldef:storage {date?}', function () {
    $date = $this->argument('date') ?? now('Asia/Jakarta')->subDay()->toDateString();
    AccrueStorage::dispatch($date);
    $this->info('Storage accrual queued: '.$date);
});
Schedule::call(fn () => AccrueStorage::dispatch(now('Asia/Jakarta')->subDay()->toDateString()))->dailyAt('00:10')->timezone('Asia/Jakarta');
Schedule::job(new GenerateMonthlyInvoices)->monthlyOn(1, '00:30')->timezone('Asia/Jakarta');
