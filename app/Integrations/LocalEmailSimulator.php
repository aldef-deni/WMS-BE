<?php

namespace App\Integrations;

use Illuminate\Support\Facades\Log;

class LocalEmailSimulator implements EmailAdapter
{
    public function simulate(string $recipient, string $subject, string $message): array
    {
        Log::info('EMAIL SIMULATOR LOKAL', ['recipient' => $recipient, 'subject' => $subject, 'body' => $message]);

        return ['simulated' => true, 'status' => 'logged', 'message' => 'Pesan simulator dicatat pada storage/logs/laravel.log. Tidak ada email dikirim.'];
    }
}
