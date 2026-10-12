<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Allocation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['client_id' => 'integer', 'warehouse_id' => 'integer', 'location_id' => 'integer', 'product_id' => 'integer', 'document_id' => 'integer', 'quantity' => 'integer', 'reserved' => 'integer', 'received' => 'integer', 'putaway' => 'integer', 'allocated' => 'integer', 'picked' => 'integer', 'packed' => 'integer', 'dispatched' => 'integer', 'returned' => 'integer', 'counted' => 'integer', 'snapshot' => 'integer', 'created_by' => 'integer', 'assigned_to' => 'integer', 'approved_by' => 'integer', 'destination_warehouse_id' => 'integer', 'reference_id' => 'integer'];
    }

    public function balance()
    {
        return $this->belongsTo(Balance::class);
    }

    public function documentLine()
    {
        return $this->belongsTo(DocumentLine::class);
    }
}
