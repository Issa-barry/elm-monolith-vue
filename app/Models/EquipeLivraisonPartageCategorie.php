<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Partage Livreur d'une équipe véhicule, PAR CATÉGORIE — un livreur reçoit un
 * montant GNF fixe par unité vendue, différent selon la catégorie (les barèmes
 * Livreur eux-mêmes varient par catégorie, cf. CommissionRegle). La somme des
 * montants des membres actifs d'une équipe/catégorie doit être exactement égale
 * au barème (cf. CommissionPartageLivraisonValidator) — plus aucun pourcentage
 * n'est calculé ni persisté pour ce partage. Absence de ligne pour un couple
 * (équipe, catégorie) = non configuré, jamais déduit.
 *
 * Versionné (même principe que CommissionRegle) : jamais mutée en place, une
 * modification ferme la ligne active (effective_to) et en insère une nouvelle
 * (effective_to NULL) — nécessaire pour qu'une relance de commission historique
 * résolve le partage réellement en vigueur à la date du fait générateur.
 *
 * part_pourcentage (legacy, en cours de retrait) : encore lue par le code %
 * historique le temps de la migration commissions:migrer-partages-livraison ;
 * les nouvelles lignes y écrivent un placeholder 0, jamais lu.
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
        'montant_unitaire',
        'effective_from',
        'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'part_pourcentage' => 'decimal:2',
            'montant_unitaire' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    /** Lignes actives à une date donnée (par défaut aujourd'hui) — jamais les versions closes. */
    public function scopeActifA($query, ?\DateTimeInterface $date = null)
    {
        $date = $date ? Carbon::instance($date) : Carbon::today();

        return $query
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>', $date));
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
