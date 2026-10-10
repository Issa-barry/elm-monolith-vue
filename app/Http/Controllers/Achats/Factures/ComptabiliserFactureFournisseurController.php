<?php

namespace App\Http\Controllers\Achats\Factures;

use App\Http\Controllers\Controller;
use App\Models\FactureFournisseur;
use App\Services\Achats\FactureFournisseurService;
use App\Services\Achats\PerimetreCommandesAchat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Relance l'écriture d'une facture validée restée en attente (comptes paramétrés depuis). */
class ComptabiliserFactureFournisseurController extends Controller
{
    public function __invoke(Request $request, FactureFournisseur $facture, FactureFournisseurService $service, PerimetreCommandesAchat $perimetre): RedirectResponse
    {
        $this->authorize('valider', $facture);
        $perimetre->autoriserFacture($facture, $request->user());
        abort_unless($facture->isConstatee(), 422, 'Seule une facture validée peut être comptabilisée.');

        return $service->comptabiliser($facture)
            ? back()->with('success', 'Écriture comptable passée.')
            : back()->with('warning', "L'écriture comptable n'a pas pu être passée : {$facture->comptabilisation_erreur}");
    }
}
