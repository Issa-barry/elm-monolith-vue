<?php

namespace App\Models;

use App\Enums\DomaineActivite;
use App\Services\Comptabilite\PlanComptableBootstrapService;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Organization extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = ['name', 'slug', 'code', 'siret', 'logo_path', 'is_active', 'domaine_activite'];

    protected $appends = ['logo_url'];

    protected function casts(): array
    {
        return [
            'domaine_activite' => DomaineActivite::class,
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Organization $org) {
            if (empty($org->code)) {
                $org->code = self::generateCode($org->name);
            }
        });

        // Provisionne le plan comptable minimal dès la création de l'organisation —
        // sans ça, toute tentative de comptabilisation ultérieure (dépense validée,
        // fiche validée, vente facturée...) échoue faute de compte/journal/mapping.
        // Idempotent (PlanComptableBootstrapService::bootstrap() ne fait que des
        // firstOrCreate) et non bloquant (mode shadow, même principe que le reste du
        // module comptable) : ne doit jamais empêcher la création d'une organisation.
        // Pour les organisations déjà existantes avant l'introduction de ce hook, voir
        // `php artisan comptabilite:bootstrap` / `comptabilite:rattraper`.
        static::created(function (Organization $org) {
            try {
                app(PlanComptableBootstrapService::class)->bootstrap($org->id);
            } catch (\Throwable $e) {
                Log::error('Bootstrap comptable automatique échoué', [
                    'organization_id' => $org->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo_path ? '/storage/'.$this->logo_path : null;
    }

    /**
     * Génère un code court ("trinôme") identifiant l'organisation avant
     * connexion (utilisé dans l'URL /login/{code} pour afficher le bon
     * logo/nom). Basé sur les initiales du nom (ex: "Eau la maman" → ELM,
     * "Fello Demo" → FDL), complété si besoin par les lettres suivantes du
     * premier mot, et rendu unique par un suffixe numérique en cas de
     * collision. Reste modifiable ensuite par un admin (voir OrganisationController).
     */
    public static function generateCode(string $name): string
    {
        $words = preg_split('/\s+/', trim($name)) ?: [];
        $letters = '';

        foreach ($words as $word) {
            $word = preg_replace('/[^A-Za-z]/', '', $word) ?? '';
            if ($word !== '') {
                $letters .= mb_strtoupper(mb_substr($word, 0, 1));
            }
            if (mb_strlen($letters) >= 3) {
                break;
            }
        }

        if (mb_strlen($letters) < 3) {
            $base = preg_replace('/[^A-Za-z]/', '', $words[0] ?? '') ?? '';
            for ($i = mb_strlen($letters); mb_strlen($letters) < 3 && $i < mb_strlen($base); $i++) {
                $letters .= mb_strtoupper(mb_substr($base, $i, 1));
            }
        }

        $letters = mb_substr(str_pad($letters, 3, 'X'), 0, 3);

        $code = $letters;
        $suffix = 1;
        while (self::where('code', $code)->exists()) {
            $code = $letters.$suffix;
            $suffix++;
        }

        return $code;
    }

    /**
     * Prochaine référence produit (ProduitVariante.sku) de cette organisation — un entier
     * séquentiel, style numéro d'article IKEA : stable une fois attribué, n'encode ni le nom,
     * ni la catégorie, ni le prix, ni la date de création. Le compteur vit sur l'organisation
     * (pas une table globale) pour que chaque tenant ait sa propre numérotation, cohérente avec
     * l'unicité ['organization_id', 'sku'] de produit_variantes — deux organisations peuvent
     * avoir la référence "100001" sans collision.
     *
     * `lockForUpdate` sérialise les créations concurrentes de variantes pour la même
     * organisation (verrou levé à la fin de la transaction appelante, cf.
     * ProduitService::creer) : deux créations simultanées ne peuvent jamais se voir attribuer
     * la même référence.
     */
    public static function prochaineReferenceProduit(string $organizationId): string
    {
        return DB::transaction(function () use ($organizationId) {
            $organization = self::whereKey($organizationId)->lockForUpdate()->firstOrFail();
            $reference = (string) $organization->next_produit_reference;
            $organization->increment('next_produit_reference');

            return $reference;
        });
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function prestataires(): HasMany
    {
        return $this->hasMany(Prestataire::class);
    }

    public function livreurs(): HasMany
    {
        return $this->hasMany(Livreur::class);
    }

    public function proprietaires(): HasMany
    {
        return $this->hasMany(Proprietaire::class);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }
}
