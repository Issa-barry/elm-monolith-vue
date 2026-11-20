<?php

namespace App\Services\Commission;

use App\Enums\AuditEvent;
use App\Models\CompteTresorerie;
use App\Models\PaiementFiche;
use App\Models\PaiementFichePaiement;
use App\Models\User;
use App\Notifications\CommissionPayeeNotification;
use App\Services\AuditLogService;
use App\Services\CommissionEnveloppePartAllocationService;
use App\Services\Notification\BeneficiaireUserResolver;
use App\Services\Notification\NotificationDispatcher;
use App\Services\Notification\PushBodyFormatter;
use App\Services\Tresorerie\TresorerieDisponibiliteService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Enregistrement d'un paiement sur une fiche (ADR 0009) : une seule chaîne, partagée par le bouton
 * Payer (PaiementFichePaiementController) et le paiement groupé (PaiementFicheLotController, ADR 0068).
 */
final class PaiementFicheService
{
    public function __construct(private readonly TresorerieDisponibiliteService $disponibilite) {}

    /**
     * À appeler dans une transaction : un refus (reste dépassé, solde insuffisant, comptabilisation
     * impossible) doit tout annuler — aucun paiement, aucune allocation, aucune écriture.
     *
     * @param  array{montant: float|int|string, mode_paiement: string, reference_paiement?: ?string, date_paiement: string, note?: ?string}  $data
     *
     * @throws ValidationException
     */
    public function enregistrer(PaiementFiche $fiche, CompteTresorerie $support, array $data): PaiementFichePaiement
    {
        // Sous verrou : deux paiements concurrents de la même fiche relisent le reste dû déjà
        // diminué, et le solde du support est relu depuis le grand livre juste avant la sortie.
        $restantVerrouille = PaiementFiche::whereKey($fiche->id)->lockForUpdate()->firstOrFail()->montant_restant;
        if ((float) $data['montant'] > $restantVerrouille + 0.004) {
            throw ValidationException::withMessages([
                'montant' => 'Le montant dépasse le reste à payer de la fiche ('.number_format($restantVerrouille, 0, ',', ' ').' GNF).',
            ]);
        }

        $this->disponibilite->garantirSoldeSuffisant($support->id, (float) $data['montant'], now(), 'un paiement');

        $paiement = PaiementFichePaiement::create([
            'fiche_id' => $fiche->id,
            'organization_id' => $fiche->organization_id,
            // Agence d'où sort l'argent (site central de trésorerie pour une fiche sans agence) : la
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

        // Reporte le paiement sur les CommissionEnveloppePart de la fiche (une seule chaîne de
        // paiement) ; sans effet pour une fiche dont aucune ligne ne référence une part.
        CommissionEnveloppePartAllocationService::allouer($fiche, $paiement);

        return $paiement;
    }

    /** Après validation de la transaction : jamais de notification pour un paiement annulé. */
    public function apresEnregistrement(PaiementFiche $fiche, PaiementFichePaiement $paiement, ?User $auteur, bool $groupe = false): void
    {
        $this->notifierCommissionPayee($fiche, $paiement);

        $montantFmt = number_format((float) $paiement->montant, 0, ',', "\u{202F}");
        app(AuditLogService::class)->record($fiche, AuditEvent::PAID, $auteur, null, null, [
            'module' => 'fiches_paiement',
            'site_id' => $fiche->site_id,
            'montant' => (float) $paiement->montant,
            'mode_paiement' => $paiement->mode_paiement,
            'description' => "Paiement de {$montantFmt} GNF enregistré pour {$fiche->beneficiaire_nom}".($groupe ? ' (paiement groupé)' : ''),
        ]);
    }

    /**
     * Bénéficiaire réel de la fiche (proprietaire/livreur) : `site`/`salarie`/`prestataire` n'ont
     * aucun compte utilisateur et ne déclenchent jamais d'envoi (cf. BeneficiaireUserResolver).
     * Jamais de rethrow : un échec d'envoi ne remet jamais en cause un paiement enregistré.
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
                // Pas d'ID navigable ici (fiche = document comptable interne) : le type seul suffit.
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
