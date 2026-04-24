<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    protected $fillable = [
        'name',
        'email',
        'password',
        'dsco_username',
        'dsco_accessible_vehicle_ids',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'           => 'datetime',
            'password'                    => 'hashed',
            'dsco_accessible_vehicle_ids' => 'array',
        ];
    }

    public function dscoToken(): HasOne
    {
        return $this->hasOne(DscoUserToken::class);
    }

    /** Local Vehicle models this user has access to, based on DSCO vehicle IDs. */
    public function accessibleVehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'dsco_vehicle_id', 'id')
            ->whereIn('dsco_vehicle_id', $this->dsco_accessible_vehicle_ids ?? []);
    }

    /** Returns a query builder scoped to the vehicles this user can see. */
    public function vehicleQuery()
    {
        return Vehicle::whereIn('dsco_vehicle_id', $this->dsco_accessible_vehicle_ids ?? []);
    }
}
