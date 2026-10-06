<?php

namespace App\Http\Controllers\Settings\Logistique;

use App\Enums\DeclencheurCommissionLogistique;
use App\Http\Controllers\Controller;
use App\Models\Parametre;
use App\Models\Site;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Paramètres → Logistique. Réglages de workflow des transferts logistiques — déplacés le
 * 07/09/2026 depuis Paramètres → Ventes, où ils avaient atterri par réutilisation de contrôleur
 * plutôt que par cohérence métier. `Parametre::CLE_DECLENCHEUR_COMMISSION_LOGISTIQUE` et
 * `CLE_LOGISTIQUE_APPROBATION_RECEPTION_OBLIGATOIRE` ne changent pas de clé ni de valeur — seule
 * leur écran d'édition change.
 *
 * Ne gère QUE le déclenchement du workflow (quand la commission naît, quand l'admin doit
 * l'approuver) — jamais le calcul lui-même : le montant et le partage entre bénéficiaires sont
 * exclusivement pilotés par `Settings\CommissionRegleController` (Paramètres > Commissions >
 * Transferts logistiques).
 */
class EditLogistiqueParametrageController extends Controller
{
    public function __invoke(): Response
    {
        abort_if(! auth()->user()->can('parametres.read'), 403);

        $orgId = auth()->user()->organization_id;

        $sites = Site::where('organization_id', $orgId)
            ->orderBy('type')
            ->orderBy('nom')
            ->get(['id', 'nom', 'type', 'approbation_reception_logistique_obligatoire'])
            ->map(fn (Site $site) => [
                'id' => $site->id,
                'label' => $site->label,
                'type_label' => $site->type_label,
                'approbation_reception_logistique_obligatoire' => $site->approbation_reception_logistique_obligatoire,
            ])
            ->values();

        return Inertia::render('settings/Logistique', [
            'declencheur_commission_logistique' => Parametre::getDeclencheurCommissionLogistique($orgId)->value,
            'approbation_reception_logistique_obligatoire' => Parametre::isApprobationReceptionLogistiqueObligatoire($orgId),
            'declencheurs_commission_logistique_options' => DeclencheurCommissionLogistique::options(),
            'sites' => $sites,
        ]);
    }
}
