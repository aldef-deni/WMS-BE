<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

trait VisibleScope
{
    public function scopeVisible(Builder $q, User $user): Builder
    {
        if ($user->hasRole('Super Admin')) {
            return $q;
        }
        $table = $this->getTable();
        $q->whereIn($table.'.client_id', $user->clients()->pluck('clients.id'));
        if (Schema::hasColumn($table, 'warehouse_id')) {
            $warehouseIds = $user->warehouses()->pluck('warehouses.id');
            if ($table === 'documents') {
                $q->where(fn ($q) => $q->whereIn('warehouse_id', $warehouseIds)->orWhere(fn ($q) => $q->where('type', 'transfer')->whereIn('destination_warehouse_id', $warehouseIds)));
            } else {
                $q->whereIn($table.'.warehouse_id', $warehouseIds);
            }
        }

        return $q;
    }
}
