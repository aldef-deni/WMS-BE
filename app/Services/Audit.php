<?php

namespace App\Services;

use App\Models\AuditLog;

class Audit
{
    public static function record(string $action, string $subject, ?int $client = null, ?int $warehouse = null, array $details = []): void
    {
        AuditLog::create(['user_id' => auth()->id(), 'client_id' => $client, 'warehouse_id' => $warehouse, 'action' => $action, 'subject' => $subject, 'details' => array_diff_key($details, array_flip(['password', 'token', 'secret'])), 'created_at' => now()]);
    }
}
