<?php

namespace App\Http\Controllers\Achats\Factures;

use App\Http\Controllers\Controller;
use App\Models\CommandeAchat;
use App\Models\FactureFournisseur;
use App\Services\Achats\PerimetreCommandesAchat;
use App\Support\Achats\FactureFournisseurPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Saisie d'une facture depuis un bon de commande validé (?commande=…). */
class CreateFactureFournisseurController extends Controller
{
    public function __invoke(Request $request, PerimetreCommandesAchat $perimetre, FactureFournisseurPresenter $presenter): Response
    {
        $this->authorize('create', FactureFournisseur::class);

        $commande = CommandeAchat::where('organization_id', $request->user()->organization_id)
            ->findOrFail((string) $request->query('commande'));
        $this->authorize('view', $commande);
        $perimetre->autoriser($commande, $request->user());

        return Inertia::render('Achats/Factures/Form', [
            'facture' => null,
            'commande' => $presenter->commande($commande),
            'receptions' => $presenter->receptions($commande),
        ]);
    }
}
