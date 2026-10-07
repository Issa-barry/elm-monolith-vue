<?php

namespace App\Jobs;

use App\Models\CommandeAchat;
use App\Models\RegleValidationRole;
use App\Models\User;
use App\Notifications\CommandeAchatNotification;
use App\Services\Notification\NotificationDispatcher;
use App\Services\Notification\PushBodyFormatter;
use App\Services\Validation\ValidationParPlafondService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;

/**
 * Notifications d'un bon de commande fournisseur (ADR 0021). Toujours mis en file APRÈS le commit
 * de la transaction métier (`->afterCommit()` chez l'appelant) : jamais de notification pour une
 * opération annulée par un rollback. L'auteur de l'action n'est jamais notifié de sa propre action.
 *
 * - créée   → validateurs potentiels (permission `achats.valider` ET plafond couvrant le montant
 *             et l'agence) + super administrateurs de l'organisation ;
 * - validée → créateur + utilisateurs de l'agence de réception ayant `receptions.create` ;
 * - annulée → créateur.
 */
class NotifierCommandeAchatJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const CREEE = 'creee';

    public const VALIDEE = 'validee';

    public const ANNULEE = 'annulee';

    public int $tries = 3;

    public function __construct(
        public readonly string $commandeId,
        public readonly string $evenement,
        public readonly ?string $acteurId,
    ) {}

    public function handle(ValidationParPlafondService $plafonds): void
    {
        $commande = CommandeAchat::with(['site:id,nom', 'createdBy'])->find($this->commandeId);
        if ($commande === null) {
            return;
        }

        $destinataires = match ($this->evenement) {
            self::CREEE => $this->validateursPotentiels($commande, $plafonds),
            self::VALIDEE => collect([$commande->createdBy])->merge($this->receptionnaires($commande)),
            self::ANNULEE => collect([$commande->createdBy]),
            default => collect(),
        };

        $destinataires = $destinataires
            ->filter(fn (?User $u) => $u !== null && $u->id !== $this->acteurId && $u->is_active !== false)
            ->unique('id')
            ->values();

        if ($destinataires->isEmpty()) {
            return;
        }

        [$titre, $message] = $this->texte($commande);
        $notification = new CommandeAchatNotification($commande->id, 'commande_achat_'.$this->evenement, $titre, $message, (float) $commande->total_commande);
        $donnees = $notification->toArray($destinataires->first());

        NotificationDispatcher::send(
            $notification,
            $destinataires,
            'activite',
            fn () => [
                'title' => $titre,
                'body' => PushBodyFormatter::format($donnees),
                'data' => ['type' => 'commande_achat.'.$this->evenement, 'commande_achat_id' => $commande->id],
            ],
        );
    }

    /** @return Collection<int, User> */
    private function validateursPotentiels(CommandeAchat $commande, ValidationParPlafondService $plafonds): Collection
    {
        $montant = (float) $commande->total_commande;

        return User::where('organization_id', $commande->organization_id)
            ->with('roles')
            ->get()
            ->filter(fn (User $u) => $u->hasRole('super_admin')
                || ($u->checkPermissionTo('achats.valider')
                    && $plafonds->peutValider($u, RegleValidationRole::DOMAINE_ACHATS, $commande->site_id, $montant)));
    }

    /** @return Collection<int, User> */
    private function receptionnaires(CommandeAchat $commande): Collection
    {
        if ($commande->site_id === null) {
            return collect();
        }

        return User::where('organization_id', $commande->organization_id)
            ->whereHas('sites', fn ($q) => $q->where('sites.id', $commande->site_id))
            ->with('roles')
            ->get()
            ->filter(fn (User $u) => $u->checkPermissionTo('receptions.create'));
    }

    /** @return array{0: string, 1: string} */
    private function texte(CommandeAchat $commande): array
    {
        $agence = $commande->siteNom() ?? '—';

        return match ($this->evenement) {
            self::CREEE => ['Bon de commande à valider', "{$commande->reference} pour {$agence} attend votre validation."],
            self::VALIDEE => ['Bon de commande validé', "{$commande->reference} est validé : réception attendue à {$agence}."],
            default => ['Bon de commande annulé', "{$commande->reference} a été annulé : {$commande->motif_annulation}"],
        };
    }
}
