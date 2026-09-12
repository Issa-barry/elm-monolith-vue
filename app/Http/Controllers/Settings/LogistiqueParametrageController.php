<?php

namespace App\Http\Controllers\Settings;

use App\Enums\DeclencheurCommissionLogistique;
use App\Http\Controllers\Controller;
use App\Models\Parametre;
use App\Models\Site;
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
 *
 * Depuis le 07/09/2026 (même jour) : `approbation_reception_logistique_obligatoire` n'est plus
 * qu'un DÉFAUT organisation, dérogeable par site (cf. Site::approbationReceptionObligatoireEffective(),
 * résolu par le site DESTINATION du transfert). updateSite() édite cette dérogation une ligne à la
 * fois — tableau récapitulatif choisi plutôt qu'un champ sur la fiche Site (EditSiteController), le
 * nombre de sites d'une organisation restant en pratique bien plus restreint que son nombre de
 * véhicules/clients, pour qui la dérogation individuelle (cf. SolvabiliteService) reste sur leur
 * propre fiche.
 */
class LogistiqueParametrageController extends Controller
{
    public function edit(): Response
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

    /**
     * Édite la dérogation d'UN site — indépendante du formulaire principal (chaque ligne du
     * tableau s'enregistre elle-même, pas de soumission groupée). `approbation_reception_
     * logistique_obligatoire` accepte explicitement `null` ("hérite du réglage organisation"),
     * en plus de `true`/`false` (dérogation explicite) — cf. Site::approbationReceptionObligatoireEffective().
     */
    public function updateSite(Request $request, Site $site): RedirectResponse
    {
        abort_if(! auth()->user()->can('parametres.update'), 403);

        // Vérification cross-tenant explicite : {site} est lié par route sans scope organisation
        // (contrairement à update() ci-dessus, qui n'agit que sur l'organisation de l'acteur) —
        // sans ce contrôle, un admin pourrait modifier la dérogation d'un site d'une AUTRE
        // organisation en devinant/rejouant son identifiant (cf. CLAUDE.md §11).
        abort_if($site->organization_id !== auth()->user()->organization_id, 403);

        $validated = $request->validate([
            'approbation_reception_logistique_obligatoire' => ['nullable', 'boolean'],
        ]);

        $site->update([
            'approbation_reception_logistique_obligatoire' => $validated['approbation_reception_logistique_obligatoire'] ?? null,
        ]);

        return back()->with('success', 'Reglage du site mis a jour.');
    }
}
