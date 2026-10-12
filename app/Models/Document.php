<?php

namespace App\Models;

use App\Models\Concerns\VisibleScope;
use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['client_id' => 'integer', 'warehouse_id' => 'integer', 'location_id' => 'integer', 'product_id' => 'integer', 'document_id' => 'integer', 'quantity' => 'integer', 'reserved' => 'integer', 'received' => 'integer', 'putaway' => 'integer', 'allocated' => 'integer', 'picked' => 'integer', 'packed' => 'integer', 'dispatched' => 'integer', 'returned' => 'integer', 'counted' => 'integer', 'snapshot' => 'integer', 'created_by' => 'integer', 'assigned_to' => 'integer', 'approved_by' => 'integer', 'destination_warehouse_id' => 'integer', 'reference_id' => 'integer', 'meta' => 'array', 'scheduled_at' => 'datetime', 'dispatched_at' => 'datetime'];
    }

    use VisibleScope;

    public function lines()
    {
        return $this->hasMany(DocumentLine::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function attachments()
    {
        return $this->hasMany(Attachment::class);
    }

    public function tracking()
    {
        return $this->hasMany(TrackingEvent::class);
    }

    public function shipments()
    {
        return $this->hasMany(Shipment::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
