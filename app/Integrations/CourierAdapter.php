<?php

namespace App\Integrations;

use App\Models\Document;

interface CourierAdapter
{
    public function track(Document $document): array;
}
