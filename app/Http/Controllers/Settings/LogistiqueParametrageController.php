<?php

namespace App\Http\Controllers\Settings;

use App\Enums\DeclencheurCommissionLogistique;
use App\Http\Controllers\Controller;
use App\Models\Parametre;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Paramètres → Logistique. Réglages de workflow des transferts logistiques — déplacés le
 * 07/09/2026 depuis Paramètres → Ventes (VenteParametrageController), où ils avaient atterri par
 * réutilisation de contrôleur plutôt que par cohérence métier : ce sont des réglages logistiques,
 * pas des réglages de vente. `Parametre::CLE_DECLENCHEUR_COMMISSION_LOGISTIQUE` et
 * `CLE_LOGISTIQUE_APPROBATION_RECEPTION_OBLIGATOIRE` ne changent pas de clé ni de valeur — seule
 * leur écran d'édition change.
 *
 * Ne gère QUE le déclenchement du workflow (quand la commission naît, quand l'admin doit
 * l'approuver) — jamais le calcul lui-même : le montant et le partage entre bénéficiaires sont
 * exclusivement pilotés par `Settings\CommissionRegleController` (Paramètres > Commissions >
 * Transferts logistiques). Le paramètre `montant_defaut_commission_logistique_par_pack`, retiré
 * le 07/09/2026, en était un vestige mort (plus lu par aucun code de génération depuis le retrait
 * de `CommissionLogistiqueService`, cf. COMM-007) : il aurait suggéré une seconde source de
 * vérité, jamais réellement consommée.
 */
class LogistiqueParametrageController extends Controller
{
    public function edit(): Response
    {
        abort_if(! auth()->user()->can('parametres.read'), 403);

        $orgId = auth()->user()->organization_id;

        return Inertia::render('settings/Logistique', [
            'declencheur_commission_logistique' => Parametre::getDeclencheurCommissionLogistique($orgId)->value,
            'approbation_reception_logistique_obligatoire' => Parametre::isApprobationReceptionLogistiqueObligatoire($orgId),
            'declencheurs_commission_logistique_options' => DeclencheurCommissionLogistique::options(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_if(! auth()->user()->can('parametres.update'), 403);

        $validated = $request->validate([
            'declencheur_commission_logistique' => ['required', Rule::in(array_column(DeclencheurCommissionLogistique::cases(), 'value'))],
            'approbation_reception_logistique_obligatoire' => ['required', 'boolean'],
        ]);

        $orgId = auth()->user()->organization_id;

        Parametre::setDeclencheurCommissionLogistique(
            $orgId,
            DeclencheurCommissionLogistique::from($validated['declencheur_commission_logistique']),
        );
        Parametre::setApprobationReceptionLogistiqueObligatoire(
            $orgId,
            (bool) $validated['approbation_reception_logistique_obligatoire'],
        );

        Parametre::clearCache($orgId);

        return back()->with('success', 'Parametrage logistique mis a jour.');
    }
}
