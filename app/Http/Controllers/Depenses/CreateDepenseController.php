<?php

namespace App\Http\Controllers\Depenses;

use App\Enums\CategorieDepense;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Depense;
use App\Support\Depenses\DepenseFormOptionsLoader;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CreateDepenseController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $this->authorize('create', Depense::class);

        $user = auth()->user();
        $orgId = $user->organization_id;
        $initialBeneficiaireType = $request->query('beneficiaire_type') === 'client' ? 'client' : '';
        $initialBeneficiaireId = '';
        if ($initialBeneficiaireType === 'client') {
            $initialBeneficiaireId = (string) Client::where('organization_id', $orgId)
                ->whereKey($request->query('beneficiaire_id'))
                ->value('id');
        }

        $sites = $user->agitSurToutesLesAgences()
            ? DepenseFormOptionsLoader::sites($orgId)
            : $user->sites()
                ->where('sites.organization_id', $orgId)
                ->orderBy('sites.nom')
                ->get(['sites.id', 'sites.nom'])
                ->map(fn ($s) => ['id' => $s->id, 'nom' => $s->nom]);

        return Inertia::render('Depenses/Create', [
            'types' => DepenseFormOptionsLoader::types($orgId),
            'vehicules' => DepenseFormOptionsLoader::vehicules($orgId),
            'sites' => $sites,
            'employes' => DepenseFormOptionsLoader::employes($orgId),
            'livreurs' => DepenseFormOptionsLoader::livreurs($orgId),
            'proprietaires' => DepenseFormOptionsLoader::proprietaires($orgId),
            'prestataires' => DepenseFormOptionsLoader::prestataires($orgId),
            'clients' => DepenseFormOptionsLoader::clients($orgId),
            'parrains' => DepenseFormOptionsLoader::parrains($orgId),
            'default_site_id' => $this->defaultSiteId(),
            'categories' => CategorieDepense::optionsConcerne(),
            'can_change_site' => $user->agitSurToutesLesAgences(),
            'initial_beneficiaire_type' => $initialBeneficiaireType,
            'initial_beneficiaire_id' => $initialBeneficiaireId,
        ]);
    }

    private function defaultSiteId(): ?string
    {
        // Agence obligatoire (ADR 0065) : à défaut d'agence par défaut, la première agence de
        // rattachement, pour qu'un sélecteur verrouillé ne reste jamais vide.
        return auth()->user()->sites()
            ->orderByDesc('user_sites.is_default')
            ->orderBy('sites.nom')
            ->select('sites.id')
            ->first()?->id;
    }
}
