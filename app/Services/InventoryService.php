<?php

namespace App\Services;

use App\Models\Balance;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\LedgerEntry;
use App\Models\Location;
use App\Models\Product;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function lockProduct(int $id): Product
    {
        return Product::whereKey($id)->lockForUpdate()->firstOrFail();
    }

    public function balance(Document $d, DocumentLine $line, Location $location, string $condition = 'sellable'): Balance
    {
        $this->lockProduct($line->product_id);
        if ($location->warehouse_id !== $d->warehouse_id) {
            $this->fail('Lokasi tidak berada pada gudang dokumen.');
        }

        return Balance::firstOrCreate(['location_id' => $location->id, 'product_id' => $line->product_id, 'batch' => $line->batch, 'serial' => $line->serial, 'condition' => $condition], ['client_id' => $d->client_id, 'warehouse_id' => $d->warehouse_id, 'expiry' => $line->expiry]);
    }

    public function change(Balance $balance, int $qty, int $reserved, string $event, ?Document $doc = null): Balance
    {
        $balance = Balance::whereKey($balance->id)->lockForUpdate()->firstOrFail();
        $next = $balance->quantity + $qty;
        $nextReserved = $balance->reserved + $reserved;
        if ($next < 0 || $nextReserved < 0 || $nextReserved > $next) {
            $this->fail('Stok tidak mencukupi atau reservasi tidak valid.');
        }
        $balance->update(['quantity' => $next, 'reserved' => $nextReserved]);
        LedgerEntry::create(['client_id' => $balance->client_id, 'warehouse_id' => $balance->warehouse_id, 'balance_id' => $balance->id, 'document_id' => $doc?->id, 'user_id' => auth()->id(), 'event' => $event, 'quantity_delta' => $qty, 'reserved_delta' => $reserved, 'quantity_after' => $next, 'reserved_after' => $nextReserved, 'created_at' => now()]);

        return $balance;
    }

    public function location(Document $d, string $type): Location
    {
        return Location::where('warehouse_id', $d->warehouse_id)->where('type', $type)->firstOrFail();
    }

    public function move(Balance $source, Location $target, int $qty, string $event, Document $doc, int $reserved = 0): Balance
    {
        $this->lockProduct($source->product_id);
        if ($source->warehouse_id !== $target->warehouse_id || $source->location_id === $target->id) {
            $this->fail('Lokasi tujuan mutasi tidak valid.');
        }
        $target = Location::whereKey($target->id)->lockForUpdate()->firstOrFail();
        if (Balance::where('location_id', $target->id)->sum('quantity') + $qty > $target->capacity) {
            $this->fail('Kapasitas lokasi tidak cukup.');
        }
        $to = Balance::firstOrCreate(['location_id' => $target->id, 'product_id' => $source->product_id, 'batch' => $source->batch, 'serial' => $source->serial, 'condition' => $source->condition], ['client_id' => $source->client_id, 'warehouse_id' => $target->warehouse_id, 'expiry' => $source->expiry]);
        $this->change($source, -$qty, -$reserved, $event.'_out', $doc);

        return $this->change($to, $qty, $reserved, $event.'_in', $doc);
    }

    public function fail(string $message): never
    {
        throw ValidationException::withMessages(['operation' => $message]);
    }
}
