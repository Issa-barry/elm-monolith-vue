<?php

namespace App\Http\Controllers;

use App\Enums\ModePaiement;
use App\Enums\StatutFactureVente;
use App\Models\FactureVente;
use Inertia\Inertia;
use Inertia\Response;

class FactureVenteController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', \App\Models\CommandeVente::class);

        $orgId = auth()->user()->organization_id;

        $factures = FactureVente::with([
                'commande.vehicule',
                'commande.client',
                'commande.site',
                'encaissements',
            ])
            ->where('organization_id', $orgId)
            ->whereNotNull('reference')
            ->where('reference', 'not like', 'TMP-%')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (FactureVente $f) => [
                'id'                 => $f->id,
                'reference'          => $f->reference,
                'commande_id'        => $f->commande_vente_id,
                'commande_reference' => $f->commande?->reference,
                'vehicule_nom'       => $f->commande?->vehicule?->nom_vehicule,
                'client_nom'         => $f->commande?->client
                    ? trim($f->commande->client->prenom . ' ' . $f->commande->client->nom)
                    : null,
                'site_nom'           => $f->commande?->site?->nom,
                'montant_net'        => (float) $f->montant_net,
                'montant_encaisse'   => (float) $f->montant_encaisse,
                'montant_restant'    => (float) $f->montant_restant,
                'statut_facture'     => $f->statut_facture?->value,
                'statut_label'       => $f->statut_label,
                'is_annulee'         => $f->isAnnulee(),
                'is_payee'           => $f->isPayee(),
                'created_at'         => $f->created_at?->format('d/m/Y'),
            ]);

        // Totaux pour les cartes de synthèse
        $impayees   = $factures->where('statut_facture', StatutFactureVente::IMPAYEE->value);
        $partielles = $factures->where('statut_facture', StatutFactureVente::PARTIEL->value);
        $payees     = $factures->where('statut_facture', StatutFactureVente::PAYEE->value);

        $totaux = [
            'total_a_encaisser'    => $factures
                ->whereNotIn('statut_facture', [StatutFactureVente::PAYEE->value, StatutFactureVente::ANNULEE->value])
                ->sum('montant_restant'),
            'nb_impayees'          => $impayees->count(),
            'montant_impayees'     => $impayees->sum('montant_restant'),
            'nb_partielles'        => $partielles->count(),
            'montant_partielles'   => $partielles->sum('montant_restant'),
            'nb_payees'            => $payees->count(),
            'montant_payees'       => $payees->sum('montant_net'),
        ];

        return Inertia::render('Factures/Index', [
            'factures'       => $factures->values(),
            'totaux'         => $totaux,
            'modes_paiement' => ModePaiement::options(),
        ]);
    }
}
