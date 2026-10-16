<?php

namespace App\Http\Controllers;

use App\Models\Allocation;
use App\Models\Balance;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\LedgerEntry;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Traceability lookup: follow a serial number, batch/lot, SKU or barcode from
 * receiving through putaway, moves, picking, dispatch and returns.
 */
class TraceController extends Controller
{
    private const LIMIT = 300;

    public function show(Request $r)
    {
        $u = $r->user();
        abort_unless($u->allowed('inventory.read'), 403);
        $data = $r->validate(['q' => 'required|string|min:2|max:100', 'product_id' => 'nullable|integer', 'client_id' => 'nullable|integer', 'warehouse_id' => 'nullable|integer']);
        $term = trim($data['q']);

        $productIds = Product::visible($u)->where(fn ($p) => $p->where('sku', $term)->orWhere('barcode', $term))->pluck('id');
        $matchedBy = [];
        $balances = Balance::visible($u)
            ->where(fn ($b) => $b->where('serial', $term)->orWhere('batch', $term)->orWhereIn('product_id', $productIds))
            ->when($r->filled('product_id'), fn ($b) => $b->where('product_id', $data['product_id']))
            ->when($r->filled('client_id'), fn ($b) => $b->where('client_id', $data['client_id']))
            ->when($r->filled('warehouse_id'), fn ($b) => $b->where('warehouse_id', $data['warehouse_id']))
            ->with('product', 'location', 'warehouse', 'client')
            ->orderBy('product_id')->orderBy('batch')->orderBy('serial')
            ->limit(500)->get();
        foreach (['serial', 'batch'] as $field) {
            if ($balances->contains(fn ($b) => strcasecmp($b->{$field}, $term) === 0)) {
                $matchedBy[] = $field;
            }
        }
        if ($productIds->isNotEmpty()) {
            $matchedBy[] = 'product';
        }
        $balanceIds = $balances->pluck('id');

        $ledgerQuery = LedgerEntry::visible($u)->whereIn('balance_id', $balanceIds);
        $ledgerTotal = (clone $ledgerQuery)->count();
        $ledger = $ledgerQuery->with('balance.product', 'balance.location', 'balance.warehouse', 'document:id,number,type,status', 'user:id,name')
            ->latest('id')->limit(self::LIMIT)->get();

        // Documents touching the item: ledger references, allocations, and lines naming the serial/batch before any stock moved.
        $allocations = Allocation::whereIn('balance_id', $balanceIds)->with('documentLine:id,document_id,product_id')->get();
        $lineDocs = DocumentLine::where(fn ($l) => $l->where('serial', $term)->orWhere('batch', $term)->orWhereIn('product_id', $productIds))
            ->when($r->filled('product_id'), fn ($l) => $l->where('product_id', $data['product_id']))
            ->limit(500)->pluck('document_id');
        $documentIds = LedgerEntry::visible($u)->whereIn('balance_id', $balanceIds)->whereNotNull('document_id')->distinct()->pluck('document_id')
            ->merge($allocations->pluck('documentLine.document_id'))->merge($lineDocs)->filter()->unique();
        $documents = Document::visible($u)->whereIn('id', $documentIds)
            ->when($r->filled('client_id'), fn ($d) => $d->where('client_id', $data['client_id']))
            ->when($r->filled('warehouse_id'), fn ($d) => $d->where(fn ($w) => $w->where('warehouse_id', $data['warehouse_id'])->orWhere('destination_warehouse_id', $data['warehouse_id'])))
            ->with('shipments', 'lines', 'client:id,name', 'warehouse:id,name')->latest('id')->limit(self::LIMIT)->get();

        $byOrder = $allocations->groupBy(fn ($a) => $a->documentLine?->document_id);
        $traced = $balances->pluck('product_id')->merge($productIds)->unique();
        $docRows = $documents->map(fn (Document $d) => $this->document($d, $byOrder->get($d->id, collect()), $traced));

        $sum = fn (string $prefix, array $except = []) => (int) LedgerEntry::visible($u)->whereIn('balance_id', $balanceIds)
            ->where('event', 'like', $prefix.'%')->when($except, fn ($q) => $q->where(fn ($q) => collect($except)->each(fn ($e) => $q->where('event', 'not like', $e.'%'))))
            ->sum('quantity_delta');

        return ['data' => [
            'query' => $term,
            'matched_by' => $matchedBy,
            'products' => $balances->pluck('product')->merge(Product::whereIn('id', $productIds)->get())->filter()->unique('id')->values()->map(fn ($p) => ['id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'barcode' => $p->barcode, 'serial_tracked' => (bool) $p->serial_tracked]),
            'summary' => [
                'received' => $sum('receiving'),
                'on_hand' => (int) $balances->sum('quantity'),
                'reserved' => (int) $balances->sum('reserved'),
                'dispatched' => -$sum('dispatch'),
                'returned' => $sum('return_', ['return_picked', 'return_restock']),
                'locations' => $balances->where('quantity', '>', 0)->pluck('location_id')->unique()->count(),
            ],
            'positions' => $balances->map(fn (Balance $b) => [
                'id' => $b->id,
                'product' => ['id' => $b->product->id, 'sku' => $b->product->sku, 'name' => $b->product->name],
                'client' => $b->client?->name,
                'warehouse' => $b->warehouse?->name,
                'location' => $b->location?->code,
                'location_type' => $b->location?->type,
                'batch' => $b->batch,
                'serial' => $b->serial,
                'expiry' => $b->expiry,
                'condition' => $b->condition,
                'quantity' => $b->quantity,
                'reserved' => $b->reserved,
            ])->values(),
            'timeline' => $ledger->map(fn (LedgerEntry $e) => [
                'id' => $e->id,
                'created_at' => Carbon::parse($e->created_at, config('app.timezone'))->toIso8601String(),
                'event' => $e->event,
                'quantity_delta' => $e->quantity_delta,
                'reserved_delta' => $e->reserved_delta,
                'quantity_after' => $e->quantity_after,
                'sku' => $e->balance?->product?->sku,
                'batch' => $e->balance?->batch,
                'serial' => $e->balance?->serial,
                'condition' => $e->balance?->condition,
                'location' => $e->balance?->location?->code,
                'warehouse' => $e->balance?->warehouse?->name,
                'document' => $e->document ? $e->document->only('id', 'number', 'type', 'status') : null,
                'user' => $e->user?->name,
            ])->values(),
            'timeline_total' => $ledgerTotal,
            'documents' => $docRows->values(),
        ]];
    }

    private function document(Document $d, Collection $allocations, Collection $products): array
    {
        $meta = $d->meta ?? [];
        $lines = $d->lines->whereIn('product_id', $products);

        return [
            'id' => $d->id,
            'number' => $d->number,
            'type' => $d->type,
            'status' => $d->status,
            'client' => $d->client?->name,
            'warehouse' => $d->warehouse?->name,
            'created_at' => $d->created_at,
            'dispatched_at' => $d->dispatched_at,
            'recipient' => $meta['recipient'] ?? null,
            'address' => $meta['address'] ?? null,
            'courier' => $meta['courier'] ?? null,
            'tracking_number' => $meta['tracking_number'] ?? null,
            // Quantities of the traced product(s) on this document; allocations narrow shipped units to the traced batch/serial.
            'quantity' => (int) $lines->sum('quantity'),
            'received' => (int) $lines->sum('received'),
            'dispatched' => $allocations->isNotEmpty() ? (int) $allocations->sum('dispatched') : (int) $lines->sum('dispatched'),
            'returned' => $allocations->isNotEmpty() ? (int) $allocations->sum('returned') : (int) $lines->sum('returned'),
            'shipments' => $d->shipments->map(fn ($s) => ['tracking_number' => $s->tracking_number, 'created_at' => $s->created_at])->values(),
        ];
    }
}
