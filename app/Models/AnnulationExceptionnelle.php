<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trace d'audit d'une annulation exceptionnelle confirmée (cf. AnnulationExceptionnelleService) :
 * une par commande. Jamais modifiée après sa création, et ne contient jamais le code de
 * confirmation — uniquement la méthode (`methode_confirmation`, valeur de
 * ModeConfirmationAnnulationExceptionnelle), l'adresse masquée et les horodatages.
 */
class AnnulationExceptionnelle extends Model
{
    use HasUlids;

    protected $table = 'annulations_exceptionnelles';

    protected $fillable = [
        'organization_id',
        'commande_vente_id',
        'facture_vente_id',
        'user_id',
        'motif',
        'statut_avant',
        'empreinte',
        'methode_confirmation',
        'code_envoye_a',
        'code_demande_at',
        'confirmee_at',
        'montant_commande',
        'montant_facture',
        'montant_encaisse',
        'montant_commissions',
        'montant_cashback',
        'snapshot',
        'regularisations',
    ];

    protected function casts(): array
    {
        return [
            'code_demande_at' => 'datetime',
            'confirmee_at' => 'datetime',
            'montant_commande' => 'decimal:2',
            'montant_facture' => 'decimal:2',
            'montant_encaisse' => 'decimal:2',
            'montant_commissions' => 'decimal:2',
            'montant_cashback' => 'decimal:2',
            'snapshot' => 'array',
            'regularisations' => 'array',
        ];
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(CommandeVente::class, 'commande_vente_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
