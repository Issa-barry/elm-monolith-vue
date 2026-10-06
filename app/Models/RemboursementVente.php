<?php

namespace App\Models;

use App\Enums\ModePaiement;
use App\Enums\OperateurMobileMoney;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Remboursement d'un client (ADR 0019) — sortie réelle de trésorerie depuis un support de l'agence de
 * la commande. Créé et comptabilisé par PrecommandeService (jamais directement), jamais supprimé.
 */
class RemboursementVente extends Model
{
    use HasUlids;

    /** Trop-perçu : la précommande a été remise pour moins que ses acomptes. */
    public const MOTIF_TROP_PERCU = 'trop_percu';

    /** Annulation : les acomptes d'une précommande annulée sont rendus au client. */
    public const MOTIF_ANNULATION = 'annulation';

    protected $table = 'remboursements_ventes';

    protected $fillable = [
        'organization_id',
        'site_id',
        'commande_vente_id',
        'facture_vente_id',
        'motif',
        'montant',
        'mode_paiement',
        'operateur_mobile_money',
        'compte_tresorerie_id',
        'reference_paiement',
        'date_remboursement',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'mode_paiement' => ModePaiement::class,
            'operateur_mobile_money' => OperateurMobileMoney::class,
            'date_remboursement' => 'date:Y-m-d',
        ];
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(CommandeVente::class, 'commande_vente_id');
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(FactureVente::class, 'facture_vente_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function compteTresorerie(): BelongsTo
    {
        return $this->belongsTo(CompteTresorerie::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
