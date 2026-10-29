<?php

namespace App\Http\Controllers\Rh\TypesRemuneration;

use App\Enums\CategorieTypeRemuneration;
use App\Enums\ModeCalculRemuneration;
use App\Enums\NatureMontantRemuneration;
use App\Enums\PeriodiciteRemuneration;
use App\Enums\StatutTypeRemuneration;
use App\Http\Controllers\Controller;
use App\Models\TypeRemuneration;
use App\Services\Rh\AffectationRemunerationService;
use App\Services\Rh\Paie\CalendrierPaieService;
use Inertia\Inertia;
use Inertia\Response;

/** Paramètres → RH → Primes et avantages (docs/primes-avantages.md). */
class IndexTypeRemunerationController extends Controller
{
    public function __invoke(): Response
    {
        $this->authorize('viewAny', TypeRemuneration::class);

        $orgId = auth()->user()->organization_id;
        abort_if(! $orgId, 403);

        $types = TypeRemuneration::where('organization_id', $orgId)
            ->with(['auteur.personne', 'valideur.personne', 'modificateur.personne'])
            ->orderBy('libelle')
            ->get()
            ->map(fn (TypeRemuneration $t) => [
                'id' => $t->id,
                'code' => $t->code,
                'libelle' => $t->libelle,
                'description' => $t->description,
                'categorie' => $t->categorie->value,
                'categorie_label' => $t->categorie->label(),
                'valorise' => $t->categorie->estValorise(),
                'mode_calcul' => $t->mode_calcul->value,
                'mode_calcul_label' => $t->mode_calcul->label(),
                'periodicite' => $t->periodicite->value,
                'periodicite_label' => $t->periodicite->label(),
                'nature_montant' => $t->nature_montant->value,
                'nature_montant_label' => $t->nature_montant->label(),
                'proratisable' => $t->proratisable,
                'cumul_autorise' => $t->cumul_autorise,
                'montant_defaut' => $t->montant_defaut !== null ? (float) $t->montant_defaut : null,
                'taux_defaut' => $t->taux_defaut !== null ? (float) $t->taux_defaut : null,
                'date_debut_application' => $t->date_debut_application?->toDateString(),
                'date_fin_application' => $t->date_fin_application?->toDateString(),
                'statut' => $t->statut->value,
                'statut_label' => $t->statut->label(),
                'est_valide' => $t->statut->estValide(),
                'attribuable' => $t->estAttribuable(now()),
                'est_utilise' => $t->estUtilise(),
                // Bénéficiaires encore en cours ou à venir : la désactivation exige alors une date d'effet.
                'nb_beneficiaires' => app(AffectationRemunerationService::class)->nbBeneficiairesOuverts($t, today()),
                'desactive_au' => $t->desactive_au?->toDateString(),
                'cree_par' => $t->auteur?->name,
                'modifie_par' => $t->modificateur?->name,
                'valide_par' => $t->valideur?->name,
                'valide_le' => $t->valide_le?->format('d/m/Y H:i'),
                'created_at' => $t->created_at?->format('d/m/Y H:i'),
                'updated_at' => $t->updated_at?->format('d/m/Y H:i'),
            ]);

        return Inertia::render('TypesRemuneration/Index', [
            'types' => $types,
            'categories' => CategorieTypeRemuneration::options(),
            'modes_calcul' => ModeCalculRemuneration::options(),
            'periodicites' => PeriodiciteRemuneration::options(),
            'natures_montant' => NatureMontantRemuneration::options(),
            'statuts' => StatutTypeRemuneration::options(),
            'premier_jour_modifiable' => app(CalendrierPaieService::class)->premierJourModifiable($orgId)?->toDateString(),
        ]);
    }
}
