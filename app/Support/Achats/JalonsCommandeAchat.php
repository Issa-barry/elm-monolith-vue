<?php

namespace App\Support\Achats;

use App\Enums\EvenementComptable;
use App\Enums\StatutCommandeAchat;
use App\Enums\StatutFactureFournisseur;
use App\Enums\StatutPieceComptable;
use App\Models\CommandeAchat;
use App\Models\FactureFournisseur;
use App\Models\PieceComptable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Frise d'avancement d'un achat (bon → validation → réception → facture → paiement → terminé) et
 * prochaine action attendue. Aucun statut nouveau : tout est DÉRIVÉ des données enregistrées
 * (statut du bon, quantités commandées / reçues / facturées, factures, reste dû, écriture passée).
 * Une étape n'est jamais « faite » si ces données ne le confirment pas.
 *
 * Elle donne aussi le « statut facture » du bon affiché dans la liste : synthèse de ses factures non
 * annulées (la moins avancée l'emporte), elle aussi dérivée, jamais enregistrée.
 *
 * Une seule source pour la fiche et la liste, calculée par lot (requêtes groupées, jamais par ligne).
 * C'est un affichage : il ne donne aucun droit, les boutons restent soumis aux permissions.
 */
class JalonsCommandeAchat
{
    public const ETAPES = [
        'creation' => 'Création',
        'validation' => 'Validation',
        'reception' => 'Réception',
        'facture' => "Facture d'achat",
        'paiement' => 'Paiement',
        'termine' => 'Terminé',
    ];

    /** @return array{etapes: list<array{cle: string, libelle: string, etat: string}>, annule: bool, termine: bool, prochaine_action: ?string, autres_actions: list<string>, resume: string, resume_domaine: ?string, statut_facture: ?array{statut: string, label: string}} */
    public function pour(CommandeAchat $commande): array
    {
        return $this->pourCommandes(collect([$commande]))[$commande->id];
    }

    /**
     * @param  Collection<int, CommandeAchat>  $commandes
     * @return array<string, array<string, mixed>> par id de bon
     */
    public function pourCommandes(Collection $commandes): array
    {
        if ($commandes->isEmpty()) {
            return [];
        }
        $ids = $commandes->pluck('id')->all();

        $quantites = DB::table('commande_achat_lignes')
            ->whereIn('commande_achat_id', $ids)
            ->groupBy('commande_achat_id')
            ->selectRaw('commande_achat_id, COALESCE(SUM(qte), 0) as commande, COALESCE(SUM(qte_recue), 0) as recu')
            ->get()
            ->keyBy('commande_achat_id');

        $factures = FactureFournisseur::whereIn('commande_achat_id', $ids)
            ->where('statut', '!=', StatutFactureFournisseur::ANNULEE->value)
            ->orderBy('created_at')
            ->get(['id', 'commande_achat_id', 'reference', 'statut', 'montant_ttc', 'montant_paye'])
            ->groupBy('commande_achat_id');

        $constatees = array_map(fn ($s) => $s->value, StatutFactureFournisseur::constatees());
        $facture = DB::table('facture_fournisseur_lignes')
            ->join('factures_fournisseurs', 'factures_fournisseurs.id', '=', 'facture_fournisseur_lignes.facture_fournisseur_id')
            ->whereIn('factures_fournisseurs.commande_achat_id', $ids)
            ->whereIn('factures_fournisseurs.statut', $constatees)
            ->groupBy('factures_fournisseurs.commande_achat_id')
            ->selectRaw('factures_fournisseurs.commande_achat_id as commande_achat_id, COALESCE(SUM(facture_fournisseur_lignes.qte_facturee), 0) as qte')
            ->pluck('qte', 'commande_achat_id');

        // Factures dont l'écriture de validation est passée : condition du paiement (ADR 0024).
        $comptabilisees = PieceComptable::query()
            ->where('source_type', (new FactureFournisseur)->getMorphClass())
            ->whereIn('source_id', $factures->flatten()->pluck('id'))
            ->where('type_evenement', EvenementComptable::FACTURE_FOURNISSEUR_VALIDEE->value)
            ->where('statut', StatutPieceComptable::VALIDEE->value)
            ->pluck('source_id')
            ->flip();

        return $commandes->mapWithKeys(fn (CommandeAchat $c) => [$c->id => $this->calculer(
            $c,
            (int) ($quantites->get($c->id)->commande ?? 0),
            (int) ($quantites->get($c->id)->recu ?? 0),
            (int) ($facture->get($c->id) ?? 0),
            $factures->get($c->id, collect()),
            $comptabilisees,
        )])->all();
    }

    /** @param  Collection<int, FactureFournisseur>  $factures  factures non annulées du bon */
    private function calculer(CommandeAchat $c, int $commande, int $recu, int $facture, Collection $factures, Collection $comptabilisees): array
    {
        if ($c->isAnnulee()) {
            return $this->resultat([], true, false, [], 'Annulé', null);
        }

        $statut = $c->statut;
        $valide = $c->validee_at !== null && ! $c->isAValider();
        $receptionFinie = in_array($statut, [StatutCommandeAchat::RECEPTIONNEE, StatutCommandeAchat::CLOTUREE], true);
        $brouillons = $factures->filter(fn (FactureFournisseur $f) => $f->isBrouillon());
        $dues = $factures->filter(fn (FactureFournisseur $f) => $f->isConstatee() && $f->resteDu() > 0);
        $resteAFacturer = max(0, $recu - $facture);

        $factureFaite = $receptionFinie && $recu > 0 && $resteAFacturer === 0 && $brouillons->isEmpty() && $factures->isNotEmpty();
        $paiementFait = $factureFaite && $dues->isEmpty();

        $faites = [
            'creation' => true,
            'validation' => $valide,
            'reception' => $valide && $receptionFinie,
            'facture' => $factureFaite,
            'paiement' => $paiementFait,
            'termine' => $paiementFait,
        ];

        // Actions en attente, dans l'ordre du circuit : la première est la prochaine action, les
        // suivantes sont possibles en parallèle (ex. facturer le déjà reçu avant la fin de la réception).
        $actions = [];
        if (! $valide) {
            $actions[] = ['Faire valider le bon de commande', 'À valider', 'commande'];
        } elseif (! $receptionFinie) {
            $actions[] = $recu === 0
                ? ['Réceptionner les marchandises (Logistique → Réceptions)', 'À réceptionner', 'commande']
                : ['Réceptionner le reliquat : '.($commande - $recu)." sur {$commande} restent à recevoir", 'Reliquat à réceptionner', 'commande'];
        }
        if ($valide && $resteAFacturer > 0 && $brouillons->isEmpty()) {
            $actions[] = [
                $receptionFinie ? "Saisir la facture d'achat" : "Saisir la facture d'achat des quantités déjà reçues",
                'À facturer',
                'facture',
            ];
        }
        foreach ($brouillons as $f) {
            $actions[] = ["Valider la facture {$f->reference}", 'Facture à valider', 'facture'];
        }
        foreach ($dues as $f) {
            $actions[] = $comptabilisees->has($f->id)
                ? ["Payer la facture {$f->reference} (reste dû ".number_format($f->resteDu(), 0, ',', ' ').' GNF)', 'À payer', 'facture']
                : ["Relancer la comptabilisation de la facture {$f->reference} avant de la payer", 'Écriture à passer', 'facture'];
        }

        $etapes = [];
        $courantePosee = false;
        foreach (self::ETAPES as $cle => $libelle) {
            $etat = $faites[$cle] ? 'fait' : ($courantePosee ? 'a_venir' : 'en_cours');
            $courantePosee = $courantePosee || $etat === 'en_cours';
            $etapes[] = [
                'cle' => $cle,
                'libelle' => $cle === 'reception' && $statut === StatutCommandeAchat::CLOTUREE ? 'Réception (reliquat abandonné)' : $libelle,
                'etat' => $etat,
            ];
        }

        return $this->resultat(
            $etapes,
            false,
            $paiementFait,
            $actions,
            $paiementFait ? 'Terminé' : ($actions[0][1] ?? 'En cours'),
            $this->statutFacture($factures, $brouillons, $dues, $recu, $resteAFacturer),
        );
    }

    /**
     * Statut de facturation d'un bon, pour la colonne « Statut facture » de la liste. Un bon peut
     * porter plusieurs factures : c'est la situation la moins avancée qui est affichée. Les valeurs
     * et libellés d'une facture sont ceux de StatutFactureFournisseur (mêmes mots que la liste des
     * factures d'achat) ; s'y ajoutent « À facturer » et « Partiellement facturée », propres au bon.
     * Rien tant qu'il n'y a ni facture ni quantité reçue à facturer.
     *
     * @param  Collection<int, FactureFournisseur>  $factures  factures non annulées du bon
     * @return ?array{statut: string, label: string}
     */
    private function statutFacture(Collection $factures, Collection $brouillons, Collection $dues, int $recu, int $resteAFacturer): ?array
    {
        if ($factures->isEmpty()) {
            return $recu > 0 ? ['statut' => 'a_facturer', 'label' => 'À facturer'] : null;
        }
        if ($brouillons->isNotEmpty()) {
            return $this->statutDe(StatutFactureFournisseur::BROUILLON);
        }
        if ($dues->isNotEmpty()) {
            $dejaPaye = (float) $factures->sum(fn (FactureFournisseur $f) => (float) $f->montant_paye);

            return $this->statutDe($dejaPaye > 0 ? StatutFactureFournisseur::PARTIELLEMENT_PAYEE : StatutFactureFournisseur::VALIDEE);
        }

        return $resteAFacturer > 0
            ? ['statut' => 'partiellement_facturee', 'label' => 'Partiellement facturée']
            : $this->statutDe(StatutFactureFournisseur::PAYEE);
    }

    /** @return array{statut: string, label: string} */
    private function statutDe(StatutFactureFournisseur $statut): array
    {
        return ['statut' => $statut->statutAffichage(), 'label' => $statut->label()];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $actions  [libellé détaillé, libellé court, domaine « commande » ou « facture »]
     * @param  ?array{statut: string, label: string}  $statutFacture
     */
    private function resultat(array $etapes, bool $annule, bool $termine, array $actions, string $resume, ?array $statutFacture): array
    {
        return [
            'etapes' => $etapes,
            'annule' => $annule,
            'termine' => $termine,
            'prochaine_action' => $actions[0][0] ?? null,
            'autres_actions' => array_values(array_map(fn (array $a) => $a[0], array_slice($actions, 1, 3))),
            'resume' => $resume,
            // Domaine de la prochaine action : la liste n'affiche sous le statut du bon que ce qui
            // le concerne (validation, réception) ; la facturation a sa propre colonne.
            'resume_domaine' => $actions[0][2] ?? null,
            'statut_facture' => $statutFacture,
        ];
    }
}
