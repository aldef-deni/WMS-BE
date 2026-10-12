<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('kind');
            $t->json('filters');
            $t->json('client_scope');
            $t->json('warehouse_scope');
            $t->string('status')->default('queued');
            $t->string('path')->nullable();
            $t->unsignedInteger('rows')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
