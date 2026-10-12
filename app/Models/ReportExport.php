<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportExport extends Model
{
    protected $guarded = [];

    protected $hidden = ['path', 'client_scope', 'warehouse_scope'];

    protected function casts(): array
    {
        return ['filters' => 'array', 'client_scope' => 'array', 'warehouse_scope' => 'array'];
    }
}
