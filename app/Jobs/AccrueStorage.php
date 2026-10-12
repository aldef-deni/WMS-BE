<?php

namespace App\Jobs;

use App\Models\Balance;
use App\Models\Charge;
use App\Models\Client;
use App\Models\LedgerEntry;
use App\Models\Tariff;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class AccrueStorage implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $date) {}

    public function handle(): void
    {
        $cutoff = Carbon::parse($this->date, 'Asia/Jakarta')->endOfDay()->utc();
        Balance::orderBy('id')->chunkById(100, function ($balances) use ($cutoff) {
            foreach ($balances as $b) {
                DB::transaction(function () use ($b, $cutoff) {
                    Client::whereKey($b->client_id)->lockForUpdate()->firstOrFail();
                    $rate = Tariff::where('client_id', $b->client_id)->where('service', 'storage')->first();
                    if (! $rate) {
                        return;
                    }$units = LedgerEntry::where('balance_id', $b->id)->where('created_at', '<=', $cutoff)->sum('quantity_delta');
                    if ($units <= 0) {
                        return;
                    }Charge::firstOrCreate(['activity_key' => 'storage:'.$this->date.':'.$b->id], ['client_id' => $b->client_id, 'warehouse_id' => $b->warehouse_id, 'service' => 'storage', 'unit' => 'unit_day', 'rate' => $rate->rate, 'units' => $units, 'amount' => bcmul($rate->rate, (string) $units, 2)]);
                }, 5);
            }
        });
    }
}
