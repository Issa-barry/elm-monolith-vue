<?php

namespace App\Models;

use App\Enums\MotifRetourTransfert;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Retour au site source de tout ou partie de la marchandise partie sur un transfert logistique
 * (ADR 0029, cf. TransfertRetourService). Un même transfert peut en porter plusieurs, jusqu'à
 * épuisement des quantités retournables. Jamais supprimé ni annulé.
 */
class TransfertRetour extends Model
{
    use HasUlids;

    protected $table = 'transfert_retours';

    protected $fillable = [
        'organization_id',
        'transfert_logistique_id',
        'site_id',
        'motif',
        'commentaire',
        'quantite_totale',
        'retour_total',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'motif' => MotifRetourTransfert::class,
            'quantite_totale' => 'integer',
            'retour_total' => 'boolean',
        ];
    }

    public function transfert(): BelongsTo
    {
        return $this->belongsTo(TransfertLogistique::class, 'transfert_logistique_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(TransfertRetourLigne::class, 'transfert_retour_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
