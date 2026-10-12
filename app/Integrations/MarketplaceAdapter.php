<?php

namespace App\Integrations;

interface MarketplaceAdapter
{
    public function orderPayload(array $input): array;
}
