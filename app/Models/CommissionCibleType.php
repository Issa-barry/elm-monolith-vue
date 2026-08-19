<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Référentiel des cibles bénéficiaires (proprietaire, equipe_livraison,
 * equipe_depot, it...) — remplace l'enum PHP fermé TypePeriodePaiement.
 * organization_id NULL = valeur par défaut applicative, surchargeable par
 * organisation.
 */
class CommissionCibleType extends Model
{
    use HasUlids;

    public const CODE_PROPRIETAIRE = 'proprietaire';

    public const CODE_EQUIPE_LIVRAISON = 'equipe_livraison';

    // Réservés — non seedés avant la Phase 3 (cf. conception cible §0.2.4, §F).
    public const CODE_EQUIPE_DEPOT = 'equipe_depot';

    public const CODE_IT = 'it';

    public const PERIODICITE_QUINZAINE = 'quinzaine';

    public const PERIODICITE_MENSUELLE = 'mensuelle';

    protected $table = 'commission_cible_types';

    protected $fillable = [
        'organization_id',
        'code',
        'libelle',
        'periodicite',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
