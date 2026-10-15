<?php

use App\Models\Role;
use App\Models\User;
use App\Services\DemoReset;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_baselines', function (Blueprint $t) {
            $t->id();
            $t->longText('payload');
            $t->dateTime('reset_due_at')->nullable();
            $t->dateTime('last_reset_at')->nullable();
            $t->timestamps();
        });
        // Test schema is seeded by the test suite itself.
        if (app()->environment('testing')) {
            return;
        }
        $role = Role::firstOrCreate(['name' => 'Super Admin'], ['permissions' => config('permissions.roles')['Super Admin']]);
        $demo = User::firstOrCreate(['email' => DemoReset::EMAIL], ['name' => 'Demo Super Admin', 'password' => DemoReset::PASSWORD]);
        $demo->roles()->syncWithoutDetaching([$role->id]);
        // Existing data (including the current transaction) becomes the default demo data.
        app(DemoReset::class)->capture();
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_baselines');
    }
};
