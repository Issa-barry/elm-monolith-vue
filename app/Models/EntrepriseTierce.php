<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Identité civile d'une personne morale externe (fournisseur, prestataire...) au sein d'une
 * organisation — pendant de Personne côté entreprise. Une même société peut jouer plusieurs
 * rôles métier (Fournisseur, Prestataire...) sans jamais dupliquer raison sociale/téléphone/
 * email/adresse : chaque rôle référence son EntrepriseTierce via entreprise_tierce_id plutôt que
 * de recopier son identité.
 */
class EntrepriseTierce extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    // Accord grammatical français ("entreprises tierces") incompatible avec la pluralisation
    // naïve d'Eloquent (qui donnerait "entreprise_tierces") — nom de table explicite.
    protected $table = 'entreprises_tierces';

    protected $fillable = [
        'organization_id', 'raison_sociale', 'nom_commercial', 'identifiant_fiscal', 'rccm',
        'telephone', 'telephone_normalise', 'telephone_secondaire', 'email',
        'pays', 'code_pays', 'code_phone_pays', 'ville', 'quartier', 'adresse', 'latitude', 'longitude',
        'contact_personne_id', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    /**
     * La fiche Entreprise fait foi pour la raison sociale (ADR 0060, lot 5, D5) : quel que soit
     * l'écran qui la modifie (Fournisseur, Prestataire, Propriétaire), la copie portée par les
     * propriétaires reliés suit dans la même requête SQL. Les écrans qui modifient une entreprise le
     * font dans une transaction, pour que les deux écritures réussissent ou échouent ensemble.
     */
    protected static function booted(): void
    {
        static::updated(function (EntrepriseTierce $entreprise) {
            if ($entreprise->wasChanged('raison_sociale')) {
                Proprietaire::withTrashed()
                    ->where('entreprise_tierce_id', $entreprise->id)
                    ->update(['raison_sociale' => $entreprise->raison_sociale]);
                // Lot 7b (L7-2) : la raison sociale est le nom principal du client professionnel.
                Client::withTrashed()
                    ->where('entreprise_tierce_id', $entreprise->id)
                    ->update(['nom_complet' => $entreprise->raison_sociale]);
            }
        });
    }

    // ── Mutateurs ─────────────────────────────────────────────────────────────

    public function setEmailAttribute(mixed $value): void
    {
        $v = $value !== null ? trim((string) $value) : null;
        $this->attributes['email'] = $v !== null && $v !== '' ? strtolower($v) : null;
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function contactPersonne(): BelongsTo
    {
        return $this->belongsTo(Personne::class, 'contact_personne_id');
    }

    public function fournisseurs(): HasMany
    {
        return $this->hasMany(Fournisseur::class);
    }

    public function prestataires(): HasMany
    {
        return $this->hasMany(Prestataire::class);
    }

    /** Au plus un, garanti par App\Services\Contacts\ProprietaireEntreprise (ADR 0060, lot 5). */
    public function proprietaires(): HasMany
    {
        return $this->hasMany(Proprietaire::class);
    }

    /** Au plus un, garanti par App\Services\Contacts\ProprietaireEntreprise (ADR 0060, lot 7b). */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /** Catégories libres du référentiel Contacts (ADR 0060, lot 6a), sans effet métier. */
    public function categoriesLibres(): BelongsToMany
    {
        return $this->belongsToMany(CategorieLibre::class, 'entreprise_contact_categories', 'entreprise_tierce_id', 'contact_categorie_id')
            ->using(CategorieLibreAttribution::class)
            ->withPivot(['id', 'created_by'])
            ->withTimestamps();
    }

    public function interlocuteurs(): HasMany
    {
        return $this->hasMany(EntrepriseInterlocuteur::class);
    }

    /** Au plus un, garanti par App\Services\Contacts\InterlocuteurService (ADR 0060). */
    public function interlocuteurPrincipal(): HasOne
    {
        return $this->hasOne(EntrepriseInterlocuteur::class)->where('est_principal', true)->where('is_active', true);
    }

    /**
     * Cherche une EntrepriseTierce existante dans l'organisation par téléphone normalisé, sinon
     * en crée une nouvelle — même principe que Personne::resoudreOuCreer() : le téléphone
     * normalisé est la SEULE clé de rapprochement, volontairement stricte. Permet à une même
     * société d'être à la fois Fournisseur et Prestataire sans dupliquer son identité.
     *
     * @param  array{raison_sociale: string, identifiant_fiscal?: ?string, telephone?: ?string, email?: ?string, pays?: ?string, code_pays?: ?string, code_phone_pays?: ?string, ville?: ?string, adresse?: ?string}  $attributs
     */
    public static function resoudreOuCreer(string $organizationId, array $attributs): self
    {
        $telephoneNormalise = ! empty($attributs['telephone'])
            ? Personne::normaliserTelephone($attributs['telephone'])
            : null;

        if ($telephoneNormalise) {
            $existante = static::where('organization_id', $organizationId)
                ->where('telephone_normalise', $telephoneNormalise)
                ->first();

            if ($existante) {
                return $existante;
            }
        }

        return static::create([
            'organization_id' => $organizationId,
            'telephone_normalise' => $telephoneNormalise,
            ...$attributs,
        ]);
    }
}
