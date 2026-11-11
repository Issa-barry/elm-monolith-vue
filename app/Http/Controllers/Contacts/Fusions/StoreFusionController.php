<?php

namespace App\Http\Controllers\Contacts\Fusions;

use App\Http\Controllers\Controller;
use App\Models\ContactsFusion;
use App\Models\EntrepriseTierce;
use App\Models\Personne;
use App\Services\Contacts\Fusion\FusionContacts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Confirmation d'une fusion (lot 9b) : le serveur refait la simulation sous verrou, compare
 * l'empreinte et les confirmations, puis exécute. Aucune donnée du navigateur n'est prise pour
 * acquise hormis les choix, revalidés.
 */
class StoreFusionController extends Controller
{
    public function __invoke(Request $request, FusionContacts $fusions): RedirectResponse
    {
        $donnees = $request->validate([
            'type' => ['required', Rule::in([ContactsFusion::TYPE_PERSONNE, ContactsFusion::TYPE_ENTREPRISE])],
            'fiche_a' => ['required', 'ulid'],
            'fiche_b' => ['required', 'ulid'],
            'choix' => ['array'],
            'choix.*' => ['nullable', 'string', 'max:100'],
            'confirmations' => ['array'],
            'empreinte' => ['required', 'string', 'size:64'],
            'jeton' => ['required', 'uuid'],
        ]);

        $this->authorize('fusionner', $donnees['type'] === ContactsFusion::TYPE_PERSONNE ? Personne::class : EntrepriseTierce::class);

        $confirmations = collect($donnees['confirmations'] ?? [])
            ->map(fn ($v, $cle) => $cle === 'transfert_compte' ? (string) $v : filter_var($v, FILTER_VALIDATE_BOOLEAN))
            ->all();

        $fusion = $fusions->executer(
            $request->user(), $donnees['type'], $donnees['fiche_a'], $donnees['fiche_b'],
            $donnees['choix'] ?? [], $confirmations, $donnees['empreinte'], $donnees['jeton'],
        );

        $dossier = $fusion->type === ContactsFusion::TYPE_PERSONNE ? 'personnes' : 'entreprises';
        $nomB = $fusion->simulation['b']['nom'] ?? 'La fiche absorbée';

        return redirect("/backoffice/contacts/{$dossier}/{$fusion->fiche_conservee_id}")
            ->with('success', "Fusion effectuée : {$nomB} a été fusionnée dans cette fiche (annulable depuis l'historique des fusions).");
    }
}
