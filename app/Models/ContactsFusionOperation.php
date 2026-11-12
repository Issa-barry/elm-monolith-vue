<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une valeur écrite par une fusion (lot 9b) : champ arbitré de la fiche conservée, lien transféré,
 * téléphone libéré, archivage, ou effet induit (identité recopiée sur un client, rattachement au
 * compte). L'annulation ne remet `valeur_avant` que si la valeur actuelle est encore `valeur_apres`.
 */
class ContactsFusionOperation extends Model
{
    use HasUlids;

    public const CHAMP = 'champ';

    public const LIEN = 'lien';

    public const INDUIT = 'induit';

    public const ARCHIVAGE = 'archivage';

    /** Rien n'est écrit : élément laissé sur la fiche archivée (déjà présent sur la fiche conservée). */
    public const LAISSE = 'laisse';

    public const RESTAUREE = 'restauree';

    public const CONFLIT = 'conflit';

    protected $fillable = [
        'contacts_fusion_id', 'ordre', 'nature', 'table_cible', 'cible_id', 'champ',
        'valeur_avant', 'valeur_apres', 'libelle', 'annulation_statut',
    ];

    public function fusion(): BelongsTo
    {
        return $this->belongsTo(ContactsFusion::class, 'contacts_fusion_id');
    }
}
