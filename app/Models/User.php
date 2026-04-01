<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;

    protected $fillable = [
        'prenom',
        'nom',
        'email',
        'password',
        'telephone',
        'is_active',
        'pays',
        'code_pays',
        'code_phone_pays',
        'ville',
        'adresse',
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
            'is_active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'user_sites')
            ->withPivot('role', 'is_default')
            ->withTimestamps();
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
        $resources = ['clients', 'prestataires', 'livreurs', 'proprietaires', 'vehicules', 'sites', 'produits', 'packings', 'ventes', 'achats', 'users', 'parametres'];
        $actions = ['create', 'read', 'update', 'delete'];

        $map = [];
        foreach ($resources as $resource) {
            foreach ($actions as $action) {
                $key = "{$resource}.{$action}";
                $map[$key] = $this->isSuperAdmin() || $this->can($key);
            }
        }

        return $map;
    }
}
