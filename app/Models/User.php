<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, Notifiable, TwoFactorAuthenticatable, HasRoles;

    protected $fillable = [
        'prenom',
        'nom',
        'email',
        'password',
        'telephone',
        'organization_id',
    ];

    public function getNameAttribute(): string
    {
        return trim("{$this->prenom} {$this->nom}");
    }

    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime:Y-m-d H:i:s',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    /**
     * Map de toutes les permissions CRUD pour Inertia/Vue.
     * Ex: ['clients.read' => true, 'clients.create' => false, ...]
     */
    public function permissionsMap(): array
    {
        $resources = ['clients', 'prestataires', 'livreurs', 'proprietaires', 'vehicules', 'sites', 'produits', 'packings', 'ventes', 'users', 'parametres'];
        $actions   = ['create', 'read', 'update', 'delete'];

        $map = [];
        foreach ($resources as $resource) {
            foreach ($actions as $action) {
                $key        = "{$resource}.{$action}";
                $map[$key]  = $this->isSuperAdmin() || $this->can($key);
            }
        }

        return $map;
    }
}
