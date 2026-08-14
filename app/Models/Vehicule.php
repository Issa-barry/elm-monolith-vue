<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicule extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'site_id',
        'nom_vehicule',
        'marque',
        'modele',
        'immatriculation',
        'type_vehicule_id',
        'capacite_packs',
        'capacite_bouteilles',
        'proprietaire_id',
        'livraison_vente',
        'livraison_logistique',
        'photo_path',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'livraison_vente' => 'boolean',
            'livraison_logistique' => 'boolean',
            'capacite_packs' => 'integer',
            'capacite_bouteilles' => 'integer',
        ];
    }

    protected $appends = ['photo_url', 'type_label', 'usage_label'];

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path ? '/storage/'.$this->photo_path : null;
    }

    public function getTypeLabelAttribute(): string
    {
        return $this->relationLoaded('typeVehicule') && $this->typeVehicule
            ? $this->typeVehicule->nom
            : '';
    }

    /**
     * Libellé d'usage affiché dans la gestion des véhicules (Index/Show) — dérivé des deux
     * booléens plutôt que stocké, pour une seule source de vérité. "Usage non défini" est
     * le cas d'un véhicule créé par import flotte sans qu'aucun usage n'ait été saisi dans
     * le fichier (cf. ImportFlotteParser) — jamais possible via le formulaire manuel, qui
     * exige toujours au moins un usage (cf. VehiculeController::ensureAuMoinsUnUsage()).
     */
    public function getUsageLabelAttribute(): string
    {
        return match (true) {
            $this->livraison_vente && $this->livraison_logistique => 'Vente + Logistique',
            $this->livraison_vente => 'Vente',
            $this->livraison_logistique => 'Logistique',
            default => 'Usage non défini',
        };
    }

    /**
     * Un véhicule sans aucun usage n'a pas sa place dans les opérations métier — déjà
     * garanti par scopeLivraisonVente()/scopeLivraisonLogistique() ci-dessous (qui
     * l'excluent tous les deux), cette méthode ne fait que centraliser la lecture de cet
     * état pour l'UI et les contrôles applicatifs.
     */
    public function aAuMoinsUnUsage(): bool
    {
        return $this->livraison_vente || $this->livraison_logistique;
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function typeVehicule(): BelongsTo
    {
        return $this->belongsTo(TypeVehicule::class, 'type_vehicule_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function proprietaire(): BelongsTo
    {
        return $this->belongsTo(Proprietaire::class);
    }

    public function equipe(): HasOne
    {
        return $this->hasOne(EquipeLivraison::class, 'vehicule_id');
    }

    public function frais(): HasMany
    {
        return $this->hasMany(VehiculeFrais::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(CommissionLogistique::class);
    }

    public function capacites(): HasMany
    {
        return $this->hasMany(VehiculeCapacite::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * Véhicules sélectionnables pour une vente/PDV — remplace l'ancien filtre
     * `categorie = 'externe'` (cf. CommandeVenteController, PdvController).
     */
    public function scopeLivraisonVente($query)
    {
        return $query->where('livraison_vente', true);
    }

    /**
     * Véhicules sélectionnables pour un transfert logistique — remplace l'ancien filtre
     * `categorie = 'interne'` (cf. TransfertLogistiqueController).
     */
    public function scopeLivraisonLogistique($query)
    {
        return $query->where('livraison_logistique', true);
    }

    // ── Métier ────────────────────────────────────────────────────────────────

    /**
     * Somme taux propriétaire + taux membres équipe.
     * Doit être égale à 100 pour que les commissions soient générées.
     */
    public function sommeTauxTotale(): float
    {
        if (! $this->equipe) {
            return 0.0;
        }

        return (float) $this->equipe->taux_commission_proprietaire + $this->equipe->sommeTaux();
    }
}
