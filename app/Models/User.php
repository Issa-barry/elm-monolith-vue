<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, HasUlids, Notifiable, TwoFactorAuthenticatable;

    /**
     * Statuts de cycle de vie du compte. Un compte créé via invitation démarre
     * toujours en pending_validation, quel que soit le rôle : il ne devient
     * "active" qu'après validation explicite par un admin (voir UserController).
     */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_PENDING_VALIDATION = 'pending_validation';

    public const STATUS_INACTIVE = 'inactive';

    // Compte d'accès pur — ni identité (nom/prenom, cf. Personne) ni identifiant de connexion
    // (telephone/email, cf. UserAuthIdentity) ne sont mass-assignables ici. Un User n'existe
    // jamais sans personne_id.
    protected $fillable = [
        'password',
        'is_active',
        'status',
        'organization_id',
        'personne_id',
        'matricule',
        'expo_push_token',
        'must_change_password',
    ];

    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    public function getNameAttribute(): string
    {
        return trim("{$this->prenom} {$this->nom}");
    }

    /** Proxy en lecture seule vers Personne — jamais de colonne nom/prenom sur users. */
    public function getNomAttribute(): ?string
    {
        return $this->personne?->nom;
    }

    public function getPrenomAttribute(): ?string
    {
        return $this->personne?->prenom;
    }

    public function getPaysAttribute(): ?string
    {
        return $this->personne?->pays;
    }

    public function getCodePaysAttribute(): ?string
    {
        return $this->personne?->code_pays;
    }

    public function getCodePhonePaysAttribute(): ?string
    {
        return $this->personne?->code_phone_pays;
    }

    public function getVilleAttribute(): ?string
    {
        return $this->personne?->ville;
    }

    public function getAdresseAttribute(): ?string
    {
        return $this->personne?->adresse;
    }

    /** Proxy en lecture seule vers l'identité de connexion correspondante. */
    public function getTelephoneAttribute(): ?string
    {
        return $this->telephoneIdentity()?->value;
    }

    public function getEmailAttribute(): ?string
    {
        return $this->emailIdentity()?->value;
    }

    public function telephoneIdentity(): ?UserAuthIdentity
    {
        return $this->authIdentities->firstWhere('type', UserAuthIdentity::TYPE_TELEPHONE);
    }

    public function emailIdentity(): ?UserAuthIdentity
    {
        return $this->authIdentities->firstWhere('type', UserAuthIdentity::TYPE_EMAIL);
    }

    /** Un email est facultatif : sans identité email du tout, on considère "rien à vérifier". */
    public function hasVerifiedEmail(): bool
    {
        $identity = $this->emailIdentity();

        return $identity === null || $identity->isVerified();
    }

    /** Utilisé par le PasswordBroker de Fortify (Features::resetPasswords()). */
    public function getEmailForPasswordReset(): ?string
    {
        return $this->emailIdentity()?->value;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function personne(): BelongsTo
    {
        return $this->belongsTo(Personne::class);
    }

    public function authIdentities(): HasMany
    {
        return $this->hasMany(UserAuthIdentity::class);
    }

    public function client(): HasOne
    {
        return $this->hasOne(Client::class);
    }

    public function livreur(): HasOne
    {
        return $this->hasOne(Livreur::class);
    }

    public function proprietaire(): HasOne
    {
        return $this->hasOne(Proprietaire::class);
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

    public function isAdmin(): bool
    {
        return $this->hasAnyRole(['super_admin', 'admin_entreprise']);
    }

    public function isPendingValidation(): bool
    {
        return $this->status === self::STATUS_PENDING_VALIDATION;
    }

    /**
     * Map de toutes les permissions CRUD pour Inertia/Vue.
     * Ex: ['clients.read' => true, 'clients.create' => false, ...]
     */
    public function permissionsMap(): array
    {
        $resources = [
            'clients', 'prestataires', 'livreurs', 'proprietaires', 'pieces-identite',
            'vehicules', 'type-vehicules', 'equipes-livraison', 'sites',
            'produits', 'categories', 'options', 'type-produits', 'packings', 'ventes', 'achats', 'fournisseurs', 'factures', 'commissions', 'cashback', 'pdv',
            'logistique', 'transferts', 'receptions',
            'depenses', 'comptabilite', 'journal-financier',
            'rh-employes', 'rh-contrats', 'rh-paie',
            'users',
            'parametres', 'parametres-produits', 'parametres-depenses', 'parametres-ventes', 'parametres-systeme', 'modules-metier',
        ];
        $actions = ['create', 'read', 'update', 'delete'];

        $map = [];
        foreach ($resources as $resource) {
            foreach ($actions as $action) {
                $key = "{$resource}.{$action}";
                $map[$key] = $this->isSuperAdmin() || $this->can($key);
            }
        }

        // Permissions standalone hors matrice CRUD
        $standalone = [
            'logistique.commission.verser', 'ventes.qte.update', 'ventes.prix.update',
            'rh-paie.validate', 'rh-paie.pay', 'rh-paie.close', 'comptabilite.payer',
            'depenses.soumettre', 'depenses.valider', 'depenses.rejeter', 'depenses.annuler',
            'produits.ajuster_stock',
            'ventes.confirmer', 'ventes.annuler', 'ventes.demarrer_chargement', 'ventes.valider_chargement',
            'factures.encaisser', 'factures.annuler',
            'commissions.payer', 'commissions.cloturer', 'commissions.exporter',
            'logistique.valider_chargement', 'logistique.valider_reception', 'logistique.cloturer',
            'pieces-identite.download', 'pieces-identite.valider', 'pieces-identite.rejeter',
            'imports-flotte.create', 'imports-flotte.read',
        ];
        foreach ($standalone as $perm) {
            $map[$perm] = $this->isSuperAdmin() || $this->can($perm);
        }

        return $map;
    }
}
