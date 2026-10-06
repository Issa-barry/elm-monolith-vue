<?php

namespace App\Http\Controllers\Depenses;

use App\Enums\CategorieDepense;
use App\Http\Controllers\Controller;
use App\Models\Depense;
use App\Support\Depenses\DepenseFormOptionsLoader;
use Inertia\Inertia;
use Inertia\Response;

class EditDepenseController extends Controller
{
    public function __invoke(Depense $depense): Response
    {
        $this->authorize('update', $depense);

        $user = auth()->user();
        $orgId = $user->organization_id;

        $sites = $user->isAdmin()
            ? DepenseFormOptionsLoader::sites($orgId)
            : $user->sites()
                ->where('sites.organization_id', $orgId)
                ->orderBy('sites.nom')
                ->get(['sites.id', 'sites.nom'])
                ->map(fn ($s) => ['id' => $s->id, 'nom' => $s->nom]);

        return Inertia::render('Depenses/Edit', [
            'depense' => [
                'id' => $depense->id,
                'depense_type_id' => $depense->depense_type_id,
                'beneficiaire_type' => $depense->beneficiaire_type,
                'beneficiaire_id' => $depense->beneficiaire_id,
                'site_id' => $depense->site_id,
                'montant' => (float) $depense->montant,
                'date_depense' => $depense->date_depense->toDateString(),
                'commentaire' => $depense->commentaire ?? '',
                'statut' => $depense->statut->value,
            ],
            'types' => DepenseFormOptionsLoader::types($orgId),
            'vehicules' => DepenseFormOptionsLoader::vehicules($orgId),
            'sites' => $sites,
            'employes' => DepenseFormOptionsLoader::employes($orgId),
            'livreurs' => DepenseFormOptionsLoader::livreurs($orgId),
            'proprietaires' => DepenseFormOptionsLoader::proprietaires($orgId),
            'prestataires' => DepenseFormOptionsLoader::prestataires($orgId),
            'clients' => DepenseFormOptionsLoader::clients($orgId),
            'categories' => CategorieDepense::optionsConcerne(),
            'can_change_site' => $user->isAdmin(),
        ]);
    }
}
