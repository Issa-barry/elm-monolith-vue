<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, HasUlids, Notifiable, TwoFactorAuthenticatable;

    protected $fillable = [
        'prenom',
        'nom',
        'email',
        'password',
        'telephone',
        'is_active',
        'status',
        'email_verified_at',
        'email_verification_token',
        'email_verification_expires_at',
        'pays',
        'code_pays',
        'code_phone_pays',
        'ville',
        'adresse',
        'organization_id',
        'matricule',
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
            'email_verified_at'             => 'datetime:Y-m-d H:i:s',
            'email_verification_expires_at' => 'datetime',
            'password'                      => 'hashed',
            'two_factor_confirmed_at'       => 'datetime',
            'is_active'                     => 'boolean',
        ];
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'user_sites')
            ->using(UserSite::class)
            ->withPivot('role', 'is_default')
            ->withTimestamps();
    }

    /**
     * Indique si l'utilisateur est affecté à un site donné (via user_sites).
     */
    public function isAssignedToSite(string $siteId): bool
    {
        return $this->sites()->where('sites.id', $siteId)->exists();
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
        $resources = ['clients', 'prestataires', 'livreurs', 'proprietaires', 'vehicules', 'equipes-livraison', 'sites', 'produits', 'packings', 'ventes', 'achats', 'users', 'parametres', 'logistique', 'depenses', 'rh-employes', 'rh-contrats', 'rh-paie'];
        $actions = ['create', 'read', 'update', 'delete'];

        $map = [];
        foreach ($resources as $resource) {
            foreach ($actions as $action) {
                $key = "{$resource}.{$action}";
                $map[$key] = $this->isSuperAdmin() || $this->can($key);
            }
        }

        // Permissions standalone hors matrice CRUD
        $standalone = ['logistique.commission.verser', 'ventes.qte.update', 'ventes.prix.update', 'rh-paie.validate', 'rh-paie.pay', 'rh-paie.close'];
        foreach ($standalone as $perm) {
            $map[$perm] = $this->isSuperAdmin() || $this->can($perm);
        }

        return $map;
    }
}
