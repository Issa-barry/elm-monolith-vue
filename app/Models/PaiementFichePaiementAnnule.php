<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Paiement de fiche annulé (ADR 0069, décision 11) : copie de la ligne supprimée par
 * PaiementFicheService::supprimer(). Même identifiant que le paiement d'origine, donc que le
 * source_id de sa pièce contrepassée. Sa clé d'opération ne peut plus recréer de paiement.
 */
class PaiementFichePaiementAnnule extends Model
{
    protected $table = 'paiement_fiche_paiements_annules';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'fiche_id',
        'organization_id',
        'site_id',
        'montant',
        'mode_paiement',
        'moyen_paiement_detail',
        'reference_paiement',
        'compte_tresorerie_id',
        'date_paiement',
        'note',
        'operation_id',
        'operation_empreinte',
        'created_by',
        'paye_le',
        'annule_par',
        'annule_le',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'date_paiement' => 'date',
            'paye_le' => 'datetime',
            'annule_le' => 'datetime',
        ];
    }

    public static function archiver(PaiementFichePaiement $paiement, ?User $auteur): self
    {
        return self::create([
            ...collect($paiement->getAttributes())->only([
                'id', 'fiche_id', 'organization_id', 'site_id', 'montant', 'mode_paiement',
                'moyen_paiement_detail', 'reference_paiement', 'compte_tresorerie_id', 'note',
                'operation_id', 'operation_empreinte', 'created_by',
            ])->all(),
            'date_paiement' => $paiement->date_paiement,
            'paye_le' => $paiement->created_at,
            'annule_par' => $auteur?->id,
            'annule_le' => now(),
        ]);
    }
}
