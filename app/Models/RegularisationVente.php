<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Trace d'une régularisation de vente après encaissement (ADR 0043) : qui, quand, pourquoi, comment
 * elle a été confirmée, et l'état avant/après (quantités, facture, encaissements, commissions).
 */
class RegularisationVente extends Model
{
    use HasUlids;

    public const EXCEDENT_NON_RECU = 'non_recu';

    public const EXCEDENT_A_REMBOURSER = 'a_rembourser';

    protected $table = 'regularisations_ventes';

    protected $fillable = [
        'organization_id',
        'commande_vente_id',
        'commande_vente_retour_id',
        'user_id',
        'motif',
        'traitement_excedent',
        'montant_avant',
        'montant_apres',
        'encaisse_avant',
        'excedent',
        'commissions_avant',
        'commissions_apres',
        'empreinte',
        'methode_confirmation',
        'code_envoye_a',
        'code_demande_at',
        'recapitulatif',
        'operations',
        'confirmee_at',
    ];

    protected function casts(): array
    {
        return [
            'montant_avant' => 'decimal:2',
            'montant_apres' => 'decimal:2',
            'encaisse_avant' => 'decimal:2',
            'excedent' => 'decimal:2',
            'commissions_avant' => 'decimal:2',
            'commissions_apres' => 'decimal:2',
            'recapitulatif' => 'array',
            'operations' => 'array',
            'code_demande_at' => 'datetime',
            'confirmee_at' => 'datetime',
        ];
    }

    public static function libelleTraitementExcedent(?string $traitement): ?string
    {
        return match ($traitement) {
            self::EXCEDENT_NON_RECU => 'Montant jamais reçu : encaissement réduit',
            self::EXCEDENT_A_REMBOURSER => 'Montant reçu : trop-perçu à rembourser',
            default => null,
        };
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(CommandeVente::class, 'commande_vente_id');
    }

    public function mouvement(): BelongsTo
    {
        return $this->belongsTo(CommandeVenteRetour::class, 'commande_vente_retour_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ecartsCommission(): HasMany
    {
        return $this->hasMany(CommissionEcartRegularisation::class, 'regularisation_vente_id');
    }
}
