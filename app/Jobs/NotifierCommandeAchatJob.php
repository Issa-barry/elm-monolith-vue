<?php

namespace App\Jobs;

use App\Models\CommandeAchat;
use App\Models\User;
use App\Notifications\CommandeAchatNotification;
use App\Services\Achats\CommandeAchatService;
use App\Services\Achats\PerimetreCommandesAchat;
use App\Services\Notification\NotificationDispatcher;
use App\Services\Notification\PushBodyFormatter;
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
 * - créée   → validateurs possibles (CommandeAchatService::validateursPossibles() : permission,
 *             séparation des tâches, périmètre et plafond) + super administrateurs dont le
 *             périmètre couvre le bon ;
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

    public function handle(CommandeAchatService $service): void
    {
        $commande = CommandeAchat::with(['site:id,nom', 'createdBy'])->find($this->commandeId);
        if ($commande === null) {
            return;
        }

        $destinataires = match ($this->evenement) {
            self::CREEE => $this->validateursPotentiels($commande, $service),
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
    private function validateursPotentiels(CommandeAchat $commande, CommandeAchatService $service): Collection
    {
        $superAdmins = User::where('organization_id', $commande->organization_id)
            ->whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))
            ->get()
            ->filter(fn (User $u) => app(PerimetreCommandesAchat::class)->estVisible($commande, $u));

        return $service->validateursPossibles($commande)->merge($superAdmins);
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
