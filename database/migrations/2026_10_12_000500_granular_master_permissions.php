<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        foreach (config('permissions.roles') as $name => $defaults) {
            $role = Role::where('name', $name)->first();
            if (! $role) {
                continue;
            }$existing = array_values(array_diff($role->permissions, ['master.write']));
            $master = array_values(array_filter($defaults, fn ($p) => in_array($p, ['clients.write', 'warehouses.write', 'locations.write', 'products.write', 'catalogs.write'])));
            $role->update(['permissions' => array_values(array_unique(array_merge($existing, $master)))]);
        }
    }

    public function down(): void {} // Permission evolution is intentionally not destructive.
};
