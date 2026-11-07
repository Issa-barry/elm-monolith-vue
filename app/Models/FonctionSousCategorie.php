<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Liaison entre une fonction (prestataire, fournisseur) et une sous-catégorie du référentiel
 * Contacts (ADR 0060). Une seule classe pour les deux tables de liaison : la table vient de la
 * relation (`prestataire_sous_categories`, `fournisseur_sous_categories`). Identifiant ULID, comme
 * toutes les tables du projet.
 */
class FonctionSousCategorie extends Pivot
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';
}
