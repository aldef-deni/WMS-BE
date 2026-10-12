<?php

namespace App\Models;

use App\Models\Concerns\VisibleScope;
use Illuminate\Database\Eloquent\Model;

class Balance extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['client_id' => 'integer', 'warehouse_id' => 'integer', 'location_id' => 'integer', 'product_id' => 'integer', 'document_id' => 'integer', 'quantity' => 'integer', 'reserved' => 'integer', 'received' => 'integer', 'putaway' => 'integer', 'allocated' => 'integer', 'picked' => 'integer', 'packed' => 'integer', 'dispatched' => 'integer', 'returned' => 'integer', 'counted' => 'integer', 'snapshot' => 'integer', 'created_by' => 'integer', 'assigned_to' => 'integer', 'approved_by' => 'integer', 'destination_warehouse_id' => 'integer', 'reference_id' => 'integer'];
    }

    use VisibleScope;

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    protected $appends = ['available'];

    public function scopeAllocatable($q)
    {
        return $q->where('condition', 'sellable')->whereHas('location', fn ($q) => $q->where('type', 'storage'))->where(fn ($q) => $q->whereNull('expiry')->orWhere('expiry', '>=', now('Asia/Jakarta')->toDateString()));
    }

    public function getAvailableAttribute()
    {
        return $this->condition === 'sellable' && $this->location?->type === 'storage' && (! $this->expiry || $this->expiry >= now('Asia/Jakarta')->toDateString()) ? $this->quantity - $this->reserved : 0;
    }
}
