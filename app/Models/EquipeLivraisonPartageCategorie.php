<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Partage Livraison d'une équipe véhicule, PAR CATÉGORIE — un livreur peut
 * recevoir un pourcentage différent selon la catégorie vendue (les barèmes
 * Livraison eux-mêmes varient par catégorie, cf. CommissionRegle). Absence de
 * ligne pour un couple (équipe, catégorie) = non configuré, jamais déduit.
 *
 * beneficiaire_type/beneficiaire_id : accesseurs calculés (jamais de colonne
 * dédiée) qui rendent ce modèle directement compatible avec
 * CommissionRepartitionEngine::repartir(), sans dupliquer son algorithme de
 * répartition/reliquat — cf. CommissionGroupeMembre pour le même contrat.
 */
class EquipeLivraisonPartageCategorie extends Model
{
    use HasUlids;

    protected $table = 'equipe_livraison_partages_categorie';

    protected $fillable = [
        'equipe_id',
        'categorie_id',
        'livreur_id',
        'part_pourcentage',
    ];

    protected function casts(): array
    {
        return [
            'part_pourcentage' => 'decimal:2',
        ];
    }

    public function getBeneficiaireTypeAttribute(): string
    {
        return CommissionGroupeMembre::TYPE_LIVREUR;
    }

    public function getBeneficiaireIdAttribute(): string
    {
        return $this->livreur_id;
    }

    public function equipe(): BelongsTo
    {
        return $this->belongsTo(EquipeLivraison::class, 'equipe_id');
    }

    public function categorie(): BelongsTo
    {
        return $this->belongsTo(Categorie::class, 'categorie_id');
    }

    public function livreur(): BelongsTo
    {
        return $this->belongsTo(Livreur::class);
    }
}
