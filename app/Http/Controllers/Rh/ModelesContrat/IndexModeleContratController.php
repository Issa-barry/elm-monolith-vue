<?php

namespace App\Http\Controllers\Rh\ModelesContrat;

use App\Enums\TypeContrat;
use App\Http\Controllers\Controller;
use App\Services\Rh\ModeleContratService;
use Inertia\Inertia;
use Inertia\Response;

/** Paramètres → RH → Modèles de contrat (permission `rh-referentiels.gerer`, ADR 0044). */
class IndexModeleContratController extends Controller
{
    public function __invoke(ModeleContratService $modeles): Response
    {
        $user = request()->user();
        abort_unless($user->can('rh-referentiels.gerer'), 403);

        $liste = collect(TypeContrat::cases())->map(function (TypeContrat $type) use ($modeles, $user) {
            $modele = $modeles->pourType($user->organization_id, $type);
            $modele->loadMissing('modificateur.personne');

            return [
                'type_contrat' => $type->value,
                'type_contrat_label' => $type->label(),
                'titre' => $modele->titre,
                'statut' => $modele->statut->value,
                'statut_label' => $modele->statut->label(),
                'enregistre' => $modele->exists,
                'revision' => $modele->revision,
                'articles_count' => count($modele->articles),
                'modifie_le' => $modele->updated_at?->format('d/m/Y à H:i'),
                'modifie_par' => $modele->modificateur?->name,
            ];
        });

        return Inertia::render('ModelesContrat/Index', ['modeles' => $liste]);
    }
}
