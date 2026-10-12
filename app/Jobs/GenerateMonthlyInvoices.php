<?php

namespace App\Jobs;

use App\Models\Charge;
use App\Models\Client;
use App\Services\BillingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class GenerateMonthlyInvoices implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $month = now('Asia/Jakarta')->subMonthNoOverflow();
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();
        foreach (Client::where('active', true)->orderBy('id')->cursor() as $client) {
            DB::transaction(function () use ($client, $start, $end) {
                Client::whereKey($client->id)->lockForUpdate()->firstOrFail();
                if (! Charge::where('client_id', $client->id)->whereNull('invoice_id')->whereBetween('created_at', [$start->copy()->utc(), $end->copy()->utc()])->exists()) {
                    return;
                }app(BillingService::class)->invoice(['client_id' => $client->id, 'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(), 'due_date' => now('Asia/Jakarta')->addDays(14)->toDateString()]);
            }, 5);
        }
    }
}
