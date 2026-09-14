<?php

namespace App\Http\Controllers\Ventes;

use App\Enums\CommissionGenerationDeclenchePar;
use App\Http\Controllers\Controller;
use App\Models\CommandeVente;
use App\Services\Commission\CommissionEnveloppeGenerator;
use App\Support\Ventes\CommandeVenteCommissionStatus;
use Illuminate\Http\RedirectResponse;

class RelancerCommissionsCommandeVenteController extends Controller
{
    /**
     * Relance manuelle après un échec de génération de commission ("à
     * régulariser") — rejoue le mécanisme officiel (CommissionEnveloppeGenerator),
     * jamais un recalcul ad hoc. Idempotent par construction : si une enveloppe
     * existe déjà (généré entre-temps), l'appel est un no-op silencieux.
     */
    public function __invoke(CommandeVente $commande_vente): RedirectResponse
    {
        $this->authorize('update', $commande_vente);

        CommissionEnveloppeGenerator::genererPourCommandeVente(
            $commande_vente,
            CommissionGenerationDeclenchePar::UTILISATEUR,
            auth()->id(),
        );

        $statut = CommandeVenteCommissionStatus::getCommissionGenerationStatut($commande_vente);

        $commande_vente->cloturerSiComplete();

        if ($statut !== null && $statut['value'] === 'erreur') {
            return redirect()->route('ventes.show', $commande_vente)->withErrors([
                'commissions' => "La génération a de nouveau échoué : {$statut['motif']}",
            ]);
        }

        if ($statut !== null && $statut['value'] === 'partiel') {
            return redirect()->route('ventes.show', $commande_vente)->withErrors([
                'commissions' => "Certaines cibles restent à régulariser : {$statut['motif']}",
            ]);
        }

        return redirect()->route('ventes.show', $commande_vente)->with('success', 'Commissions générées avec succès.');
    }
}
