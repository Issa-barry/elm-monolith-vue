<?php

namespace App\Jobs;

use App\Models\CommandeVente;
use App\Models\Livreur;
use App\Models\Personne;
use App\Models\Proprietaire;
use App\Models\User;
use App\Models\UserAuthIdentity;
use App\Notifications\CommandeValideeNotification;
use App\Services\ExpoPushNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Seul point d'envoi réel de la catégorie de préférence `activite`
 * (`User::NOTIFICATION_PREFERENCE_DEFAULTS`, cf. audit backend du 26/08/2026,
 * section notifications) : avant ce correctif, `notification_preferences`
 * était stocké et exposé via l'API profil (GET/PATCH /v1/mobile/profile*)
 * mais JAMAIS consulté ici — désactiver "activite" dans les préférences
 * n'avait donc AUCUN effet réel (ni sur la notification en base, ni sur le
 * push Expo). Corrigé en filtrant les deux par utilisateur avant envoi.
 * `NotificationsController::index()` (lecture) reste volontairement
 * inchangé : l'historique déjà généré n'est jamais purgé rétroactivement par
 * un changement de préférence, seule la génération future est concernée.
 */
class NotifierLivreursCommandeVenteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private readonly string $commandeId,
        private readonly string $reference,
    ) {}

    public function handle(ExpoPushNotificationService $push): void
    {
        $commande = CommandeVente::with([
            'site:id,nom',
            'vehicule.proprietaire',
            'vehicule.equipe.livreurs.user',
        ])->find($this->commandeId);

        if (! $commande?->vehicule) {
            return;
        }

        $siteNom = $commande->site?->nom ?? '—';
        $notif = new CommandeValideeNotification($this->commandeId, $this->reference, $siteNom);
        $pushTokens = [];

        // ── Livreurs de l'équipe ─────────────────────────────────────────────
        $livreurs = $commande->vehicule->equipe?->livreurs ?? collect();

        foreach ($livreurs as $livreur) {
            $user = $this->userForLivreur($livreur);
            if ($user && $this->veutNotificationsActivite($user)) {
                $user->notify($notif);
                if ($user->expo_push_token) {
                    $pushTokens[] = $user->expo_push_token;
                }
            }
        }

        // ── Propriétaire du véhicule ─────────────────────────────────────────
        $proprietaire = $commande->vehicule->proprietaire;
        if ($proprietaire) {
            $user = $this->userForProprietaire($proprietaire);
            if ($user && $this->veutNotificationsActivite($user)) {
                $user->notify($notif);
                if ($user->expo_push_token) {
                    $pushTokens[] = $user->expo_push_token;
                }
            }
        }

        // ── Push Expo ────────────────────────────────────────────────────────
        $pushTokens = array_unique(array_filter($pushTokens));
        if (empty($pushTokens)) {
            return;
        }

        try {
            $push->sendMany(
                array_values($pushTokens),
                'Nouvelle commande assignée',
                "Réf. {$this->reference} — Vous avez une livraison à effectuer.",
                [
                    'type' => 'commande_vente_validee',
                    'commande_id' => $this->commandeId,
                ]
            );
        } catch (\Throwable $e) {
            Log::error('NotifierLivreursCommandeVenteJob: push échoué', [
                'commande_id' => $this->commandeId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function veutNotificationsActivite(User $user): bool
    {
        return $user->notificationPreferences()['activite'] ?? true;
    }

    private function userForLivreur(Livreur $livreur): ?User
    {
        if ($livreur->user_id) {
            return $livreur->user ?? User::find($livreur->user_id);
        }

        return $livreur->telephone
            ? UserAuthIdentity::resoudre(UserAuthIdentity::TYPE_TELEPHONE, Personne::normaliserTelephone($livreur->telephone))
            : null;
    }

    private function userForProprietaire(Proprietaire $proprietaire): ?User
    {
        if ($proprietaire->user_id) {
            return User::find($proprietaire->user_id);
        }

        return $proprietaire->telephone
            ? UserAuthIdentity::resoudre(UserAuthIdentity::TYPE_TELEPHONE, Personne::normaliserTelephone($proprietaire->telephone))
            : null;
    }
}
