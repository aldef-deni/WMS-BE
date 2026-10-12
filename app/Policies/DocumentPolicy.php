<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    public function view(User $u, Document $d): bool
    {
        return $u->allowed($d->type.'.read') && ($u->inScope($d->client_id, $d->warehouse_id) || ($d->type === 'transfer' && $d->destination_warehouse_id && $u->inScope($d->client_id, $d->destination_warehouse_id)));
    }

    public function update(User $u, Document $d, string $action): bool
    {
        $permissions = ['receive' => 'inbound.receive', 'putaway' => 'inbound.putaway', 'close-receiving' => 'inbound.receive', 'approve-receiving' => 'inventory.approve', 'reserve' => 'order.allocate', 'pick' => 'picking.execute', 'short-pick' => 'picking.execute', 'pack' => 'packing.execute', 'manifest' => 'shipping.execute', 'dispatch' => 'shipping.execute', 'cancel' => 'order.cancel', 'restock' => 'return.inspect', 'inspect' => 'return.inspect', 'submit' => 'inventory.count', 'count' => 'inventory.count', 'approve' => 'inventory.approve', 'move' => 'inventory.move', 'send' => 'inventory.move', 'arrive' => 'inventory.move', 'return-picked' => 'order.cancel', 'tracking' => 'shipping.execute'];

        return $u->inScope($d->client_id, $action === 'arrive' ? $d->destination_warehouse_id : $d->warehouse_id) && $u->allowed($permissions[$action] ?? ($d->type.'.write'));
    }
}
