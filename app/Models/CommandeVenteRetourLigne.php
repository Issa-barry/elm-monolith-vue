<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Détail par ligne de commande d'un CommandeVenteRetour. Sert aussi de source
 * (`source_type`/`source_id`) au mouvement de stock d'entrée qui réintègre la marchandise
 * retournée (cf. MouvementStockService::reintegrerRetour()).
 */
class CommandeVenteRetourLigne extends Model
{
    use HasUlids;

    protected $table = 'commande_vente_retour_lignes';

    protected $fillable = [
        'commande_vente_retour_id',
        'commande_vente_ligne_id',
        'variante_id',
        'quantite_retournee',
        'prix_unitaire',
        'montant_retourne',
        'libelle_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'quantite_retournee' => 'integer',
            'prix_unitaire' => 'decimal:2',
            'montant_retourne' => 'decimal:2',
        ];
    }

    public function retour(): BelongsTo
    {
        return $this->belongsTo(CommandeVenteRetour::class, 'commande_vente_retour_id');
    }

    public function ligne(): BelongsTo
    {
        return $this->belongsTo(CommandeVenteLigne::class, 'commande_vente_ligne_id');
    }

    public function variante(): BelongsTo
    {
        return $this->belongsTo(ProduitVariante::class, 'variante_id');
    }
}
