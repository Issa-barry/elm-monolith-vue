<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FactureFournisseurLigne extends Model
{
    use HasUlids;

    protected $table = 'facture_fournisseur_lignes';

    protected $fillable = [
        'facture_fournisseur_id', 'reception_achat_ligne_id', 'commande_achat_ligne_id', 'variante_id',
        'libelle_snapshot', 'reference_snapshot', 'qte_facturee', 'prix_unitaire', 'total_ht',
    ];

    protected function casts(): array
    {
        return [
            'qte_facturee' => 'integer',
            'prix_unitaire' => 'decimal:2',
            'total_ht' => 'decimal:2',
        ];
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(FactureFournisseur::class, 'facture_fournisseur_id');
    }

    public function receptionLigne(): BelongsTo
    {
        return $this->belongsTo(ReceptionAchatLigne::class, 'reception_achat_ligne_id');
    }

    public function commandeLigne(): BelongsTo
    {
        return $this->belongsTo(CommandeAchatLigne::class, 'commande_achat_ligne_id');
    }

    public function variante(): BelongsTo
    {
        return $this->belongsTo(ProduitVariante::class, 'variante_id');
    }
}
