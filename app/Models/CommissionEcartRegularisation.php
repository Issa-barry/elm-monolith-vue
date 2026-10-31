<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Écart entre ce qui a déjà été versé (ou engagé sur une fiche payée) à un bénéficiaire et sa
 * commission recalculée après la régularisation d'une vente (ADR 0043). Le recalcul est immédiat ;
 * les paiements historiques ne sont jamais touchés.
 *
 * ecart = montant_verse - montant_apres : positif = trop-perçu, négatif = complément à payer.
 * Un trop-perçu se régularise hors de l'application (retenue, remboursement), constaté par
 * « Marquer régularisé ». Un complément se paie par la fiche, dont le reste à payer est ramené au
 * montant recalculé : il est soldé automatiquement (RegularisationCommissionService::synchroniserComplements).
 */
class CommissionEcartRegularisation extends Model
{
    use HasUlids;

    public const SENS_TROP_PERCU = 'trop_percu';

    public const SENS_COMPLEMENT = 'complement';

    public const SENS_AUCUN = 'aucun';

    public const STATUT_A_REGULARISER = 'a_regulariser';

    public const STATUT_REGULARISE = 'regularise';

    public const STATUT_SANS_OBJET = 'sans_objet';

    public const COMMENTAIRE_COMPLEMENT_VERSE = 'Complément versé par la fiche de paiement.';

    protected $table = 'commission_ecarts_regularisation';

    protected $fillable = [
        'organization_id',
        'regularisation_vente_id',
        'commande_vente_id',
        'commission_enveloppe_part_id',
        'beneficiaire_type',
        'beneficiaire_id',
        'beneficiaire_libelle',
        'montant_avant',
        'montant_apres',
        'montant_verse',
        'ecart',
        'sens',
        'statut',
        'commentaire_regularisation',
        'regularise_par',
        'regularise_at',
    ];

    protected function casts(): array
    {
        return [
            'montant_avant' => 'decimal:2',
            'montant_apres' => 'decimal:2',
            'montant_verse' => 'decimal:2',
            'ecart' => 'decimal:2',
            'regularise_at' => 'datetime',
        ];
    }

    public function estARegulariser(): bool
    {
        return $this->statut === self::STATUT_A_REGULARISER;
    }

    /** Seul un trop-perçu se constate à la main : un complément est soldé par la fiche. */
    public function peutEtreMarqueRegularise(): bool
    {
        return $this->estARegulariser() && $this->sens === self::SENS_TROP_PERCU;
    }

    public function sensLabel(): string
    {
        return match ($this->sens) {
            self::SENS_TROP_PERCU => 'Trop-perçu à régulariser',
            self::SENS_COMPLEMENT => 'Complément à payer',
            default => 'Aucun écart',
        };
    }

    public function statutLabel(): string
    {
        return match ($this->statut) {
            self::STATUT_REGULARISE => 'Régularisé',
            self::STATUT_SANS_OBJET => 'Sans objet',
            default => 'À régulariser',
        };
    }

    public function regularisation(): BelongsTo
    {
        return $this->belongsTo(RegularisationVente::class, 'regularisation_vente_id');
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(CommissionEnveloppePart::class, 'commission_enveloppe_part_id');
    }

    public function regularisePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'regularise_par');
    }
}
