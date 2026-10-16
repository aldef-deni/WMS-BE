<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('phone', 30)->nullable()->after('email');
            $t->string('job_title', 100)->nullable()->after('phone');
            $t->string('avatar_path')->nullable()->after('job_title');
            $t->timestamp('last_login_at')->nullable()->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['phone', 'job_title', 'avatar_path', 'last_login_at']);
        });
    }
};
