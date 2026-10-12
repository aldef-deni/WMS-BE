<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class RecordResource extends JsonResource
{
    public function toArray($request): array
    {
        $data = $this->resource->toArray();
        if (($data['type'] ?? '') === 'count' && ! $request->user()?->allowed('inventory.approve')) {
            foreach ($data['lines'] ?? [] as $i => $line) {
                unset($data['lines'][$i]['snapshot'],$data['lines'][$i]['cutoff_ledger_id']);
            }
        }

return $data;
    }
}
