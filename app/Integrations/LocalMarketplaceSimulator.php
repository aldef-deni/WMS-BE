<?php

namespace App\Integrations;

class LocalMarketplaceSimulator implements MarketplaceAdapter
{
    public function orderPayload(array $input): array
    {
        return ['type' => 'order', 'client_id' => $input['client_id'], 'warehouse_id' => $input['warehouse_id'], 'meta' => ['recipient' => 'Pembeli Simulator Lokal', 'address' => 'Jakarta - ALAMAT DEMO, bukan transaksi marketplace live'], 'lines' => [['product_id' => $input['product_id'], 'quantity' => $input['quantity']]]];
    }
}
