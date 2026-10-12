<?php

namespace App\Services;

use App\Models\IdempotencyKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Idempotency
{
    public static function run(string $key, string $operation, array $input, callable $fn): array
    {
        return DB::transaction(function () use ($key, $operation, $input, $fn) {
            $hash = hash('sha256', json_encode($input));
            DB::table('idempotency_keys')->insertOrIgnore(['user_id' => auth()->id(), 'key' => $key, 'operation' => $operation, 'request_hash' => $hash, 'created_at' => now(), 'updated_at' => now()]);
            $row = IdempotencyKey::where('user_id', auth()->id())->where('key', $key)->lockForUpdate()->firstOrFail();
            if ($row->operation !== $operation || $row->request_hash !== $hash) {
                throw ValidationException::withMessages(['idempotency_key' => 'Kunci sudah digunakan untuk request berbeda.']);
            }
            if ($row->response !== null) {
                return $row->response;
            }
            $response = $fn();
            $row->update(['response' => $response]);

            return $response;
        }, 5);
    }
}
