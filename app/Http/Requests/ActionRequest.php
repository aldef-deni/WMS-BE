<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $a = $this->route('action');
        $lines = ['receive', 'putaway', 'pick', 'short-pick', 'pack', 'inspect', 'restock', 'move', 'count'];
        $qty = ['receive', 'putaway', 'pick', 'short-pick', 'pack', 'inspect', 'restock', 'move'];

        return ['idempotency_key' => 'required|string|max:100', 'line_id' => [Rule::requiredIf(in_array($a, $lines)), 'integer'],
            'quantity' => [Rule::requiredIf(in_array($a, $qty)), 'integer', 'min:1', 'max:1000000'],
            'scan' => [Rule::requiredIf(in_array($a, ['receive', 'pick', 'pack', 'inspect', 'dispatch'])), 'string', 'max:150'],
            'location_id' => [Rule::requiredIf(in_array($a, ['putaway', 'restock', 'move', 'arrive'])), 'integer', 'exists:locations,id'],
            'location_code' => [Rule::requiredIf($a === 'pick'), 'string', 'max:100'],
            'condition' => 'sometimes|in:sellable,quarantine,damaged', 'reason' => [Rule::requiredIf(in_array($a, ['short-pick', 'close-receiving'])), 'string', 'max:1000'],
            'packaging_id' => [Rule::requiredIf($a === 'pack'), 'integer', Rule::exists('catalogs', 'id')->where('kind', 'packaging')],
            'actual_weight' => [Rule::requiredIf($a === 'pack'), 'numeric', 'min:0.001', 'max:100000'],
            'courier_id' => [Rule::requiredIf($a === 'manifest'), 'integer', Rule::exists('catalogs', 'id')->where('kind', 'courier')],
            'tracking_number' => [Rule::requiredIf($a === 'manifest'), 'string', 'max:100'], 'manifest_number' => [Rule::requiredIf($a === 'manifest'), 'string', 'max:100'],
            'counted' => [Rule::requiredIf($a === 'count'), 'integer', 'min:0', 'max:1000000']];
    }
}
