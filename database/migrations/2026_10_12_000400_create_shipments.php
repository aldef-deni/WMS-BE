<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained()->restrictOnDelete();
            $t->foreignId('client_id')->constrained()->restrictOnDelete();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->string('dispatch_key')->unique();
            $t->string('tracking_number');
            $t->string('manifest_number');
            $t->json('items');
            $t->json('package');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
