<?php

namespace App\Models;

use App\Models\Concerns\VisibleScope;
use Illuminate\Database\Eloquent\Model;

class Shipment extends Model
{
    use VisibleScope;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['items' => 'array', 'package' => 'array'];
    }
}
