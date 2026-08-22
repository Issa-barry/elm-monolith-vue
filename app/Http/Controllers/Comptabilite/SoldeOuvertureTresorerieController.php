<?php

namespace App\Http\Controllers\Comptabilite;

use App\Http\Controllers\Controller;
use App\Models\CompteTresorerie;
use App\Models\SoldeOuvertureTresorerie;
use App\Services\Tresorerie\SoldeOuvertureTresorerieService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SoldeOuvertureTresorerieController extends Controller
{
    public function __construct(
        private readonly SoldeOuvertureTresorerieService $service,
    ) {}

    public function store(Request $request)
    {
        abort_unless(auth()->user()->can('tresorerie.gerer_soldes_ouverture'), 403);

        $orgId = auth()->user()->organization_id;

        $data = $request->validate([
            'compte_tresorerie_id' => ['required', Rule::exists('compta_supports_tresorerie', 'id')->where('organization_id', $orgId)],
            'date_situation' => ['required', 'date'],
            'montant' => ['required', 'numeric', 'min:0'],
            'commentaire' => ['nullable', 'string'],
            'justificatif' => ['nullable', 'file', 'max:10240'],
        ]);

        if ($request->hasFile('justificatif')) {
            $data['justificatif_path'] = $request->file('justificatif')->store('tresorerie/justificatifs', 'public');
        }

        $compteTresorerie = CompteTresorerie::forOrg($orgId)->findOrFail($data['compte_tresorerie_id']);

        $solde = $this->service->enregistrer($orgId, $compteTresorerie, $data, auth()->id());

        return back()->with('success', "Solde d'ouverture enregistré pour {$compteTresorerie->libelle} (à valider).");
    }

    public function valider(SoldeOuvertureTresorerie $soldeOuverture)
    {
        abort_unless(auth()->user()->can('tresorerie.gerer_soldes_ouverture'), 403);
        abort_unless($soldeOuverture->organization_id === auth()->user()->organization_id, 403);

        $this->service->valider($soldeOuverture, auth()->id());

        return back()->with('success', "Solde d'ouverture validé.");
    }
}
