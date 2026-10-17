<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Détail par ligne de transfert d'un TransfertRetour. Sert aussi de source
 * (`source_type`/`source_id`) au mouvement d'entrée qui réintègre la marchandise au site source
 * (cf. MouvementStockService::reintegrerRetour()) — distincte de TransfertLigne, qui porte la
 * sortie source et l'entrée destination du transfert lui-même.
 */
class TransfertRetourLigne extends Model
{
    use HasUlids;

    protected $table = 'transfert_retour_lignes';

    protected $fillable = [
        'transfert_retour_id',
        'transfert_ligne_id',
        'variante_id',
        'quantite_retournee',
        'libelle_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'quantite_retournee' => 'integer',
        ];
    }

    public function retour(): BelongsTo
    {
        return $this->belongsTo(TransfertRetour::class, 'transfert_retour_id');
    }

    public function ligne(): BelongsTo
    {
        return $this->belongsTo(TransfertLigne::class, 'transfert_ligne_id');
    }

    public function variante(): BelongsTo
    {
        return $this->belongsTo(ProduitVariante::class, 'variante_id');
    }
}
