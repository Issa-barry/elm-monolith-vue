<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un propriétaire traité par une reprise appliquée (ADR 0060, lot 5) : valeurs d'avant, entreprise
 * créée ou réutilisée, état du lien interlocuteur avant l'exécution, principal posé ou non.
 */
class ContactsRepriseLigne extends Model
{
    use HasUlids;

    public const ANNULEE = 'annulee';

    public const CONFLIT = 'conflit';

    protected $fillable = [
        'contacts_reprise_id', 'proprietaire_id', 'entreprise_tierce_id', 'entreprise_creee', 'interlocuteur_id',
        'interlocuteur_etat_avant', 'principal_pose', 'valeurs_avant', 'annulation_statut', 'annulee_le',
    ];

    protected function casts(): array
    {
        return [
            'entreprise_creee' => 'boolean',
            'principal_pose' => 'boolean',
            'valeurs_avant' => 'array',
            'annulee_le' => 'datetime',
        ];
    }

    public function reprise(): BelongsTo
    {
        return $this->belongsTo(ContactsReprise::class, 'contacts_reprise_id');
    }

    public function proprietaire(): BelongsTo
    {
        return $this->belongsTo(Proprietaire::class)->withTrashed();
    }
}
