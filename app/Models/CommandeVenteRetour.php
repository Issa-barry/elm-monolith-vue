<?php

namespace App\Models;

use App\Enums\MotifRetourCommande;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un retour de livraison enregistré sur une vente standard avant tout encaissement — le livreur
 * est revenu avec tout ou partie de la marchandise (cf. CommandeVenteRetourService). Un même
 * CommandeVente peut en porter plusieurs, jusqu'à épuisement des quantités chargées.
 */
class CommandeVenteRetour extends Model
{
    use HasUlids;

    protected $table = 'commande_vente_retours';

    protected $fillable = [
        'organization_id',
        'commande_vente_id',
        'motif',
        'commentaire',
        'quantite_totale',
        'montant_retourne',
        'retour_total',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'motif' => MotifRetourCommande::class,
            'quantite_totale' => 'integer',
            'montant_retourne' => 'decimal:2',
            'retour_total' => 'boolean',
        ];
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(CommandeVente::class, 'commande_vente_id');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(CommandeVenteRetourLigne::class, 'commande_vente_retour_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
