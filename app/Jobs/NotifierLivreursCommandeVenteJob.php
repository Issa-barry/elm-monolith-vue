<?php

namespace App\Jobs;

use App\Models\CommandeVente;
use App\Models\Livreur;
use App\Models\Proprietaire;
use App\Models\User;
use App\Notifications\CommandeValideeNotification;
use App\Services\ExpoPushNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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

        $siteNom    = $commande->site?->nom ?? '—';
        $notif      = new CommandeValideeNotification($this->commandeId, $this->reference, $siteNom);
        $pushTokens = [];

        // ── Livreurs de l'équipe ─────────────────────────────────────────────
        $livreurs = $commande->vehicule->equipe?->livreurs ?? collect();

        foreach ($livreurs as $livreur) {
            $user = $this->userForLivreur($livreur);
            if ($user) {
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
            $user?->notify($notif);
            if ($user?->expo_push_token) {
                $pushTokens[] = $user->expo_push_token;
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
                    'type'        => 'commande_vente_validee',
                    'commande_id' => $this->commandeId,
                ]
            );
        } catch (\Throwable $e) {
            Log::error('NotifierLivreursCommandeVenteJob: push échoué', [
                'commande_id' => $this->commandeId,
                'error'       => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function userForLivreur(Livreur $livreur): ?User
    {
        if ($livreur->user_id) {
            return $livreur->user ?? User::find($livreur->user_id);
        }

        return $livreur->telephone
            ? User::where('telephone', $livreur->telephone)->first()
            : null;
    }

    private function userForProprietaire(Proprietaire $proprietaire): ?User
    {
        if ($proprietaire->user_id) {
            return User::find($proprietaire->user_id);
        }

        return $proprietaire->telephone
            ? User::where('telephone', $proprietaire->telephone)->first()
            : null;
    }
}
