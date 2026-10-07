<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une fiche livreur/propriétaire déjà comptabilisée a changé d'agence (son véhicule a été
 * réaffecté, ADR 0020) : le reste dû et la charge correspondante passent de l'agence d'origine
 * à l'agence de destination (FicheComptabilisationService::comptabiliserReaffectation()).
 */
class PaiementFicheReaffectation extends Model
{
    use HasUlids;

    protected $fillable = [
        'organization_id',
        'fiche_id',
        'site_origine_id',
        'site_destination_id',
        'montant',
    ];

    protected function casts(): array
    {
        return ['montant' => 'decimal:2'];
    }

    public function fiche(): BelongsTo
    {
        return $this->belongsTo(PaiementFiche::class, 'fiche_id');
    }

    public function siteOrigine(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_origine_id');
    }

    public function siteDestination(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_destination_id');
    }
}
