<?php

namespace App\Services\Achats;

use App\Models\CommandeAchat;
use App\Models\FactureFournisseur;
use App\Models\RegleValidationRole;
use App\Models\Site;
use App\Models\User;
use App\Services\Validation\ValidationParPlafondService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Périmètre « Peut acheter pour » (ADR 0021) : les agences couvertes par les règles des rôles de
 * l'utilisateur (`regles_validation_roles`, domaine achats). Il gouverne la CRÉATION (agences
 * proposées et acceptées), la LECTURE et la VALIDATION — un seul système, sans passe-droit :
 * ni admin_entreprise ni super_admin n'ont d'accès automatique sans règle. Le créateur et le
 * validateur d'un bon le voient toujours.
 *
 * Un bon a deux agences depuis le 10/10/2026 : livraison (`site_id`) et paiement (`site_payeur_id`).
 * Le LIRE demande qu'une des deux soit couverte (estVisible()) ; AGIR dessus demande les deux
 * (peutAgir()). Une facture relève de l'agence payeuse (`factures_fournisseurs.site_id`).
 *
 * Seule ouverture, en CONSULTATION uniquement (ADR 0025, qui amende le point « voir » de l'ADR
 * 0021) : la permission « consulter les données de toutes les agences » rend visibles les bons et
 * factures de toute l'organisation — méthodes *Consultation / *Consultable. C'est une permission du
 * rôle, pas une condition sur un rôle. Créer, modifier, valider, annuler, facturer et payer restent
 * sur « Peut acheter pour » (estVisible(), couvreSite()).
 *
 * Vérifié explicitement par les contrôleurs, pas seulement par la policy : le Gate::before du
 * super administrateur court-circuite les policies.
 */
class PerimetreCommandesAchat
{
    public function __construct(private readonly ValidationParPlafondService $regles) {}

    /** @return list<string>|null null = toutes les agences de l'organisation */
    public function sitesCouverts(User $user): ?array
    {
        return $this->regles->sitesCouverts($user, RegleValidationRole::DOMAINE_ACHATS);
    }

    public function couvreSite(User $user, ?string $siteId): bool
    {
        return $this->regles->couvreSite($user, RegleValidationRole::DOMAINE_ACHATS, $siteId);
    }

    /** Une règle du rôle couvrant l'agence autorise à valider ses propres factures d'achat (Paramètres → Achats). */
    public function peutValiderSesPropresFactures(User $user, ?string $siteId): bool
    {
        return $this->regles->autoriseSurSite($user, RegleValidationRole::DOMAINE_ACHATS, $siteId, 'peut_valider_ses_propres_factures');
    }

    /** Agences pour lesquelles l'utilisateur peut créer un bon (formulaire, filtre Agence). */
    public function sites(User $user): Collection
    {
        $couverts = $this->sitesCouverts($user);

        return Site::where('organization_id', $user->organization_id)
            ->when($couverts !== null, fn (Builder $q) => $q->whereIn('id', $couverts))
            ->orderBy('nom')
            ->get(['id', 'nom']);
    }

    public function appliquer(Builder $query, User $user): Builder
    {
        $query->where('organization_id', $user->organization_id);
        $couverts = $this->sitesCouverts($user);

        if ($couverts === null) {
            return $query;
        }

        $couverts = $couverts === [] ? [''] : $couverts;

        // Un bon se lit dès qu'une de ses deux agences (livraison ou paiement) est couverte.
        return $query->where(fn (Builder $q) => $q
            ->whereIn('site_id', $couverts)
            ->orWhereIn('site_payeur_id', $couverts)
            ->orWhere('created_by', $user->id)
            ->orWhere('validee_par', $user->id));
    }

    public function estVisible(CommandeAchat $commande, User $user): bool
    {
        if ($commande->organization_id !== $user->organization_id) {
            return false;
        }
        if (($commande->created_by !== null && $commande->created_by === $user->id)
            || ($commande->validee_par !== null && $commande->validee_par === $user->id)) {
            return true;
        }

        return $this->sitesCouverts($user) === null
            || $this->couvreSite($user, $commande->site_id)
            || $this->couvreSite($user, $commande->sitePayeurId());
    }

    /**
     * Agir sur un bon (modifier, valider, annuler, clôturer, supprimer) : le périmètre doit couvrir
     * SES DEUX agences. Voir un bon livré à son agence ne donne pas le droit d'engager l'agence qui
     * le paie, et inversement (décision du 10/10/2026).
     */
    public function peutAgir(CommandeAchat $commande, User $user): bool
    {
        return $commande->organization_id === $user->organization_id
            && $this->couvreSite($user, $commande->site_id)
            && $this->couvreSite($user, $commande->sitePayeurId());
    }

    /** 403 si l'utilisateur ne peut pas agir sur ce bon — à appeler après authorize(). */
    public function autoriserAction(CommandeAchat $commande, User $user): void
    {
        abort_unless($this->peutAgir($commande, $user), 403, "Ce bon de commande engage une agence qui n'est pas dans votre périmètre d'achat.");
    }

    /** Une facture fournisseur suit le périmètre de son bon de commande ; son auteur et son validateur la voient toujours. */
    public function factureVisible(FactureFournisseur $facture, User $user): bool
    {
        if ($facture->organization_id !== $user->organization_id) {
            return false;
        }
        if (($facture->created_by !== null && $facture->created_by === $user->id)
            || ($facture->validee_par !== null && $facture->validee_par === $user->id)) {
            return true;
        }

        return $this->estVisible($facture->commande, $user);
    }

    public function autoriserFacture(FactureFournisseur $facture, User $user): void
    {
        abort_unless($this->factureVisible($facture, $user), 403, "Cette facture n'est pas dans votre périmètre d'achat.");
    }

    /** Restreint une requête de factures au périmètre de l'utilisateur. */
    public function appliquerFactures(Builder $query, User $user): Builder
    {
        $query->where('organization_id', $user->organization_id);
        if ($this->sitesCouverts($user) === null) {
            return $query;
        }

        $commandes = $this->appliquer(CommandeAchat::query(), $user)->select('id');

        return $query->where(fn (Builder $q) => $q
            ->whereIn('commande_achat_id', $commandes)
            ->orWhere('created_by', $user->id)
            ->orWhere('validee_par', $user->id));
    }

    /** 403 si le bon est hors du périmètre de l'utilisateur — à appeler après authorize(). */
    public function autoriser(CommandeAchat $commande, User $user): void
    {
        abort_unless($this->estVisible($commande, $user), 403, "Ce bon de commande n'est pas dans votre périmètre d'achat.");
    }

    // ── Consultation (ADR 0025) : lecture seule, jamais utilisé pour autoriser une écriture ──────

    /** Agences proposées au filtre Agence des listes. */
    public function sitesConsultables(User $user): Collection
    {
        if (! $this->consulteToutesLesAgences($user)) {
            return $this->sites($user);
        }

        return Site::where('organization_id', $user->organization_id)->orderBy('nom')->get(['id', 'nom']);
    }

    public function appliquerConsultation(Builder $query, User $user): Builder
    {
        return $this->consulteToutesLesAgences($user)
            ? $query->where('organization_id', $user->organization_id)
            : $this->appliquer($query, $user);
    }

    public function appliquerFacturesConsultation(Builder $query, User $user): Builder
    {
        return $this->consulteToutesLesAgences($user)
            ? $query->where('organization_id', $user->organization_id)
            : $this->appliquerFactures($query, $user);
    }

    public function estConsultable(CommandeAchat $commande, User $user): bool
    {
        if ($commande->organization_id !== $user->organization_id) {
            return false;
        }

        return $this->consulteToutesLesAgences($user) || $this->estVisible($commande, $user);
    }

    public function factureConsultable(FactureFournisseur $facture, User $user): bool
    {
        if ($facture->organization_id !== $user->organization_id) {
            return false;
        }

        return $this->consulteToutesLesAgences($user) || $this->factureVisible($facture, $user);
    }

    /** 403 si le bon n'est pas consultable — fiche et PDF, à appeler après authorize(). */
    public function autoriserConsultation(CommandeAchat $commande, User $user): void
    {
        abort_unless($this->estConsultable($commande, $user), 403, "Ce bon de commande n'est pas dans votre périmètre d'achat.");
    }

    public function autoriserConsultationFacture(FactureFournisseur $facture, User $user): void
    {
        abort_unless($this->factureConsultable($facture, $user), 403, "Cette facture n'est pas dans votre périmètre d'achat.");
    }

    /** La permission seule, sans isAdmin() : aucun rôle n'a d'accès automatique aux achats (ADR 0021). */
    private function consulteToutesLesAgences(User $user): bool
    {
        return $user->can(User::PERMISSION_LECTURE_TOUTES_AGENCES);
    }
}
