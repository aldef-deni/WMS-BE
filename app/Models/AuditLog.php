<?php

namespace App\Models;

use App\Models\Concerns\VisibleScope;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['client_id' => 'integer', 'warehouse_id' => 'integer', 'location_id' => 'integer', 'product_id' => 'integer', 'document_id' => 'integer', 'quantity' => 'integer', 'reserved' => 'integer', 'received' => 'integer', 'putaway' => 'integer', 'allocated' => 'integer', 'picked' => 'integer', 'packed' => 'integer', 'dispatched' => 'integer', 'returned' => 'integer', 'counted' => 'integer', 'snapshot' => 'integer', 'created_by' => 'integer', 'assigned_to' => 'integer', 'approved_by' => 'integer', 'destination_warehouse_id' => 'integer', 'reference_id' => 'integer', 'details' => 'array'];
    }

    use VisibleScope;

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
