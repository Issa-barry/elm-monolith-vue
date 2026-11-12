<?php

namespace App\Http\Controllers\Contacts\Fusions;

use App\Http\Controllers\Controller;
use App\Models\ContactsFusion;
use App\Models\ContactsFusionOperation;
use App\Models\Personne;
use App\Services\Contacts\Fusion\AnnulationFusionContacts;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Détail d'une fusion (lot 9b) : arbitrages, journal complet et, tant qu'elle est exécutée, ce que
 * l'annulation restaurerait et ce qui demanderait une intervention (aucune écriture ici).
 */
class ShowFusionController extends Controller
{
    public function __invoke(Request $request, ContactsFusion $fusion, AnnulationFusionContacts $annulation): Response
    {
        $this->authorize('fusionner', Personne::class);
        abort_unless($fusion->organization_id === $request->user()->organization_id, 404);

        $fusion->load(['acteur.personne', 'annuleePar.personne']);

        return Inertia::render('Contacts/Fusions/Show', [
            'fusion' => [
                'id' => $fusion->id,
                'type' => $fusion->type,
                'statut' => $fusion->statut,
                'statut_label' => ContactsFusion::LIBELLES_STATUT[$fusion->statut] ?? $fusion->statut,
                'date' => $fusion->created_at?->format('d/m/Y H:i'),
                'acteur' => $fusion->acteur?->personne?->nom_complet,
                'annulee_le' => $fusion->annulee_le?->format('d/m/Y H:i'),
                'annulee_par' => $fusion->annuleePar?->personne?->nom_complet,
                'annulation' => $fusion->annulation,
                'simulation' => $fusion->simulation,
                'confirmations' => $fusion->confirmations,
            ],
            'operations' => $fusion->operations()->get()->map(fn (ContactsFusionOperation $o) => [
                'id' => $o->id,
                'nature' => $o->nature,
                'libelle' => $o->libelle,
                'valeur_avant' => $o->valeur_avant,
                'valeur_apres' => $o->valeur_apres,
                'annulation_statut' => $o->annulation_statut,
            ])->values(),
            'analyse_annulation' => $fusion->statut === ContactsFusion::EXECUTEE ? $annulation->analyser($fusion, $request->user()) : null,
        ]);
    }
}
