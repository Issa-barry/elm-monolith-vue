<?php

namespace App\Http\Controllers\Comptabilite;

use App\Enums\AuditEvent;
use App\Enums\ModePaiement;
use App\Http\Controllers\Controller;
use App\Models\PaiementFiche;
use App\Models\PaiementFichePaiement;
use App\Notifications\CommissionPayeeNotification;
use App\Services\AuditLogService;
use App\Services\CommissionEnveloppePartAllocationService;
use App\Services\Notification\BeneficiaireUserResolver;
use App\Services\Notification\NotificationDispatcher;
use App\Services\Notification\PushBodyFormatter;
use App\Services\PeriodePayabilityChecker;
use App\Services\Tresorerie\DecaissementFicheResolver;
use App\Services\Tresorerie\TresorerieDisponibiliteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class PaiementFichePaiementController extends Controller
{
    public const MESSAGE_SALARIE = 'Les salaires se paient depuis Comptabilité > Paiement salaire.';

    public function store(Request $request, PaiementFiche $fiche): RedirectResponse
    {
        $this->authorize('payer', $fiche);

        // Un salaire a un seul circuit de paiement : la Paie (PaieLigne/PaiePaiement, comptabilisée
        // par PaieComptabilisationService). La fiche salarié est construite sur la même PaieLigne
        // sans qu'aucun des deux circuits ne voie l'autre : la payer ici permettrait de payer deux
        // fois le même salaire, sans écriture comptable (ADR 0009).
        if ($fiche->beneficiaire_type === 'salarie') {
            throw ValidationException::withMessages(['fiche' => self::MESSAGE_SALARIE]);
        }

        try {
            PeriodePayabilityChecker::assertPeriodePayable($fiche->periode);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        $restant = $fiche->montant_restant;

        $data = $request->validate([
            'montant' => ['required', 'numeric', 'min:1', 'max:'.$restant],
            'mode_paiement' => ['required', 'in:'.implode(',', array_column(ModePaiement::cases(), 'value'))],
            // Support d'où sort l'argent (ADR 0009) — inutile en espèces : la caisse dédiée du payeur
            // est résolue côté serveur, jamais choisie par l'écran.
            'compte_tresorerie_id' => ['nullable', 'string', 'required_unless:mode_paiement,'.ModePaiement::ESPECES->value],
            'reference_paiement' => ['nullable', 'string', 'max:190'],
            'date_paiement' => ['required', 'date'],
            'note' => ['nullable', 'string'],
        ], [
            'compte_tresorerie_id.required_unless' => "Choisissez le compte d'où sort ce paiement.",
        ]);

        $decaissement = app(DecaissementFicheResolver::class);
        $user = $request->user();
        $support = $decaissement->supportPour($fiche, $user, $data['mode_paiement'], $data['compte_tresorerie_id'] ?? null);

        if ($decaissement->referenceRequise($fiche, $data['mode_paiement'], $support->id) && blank($data['reference_paiement'] ?? null)) {
            throw ValidationException::withMessages([
                'reference_paiement' => 'La référence du paiement est obligatoire pour ce mode de paiement.',
            ]);
        }

        try {
            $paiement = DB::transaction(function () use ($fiche, $data, $support) {
                // Sous verrou : deux paiements concurrents de la même fiche relisent le reste dû
                // déjà diminué, et le solde du support est relu depuis le grand livre juste avant
                // la sortie (TresorerieDisponibiliteService::garantirSoldeSuffisant()). Un refus
                // ici annule tout : aucun paiement, aucune allocation, aucune écriture.
                $restantVerrouille = PaiementFiche::whereKey($fiche->id)->lockForUpdate()->firstOrFail()->montant_restant;
                if ((float) $data['montant'] > $restantVerrouille + 0.004) {
                    throw ValidationException::withMessages([
                        'montant' => 'Le montant dépasse le reste à payer de la fiche ('.number_format($restantVerrouille, 0, ',', ' ').' GNF).',
                    ]);
                }

                app(TresorerieDisponibiliteService::class)->garantirSoldeSuffisant(
                    $support->id,
                    (float) $data['montant'],
                    now(),
                    'un paiement',
                );

                $paiement = PaiementFichePaiement::create([
                    'fiche_id' => $fiche->id,
                    'organization_id' => $fiche->organization_id,
                    // Agence d'où sort l'argent (siège principal pour une fiche sans agence) : la
                    // pièce comptable et le solde du support sont rattachés à ce site.
                    'site_id' => $support->site_id,
                    'montant' => $data['montant'],
                    'mode_paiement' => $data['mode_paiement'],
                    'moyen_paiement_detail' => $support->operateur_mobile_money?->value,
                    'reference_paiement' => $data['reference_paiement'] ?? null,
                    'compte_tresorerie_id' => $support->id,
                    'date_paiement' => $data['date_paiement'],
                    'note' => $data['note'] ?? null,
                ]);

                // Reporte ce paiement sur les CommissionEnveloppePart sous-jacentes de la
                // fiche, pour que Commission vente/propriétaire restent synchronisées avec
                // ce paiement (une seule chaîne de paiement). Auto-scopé et sans effet pour
                // une fiche logistique : partsPourFiche() ne trouve rien à allouer quand
                // aucune ligne de la fiche ne référence CommissionEnveloppePart.
                CommissionEnveloppePartAllocationService::allouer($fiche, $paiement);

                $this->notifierCommissionPayee($fiche, $paiement);

                return $paiement;
            });
        } catch (\RuntimeException $e) {
            return back()->withErrors(['comptabilisation' => "Paiement non enregistré : {$e->getMessage()}"]);
        }

        $montantFmt = number_format((float) $data['montant'], 0, ',', "\u{202F}");
        app(AuditLogService::class)->record($fiche, AuditEvent::PAID, auth()->user(), null, null, [
            'module' => 'fiches_paiement',
            'site_id' => $fiche->site_id,
            'montant' => $data['montant'],
            'mode_paiement' => $data['mode_paiement'],
            'description' => "Paiement de {$montantFmt} GNF enregistré pour {$fiche->beneficiaire_nom}",
        ]);

        return back()->with('success', 'Paiement enregistré avec succès.');
    }

    public function destroy(PaiementFichePaiement $paiement): RedirectResponse
    {
        $fiche = $paiement->fiche;
        $this->authorize('payer', $fiche);

        try {
            PeriodePayabilityChecker::assertPeriodePayable($fiche->periode);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        $montantFmt = number_format((float) $paiement->montant, 0, ',', "\u{202F}");
        app(AuditLogService::class)->record($fiche, AuditEvent::PAYMENT_CANCELLED, auth()->user(), null, null, [
            'module' => 'fiches_paiement',
            'site_id' => $fiche->site_id,
            'montant' => (float) $paiement->montant,
            'description' => "Paiement de {$montantFmt} GNF annulé pour {$fiche->beneficiaire_nom}",
        ]);

        CommissionEnveloppePartAllocationService::desallouer($paiement);

        $paiement->delete();

        return back()->with('success', 'Paiement supprimé.');
    }

    /**
     * Bénéficiaire réel de la fiche (proprietaire/livreur) — couvre à la fois
     * les fiches vente et logistique en un seul point : `site`/`salarie`/
     * `prestataire` n'ont aucun compte utilisateur et ne déclenchent jamais
     * d'envoi (cf. BeneficiaireUserResolver). Jamais de rethrow : un échec
     * d'envoi ne doit jamais faire annuler un paiement déjà enregistré.
     */
    private function notifierCommissionPayee(PaiementFiche $fiche, PaiementFichePaiement $paiement): void
    {
        try {
            $user = BeneficiaireUserResolver::resolve($fiche->beneficiaire_type, $fiche->beneficiaire_id);
            $notif = new CommissionPayeeNotification((float) $paiement->montant, $paiement->mode_paiement, $paiement->note, 'paiement_fiche', $fiche->id);
            $notifData = $user ? $notif->toArray($user) : null;

            NotificationDispatcher::send(
                $notif,
                [$user],
                'commissions',
                // Pas d'ID navigable ici (fiche = document comptable interne) — le type seul
                // suffit (cf. rapport Web Push 7/7).
                $notifData ? fn () => [
                    'title' => $notifData['titre'],
                    'body' => PushBodyFormatter::format($notifData),
                    'data' => ['type' => 'commission.paid'],
                ] : null,
            );
        } catch (Throwable $e) {
            Log::error('CommissionPayeeNotification (fiche) : envoi échoué', [
                'fiche_id' => $fiche->id,
                'paiement_id' => $paiement->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
