<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Client;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tariff;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BillingService
{
    public function charge(Document $d, string $service, string $key, int|string $units): void
    {
        $tariff = Tariff::where('client_id', $d->client_id)->where('service', $service)->first();
        if (! $tariff) {
            throw ValidationException::withMessages(['tariff' => 'Tarif '.$service.' belum disiapkan untuk klien ini.']);
        }
        Charge::firstOrCreate(['activity_key' => $key.':actor:'.auth()->id()], ['client_id' => $d->client_id, 'warehouse_id' => $d->warehouse_id, 'document_id' => $d->id, 'service' => $service, 'unit' => $tariff->unit, 'rate' => $tariff->rate, 'units' => $units, 'amount' => bcmul($tariff->rate, (string) $units, 2)]);
    }

    public function invoice(array $data): Invoice
    {
        Client::whereKey($data['client_id'])->lockForUpdate()->firstOrFail();
        $from = Carbon::parse($data['period_start'], 'Asia/Jakarta')->startOfDay()->utc();
        $until = Carbon::parse($data['period_end'], 'Asia/Jakarta')->endOfDay()->utc();
        $charges = Charge::where('client_id', $data['client_id'])->whereNull('invoice_id')->whereBetween('created_at', [$from, $until])->lockForUpdate()->get();
        if ($charges->isEmpty()) {
            throw ValidationException::withMessages(['charges' => 'Tidak ada aktivitas yang belum ditagihkan dalam periode ini.']);
        }
        $total = $charges->reduce(fn ($sum, $c) => bcadd($sum, $c->amount, 2), '0.00');
        $invoice = Invoice::create($data + ['number' => 'INV-'.now()->format('ymd').'-'.strtoupper(substr((string) Str::uuid(), 0, 8)), 'total' => $total]);
        Charge::whereIn('id', $charges->pluck('id'))->update(['invoice_id' => $invoice->id]);
        Audit::record('invoice.create', $invoice->number, $invoice->client_id);

        return $invoice->load('charges', 'client');
    }

    public function payment(Invoice $invoice, array $data): Invoice
    {
        $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
        if (bccomp($data['amount'], bcsub($invoice->total, $invoice->paid, 2), 2) > 0) {
            throw ValidationException::withMessages(['amount' => 'Pembayaran melebihi sisa invoice.']);
        }
        if (Payment::where('reference', $data['reference'])->exists()) {
            throw ValidationException::withMessages(['reference' => 'Referensi pembayaran sudah dicatat.']);
        }
        Payment::create($data + ['invoice_id' => $invoice->id, 'client_id' => $invoice->client_id, 'user_id' => auth()->id()]);
        $paid = bcadd($invoice->paid, $data['amount'], 2);
        $invoice->update(['paid' => $paid, 'status' => bccomp($paid, $invoice->total, 2) === 0 ? 'paid' : 'partial']);
        Audit::record('invoice.payment',$invoice->number,$invoice->client_id);

        return $invoice->load('payments','charges','client');
    }
}
