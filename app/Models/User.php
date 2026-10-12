<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens,HasFactory,Notifiable;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed'];
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }

    public function clients()
    {
        return $this->belongsToMany(Client::class);
    }

    public function warehouses()
    {
        return $this->belongsToMany(Warehouse::class, 'warehouse_user');
    }

    public function hasRole(string $name): bool
    {
        return $this->roles->contains('name', $name);
    }

    public function permissions(): array
    {
        return $this->roles->flatMap(fn ($r) => $r->permissions)->unique()->values()->all();
    }

    public function allowed(string $permission): bool
    {
        return $this->hasRole('Super Admin') || in_array($permission, $this->permissions(), true);
    }

    public function inScope(?int $client, ?int $warehouse = null): bool
    {
        return $this->hasRole('Super Admin') || (($client === null || $this->clients->contains('id', $client)) && ($warehouse === null || $this->warehouses->contains('id', $warehouse)));
    }
}
