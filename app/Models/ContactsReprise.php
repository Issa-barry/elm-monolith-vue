<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une exécution appliquée d'une reprise du référentiel Contacts, ou son annulation (ADR 0060,
 * lot 5). Jamais supprimée : c'est la trace qui permet d'annuler.
 */
class ContactsReprise extends Model
{
    use HasUlids;

    public const MODE_APPLICATION = 'application';

    public const MODE_ANNULATION = 'annulation';

    protected $fillable = [
        'organization_id', 'commande', 'mode', 'reprise_annulee_id', 'acteur_id', 'rapport', 'terminee_le',
    ];

    protected function casts(): array
    {
        return [
            'rapport' => 'array',
            'terminee_le' => 'datetime',
        ];
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(ContactsRepriseLigne::class);
    }

    public function repriseAnnulee(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reprise_annulee_id');
    }
}
