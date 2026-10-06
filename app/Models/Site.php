<?php

namespace App\Models;

use App\Enums\SiteStatut;
use App\Enums\SiteType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Site extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'nom',
        'code',
        'type',
        'statut',
        'is_central_tresorerie',
        'approbation_reception_logistique_obligatoire',
        'commissions_active',
        'localisation',
        'pays',
        'ville',
        'quartier',
        'description',
        'parent_id',
        'latitude',
        'longitude',
        'telephone',
        'email',
    ];

    protected function casts(): array
    {
        return [
            'type' => SiteType::class,
            'statut' => SiteStatut::class,
            'is_central_tresorerie' => 'boolean',
            'approbation_reception_logistique_obligatoire' => 'boolean',
            'commissions_active' => 'boolean',
        ];
    }

    protected $appends = ['type_label', 'statut_label', 'label'];

    // ── Boot ──────────────────────────────────────────────────────────────────

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Site $site) {
            if (empty($site->statut)) {
                $site->statut = SiteStatut::ACTIVE;
            }
            if (empty($site->code)) {
                $orgId = $site->organization_id;
                $num = static::withTrashed()->where('organization_id', $orgId)->count() + 1;
                do {
                    $code = str_pad((string) $num, 3, '0', STR_PAD_LEFT);
                    $num++;
                } while (static::withTrashed()->where('organization_id', $orgId)->where('code', $code)->exists());
                $site->code = $code;
            }
        });

        // Un seul site central de trésorerie par organisation (ADR 0017) : en désigner un retire
        // le rôle à l'ancien, dans la même écriture — jamais deux centraux simultanés, quel que
        // soit le chemin (SiteCentralTresorerieResolver::designer(), installation, seeders).
        static::saving(function (Site $site) {
            if ($site->is_central_tresorerie && $site->isDirty('is_central_tresorerie')) {
                static::where('organization_id', $site->organization_id)
                    ->when($site->exists, fn ($q) => $q->whereKeyNot($site->getKey()))
                    ->where('is_central_tresorerie', true)
                    ->update(['is_central_tresorerie' => false]);
            }
        });
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getTypeLabelAttribute(): string
    {
        return $this->type instanceof SiteType
            ? $this->type->label()
            : '';
    }

    public function getStatutLabelAttribute(): string
    {
        return $this->statut instanceof SiteStatut
            ? $this->statut->label()
            : '';
    }

    /**
     * Libellé d'affichage complet — "{Type} de {Nom}" (ex: "Boutique de Matoto") — SOURCE UNIQUE
     * utilisée partout où un site doit être affiché avec son type (UserInfo.vue, HeaderWidget.vue
     * via HandleInertiaRequests::defaultSite()) : garantit le même rendu sur tous les écrans sans
     * dupliquer cette concaténation côté frontend.
     *
     * Les sites nommés automatiquement à l'onboarding (cf. SiteNamingService::generateName()) ont
     * déjà un `nom` auto-descriptif ("Usine de Matoto") — le préfixer à nouveau donnerait "Usine
     * de Usine de Matoto". On détecte ce cas (nom commençant déjà par "{préfixe} de ") pour
     * afficher `nom` tel quel ; les sites nommés manuellement (nom = simple libellé court, ex:
     * "Matoto", cf. SitesSeeder) restent préfixés comme avant.
     *
     * Type « Autre » : `nom` seul — « Autre de Matoto » ne décrit rien (c'est notamment le type
     * des anciens sièges reclassés par la migration de l'ADR 0017 en attendant leur vrai type).
     */
    public function getLabelAttribute(): string
    {
        $nom = trim((string) $this->nom);

        if ($this->type === SiteType::AUTRE) {
            return $nom;
        }

        $prefixe = explode(' / ', $this->type_label)[0];

        if (Str::startsWith(mb_strtolower($nom), mb_strtolower($prefixe).' de ')) {
            return $nom;
        }

        return "{$prefixe} de {$nom}";
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'parent_id');
    }

    public function enfants(): HasMany
    {
        return $this->hasMany(Site::class, 'parent_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_sites')
            ->withPivot('role', 'is_default')
            ->withTimestamps();
    }

    public function userSites(): HasMany
    {
        return $this->hasMany(UserSite::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(UserInvitation::class);
    }

    public function vehicules(): HasMany
    {
        return $this->hasMany(Vehicule::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActives($query)
    {
        return $query->where('statut', SiteStatut::ACTIVE->value);
    }

    public function scopeDuType($query, string $type)
    {
        return $query->where('type', $type);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isCentralTresorerie(): bool
    {
        return (bool) $this->is_central_tresorerie;
    }

    public function isActive(): bool
    {
        return $this->statut === SiteStatut::ACTIVE;
    }

    /**
     * Garde-fou métier indépendant du statut opérationnel : quand `false`, la cible de commission
     * `CommissionCibleType::CODE_SITE` n'est plus générée pour ce site (cf.
     * CommissionEnveloppeGenerator::genererDepuisContexte(), docs/commissions.md COMM-013).
     * N'affecte JAMAIS les autres bénéficiaires (équipe de livraison, propriétaire, consultant) —
     * décision produit du 13/09/2026, scope volontairement limité à la seule part du site.
     */
    public function commissionsActives(): bool
    {
        return (bool) $this->commissions_active;
    }

    /**
     * Réglage effectif d'approbation admin de la réception logistique pour CE site — dérogation
     * du site si explicitement configurée (true/false), sinon repli sur le réglage organisation
     * (cf. Parametre::isApprobationReceptionLogistiqueObligatoire()). Même principe que
     * SolvabiliteService::resoudrePlafondVehicule()/resoudrePlafondClient(), sans second flag
     * "dérogation activée" : `null` sur la colonne suffit à distinguer "pas configuré" de
     * true/false, contrairement à un seuil entier (0 y serait ambigu).
     */
    public function approbationReceptionObligatoireEffective(): bool
    {
        return $this->approbation_reception_logistique_obligatoire
            ?? Parametre::isApprobationReceptionLogistiqueObligatoire($this->organization_id);
    }
}
