<?php

namespace App\Services;

use App\Enums\TypeEcartLogistique;
use App\Models\TransfertActivite;
use App\Models\TransfertLigne;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Notifications\EcartReceptionTransfertNotification;
use App\Services\Notification\NotificationDispatcher;
use App\Services\Notification\PushBodyFormatter;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Signalement des écarts de réception d'un transfert logistique (ADR 0032). Notification seulement :
 * aucun stock, mouvement, commission ni statut n'est modifié ici — l'écart reste traité
 * manuellement.
 *
 * Une ligne est en écart quand l'écart chiffré (reçu + retourné − chargé, ADR 0029) est non nul ou
 * quand un type d'écart autre que « Conforme » a été déclaré (casse ou perte comptée comme reçue).
 * Le constat est établi sous le verrou de la réception, l'envoi part après le commit : une
 * réception refusée ou annulée ne signale rien, et une double soumission ne repasse jamais par la
 * transition (TransfertLogistiqueService::avancerStatut()).
 *
 * Destinataires, dédoublonnés : le réceptionnaire, le créateur du transfert, les utilisateurs
 * actifs du site source ayant `logistique.update` (permission vérifiée par la policy pour gérer un
 * transfert depuis son agence) et les administrateurs de l'organisation.
 */
class TransfertEcartReceptionService
{
    public const CATEGORIE = 'livraisons';

    /** À appeler dans la transaction de la réception (TRANSIT → RECEPTION), sous verrou. */
    public static function signalerApresReception(TransfertLogistique $transfert): void
    {
        $constat = self::constater($transfert, Auth::id());
        if ($constat === null) {
            return;
        }

        DB::afterCommit(fn () => self::notifier($constat));
    }

    /**
     * Une réception invalidée n'est plus la réalité du transfert : ses signalements encore non lus
     * sortent de la cloche (marqués lus). L'email déjà parti ne peut être rappelé ; la nouvelle
     * réception signale de nouveau si un écart subsiste, en le précisant.
     */
    public static function retirerSignalements(TransfertLogistique $transfert): void
    {
        DatabaseNotification::query()
            ->where('type', EcartReceptionTransfertNotification::class)
            ->whereNull('read_at')
            ->where('data->resource->id', $transfert->id)
            ->update(['read_at' => now()]);
    }

    /** @return array<string, mixed>|null null si la réception est conforme */
    public static function constater(TransfertLogistique $transfert, ?string $receptionnaireId): ?array
    {
        $transfert->load(['lignes.variante.produit', 'siteSource:id,nom', 'siteDestination:id,nom']);

        $lignes = $transfert->lignes
            ->filter(fn (TransfertLigne $l) => $l->hasEcart()
                || ($l->ecart_type !== null && $l->ecart_type !== TypeEcartLogistique::CONFORME))
            ->map(fn (TransfertLigne $l) => [
                'produit' => $l->variante?->produit?->nom ?? 'Produit',
                'variante' => $l->variante?->libelle ?: null,
                'quantite_chargee' => (int) $l->quantite_chargee,
                'quantite_recue' => (int) $l->quantite_recue,
                'quantite_retournee' => (int) $l->quantite_retournee,
                'ecart' => (int) $l->ecart,
                'ecart_type' => $l->ecart_type?->value,
                'ecart_type_label' => $l->ecart_label,
                'ecart_motif' => $l->ecart_motif,
            ])
            ->values()
            ->all();

        if ($lignes === []) {
            return null;
        }

        $receptionnaire = $receptionnaireId ? User::find($receptionnaireId) : null;

        return [
            'transfert_id' => $transfert->id,
            'organization_id' => $transfert->organization_id,
            'reference' => $transfert->reference,
            'site_source_id' => $transfert->site_source_id,
            'site_source' => $transfert->siteSource?->nom ?? '—',
            'site_destination' => $transfert->siteDestination?->nom ?? '—',
            'created_by' => $transfert->created_by,
            'receptionnaire_id' => $receptionnaire?->id,
            'receptionnaire' => $receptionnaire?->name ?: null,
            'receptionne_le' => now()->toIso8601String(),
            'nouvelle_reception' => TransfertActivite::where('transfert_logistique_id', $transfert->id)
                ->where('action', 'reception_invalidee')
                ->exists(),
            'lignes' => $lignes,
        ];
    }

    /**
     * Notification dans l'application (+ push) pour tous les destinataires, puis l'email un par un :
     * un SMTP indisponible ou saturé ne prive personne de la notification dans l'application, et
     * aucun échec ne remonte vers la réception, déjà enregistrée.
     *
     * @param  array<string, mixed>  $constat
     */
    public static function notifier(array $constat): void
    {
        try {
            $destinataires = self::destinataires($constat)
                ->filter(fn (User $u) => $u->wantsNotification(self::CATEGORIE))
                ->values();

            if ($destinataires->isEmpty()) {
                return;
            }

            $notification = new EcartReceptionTransfertNotification($constat);
            $donnees = $notification->toArray($destinataires->first());

            NotificationDispatcher::send(
                $notification,
                $destinataires,
                self::CATEGORIE,
                fn () => [
                    'title' => $donnees['titre'],
                    'body' => PushBodyFormatter::format($donnees),
                    'data' => ['type' => 'transfer.reception_discrepancy', 'transfert_id' => $constat['transfert_id']],
                ],
            );
        } catch (Throwable $e) {
            Log::error('EcartReceptionTransfertNotification : envoi échoué', [
                'transfert_id' => $constat['transfert_id'],
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $email = new EcartReceptionTransfertNotification($constat, ['mail']);
        foreach ($destinataires as $destinataire) {
            try {
                $destinataire->notify($email);
            } catch (Throwable $e) {
                Log::error('EcartReceptionTransfertNotification : email non envoyé', [
                    'transfert_id' => $constat['transfert_id'],
                    'user_id' => $destinataire->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $constat
     * @return Collection<int, User>
     */
    public static function destinataires(array $constat): Collection
    {
        $orgId = $constat['organization_id'];

        $agence = User::where('organization_id', $orgId)
            ->whereHas('sites', fn ($q) => $q->where('sites.id', $constat['site_source_id']))
            ->with('roles', 'permissions')
            ->get()
            ->filter(fn (User $u) => $u->checkPermissionTo('logistique.update'));

        $admins = User::where('organization_id', $orgId)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin_entreprise']))
            ->get();

        $createur = $constat['created_by']
            ? User::where('organization_id', $orgId)->find($constat['created_by'])
            : null;

        // Le réceptionnaire a agi sur ce transfert : il est notifié même sans organisation.
        $receptionnaire = $constat['receptionnaire_id'] ? User::find($constat['receptionnaire_id']) : null;

        return collect([$receptionnaire, $createur])
            ->merge($agence)
            ->merge($admins)
            ->filter(fn (?User $u) => $u !== null && $u->is_active !== false)
            ->unique('id')
            ->values();
    }
}
