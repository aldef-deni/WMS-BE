<?php

namespace App\Integrations;

use App\Models\Document;

class LocalCourierSimulator implements CourierAdapter
{
    public function track(Document $document): array
    {
        return ['status' => 'in_transit', 'description' => 'SIMULATOR LOKAL: paket berada di hub tujuan; bukan tracking kurir live.', 'simulated' => true];
    }
}
