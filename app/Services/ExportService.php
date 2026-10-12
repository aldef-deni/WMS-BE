<?php

namespace App\Services;

use App\Models\Balance;
use App\Models\Charge;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class ExportService
{
    public static function permission(string $kind): string
    {
        return match ($kind) {
            'inventory','ledger' => 'inventory.read','inbound' => 'inbound.read','orders' => 'order.read','charges','invoices' => 'billing.read',default => 'none'
        };
    }

    public static function query(User $user, string $kind, array $filters = [])
    {
        abort_unless($user->allowed('reports.export') && $user->allowed(self::permission($kind)), 403);
        $q = match ($kind) {
            'inventory' => Balance::visible($user)->with('product', 'location'),'ledger' => LedgerEntry::visible($user),'inbound' => Document::visible($user)->where('type', 'inbound'),'orders' => Document::visible($user)->where('type', 'order'),'charges' => Charge::visible($user),'invoices' => Invoice::visible($user),default => abort(422)
        };
        foreach (['client_id', 'warehouse_id'] as $filter) {
            if (isset($filters[$filter]) && Schema::hasColumn($q->getModel()->getTable(), $filter)) {
                $q->where($filter, $filters[$filter]);
            }
        }
        if ($kind === 'invoices' && ! $user->hasRole('Super Admin')) {
            $q->whereDoesntHave('charges', fn ($q) => $q->whereNotIn('warehouse_id', $user->warehouses->pluck('id')));
        }

return $q;
    }
}
