<?php

namespace App\Http\Controllers\Depenses;

use App\Enums\AuditEvent;
use App\Enums\StatutDepense;
use App\Http\Controllers\Controller;
use App\Models\Depense;
use App\Models\DepenseImputation;
use App\Notifications\DepenseValideeNotification;
use App\Services\AuditLogService;
use App\Services\DepenseImputationService;
use App\Services\DroitCreationDepenseService;
use App\Services\Notification\BeneficiaireUserResolver;
use App\Services\Notification\NotificationDispatcher;
use App\Services\Notification\PushBodyFormatter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ValiderDepenseController extends Controller
{
    public function __construct(
        private readonly DepenseImputationService $imputationService,
        private readonly AuditLogService $audit,
        private readonly DroitCreationDepenseService $droitCreationDepense,
    ) {}

    public function __invoke(Depense $depense): RedirectResponse
    {
        $this->authorize('valider', $depense);

        $user = auth()->user();

        if ($depense->statut !== StatutDepense::SOUMIS) {
            return back()->withErrors(['statut' => 'Seules les dépenses soumises peuvent être validées.']);
        }

        $droitValidation = $this->droitCreationDepense->droitValidationPour($user, $user->organization_id);
        if (! $this->droitCreationDepense->peutValiderMontant($user, $droitValidation, (float) $depense->montant)) {
            $plafondFmt = number_format((float) ($droitValidation?->plafond_validation ?? 0), 0, ',', "\u{202F}");

            return back()->withErrors(['montant' => "Vous ne pouvez pas valider cette dépense. Le montant de votre autorisation est limité à {$plafondFmt} GNF."]);
        }

        $depense->load('depenseType');

        $imputation = null;

        try {
            DB::transaction(function () use ($depense, &$imputation) {
                $depense->update([
                    'statut' => StatutDepense::VALIDE,
                    'validateur_id' => auth()->id(),
                    'date_validation' => now(),
                    'motif_rejet' => null,
                ]);

                $imputation = $this->imputationService->creer($depense);
            });
        } catch (\RuntimeException $e) {
            return back()->withErrors(['imputation' => $e->getMessage()]);
        }

        $this->notifierDepenseValidee($depense, $imputation);

        $montantFmt = number_format((float) $depense->montant, 0, ',', "\u{202F}");
        $this->audit->record($depense, AuditEvent::VALIDATED, auth()->user(), null, null, [
            'module' => 'depenses',
            'site_id' => $depense->site_id,
            'description' => "Dépense \"{$depense->depenseType?->libelle}\" validée — {$montantFmt} GNF",
        ]);

        return back()->with('success', 'Dépense validée et imputée.');
    }

    /**
     * Scope phase 1 (cf. rapport notifications, 2026-08-27) : seul
     * beneficiaire_type === 'proprietaire' (dépense catégorie VEHICULE imputée
     * au propriétaire du véhicule) déclenche cette notification —
     * livreur/site/salarie/prestataire restent hors périmètre pour l'instant,
     * sans erreur. Jamais de rethrow : un échec d'envoi ne doit jamais faire
     * annuler une validation déjà enregistrée.
     */
    private function notifierDepenseValidee(Depense $depense, ?DepenseImputation $imputation): void
    {
        if ($imputation?->beneficiaire_type !== 'proprietaire') {
            return;
        }

        try {
            $user = BeneficiaireUserResolver::resolve('proprietaire', $imputation->beneficiaire_id);

            $vehiculeNom = $depense->beneficiaire_type === 'vehicule'
                ? ($depense->vehiculeBeneficiaire?->nom_vehicule ?? '—')
                : '—';

            $notif = new DepenseValideeNotification($depense->id, $vehiculeNom, (float) $depense->montant);
            $notifData = $user ? $notif->toArray($user) : null;

            NotificationDispatcher::send(
                $notif,
                [$user],
                'depenses',
                $notifData ? fn () => [
                    'title' => $notifData['titre'],
                    'body' => PushBodyFormatter::format($notifData),
                    'data' => ['type' => 'expense.validated', 'depense_id' => $depense->id],
                ] : null,
            );
        } catch (\Throwable $e) {
            Log::error('DepenseValideeNotification : envoi échoué', [
                'depense_id' => $depense->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
