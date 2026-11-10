<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Catégorie libre posée sur une fiche (ADR 0060, lot 6a). Une seule classe pour les deux tables de
 * liaison : la table vient de la relation (`personne_contact_categories`,
 * `entreprise_contact_categories`). Identifiant ULID, comme toutes les tables du projet.
 */
class CategorieLibreAttribution extends Pivot
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';
}
