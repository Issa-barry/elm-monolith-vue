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
        'is_siege_principal',
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
            'is_siege_principal' => 'boolean',
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

            // Auto-désignation du siège principal (cf. SiegeResolverService) : seulement
            // quand c'est le TOUT PREMIER site de type siège de l'organisation — jamais un
            // ->first() arbitraire sur une liste déjà ambiguë. Un deuxième site "siège" créé
            // ensuite reste explicitement non-principal tant qu'un admin ne le désigne pas
            // via SiegeResolverService::assignerPrincipal().
            if ($site->type === SiteType::SIEGE && empty($site->is_siege_principal)) {
                $aDejaUnPrincipal = static::where('organization_id', $site->organization_id)
                    ->where('type', SiteType::SIEGE->value)
                    ->where('is_siege_principal', true)
                    ->exists();
                if (! $aDejaUnPrincipal) {
                    $site->is_siege_principal = true;
                }
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
     */
    public function getLabelAttribute(): string
    {
        $nom = trim((string) $this->nom);
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

    public function isSiege(): bool
    {
        return $this->type === SiteType::SIEGE;
    }

    public function isActive(): bool
    {
        return $this->statut === SiteStatut::ACTIVE;
    }
}
