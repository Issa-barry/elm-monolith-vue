<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un champ modifié par une exécution appliquée de l'arbitrage des écarts client / personne (ADR
 * 0060, lot 7a) : valeurs d'avant et d'après, pour annuler l'exécution sans rien perdre.
 */
class ContactsRepriseValeur extends Model
{
    use HasUlids;

    public const ANNULEE = 'annulee';

    public const CONFLIT = 'conflit';

    protected $fillable = [
        'contacts_reprise_id', 'cible_type', 'cible_id', 'champ', 'valeur_avant', 'valeur_apres', 'annulation_statut', 'annulee_le',
    ];

    protected function casts(): array
    {
        return ['annulee_le' => 'datetime'];
    }

    public function reprise(): BelongsTo
    {
        return $this->belongsTo(ContactsReprise::class, 'contacts_reprise_id');
    }
}
