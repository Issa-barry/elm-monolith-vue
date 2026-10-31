<?php

namespace App\Http\Controllers\Ventes\Regularisation;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\CommandeVente;
use App\Models\CommissionEcartRegularisation;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Constate la régularisation financière d'un trop-perçu de commission (retenue, remboursement par
 * le bénéficiaire…) — ADR 0043. Ne touche à aucun paiement ni à aucun montant : la démarche réelle
 * est décrite dans le commentaire obligatoire. Un complément n'est jamais marqué à la main : il se
 * paie par la fiche et se solde seul (sinon il pourrait être payé deux fois).
 */
class MarquerEcartCommissionRegulariseController extends Controller
{
    public function __invoke(Request $request, CommandeVente $commande_vente, CommissionEcartRegularisation $ecart, AuditLogService $audit): RedirectResponse
    {
        $this->authorize('regulariserApresEncaissement', $commande_vente);
        abort_unless(
            $commande_vente->organization_id === $request->user()->organization_id
                && $ecart->commande_vente_id === $commande_vente->id,
            404,
        );

        $data = $request->validate(
            ['commentaire' => ['required', 'string', 'min:10', 'max:2000']],
            [
                'commentaire.required' => 'Décrivez comment l\'écart a été régularisé.',
                'commentaire.min' => 'Le commentaire doit faire au moins 10 caractères.',
            ],
        );

        DB::transaction(function () use ($ecart, $data, $request, $commande_vente, $audit) {
            $ecart = CommissionEcartRegularisation::whereKey($ecart->id)->lockForUpdate()->firstOrFail();
            abort_unless($ecart->estARegulariser(), 422, 'Cet écart est déjà régularisé.');
            abort_unless($ecart->peutEtreMarqueRegularise(), 422, 'Un complément se paie par la fiche de paiement : il est soldé automatiquement.');

            $ecart->update([
                'statut' => CommissionEcartRegularisation::STATUT_REGULARISE,
                'commentaire_regularisation' => trim($data['commentaire']),
                'regularise_par' => $request->user()->id,
                'regularise_at' => now(),
            ]);

            $audit->record($commande_vente, AuditEvent::STATUS_CHANGED, $request->user(), [
                'ecart_commission' => CommissionEcartRegularisation::STATUT_A_REGULARISER,
            ], [
                'ecart_commission' => CommissionEcartRegularisation::STATUT_REGULARISE,
            ], [
                'commission_ecart_regularisation_id' => $ecart->id,
                'beneficiaire' => $ecart->beneficiaire_libelle,
                'ecart' => (float) $ecart->ecart,
                'commentaire' => trim($data['commentaire']),
            ]);
        });

        return back()->with('success', 'Écart de commission marqué comme régularisé.');
    }
}
