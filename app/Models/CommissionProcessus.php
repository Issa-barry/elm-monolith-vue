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
     * Legacy depuis le 01/09/2026 : plus jamais routé pour une NOUVELLE commission (une
     * distribution client utilise désormais CODE_LOGISTIQUE_TRANSFERT, cf.
     * CommissionEnveloppeGenerator::genererPourCommandeVente()) — conservé uniquement pour que
     * les CommissionEnveloppe/CommissionRegle/EquipeLivraisonPartageCategorie déjà générées sous
     * ce code restent lisibles (jamais migrées). Absent de
     * Settings\CommissionRegleController::processusCodesDisponibles(), toujours présent dans
     * App\Support\Commission\CommissionProcessusFilter (reporting historique).
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
