<?php

namespace App\Services;

use App\Integrations\LocalCourierSimulator;
use App\Models\Allocation;
use App\Models\Balance;
use App\Models\Catalog;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\LedgerEntry;
use App\Models\Location;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\TrackingEvent;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class WorkflowService
{
    public function __construct(public InventoryService $stock, public BillingService $billing) {}

    public function create(array $input): Document
    {
        $user = auth()->user();
        abort_unless($user->allowed($input['type'].'.write') && $user->inScope($input['client_id'], $input['warehouse_id']), 403);
        if (! empty($input['destination_warehouse_id'])) {
            abort_unless($user->inScope($input['client_id'], $input['destination_warehouse_id']), 403);
        }
        if (! empty($input['reference_id'])) {
            $ref = Document::visible($user)->findOrFail($input['reference_id']);
            if ($ref->client_id != $input['client_id'] || ($input['type'] === 'return' && ($ref->type !== 'order' || ! in_array($ref->status, ['dispatched', 'partially_dispatched'])))) {
                $this->stock->fail('Referensi order tidak valid.');
            }
        }
        if (! empty($input['assigned_to'])) {
            $assignee = User::findOrFail($input['assigned_to']);
            if (! $assignee->inScope($input['client_id'], $input['warehouse_id']) || ! $assignee->allowed('picking.execute')) {
                $this->stock->fail('Petugas tidak memiliki akses picking untuk penugasan ini.');
            }
        }
        if ($input['type'] === 'count' && ($input['meta']['count_type'] ?? 'cycle') === 'full') {
            $input['lines'] = Balance::where('client_id', $input['client_id'])->where('warehouse_id', $input['warehouse_id'])->whereIn('condition', ['sellable', 'quarantine', 'damaged'])->where('quantity', '>', 0)->whereHas('location', fn ($q) => $q->whereIn('type', ['storage', 'quarantine', 'damaged']))->orderBy('product_id')->get()->map(fn ($b) => ['product_id' => $b->product_id, 'quantity' => 1, 'batch' => $b->batch, 'serial' => $b->serial, 'expiry' => $b->expiry, 'location_id' => $b->location_id, 'condition' => $b->condition])->all();
            if (! $input['lines']) {
                $this->stock->fail('Tidak ada saldo fisik untuk full count.');
            }
        }
        $prefix = Setting::where('key', 'numbering')->first()?->value['prefix'] ?? 'ALDEF';
        $doc = Document::create(collect($input)->except('lines')->all() + ['number' => $prefix.'-'.strtoupper($input['type']).'-'.now()->format('ymd').'-'.strtoupper(substr((string) Str::uuid(), 0, 8)), 'created_by' => $user->id]);
        foreach ($input['lines'] as $line) {
            $line = collect($line)->only(['product_id', 'quantity', 'batch', 'serial', 'expiry', 'location_id', 'condition'])->all();
            $line['batch'] = $line['batch'] ?? '';
            $line['serial'] = $line['serial'] ?? '';
            $product = $this->stock->lockProduct($line['product_id']);
            if ($product->client_id !== $doc->client_id) {
                $this->stock->fail('SKU bukan milik klien dokumen.');
            }
            if ($product->serial_tracked && ($line['quantity'] !== 1 || empty($line['serial']))) {
                $this->stock->fail('Produk serial wajib satu unit per baris dan nomor serial.');
            }
            if (! empty($line['location_id']) && Location::findOrFail($line['location_id'])->warehouse_id !== $doc->warehouse_id) {
                $this->stock->fail('Lokasi tidak valid.');
            }
            if (in_array($doc->type, ['count', 'adjustment', 'transfer']) && empty($line['location_id'])) {
                $this->stock->fail('Lokasi per baris wajib diisi.');
            }
            if ($doc->type === 'return' && empty($doc->reference_id)) {
                $this->stock->fail('Order referensi retur wajib diisi.');
            }
            if (in_array($doc->type, ['count', 'adjustment'])) {
                $loc = Location::findOrFail($line['location_id']);
                $condition = $line['condition'] ?? 'sellable';
                if ($loc->type !== ($condition === 'sellable' ? 'storage' : $condition)) {
                    $this->stock->fail('Lokasi penghitungan/adjustment tidak sesuai kondisi stok.');
                }
            }
            if ($doc->type === 'return') {
                $allocation = Allocation::whereHas('documentLine', fn ($q) => $q->where('document_id', $doc->reference_id))->whereHas('balance', fn ($q) => $q->where('product_id', $product->id)->where('batch', $line['batch'])->where('serial', $line['serial']))->whereColumn('dispatched', '>', 'returned')->first();
                if (! $allocation) {
                    $this->stock->fail('Produk/batch/serial tidak ditemukan pada barang yang dikirim.');
                }$line['expiry'] = $allocation->balance->expiry;
            }
            if ($doc->type === 'count') {
                $b = Balance::where('product_id', $product->id)->where('location_id', $line['location_id'] ?? 0)->where('batch', $line['batch'] ?? '')->where('serial', $line['serial'] ?? '')->where('condition', $line['condition'] ?? 'sellable')->firstOrFail();
                $line['snapshot'] = $b->quantity;
                $line['cutoff_ledger_id'] = LedgerEntry::where('balance_id', $b->id)->max('id') ?? 0;
            }
            $doc->lines()->create($line);
        }
        Audit::record('document.create', $doc->number, $doc->client_id, $doc->warehouse_id);

        return $doc->load('lines.product', 'client', 'warehouse');
    }

    public function act(Document $doc, string $action, array $data, string $key): Document
    {
        $doc = Document::whereKey($doc->id)->lockForUpdate()->firstOrFail();
        Gate::authorize('update', [$doc, $action]);
        if ($doc->assigned_to && in_array($action, ['pick', 'short-pick']) && $doc->assigned_to !== auth()->id() && ! auth()->user()->allowed('picking.assign')) {
            abort(403);
        }
        foreach ($doc->lines()->orderBy('product_id')->get() as $l) {
            $this->stock->lockProduct($l->product_id);
        }
        $line = isset($data['line_id']) ? $doc->lines()->whereKey($data['line_id'])->firstOrFail() : null;
        $qty = (int) ($data['quantity'] ?? 0);
        switch ($action) {
            case 'receive':
                $this->state($doc, 'inbound', ['draft', 'receiving', 'putaway']);
                $this->line($line);
                if ($qty > $line->quantity - $line->received) {
                    $this->stock->fail('Jumlah penerimaan melebihi sisa ASN.');
                }
                $condition = $data['condition'] ?? 'sellable';
                $this->scan($line, $data);
                $b = $this->stock->balance($doc, $line, $this->stock->location($doc, 'receiving'), $condition);
                if ($line->serial && Balance::where('product_id', $line->product_id)->where('serial', $line->serial)->sum('quantity') > 0) {
                    $this->stock->fail('Serial sudah ada dalam stok.');
                }
                $this->stock->change($b, $qty, 0, 'receiving', $doc);
                $line->increment('received', $qty);
                $doc->update(['status' => 'receiving']);
                $this->billing->charge($doc, 'receiving', 'receive:'.$doc->id.':'.$key, $qty);
                break;
            case 'putaway':
                $this->state($doc, 'inbound', ['receiving', 'putaway']);
                $this->line($line);
                if ($qty > $line->received - $line->putaway) {
                    $this->stock->fail('Jumlah putaway melebihi stok diterima.');
                }
                $condition = $data['condition'] ?? 'sellable';
                $target = Location::findOrFail($data['location_id']);
                if (! in_array($target->type, $condition === 'sellable' ? ['storage'] : [$condition])) {
                    $this->stock->fail('Lokasi tidak sesuai keputusan QC.');
                }
                $source = $this->stock->balance($doc, $line, $this->stock->location($doc, 'receiving'), $condition);
                $this->stock->move($source, $target, $qty, 'putaway', $doc);
                $line->increment('putaway', $qty);
                $doc->update(['status' => $doc->lines()->whereColumn('putaway', '<', 'quantity')->exists() ? 'putaway' : 'completed']);
                break;
            case 'reserve':
                $this->state($doc, 'order', ['draft', 'backorder', 'allocated', 'partially_dispatched']);
                foreach ($doc->lines as $l) {
                    $need = $l->quantity - $l->allocated;
                    if ($need <= 0) {
                        continue;
                    }
                    $q = Balance::where('client_id', $doc->client_id)->where('warehouse_id', $doc->warehouse_id)->where('product_id', $l->product_id)->allocatable()->whereColumn('quantity', '>', 'reserved');
                    if ($l->batch !== '') {
                        $q->where('batch', $l->batch);
                    }if ($l->serial !== '') {
                        $q->where('serial', $l->serial);
                    }
                    $q->orderByRaw($l->product->rotation === 'FEFO' ? 'expiry IS NULL, expiry ASC, id ASC' : 'created_at ASC, id ASC');
                    foreach ($q->lockForUpdate()->get() as $b) {
                        $take = min($need, $b->quantity - $b->reserved);
                        if (! $take) {
                            continue;
                        }
                        $this->stock->change($b, 0, $take, 'reserve', $doc);
                        $a = Allocation::firstOrCreate(['document_line_id' => $l->id, 'balance_id' => $b->id], ['quantity' => 0]);
                        $a->increment('quantity', $take);
                        $l->increment('allocated', $take);
                        $need -= $take;
                        if (! $need) {
                            break;
                        }
                    }
                }
                $doc->update(['status' => $doc->lines()->whereColumn('allocated', '<', 'quantity')->exists() ? 'backorder' : 'allocated']);
                break;
            case 'close-receiving':
                $this->state($doc, 'inbound', ['receiving', 'putaway']);
                if ($doc->lines()->whereColumn('putaway', '<', 'received')->exists()) {
                    $this->stock->fail('Selesaikan putaway barang yang sudah diterima sebelum menutup selisih.');
                }$variances = $doc->lines->map(fn ($l) => ['line_id' => $l->id, 'expected' => $l->quantity, 'received' => $l->received, 'difference' => $l->received - $l->quantity])->all();
                $doc->update(['status' => 'pending_inbound_approval', 'meta' => array_merge($doc->meta ?? [], ['variance' => $variances, 'variance_reason' => $data['reason'], 'variance_submitted_by' => auth()->id()])]);
                break;
            case 'approve-receiving':
                $this->state($doc, 'inbound', ['pending_inbound_approval']);
                if ($doc->created_by === auth()->id() || ($doc->meta['variance_submitted_by'] ?? null) === auth()->id()) {
                    $this->stock->fail('Pembuat/pengaju tidak boleh menyetujui selisih sendiri.');
                }$doc->update(['status' => 'closed_difference', 'approved_by' => auth()->id()]);
                break;
            case 'pick':
                $this->state($doc, 'order', ['allocated', 'backorder', 'picking', 'partially_dispatched']);
                $this->line($line);
                $this->scan($line, $data);
                $remaining = $qty;
                foreach ($line->allocations()->orderBy('balance_id')->lockForUpdate()->get() as $a) {
                    $b = Balance::findOrFail($a->balance_id);
                    $take = min($remaining, $a->quantity - $a->picked);
                    if (! $take) {
                        continue;
                    }
                    if (($data['location_code'] ?? '') !== $b->location->code) {
                        continue;
                    }
                    $this->stock->move($b, $this->stock->location($doc, 'staging'), $take, 'pick', $doc, $take);
                    $a->increment('picked', $take);
                    $remaining -= $take;
                }
                if ($remaining) {
                    $this->stock->fail('Jumlah picking melebihi alokasi.');
                }$line->increment('picked', $qty);
                $doc->update(['status' => $doc->lines()->whereColumn('picked', '<', 'allocated')->exists() ? 'picking' : 'picked']);
                $this->billing->charge($doc, 'picking', 'pick:'.$doc->id.':'.$key, $qty);
                break;
            case 'short-pick':
                $this->state($doc, 'order', ['allocated', 'backorder', 'picking']);
                $this->line($line);
                $remaining = $qty;
                foreach ($line->allocations()->orderByDesc('id')->lockForUpdate()->get() as $a) {
                    $release = min($remaining, $a->quantity - $a->picked);
                    if (! $release) {
                        continue;
                    }$this->stock->change(Balance::findOrFail($a->balance_id), 0, -$release, 'short_pick_release', $doc);
                    $a->decrement('quantity', $release);
                    $remaining -= $release;
                }
                if ($remaining) {
                    $this->stock->fail('Short pick melebihi sisa alokasi.');
                }$line->decrement('allocated', $qty);
                $doc->update(['status' => 'backorder', 'meta' => array_merge($doc->meta ?? [], ['short_pick_reason' => $data['reason']])]);
                break;
            case 'pack':
                $this->state($doc, 'order', ['picked', 'picking', 'packing', 'backorder', 'partially_dispatched']);
                $this->line($line);
                $this->scan($line, $data);
                if ($qty > $line->picked - $line->packed) {
                    $this->stock->fail('Scan packing melebihi jumlah picked.');
                }
                $line->increment('packed', $qty);
                $doc->update(['status' => $doc->lines()->whereColumn('packed', '<', 'picked')->exists() ? 'packing' : 'packed', 'meta' => array_merge($doc->meta ?? [], ['packaging_id' => $data['packaging_id'], 'actual_weight' => $data['actual_weight']])]);
                $this->billing->charge($doc, 'packing', 'pack:'.$doc->id.':'.$key, $qty);
                break;
            case 'manifest':
                $this->state($doc, 'order', ['packed']);
                if ($doc->lines()->sum('packed') < 1) {
                    $this->stock->fail('Belum ada barang yang dipacking.');
                }
                $courier = Catalog::where('kind', 'courier')->findOrFail($data['courier_id']);
                $doc->update(['status' => 'manifested', 'meta' => array_merge($doc->meta ?? [], ['courier_id' => $courier->id, 'courier' => $courier->name, 'tracking_number' => $data['tracking_number'], 'manifest_number' => $data['manifest_number'], 'integration' => 'manual / simulator lokal'])]);
                $this->billing->charge($doc, 'packaging', 'packaging:'.$doc->id.':'.$key, 1);
                break;
            case 'dispatch':
                $this->state($doc, 'order', ['manifested']);
                if (($data['scan'] ?? '') !== ($doc->meta['tracking_number'] ?? '')) {
                    $this->stock->fail('Scan resi tidak sesuai.');
                }
                $shipmentItems = [];
                foreach ($doc->lines as $l) {
                    if ($l->packed > $l->dispatched) {
                        $shipmentItems[] = ['line_id' => $l->id, 'product_id' => $l->product_id, 'sku' => $l->product->sku, 'quantity' => $l->packed - $l->dispatched];
                    }
                }if (! $shipmentItems) {
                    $this->stock->fail('Tidak ada unit baru untuk dispatch.');
                }
                foreach ($doc->lines as $l) {
                    $ship = $l->packed - $l->dispatched;
                    if (! $ship) {
                        continue;
                    }
                    foreach ($l->allocations as $a) {
                        $source = Balance::findOrFail($a->balance_id);
                        $staging = Balance::where('location_id', $this->stock->location($doc, 'staging')->id)->where('product_id', $source->product_id)->where('batch', $source->batch)->where('serial', $source->serial)->where('condition', 'sellable')->firstOrFail();
                        $take = min($ship, $a->picked);
                        if (! $take) {
                            continue;
                        }
                        $this->stock->change($staging, -$take, -$take, 'dispatch', $doc);
                        $a->decrement('picked', $take);
                        $a->decrement('quantity', $take);
                        $a->increment('dispatched', $take);
                        $ship -= $take;
                    }
                    if ($ship) {
                        $this->stock->fail('Staging tidak cocok dengan packing.');
                    }$l->update(['dispatched' => $l->packed]);
                }
                $doc->update(['status' => $doc->lines()->whereColumn('dispatched', '<', 'quantity')->exists() ? 'partially_dispatched' : 'dispatched', 'dispatched_at' => now()]);
                $this->billing->charge($doc, 'shipping', 'dispatch:'.$doc->id.':'.$key, 1);
                Shipment::create(['document_id' => $doc->id, 'client_id' => $doc->client_id, 'warehouse_id' => $doc->warehouse_id, 'dispatch_key' => $doc->id.':'.auth()->id().':'.$key, 'tracking_number' => $doc->meta['tracking_number'], 'manifest_number' => $doc->meta['manifest_number'], 'items' => $shipmentItems, 'package' => collect($doc->meta)->only(['courier', 'packaging_id', 'actual_weight', 'recipient', 'address'])->all()]);
                TrackingEvent::create(['document_id' => $doc->id, 'client_id' => $doc->client_id, 'warehouse_id' => $doc->warehouse_id, 'status' => 'dispatched', 'description' => 'Serah terima kurir manual · Resi '.$doc->meta['tracking_number'].' · '.array_sum(array_column($shipmentItems, 'quantity')).' unit.', 'simulated' => false]);
                break;
            case 'return-picked':
                $this->state($doc, 'order', ['picked', 'picking', 'packing', 'packed', 'backorder', 'allocated']);
                foreach ($doc->lines as $l) {
                    foreach ($l->allocations as $a) {
                        if (! $a->picked) {
                            continue;
                        }$source = Balance::findOrFail($a->balance_id);
                        $stage = Balance::where('location_id', $this->stock->location($doc, 'staging')->id)->where('product_id', $source->product_id)->where('batch', $source->batch)->where('serial', $source->serial)->where('condition', 'sellable')->firstOrFail();
                        $this->stock->move($stage, $source->location, $a->picked, 'return_picked', $doc, $a->picked);
                        $a->update(['picked' => 0]);
                    }$l->update(['picked' => $l->dispatched, 'packed' => $l->dispatched]);
                }$doc->update(['status' => 'allocated']);
                break;
            case 'cancel':
                $this->state($doc, 'order', ['draft', 'allocated', 'backorder', 'picking', 'picked', 'packing', 'packed']);
                foreach ($doc->lines as $l) {
                    if ($l->picked > $l->dispatched) {
                        $this->stock->fail('Kembalikan barang picked sebelum pembatalan.');
                    }foreach ($l->allocations as $a) {
                        if ($a->quantity) {
                            $this->stock->change(Balance::findOrFail($a->balance_id), 0, -$a->quantity, 'cancel_release', $doc);
                        }$a->update(['quantity' => 0, 'picked' => 0]);
                    }$l->update(['allocated' => $l->dispatched]);
                }$doc->update(['status' => 'cancelled']);
                break;
            case 'inspect':
                $this->state($doc, 'return', ['draft', 'inspecting']);
                $this->line($line);
                $this->scan($line, $data);
                if ($qty > $line->quantity - $line->received) {
                    $this->stock->fail('Retur melebihi permintaan.');
                }
                $ref = Document::whereKey($doc->reference_id)->lockForUpdate()->firstOrFail();
                $allocations = Allocation::whereHas('documentLine', fn ($q) => $q->where('document_id', $ref->id))->whereHas('balance', fn ($q) => $q->where('product_id', $line->product_id)->where('batch', $line->batch)->where('serial', $line->serial))->orderBy('id')->lockForUpdate()->get();
                $remaining = $qty;
                foreach ($allocations as $a) {
                    $take = min($remaining, $a->dispatched - $a->returned);
                    if (! $take) {
                        continue;
                    }$a->increment('returned', $take);
                    $a->documentLine->increment('returned', $take);
                    $remaining -= $take;
                }if ($remaining) {
                    $this->stock->fail('Retur melebihi barang terkirim yang belum diretur.');
                }
                $condition = $data['condition'] ?? 'quarantine';
                $loc = $this->stock->location($doc, $condition === 'sellable' ? 'receiving' : $condition);
                $b = $this->stock->balance($doc, $line, $loc, $condition);
                $this->stock->change($b, $qty, 0, 'return_'.$condition, $doc);
                $line->increment('received', $qty);
                $doc->update(['status' => $doc->lines()->whereColumn('received', '<', 'quantity')->exists() ? 'inspecting' : 'completed']);
                $this->billing->charge($doc, 'return', 'return:'.$doc->id.':'.$key, $qty);
                break;
            case 'restock':
                $this->state($doc, 'return', ['inspecting', 'completed']);
                $this->line($line);
                if ($qty > $line->received - $line->putaway) {
                    $this->stock->fail('Restock melebihi jumlah retur.');
                }
                $source = $this->stock->balance($doc, $line, $this->stock->location($doc, 'receiving'));
                $target = Location::where('type', 'storage')->findOrFail($data['location_id']);
                $this->stock->move($source, $target, $qty, 'return_restock', $doc);
                $line->increment('putaway', $qty);
                break;
            case 'move':
                $this->state($doc, 'transfer', ['draft']);
                $this->line($line);
                $source = $this->stock->balance($doc, $line, Location::findOrFail($line->location_id));
                $target = Location::where('type', 'storage')->findOrFail($data['location_id']);
                if ($qty > $line->quantity - $line->received) {
                    $this->stock->fail('Mutasi melebihi dokumen.');
                }if ($source->available < $qty) {
                    $this->stock->fail('Tidak dapat memindah stok reserved.');
                }$this->stock->move($source, $target, $qty, 'move', $doc);
                $line->increment('received', $qty);
                if (! $doc->lines()->whereColumn('received', '<', 'quantity')->exists()) {
                    $doc->update(['status' => 'completed']);
                }break;
            case 'send':
                $this->state($doc, 'transfer', ['draft']);
                if (! $doc->destination_warehouse_id || $doc->destination_warehouse_id === $doc->warehouse_id) {
                    $this->stock->fail('Gudang tujuan transfer wajib berbeda.');
                }
                foreach ($doc->lines as $l) {
                    $source = $this->stock->balance($doc, $l, Location::where('type', 'storage')->findOrFail($l->location_id));
                    if ($source->available < $l->quantity) {
                        $this->stock->fail('Stok available tidak mencukupi transfer.');
                    }$this->stock->change($source, -$l->quantity, 0, 'transfer_send', $doc);
                    $transit = $this->stock->balance($doc, $l, $this->stock->location($doc, 'transit'), 'transit');
                    $this->stock->change($transit, $l->quantity, 0, 'transfer_in_transit', $doc);
                }$doc->update(['status' => 'in_transit']);
                break;
            case 'arrive':
                $this->state($doc, 'transfer', ['in_transit']);
                abort_unless(auth()->user()->inScope($doc->client_id, $doc->destination_warehouse_id), 403);
                $target = Location::where('warehouse_id', $doc->destination_warehouse_id)->where('type', 'storage')->whereKey($data['location_id'])->lockForUpdate()->firstOrFail();
                if (Balance::where('location_id', $target->id)->sum('quantity') + $doc->lines->sum('quantity') > $target->capacity) {
                    $this->stock->fail('Kapasitas lokasi tujuan tidak cukup.');
                }
                foreach ($doc->lines as $l) {
                    $transit = $this->stock->balance($doc, $l, $this->stock->location($doc, 'transit'), 'transit');
                    $this->stock->change($transit, -$l->quantity, 0, 'transfer_arrive_out', $doc);
                    $destination = clone $doc;
                    $destination->warehouse_id = $doc->destination_warehouse_id;
                    $b = $this->stock->balance($destination, $l, $target);
                    $this->stock->change($b, $l->quantity, 0, 'transfer_arrive_in', $doc);
                    $l->update(['received' => $l->quantity, 'putaway' => $l->quantity]);
                }$doc->update(['status' => 'completed']);
                break;
            case 'count':
                $this->state($doc, 'count', ['draft', 'counting']);
                $this->line($line);
                $b = $this->stock->balance($doc, $line, Location::findOrFail($line->location_id), $line->condition);
                $line->update(['counted' => $data['counted'], 'snapshot' => $b->quantity, 'cutoff_ledger_id' => LedgerEntry::where('balance_id', $b->id)->max('id') ?? 0]);
                $doc->update(['status' => 'counting']);
                break;
            case 'submit':
                if (! in_array($doc->type, ['count', 'adjustment']) || ! in_array($doc->status, ['draft', 'counting'])) {
                    $this->stock->fail('Dokumen tidak dapat diajukan.');
                }
                if ($doc->type === 'count' && $doc->lines()->whereNull('counted')->exists()) {
                    $this->stock->fail('Semua baris wajib dihitung.');
                }$doc->update(['status' => 'pending_approval']);
                break;
            case 'approve':
                if (! in_array($doc->type, ['count', 'adjustment']) || $doc->status !== 'pending_approval') {
                    $this->stock->fail('Dokumen tidak menunggu approval.');
                }if ($doc->created_by === auth()->id()) {
                    $this->stock->fail('Pembuat tidak boleh menyetujui pengajuannya sendiri.');
                }
                foreach ($doc->lines as $l) {
                    $b = $this->stock->balance($doc, $l, Location::findOrFail($l->location_id), $l->condition);
                    $delta = $doc->type === 'count' ? $l->counted - $l->snapshot : (($doc->meta['direction'] ?? 'add') === 'subtract' ? -$l->quantity : $l->quantity);
                    $this->stock->change($b, $delta, 0, 'approved_adjustment', $doc);
                }$doc->update(['status' => 'approved', 'approved_by' => auth()->id()]);
                break;
            case 'tracking':
                $this->state($doc, 'order', ['dispatched', 'partially_dispatched']);
                $event = (new LocalCourierSimulator)->track($doc);
                TrackingEvent::create($event + ['document_id' => $doc->id, 'client_id' => $doc->client_id, 'warehouse_id' => $doc->warehouse_id]);
                break;
            default: $this->stock->fail('Aksi tidak dikenal.');
        }
        Audit::record('document.'.$action,$doc->number,$doc->client_id,$doc->warehouse_id,['line_id' => $line?->id, 'quantity' => $qty]);

        return $doc->fresh()->load('lines.product','lines.allocations.balance.location','client','warehouse','attachments','tracking','shipments');
    }

    private function line(?DocumentLine $line): void
    {
        if (! $line) {
            $this->stock->fail('Pilih baris dokumen.');
        }
    }

    private function scan(DocumentLine $line,array $data): void
    {
        if (($data['scan'] ?? '') !== $line->product->barcode && ($data['scan'] ?? '') !== $line->product->sku) {
            $this->stock->fail('Barcode atau SKU tidak cocok.');
        }
    }

    private function state(Document $d,string $type,array $states): void
    {
        if ($d->type !== $type || ! in_array($d->status,$states)) {
            $this->stock->fail('Transisi status tidak valid: '.$d->status);
        }
    }
}
