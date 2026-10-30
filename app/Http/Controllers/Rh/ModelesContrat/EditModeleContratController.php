<?php

namespace App\Http\Controllers\Rh\ModelesContrat;

use App\Enums\TypeContrat;
use App\Http\Controllers\Controller;
use App\Services\Rh\ModeleContratService;
use App\Support\Rh\Contrats\VariablesContrat;
use Inertia\Inertia;
use Inertia\Response;

/** Édition du texte d'un modèle de contrat : articles et variables disponibles (ADR 0044). */
class EditModeleContratController extends Controller
{
    public function __invoke(string $type, ModeleContratService $modeles): Response
    {
        $user = request()->user();
        abort_unless($user->can('rh-referentiels.gerer'), 403);
        $typeContrat = TypeContrat::tryFrom($type) ?? abort(404);

        $modele = $modeles->pourType($user->organization_id, $typeContrat);

        return Inertia::render('ModelesContrat/Edit', [
            'modele' => [
                'type_contrat' => $typeContrat->value,
                'type_contrat_label' => $typeContrat->label(),
                'titre' => $modele->titre,
                'articles' => array_values($modele->articles),
                'statut' => $modele->statut->value,
                'statut_label' => $modele->statut->label(),
                'enregistre' => $modele->exists,
                'revision' => $modele->revision,
            ],
            'variables' => VariablesContrat::pourType($typeContrat),
        ]);
    }
}
