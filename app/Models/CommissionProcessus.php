<?php

namespace App\Models;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionStrategieAncrageSite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Famille d'opérations pouvant générer des enveloppes de commission (vente,
 * transfert logistique...). Porte le déclencheur (QUAND) et la stratégie
 * d'ancrage site (QUEL site, pour une cible collective ancrée sur un site) —
 * jamais le barème (COMBIEN), qui reste porté par CommissionRegle.
 */
class CommissionProcessus extends Model
{
    use HasUlids;

    public const CODE_VENTE = 'vente';

    /**
     * Décision produit du 02/09/2026 : processus métier réel et courant, jamais fusionné avec
     * CODE_LOGISTIQUE_TRANSFERT — chaque distribution génère une CommissionEnveloppe rattachée à
     * CE code (identité/reporting, cf. CommissionEnveloppeGenerator::genererPourCommandeVente()),
     * pour rester traçable et pouvoir diverger un jour sans dette technique. Il n'a en revanche pas
     * d'onglet dédié dans Settings\CommissionRegleController::processusCodesDisponibles() : tant
     * qu'aucune CommissionRegle active ne lui est propre, le MONTANT (jamais l'identité) est résolu
     * par repli automatique sur celui de CODE_LOGISTIQUE_TRANSFERT — cf.
     * CommissionProcessusDefaults::processusResolutionBareme(). Toujours présent dans
     * App\Support\Commission\CommissionProcessusFilter (reporting), qu'il ait ou non sa propre
     * configuration.
     */
    public const CODE_DISTRIBUTION_CLIENT = 'distribution_client';

    public const CODE_LOGISTIQUE_TRANSFERT = 'logistique_transfert';

    protected $table = 'commission_processus';

    protected $fillable = [
        'organization_id',
        'code',
        'libelle',
        'declencheur',
        'strategie_ancrage_site',
        'statut',
    ];

    protected function casts(): array
    {
        return [
            'strategie_ancrage_site' => CommissionStrategieAncrageSite::class,
            'statut' => CommissionActivationStatut::class,
        ];
    }

    public function isActif(): bool
    {
        return $this->statut === CommissionActivationStatut::ACTIF;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function regles(): HasMany
    {
        return $this->hasMany(CommissionRegle::class, 'processus_id');
    }
}
