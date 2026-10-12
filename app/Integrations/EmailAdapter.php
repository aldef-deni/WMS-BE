<?php

namespace App\Integrations;

interface EmailAdapter
{
    public function simulate(string $recipient, string $subject, string $message): array;
}
