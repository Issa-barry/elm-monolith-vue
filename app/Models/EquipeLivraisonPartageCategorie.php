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
 * Bénéficiaire : un livreur de l'équipe, ou le parrain du véhicule (`parrain_id`, jamais les deux)
 * — sa part est prise sur la même enveloppe, la somme reste égale au barème (cf.
 * docs/parrainage-vehicule.md). Jamais de ligne parrain sur un transfert logistique entre sites.
 *
 * part_pourcentage (legacy, en cours de retrait) : encore lue par le code %
 * historique le temps de la migration commissions:migrer-partages-livraison ;
 * les nouvelles lignes y écrivent un placeholder 0, jamais lu.
 */
class EquipeLivraisonPartageCategorie extends Model
{
    use HasUlids;

    public const TYPE_LIVREUR = CommissionGroupeMembre::TYPE_LIVREUR;

    public const TYPE_PARRAIN = 'parrain';

    protected $table = 'equipe_livraison_partages_categorie';

    protected $fillable = [
        'equipe_id',
        'processus_id',
        'categorie_id',
        'livreur_id',
        'parrain_id',
        'part_pourcentage',
        'montant_unitaire',
        'part_parrain_confirmee_at',
        'droits_parrain_depuis',
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
            'part_parrain_confirmee_at' => 'datetime',
            'droits_parrain_depuis' => 'date',
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
        return $this->parrain_id ? self::TYPE_PARRAIN : self::TYPE_LIVREUR;
    }

    public function getBeneficiaireIdAttribute(): string
    {
        return $this->parrain_id ?? $this->livreur_id;
    }

    public function estParrain(): bool
    {
        return $this->parrain_id !== null;
    }

    /**
     * PARRAIN-022 : part du parrain à 0 GNF jamais confirmée par une personne (créée par le
     * système) — le partage est complet mais la répartition métier reste à faire. Une part positive
     * vaut toujours décision.
     */
    public function partParrainARepartir(): bool
    {
        return $this->estParrain()
            && (int) $this->montant_unitaire === 0
            && $this->part_parrain_confirmee_at === null;
    }

    public function equipe(): BelongsTo
    {
        return $this->belongsTo(EquipeLivraison::class, 'equipe_id');
    }

    public function processus(): BelongsTo
    {
        return $this->belongsTo(CommissionProcessus::class, 'processus_id');
    }

    public function categorie(): BelongsTo
    {
        return $this->belongsTo(Categorie::class, 'categorie_id');
    }

    public function livreur(): BelongsTo
    {
        return $this->belongsTo(Livreur::class);
    }

    public function parrain(): BelongsTo
    {
        return $this->belongsTo(Parrain::class);
    }
}
