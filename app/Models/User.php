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
use Illuminate\Support\Carbon;
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

    // Ces accesseurs (proxy vers Personne/UserAuthIdentity, cf. plus bas) ne sont pas de vraies
    // colonnes : sans $appends, Eloquent les omet silencieusement de toute sérialisation
    // JSON/array (API, props Inertia partagées par HandleInertiaRequests...) — ce qui viderait
    // le nom affiché partout côté frontend sans lever la moindre erreur.
    protected $appends = [
        'name', 'nom', 'prenom', 'telephone', 'email',
        'pays', 'code_pays', 'code_phone_pays', 'ville', 'adresse',
        'email_verified_at',
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

    /** Proxy en lecture seule vers l'identité de connexion email — jamais une colonne sur users. */
    public function getEmailVerifiedAtAttribute(): ?Carbon
    {
        return $this->emailIdentity()?->verified_at;
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

    /**
     * Surcharge MustVerifyEmail::markEmailAsVerified() — la colonne email_verified_at
     * n'existe plus sur users, forceFill()->save() casserait la requête SQL. Écrit sur
     * l'UserAuthIdentity email à la place (cf. hasVerifiedEmail()/getEmailVerifiedAtAttribute()).
     */
    public function markEmailAsVerified(): bool
    {
        $identity = $this->emailIdentity();

        if (! $identity) {
            return false;
        }

        $result = $identity->update(['verified_at' => now()]);
        $this->unsetRelation('authIdentities');

        return $result;
    }

    /** Symétrique de markEmailAsVerified() — même raison de surcharge. */
    public function markEmailAsUnverified(): bool
    {
        $identity = $this->emailIdentity();

        if (! $identity) {
            return false;
        }

        $result = $identity->update(['verified_at' => null]);
        $this->unsetRelation('authIdentities');

        return $result;
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
     * Les 3 rôles strictement externes : le portail client (espace client, API
     * mobile/Nuxt). Source de vérité unique pour la distinction backoffice/espace
     * client — réutilisée par EnsureIsStaffAccount (garde d'accès backoffice) et
     * AuthRedirects (redirection post-connexion). Ne pas la dupliquer ailleurs.
     */
    private const EXTERNAL_ROLES = ['client', 'proprietaire', 'livreur'];

    /**
     * Un compte a accès au backoffice s'il porte au moins un rôle qui n'est PAS
     * strictement externe — qu'il s'agisse d'un rôle système historique
     * (super_admin, admin_entreprise, manager, commerciale, comptable) ou d'un
     * rôle personnalisé d'organisation créé via RoleController. Le cumul avec un
     * rôle externe est autorisé : un compte qui a AUSSI un rôle client/
     * proprietaire/livreur garde son accès backoffice tant qu'il conserve au moins
     * un rôle non-externe (ex: un admin qui possède lui-même un véhicule, ou
     * commande dans une boutique comme un client normal — décision du 26/08/2026).
     * Un compte n'ayant QUE des rôles externes (ou aucun rôle du tout) est refusé.
     */
    public function hasBackofficeAccess(): bool
    {
        return $this->getRoleNames()->diff(self::EXTERNAL_ROLES)->isNotEmpty();
    }

    /**
     * Un compte a accès à l'espace client s'il porte au moins un des 3 rôles
     * strictement externes — indépendamment d'un éventuel cumul avec un rôle
     * staff (cf. hasBackofficeAccess()). Les deux accès ne s'excluent jamais.
     */
    public function hasClientAccess(): bool
    {
        return $this->getRoleNames()->intersect(self::EXTERNAL_ROLES)->isNotEmpty();
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
            'imports-produits.create', 'imports-produits.read',
        ];
        foreach ($standalone as $perm) {
            $map[$perm] = $this->isSuperAdmin() || $this->can($perm);
        }

        return $map;
    }
}
