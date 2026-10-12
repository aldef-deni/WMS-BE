<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'type' => 'required|in:inbound,order,transfer,return,count,adjustment', 'client_id' => 'required|integer|exists:clients,id', 'warehouse_id' => 'required|integer|exists:warehouses,id',
            'destination_warehouse_id' => 'nullable|integer|exists:warehouses,id', 'reference_id' => 'nullable|integer|exists:documents,id', 'priority' => 'sometimes|in:normal,high,urgent', 'scheduled_at' => 'nullable|date', 'assigned_to' => 'nullable|integer|exists:users,id',
            'meta' => 'nullable|array:recipient,address,reason,direction,count_type', 'meta.recipient' => 'nullable|string|max:150', 'meta.address' => 'nullable|string|max:1000', 'meta.reason' => 'nullable|string|max:1000', 'meta.direction' => 'nullable|in:add,subtract', 'meta.count_type' => 'nullable|in:cycle,full',
            'lines' => 'required|array|min:1|max:200', 'lines.*' => 'array:product_id,quantity,batch,serial,expiry,location_id,condition', 'lines.*.condition' => 'sometimes|in:sellable,quarantine,damaged', 'lines.*.product_id' => 'required|integer|exists:products,id', 'lines.*.quantity' => 'required|integer|min:1|max:1000000', 'lines.*.batch' => 'nullable|string|max:80', 'lines.*.serial' => 'nullable|string|max:80', 'lines.*.expiry' => 'nullable|date', 'lines.*.location_id' => 'nullable|integer|exists:locations,id'];
    }
}
