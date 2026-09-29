<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne d'un règlement inter-agences (ADR 0012) : un encaissement précis, reçu par une agence pour
 * le compte d'une autre, reversé par un mouvement de fonds de nature `reglement_agences`.
 *
 * `encaissement_actif_id` = identifiant de l'encaissement tant que le règlement est actif, NULL une
 * fois annulé ou retourné (index unique : un encaissement n'est jamais engagé dans deux règlements
 * actifs). Toute écriture passe par ReglementInterAgencesService / MouvementFondsService.
 */
class MouvementFondsEncaissement extends Model
{
    use HasUlids;

    protected $table = 'mouvement_fonds_encaissements';

    protected $fillable = [
        'organization_id',
        'mouvement_fonds_id',
        'encaissement_vente_id',
        'encaissement_actif_id',
        'montant',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
        ];
    }

    public function mouvement(): BelongsTo
    {
        return $this->belongsTo(MouvementFonds::class, 'mouvement_fonds_id');
    }

    public function encaissement(): BelongsTo
    {
        return $this->belongsTo(EncaissementVente::class, 'encaissement_vente_id');
    }

    public function isActif(): bool
    {
        return $this->encaissement_actif_id !== null;
    }
}
