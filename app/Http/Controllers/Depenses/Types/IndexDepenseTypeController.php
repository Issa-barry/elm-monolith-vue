<?php

namespace App\Http\Controllers\Depenses\Types;

use App\Enums\CategorieDepense;
use App\Http\Controllers\Controller;
use App\Models\DepenseType;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Types de dépense — module Dépenses (pas Paramètres, cf. décision produit
 * 2026-08-24 : la classification métier des dépenses est une donnée du
 * module Dépenses, pas un réglage d'organisation). Les droits de validation
 * restent dans Settings\Depenses\EditDepenseParametrageController.
 */
class IndexDepenseTypeController extends Controller
{
    public function __invoke(): Response
    {
        $this->authorize('viewAny', DepenseType::class);

        return Inertia::render('Depenses/Types/Index', [
            'types' => $this->typesPourOrganisation(auth()->user()->organization_id),
            'categories' => CategorieDepense::options(),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function typesPourOrganisation(string $orgId): array
    {
        return DepenseType::where('organization_id', $orgId)
            ->ordered()
            ->get()
            ->map(fn (DepenseType $t) => [
                'id' => $t->id,
                'libelle' => $t->libelle,
                'description' => $t->description,
                'categorie' => $t->categorie->value,
                'categorie_label' => $t->categorie->label(),
                'commentaire_obligatoire' => $t->commentaire_obligatoire,
                'justificatif_obligatoire' => $t->justificatif_obligatoire,
                'type_paie' => $t->type_paie,
                'is_active' => $t->is_active,
                'depenses_count' => $t->depenses()->count(),
            ])
            ->all();
    }
}
