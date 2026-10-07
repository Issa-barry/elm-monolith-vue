<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Réception (partielle ou complète) d'un bon de commande fournisseur, enregistrée dans
 * Logistique → Réceptions fournisseurs (ADR 0021). Le stock entre sur l'agence de la commande dès
 * l'enregistrement.
 */
class ReceptionAchat extends Model
{
    use HasUlids;

    protected $table = 'receptions_achats';

    protected $fillable = [
        'organization_id',
        'commande_achat_id',
        'site_id',
        'reference',
        'numero',
        'date_reception',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'date_reception' => 'date',
        ];
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(CommandeAchat::class, 'commande_achat_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(ReceptionAchatLigne::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
