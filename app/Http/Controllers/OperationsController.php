<?php

namespace App\Http\Controllers;

use App\Http\Resources\RecordResource;
use App\Models\AuditLog;
use App\Models\Balance;
use App\Models\Charge;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Location;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Warehouse;
use App\Services\BillingService;
use App\Services\Idempotency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class OperationsController extends Controller
{
    public function filter($q, Request $r)
    {
        foreach (['client_id', 'warehouse_id', 'product_id', 'condition'] as $f) {
            if ($r->filled($f) && Schema::hasColumn($q->getModel()->getTable(), $f)) {
                $q->where($f, $r->input($f));
            }
        }

return $q;
    }

    public function dashboard(Request $r)
    {
        $u = $r->user();
        abort_unless($u->allowed('dashboard.read'), 403);
        $b = $this->filter(Balance::visible($u), $r);
        $docs = $this->filter(Document::visible($u), $r);
        $ledger = $this->filter(LedgerEntry::visible($u), $r);
        $orders = (clone $docs)->where('type', 'order');
        $kpi = ['physical' => (int) (clone $b)->sum('quantity'), 'available' => (int) (clone $b)->allocatable()->selectRaw('COALESCE(SUM(quantity-reserved),0) AS total')->value('total'), 'reserved' => (int) (clone $b)->sum('reserved'), 'quarantine' => (int) (clone $b)->whereIn('condition', ['quarantine', 'damaged'])->sum('quantity'), 'open_orders' => (clone $orders)->whereNotIn('status', ['dispatched', 'cancelled'])->count(), 'dispatched' => (clone $orders)->where('status', 'dispatched')->count(), 'inbound' => (clone $docs)->where('type', 'inbound')->whereNotIn('status', ['completed', 'closed_difference'])->count()];
        $activity = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = now('Asia/Jakarta')->subDays($i);
            $start = $day->copy()->startOfDay()->utc();
            $end = $day->copy()->endOfDay()->utc();
            $q = (clone $ledger)->whereBetween('created_at', [$start, $end]);
            $activity[] = ['date' => $day->format('d M'), 'inbound' => (int) (clone $q)->where('event', 'receiving')->sum('quantity_delta'), 'outbound' => -(int) (clone $q)->where('event', 'dispatch')->sum('quantity_delta')];
        }
        $warehouses = Warehouse::when(! $u->hasRole('Super Admin'), fn ($q) => $q->whereIn('id', $u->warehouses->pluck('id')))->get()->map(function ($w) use ($b) {
            $w->used = (int) (clone $b)->where('warehouse_id', $w->id)->sum('quantity');

            return $w;
        });
        $available = (clone $b)->allocatable()->selectRaw('product_id,SUM(quantity-reserved) AS available_units')->groupBy('product_id');
        $low = Product::visible($u)->when($r->filled('client_id'), fn ($q) => $q->where('products.client_id', $r->input('client_id')))->leftJoinSub($available, 'stock', fn ($join) => $join->on('products.id', '=', 'stock.product_id'))->whereRaw('COALESCE(stock.available_units,0)<=products.minimum_stock')->select('products.sku', 'products.name')->selectRaw('COALESCE(stock.available_units,0) AS available_units')->get()->map(fn ($row) => ['sku' => $row->sku, 'name' => $row->name, 'available' => (int) $row->available_units]);
        $sla = (clone $orders)->whereNotIn('status', ['dispatched', 'cancelled'])->where('created_at', '<', now()->subHours((int) (Setting::where('key', 'sla')->first()?->value['dispatch_hours'] ?? 24)))->count();
        $invoiceQuery = Invoice::visible($u);
        if (! $u->hasRole('Super Admin')) {
            $invoiceQuery->whereDoesntHave('charges', fn ($q) => $q->whereNotIn('warehouse_id', $u->warehouses->pluck('id')));
        }if ($r->filled('client_id')) {
            $invoiceQuery->where('client_id', $r->input('client_id'));
        }
        $financial = $u->allowed('billing.read') ? ['outstanding' => (clone $invoiceQuery)->selectRaw('COALESCE(SUM(total-paid),0) AS amount')->value('amount'), 'overdue' => (clone $invoiceQuery)->whereColumn('paid', '<', 'total')->where('due_date', '<', now('Asia/Jakarta')->toDateString())->count(), 'charges' => $this->filter(Charge::visible($u), $r)->sum('amount')] : null;
        $expiryDays = (int) (Setting::where('key', 'notifications')->first()?->value['expiry_days'] ?? 30);

        return ['kpi' => $kpi, 'activity' => $activity, 'warehouses' => $warehouses, 'recent_orders' => $u->allowed('order.read') ? (clone $orders)->with('client', 'warehouse', 'lines.product')->latest()->limit(6)->get() : [], 'queue' => (clone $docs)->selectRaw('type,status,COUNT(*) AS total')->whereNotIn('status', ['completed', 'closed_difference', 'dispatched', 'cancelled', 'approved'])->groupBy('type', 'status')->get(), 'low_stock' => $low, 'expiring' => (clone $b)->with('product', 'location')->where('quantity', '>', 0)->whereBetween('expiry', [now('Asia/Jakarta')->toDateString(), now('Asia/Jakarta')->addDays($expiryDays)->toDateString()])->get(), 'sla_warning' => $sla, 'roles' => $u->roles->pluck('name'), 'financial' => $financial, 'expiry_days' => $expiryDays];
    }

    public function balances(Request $r)
    {
        abort_unless($r->user()->allowed('inventory.read'), 403);
        $q = $this->filter(Balance::visible($r->user())->with('client', 'warehouse', 'location', 'product'), $r);
        if ($r->filled('search')) {
            $q->whereHas('product', fn ($p) => $p->where('name', 'like', '%'.$r->input('search').'%')->orWhere('sku', 'like', '%'.$r->input('search').'%'));
        }

return RecordResource::collection($q->orderBy('id')->paginate(min(100, max(1, (int) $r->input('per_page', 15)))));
    }

    public function ledger(Request $r)
    {
        abort_unless($r->user()->allowed('inventory.read'), 403);
        $q = $this->filter(LedgerEntry::visible($r->user())->with('balance.product', 'balance.location', 'document'), $r);
        if ($r->filled('balance_id')) {
            $q->where('balance_id', $r->input('balance_id'));
        }

return RecordResource::collection($q->latest('id')->paginate(min(100, max(1, (int) $r->input('per_page', 15)))));
    }

    public function audit(Request $r)
    {
        abort_unless($r->user()->allowed('audit.read'), 403);

        return RecordResource::collection(AuditLog::visible($r->user())->with('user')->latest('id')->paginate(20));
    }

    public function invoices(Request $r)
    {
        abort_unless($r->user()->allowed('billing.read'), 403);
        $q = Invoice::visible($r->user())->with('client', 'charges', 'payments');
        if (! $r->user()->hasRole('Super Admin')) {
            $q->whereDoesntHave('charges', fn ($q) => $q->whereNotIn('warehouse_id', $r->user()->warehouses->pluck('id')));
        }if ($r->filled('client_id')) {
            $q->where('client_id', $r->input('client_id'));
        }

return RecordResource::collection($q->latest()->paginate(15));
    }

    public function charges(Request $r)
    {
        abort_unless($r->user()->allowed('billing.read'), 403);

        return RecordResource::collection($this->filter(Charge::visible($r->user())->with('client', 'document'), $r)->latest()->paginate(20));
    }

    public function invoice(Request $r, BillingService $billing)
    {
        abort_unless($r->user()->allowed('billing.write'), 403);
        $d = $r->validate(['client_id' => 'required|integer|exists:clients,id', 'period_start' => 'required|date', 'period_end' => 'required|date|after_or_equal:period_start', 'due_date' => 'required|date|after_or_equal:period_end', 'idempotency_key' => 'required|string|max:100']);
        abort_unless($r->user()->inScope($d['client_id']), 403);
        if (! $r->user()->hasRole('Super Admin')) {
            abort_if(Charge::where('client_id', $d['client_id'])->whereNull('invoice_id')->whereNotIn('warehouse_id', $r->user()->warehouses->pluck('id'))->exists(), 403);
        }

return Idempotency::run($d['idempotency_key'], 'invoice:create', $d, fn () => ['data' => $billing->invoice(collect($d)->except('idempotency_key')->all())->toArray()]);
    }

    public function payment(Request $r, int $id, BillingService $billing)
    {
        abort_unless($r->user()->allowed('billing.write'), 403);
        $invoice = Invoice::visible($r->user())->findOrFail($id);
        if (! $r->user()->hasRole('Super Admin')) {
            abort_if($invoice->charges()->whereNotIn('warehouse_id', $r->user()->warehouses->pluck('id'))->exists(), 403);
        }$d = $r->validate(['amount' => 'required|decimal:0,2|gt:0', 'reference' => 'required|string|max:100', 'paid_at' => 'required|date', 'idempotency_key' => 'required|string|max:100']);

        return Idempotency::run($d['idempotency_key'], 'invoice:'.$id.':payment', $d, fn () => ['data' => $billing->payment($invoice, collect($d)->except('idempotency_key')->all())->toArray()]);
    }

    public function reports(Request $r)
    {
        abort_unless($r->user()->allowed('reports.read'), 403);
        $d = $this->dashboard($r);
        $u = $r->user();
        $q = $this->filter(Document::visible($u), $r);
        $charges = $this->filter(Charge::visible($u), $r);
        $balance = $this->filter(Balance::visible($u), $r);

        return $d + ['revenue' => $u->allowed('billing.read') ? (clone $charges)->selectRaw('service,SUM(units) AS units,SUM(amount) AS amount')->groupBy('service')->get() : [], 'productivity' => $u->allowed('reports.staff') ? $this->filter(LedgerEntry::visible($u), $r)->selectRaw('user_id,event,COUNT(*) AS transactions,SUM(ABS(quantity_delta)) AS units')->groupBy('user_id', 'event')->get() : [],
            'aging' => (clone $balance)->selectRaw('DATEDIFF(UTC_TIMESTAMP(),created_at) DIV 30 AS months,SUM(quantity) AS units')->groupBy('months')->get(), 'utilization' => Location::when(! $u->hasRole('Super Admin'), fn ($q) => $q->whereIn('warehouse_id', $u->warehouses->pluck('id')))->get()->map(fn ($l) => ['code' => $l->code, 'capacity' => $l->capacity, 'used' => (int) (clone $balance)->where('location_id', $l->id)->sum('quantity')]), 'accuracy' => ['picked' => (clone $q)->where('type', 'order')->withSum('lines', 'picked')->get()->sum('lines_sum_picked'), 'short_pick' => (clone $q)->whereNotNull('meta->short_pick_reason')->count()]];
    }

    public function export(Request $r, string $kind)
    {
        $permission = match ($kind) {
            'inventory','ledger' => 'inventory.read','charges','invoices' => 'billing.read','orders' => 'order.read','inbound' => 'inbound.read',default => 'reports.read'
        };
        abort_unless($r->user()->allowed($permission) && $r->user()->allowed('reports.export'), 403);
        $q = match ($kind) {
            'inventory' => Balance::visible($r->user())->with('product', 'location'),'ledger' => LedgerEntry::visible($r->user()),'charges' => Charge::visible($r->user()),'orders' => Document::visible($r->user())->where('type', 'order'),'inbound' => Document::visible($r->user())->where('type', 'inbound'),'invoices' => Invoice::visible($r->user()),default => abort(404)
        };
        $this->filter($q, $r);
        if ($kind === 'invoices' && ! $r->user()->hasRole('Super Admin')) {
            $q->whereDoesntHave('charges', fn ($c) => $c->whereNotIn('warehouse_id', $r->user()->warehouses->pluck('id')));
        }
        // Limited synchronous export; larger reporting is queued through report-jobs.
        $records = $q->limit(10000)->get()->map(function ($row) {
            return collect($row->toArray())->map(fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v)->all();
        })->all();
        $headers = array_keys($records[0] ?? ['info' => 'Tidak ada data']);
        if ($r->input('format') === 'xlsx') {
            $s = new Spreadsheet;
            $s->getActiveSheet()->fromArray($headers, null, 'A1');
            $row = 2;
            foreach ($records as $record) {
                foreach (array_values($record) as $col => $value) {
                    $s->getActiveSheet()->setCellValueExplicit([$col + 1, $row], (string) $value, DataType::TYPE_STRING);
                }$row++;
            }$writer = new Xlsx($s);

            return response()->streamDownload(fn () => $writer->save('php://output'), $kind.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        }

        return response()->streamDownload(function () use ($records, $headers) {
            $f = fopen('php://output', 'w');
            fwrite($f, "\xEF\xBB\xBF");
            fputcsv($f, $headers, ',', '"', '');
            foreach ($records as $row) {
                fputcsv($f, array_map(fn ($v) => is_string($v) && preg_match('/^[=+@\-]/', $v) ? "'".$v : $v, array_values($row)), ',', '"', '');
            }fclose($f);
        }, $kind.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function notifications(Request $r)
    {
        $d = $this->dashboard($r);
        $items = [];
        if ($d['sla_warning']) {
            $items[] = ['title' => 'Pesanan melewati SLA', 'body' => $d['sla_warning'].' pesanan perlu ditindaklanjuti.'];
        }if (Setting::where('key','notifications')->first()?->value['low_stock'] ?? true) {
            foreach (array_slice($d['low_stock']->all(),0,5) as $row) {
                $items[] = ['title' => 'Stok rendah: '.$row['sku'], 'body' => 'Available '.$row['available'].' unit.'];
            }
        }if ($d['expiring']->count()) {
            $items[] = ['title' => 'Mendekati kedaluwarsa', 'body' => $d['expiring']->count().' saldo batch dalam '.$d['expiry_days'].' hari.'];
        }

return ['data' => $items];
    }
}
