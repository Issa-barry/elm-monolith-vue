<?php

namespace App\Services\Commission;

use App\Enums\CommissionAnomalieStatut;
use App\Enums\CommissionGenerationDeclenchePar;
use App\Enums\CommissionGenerationStatut;
use App\Enums\CommissionMotifNonGeneration;
use App\Enums\DeclencheurCommissionLogistique;
use App\Enums\NatureOperation;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutCommission;
use App\Enums\StatutTransfert;
use App\Models\Categorie;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionGenerationAttempt;
use App\Models\CommissionProcessus;
use App\Models\Livreur;
use App\Models\Parametre;
use App\Models\Prestataire;
use App\Models\TransfertLogistique;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Monitoring des commissions : liste les cibles de commission ATTENDUES mais non générées, et
 * pilote leur relance. N'est pas un second moteur : il OBSERVE ce que
 * CommissionEnveloppeGenerator a tracé (commission_generation_attempts, append-only) et les
 * enveloppes réellement créées, et ne relance que via ce même moteur.
 *
 * Une anomalie = (opération, processus, cible) — grain d'une enveloppe du moteur : cible_type,
 * ou cible_type:consultant pour la cible Consultant. Son statut est dérivé, jamais stocké (même
 * principe que CommissionGenerationStatut) :
 *  - REGULARISEE : l'enveloppe de la cible existe désormais ;
 *  - SANS_OBJET : opération annulée/retournée, commission de l'opération annulée, ou la dernière
 *    génération ne trouve plus rien à verser pour cette cible (barème retiré ou mis à 0) ;
 *  - NON_GENEREE / ECHEC_RECURRENT : la dernière tentative échoue encore sur cette cible
 *    (récurrent à partir de SEUIL_ECHEC_RECURRENT tentatives en échec).
 *
 * « Aucun barème » et « barème à 0 » ne produisent jamais d'anomalie : le moteur ne les trace
 * pas comme des erreurs (aucune commission n'est due, décision AMOA #4). Une opération dont la
 * génération n'a JAMAIS été déclenchée n'a pas de tentative : elle relève de
 * `commissions:auditer-ventes`, pas de ce monitoring.
 */
class CommissionMonitoringService
{
    public const SEUIL_ECHEC_RECURRENT = 3;

    private const FORMAT_DATE_HEURE = 'd/m/Y H:i';

    public const TYPES_SOURCE = [
        'vente' => CommandeVente::class,
        'transfert' => TransfertLogistique::class,
    ];

    /** Clé d'une erreur qui ne vise aucune cible précise (erreur technique globale). */
    private const CLE_OPERATION = 'operation';

    private const LIBELLES_CIBLE = [
        CommissionCibleType::CODE_PROPRIETAIRE => 'Propriétaire',
        CommissionCibleType::CODE_EQUIPE_LIVRAISON => 'Livreurs',
        CommissionCibleType::CODE_SITE => 'Site',
        CommissionCibleType::CODE_CONSULTANT => 'Consultant',
    ];

    public static function libelleCible(?string $cible): string
    {
        return $cible === null ? 'Toute l\'opération' : (self::LIBELLES_CIBLE[$cible] ?? $cible);
    }

    /** @return list<array{value: string, label: string}> */
    public static function optionsCible(): array
    {
        return collect(self::LIBELLES_CIBLE)->map(fn (string $label, string $code) => ['value' => $code, 'label' => $label])->values()->all();
    }

    /**
     * Toutes les anomalies (tous statuts) de l'organisation, les plus récentes d'abord.
     *
     * @param  Collection<int, string>|null  $siteIdsAutorises  null = aucune restriction de site
     * @param  list<string>|null  $sourceIds  restreint aux opérations données
     * @return Collection<int, array<string, mixed>>
     */
    public function anomalies(string $organizationId, ?Collection $siteIdsAutorises = null, ?array $sourceIds = null): Collection
    {
        $sourcesEnEchec = CommissionGenerationAttempt::query()
            ->where('organization_id', $organizationId)
            ->whereIn('statut', [CommissionGenerationStatut::ERREUR->value, CommissionGenerationStatut::PARTIEL->value])
            ->whereIn('source_type', array_values(self::TYPES_SOURCE))
            ->when($sourceIds !== null, fn ($q) => $q->whereIn('source_id', $sourceIds))
            ->distinct()
            ->pluck('source_id');

        if ($sourcesEnEchec->isEmpty()) {
            return collect();
        }

        $tentatives = CommissionGenerationAttempt::query()
            ->where('organization_id', $organizationId)
            ->whereIn('source_type', array_values(self::TYPES_SOURCE))
            ->whereIn('source_id', $sourcesEnEchec)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $enveloppes = CommissionEnveloppe::query()
            ->where('organization_id', $organizationId)
            ->whereIn('source_id', $sourcesEnEchec)
            ->get(['id', 'source_type', 'source_id', 'cible_type', 'cible_id', 'statut', 'montant_total', 'created_at'])
            ->groupBy('source_id');

        $sources = $this->chargerSources($organizationId, $tentatives);
        $processus = CommissionProcessus::whereIn('id', $tentatives->pluck('processus_id')->unique())->get()->keyBy('id');
        $auteurs = User::whereIn('id', $tentatives->pluck('created_by')->filter()->unique())->get()->keyBy('id');

        $lignes = collect();
        foreach ($tentatives->groupBy(fn (CommissionGenerationAttempt $t) => "{$t->source_type}|{$t->source_id}|{$t->processus_id}") as $groupe) {
            $source = $sources->get($groupe->first()->source_type.'|'.$groupe->first()->source_id);
            if ($siteIdsAutorises !== null && ! $siteIdsAutorises->contains($this->siteId($source))) {
                continue;
            }

            foreach ($this->anomaliesDuGroupe($groupe, $source, $enveloppes->get($groupe->first()->source_id, collect()), $processus, $auteurs) as $ligne) {
                $lignes->push($ligne);
            }
        }

        return $this->enrichirNoms($lignes)
            ->sortByDesc('detectee_le_iso')
            ->values();
    }

    /**
     * Relance, via le moteur officiel, la génération des opérations portant les anomalies
     * OUVERTES demandées — une fois par opération, chacune isolée (un échec n'interrompt jamais
     * les suivantes). Idempotent : le moteur verrouille l'opération, ne recrée jamais une
     * enveloppe existante et ne complète que les cibles manquantes (R3) ; une anomalie déjà
     * régularisée ou sans objet n'est pas relancée.
     *
     * @param  list<string>  $anomalieIds
     * @param  Collection<int, string>|null  $siteIdsAutorises
     * @return array{regularisees: list<string>, sans_objet: list<string>, echecs: list<array{reference: string, message: string}>, ignorees: int}
     */
    public function relancer(string $organizationId, array $anomalieIds, ?string $userId, ?Collection $siteIdsAutorises = null): array
    {
        $sourceIds = collect($anomalieIds)->map(fn (string $id) => explode('~', $id)[1] ?? null)->filter()->unique()->values()->all();
        $avant = $this->anomalies($organizationId, $siteIdsAutorises, $sourceIds)->keyBy('id');

        $aRelancer = collect($anomalieIds)
            ->map(fn (string $id) => $avant->get($id))
            ->filter(fn (?array $a) => $a !== null && $a['relancable']);

        foreach ($aRelancer->unique(fn (array $a) => $a['source_id']) as $anomalie) {
            $this->relancerSource($organizationId, $anomalie['source_type'], $anomalie['source_id'], $userId);
        }

        $apres = $this->anomalies($organizationId, $siteIdsAutorises, $sourceIds)->keyBy('id');
        $resultat = ['regularisees' => [], 'sans_objet' => [], 'echecs' => [], 'ignorees' => count($anomalieIds) - $aRelancer->count()];

        foreach ($aRelancer as $anomalie) {
            $maintenant = $apres->get($anomalie['id']);
            $libelle = "{$anomalie['reference']} — {$anomalie['cible_label']}";

            match ($maintenant['statut'] ?? null) {
                CommissionAnomalieStatut::REGULARISEE->value => $resultat['regularisees'][] = $libelle,
                CommissionAnomalieStatut::SANS_OBJET->value => $resultat['sans_objet'][] = $libelle,
                default => $resultat['echecs'][] = ['reference' => $libelle, 'message' => $maintenant['message'] ?? $anomalie['message']],
            };
        }

        return $resultat;
    }

    /** Nombre d'anomalies ouvertes d'une opération — indicateur de la fiche commande. */
    public function nombreOuvertesPour(string $organizationId, string $sourceId): int
    {
        return $this->anomalies($organizationId, null, [$sourceId])
            ->filter(fn (array $a) => $a['ouverte'])
            ->count();
    }

    private function relancerSource(string $organizationId, string $type, string $sourceId, ?string $userId): void
    {
        try {
            if ($type === 'vente') {
                $commande = CommandeVente::where('organization_id', $organizationId)->findOrFail($sourceId);
                CommissionEnveloppeGenerator::genererPourCommandeVente($commande, CommissionGenerationDeclenchePar::UTILISATEUR, $userId);
                $commande->fresh()?->cloturerSiComplete();

                return;
            }

            $transfert = TransfertLogistique::where('organization_id', $organizationId)->findOrFail($sourceId);
            // Même quantité que le déclencheur configuré, cf. CommissionTriggerService.
            $champQuantite = Parametre::getDeclencheurCommissionLogistique($organizationId) === DeclencheurCommissionLogistique::RECEPTION_EFFECTUEE
                ? 'quantite_recue'
                : 'quantite_chargee';
            CommissionEnveloppeGenerator::genererPourTransfertLogistique($transfert, $champQuantite, CommissionGenerationDeclenchePar::UTILISATEUR, $userId);
        } catch (Throwable $e) {
            Log::error('Monitoring commissions : relance échouée', [
                'source_type' => $type,
                'source_id' => $sourceId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  Collection<int, CommissionGenerationAttempt>  $groupe  tentatives d'une (opération, processus), chronologiques
     * @param  Collection<int, CommissionEnveloppe>  $enveloppesSource
     * @return list<array<string, mixed>>
     */
    private function anomaliesDuGroupe(Collection $groupe, ?Model $source, Collection $enveloppesSource, Collection $processus, Collection $auteurs): array
    {
        $premiere = $groupe->first();
        $derniere = $groupe->last();

        /** @var array<string, array{tentatives: array<string, CommissionGenerationAttempt>, erreurs: array<string, list<array<string, mixed>>>}> $parCle */
        $parCle = [];
        foreach ($groupe as $tentative) {
            if ($tentative->statut === CommissionGenerationStatut::SUCCES) {
                continue;
            }
            foreach (self::erreursDe($tentative) as $erreur) {
                $cle = $erreur['cle'] ?? self::CLE_OPERATION;
                $parCle[$cle]['tentatives'][$tentative->id] = $tentative;
                $parCle[$cle]['erreurs'][$tentative->id][] = $erreur;
            }
        }

        $clesEnEchec = $derniere->statut === CommissionGenerationStatut::SUCCES
            ? []
            : collect(self::erreursDe($derniere))->map(fn (array $e) => $e['cle'] ?? self::CLE_OPERATION)->unique()->all();

        $raisonAnnulation = $this->raisonAnnulation($source, $enveloppesSource);
        $processusModele = $processus->get($premiere->processus_id);
        $type = array_search($premiere->source_type, self::TYPES_SOURCE, true);

        $lignes = [];
        foreach ($parCle as $cle => $info) {
            $tentativesCle = array_values($info['tentatives']);
            $detection = $tentativesCle[0]->created_at;
            $erreursRecentes = end($info['erreurs']);
            $premiereErreur = $erreursRecentes[0];
            $motif = CommissionMotifNonGeneration::tryFrom((string) ($premiereErreur['code'] ?? ''))
                ?? CommissionMotifNonGeneration::depuisMessage($premiereErreur['message']);
            $nbEchecs = count($tentativesCle);

            $enveloppe = $this->enveloppePourCle($enveloppesSource, $cle, $detection);

            [$statut, $raisonSansObjet] = match (true) {
                $enveloppe !== null => [CommissionAnomalieStatut::REGULARISEE, null],
                $raisonAnnulation !== null => [CommissionAnomalieStatut::SANS_OBJET, $raisonAnnulation],
                in_array($cle, $clesEnEchec, true) => [$nbEchecs >= self::SEUIL_ECHEC_RECURRENT ? CommissionAnomalieStatut::ECHEC_RECURRENT : CommissionAnomalieStatut::NON_GENEREE, null],
                default => [CommissionAnomalieStatut::SANS_OBJET, 'Plus attendue : la dernière génération ne trouve plus rien à verser pour cette cible (barème retiré ou à 0).'],
            };

            $cible = $premiereErreur['cible'] ?? null;
            $montantsAttendus = collect($erreursRecentes)->pluck('montant_attendu')->filter(fn ($m) => $m !== null);

            $lignes[] = [
                'id' => implode('~', [$type, $premiere->source_id, $premiere->processus_id, $cle]),
                'statut' => $statut->value,
                'statut_label' => $statut->label(),
                'ouverte' => $statut->estOuverte(),
                'relancable' => $statut->estOuverte() && $source !== null,
                'raison_sans_objet' => $raisonSansObjet,
                'source_type' => $type,
                'source_id' => $premiere->source_id,
                'cle' => $cle,
                'cible' => $cible,
                'cible_label' => self::libelleCible($cible),
                'processus_code' => $processusModele?->code,
                'processus_label' => $processusModele?->libelle ?? $processusModele?->code,
                'motif_code' => $motif->value,
                'motif_label' => $motif->label(),
                'action_corrective' => $motif->actionCorrective(),
                'message' => implode(' | ', array_column($erreursRecentes, 'message')),
                'erreurs' => array_map(fn (array $e) => [
                    'code' => $e['code'] ?? CommissionMotifNonGeneration::depuisMessage($e['message'])->value,
                    'message' => $e['message'],
                    'contexte' => $e['contexte'] ?? [],
                ], $erreursRecentes),
                'montant_attendu' => $montantsAttendus->isEmpty() ? null : (float) $montantsAttendus->max(),
                'detectee_le' => $detection?->format(self::FORMAT_DATE_HEURE),
                'detectee_le_iso' => $detection?->toIso8601String(),
                'derniere_tentative_le' => $derniere->created_at?->format(self::FORMAT_DATE_HEURE),
                'nb_tentatives_echouees' => $nbEchecs,
                'nb_tentatives' => $groupe->count(),
                'regularisee_le' => $enveloppe?->created_at?->format(self::FORMAT_DATE_HEURE),
                'montant_regularise' => $enveloppe ? (float) $enveloppe->montant_total : null,
                'tentatives' => $groupe->reverse()->values()->map(fn (CommissionGenerationAttempt $t) => [
                    'id' => $t->id,
                    'date' => $t->created_at?->format('d/m/Y H:i:s'),
                    'statut' => $t->statut->value,
                    'statut_label' => $t->statut->label(),
                    'declenchee_par' => $t->declenchee_par?->label(),
                    'auteur' => $t->created_by ? $auteurs->get($t->created_by)?->name : null,
                    'message' => isset($info['erreurs'][$t->id])
                        ? implode(' | ', array_column($info['erreurs'][$t->id], 'message'))
                        : null,
                ])->all(),
                'enveloppes_generees' => $enveloppesSource->map(fn (CommissionEnveloppe $e) => [
                    'cible_label' => self::libelleCible($e->cible_type),
                    'montant' => (float) $e->montant_total,
                    'statut' => $e->statut->value,
                ])->values()->all(),
                ...$this->infosSource($type, $source),
            ];
        }

        return $lignes;
    }

    /**
     * Erreurs par cible d'une tentative en échec : entrées structurées depuis le monitoring
     * (detail_erreur.cibles), sinon reconstituées depuis les messages texte (« Cible <code> : »).
     *
     * @return list<array<string, mixed>>
     */
    private static function erreursDe(CommissionGenerationAttempt $tentative): array
    {
        $detail = $tentative->detail_erreur ?? [];
        if (! empty($detail['cibles'])) {
            return array_values($detail['cibles']);
        }

        $messages = $detail['erreurs'] ?? array_filter([$tentative->motif_erreur]);

        return array_values(array_map(function (string $message) {
            $cible = preg_match('/^Cible (\w+) :/u', $message, $m) ? $m[1] : null;
            $cle = $cible;
            if ($cible === CommissionCibleType::CODE_CONSULTANT) {
                $cle = preg_match('/le consultant (\S+) n\'est plus actif/u', $message, $c)
                    ? "{$cible}:{$c[1]}"
                    : "{$cible}:sans_consultant";
            }

            return [
                'cible' => $cible,
                'cle' => $cle,
                'code' => CommissionMotifNonGeneration::depuisMessage($message)->value,
                'message' => $message,
                'montant_attendu' => null,
                'contexte' => [],
            ];
        }, $messages));
    }

    /**
     * Enveloppe qui régularise l'anomalie : celle de la cible (clé exacte), n'importe quelle
     * enveloppe Consultant créée depuis la détection pour « aucun consultant désigné » (le
     * consultant n'était pas connu), n'importe quelle enveloppe pour une erreur globale.
     *
     * @param  Collection<int, CommissionEnveloppe>  $enveloppes
     */
    private function enveloppePourCle(Collection $enveloppes, string $cle, ?Carbon $detection): ?CommissionEnveloppe
    {
        return $enveloppes->first(function (CommissionEnveloppe $e) use ($cle, $detection) {
            if ($cle === self::CLE_OPERATION) {
                return true;
            }
            if ($cle === CommissionCibleType::CODE_CONSULTANT.':sans_consultant') {
                return $e->cible_type === CommissionCibleType::CODE_CONSULTANT
                    && ($detection === null || $e->created_at === null || $e->created_at->gte($detection));
            }
            if (str_contains($cle, ':')) {
                return "{$e->cible_type}:{$e->cible_id}" === $cle;
            }

            return $e->cible_type === $cle;
        });
    }

    /** Raison pour laquelle plus aucune commission n'est due sur l'opération, ou null. */
    private function raisonAnnulation(?Model $source, Collection $enveloppesSource): ?string
    {
        if ($source === null) {
            return 'Opération introuvable.';
        }

        if ($source instanceof CommandeVente && in_array($source->statut, [StatutCommandeVente::ANNULEE, StatutCommandeVente::ANNULEE_ERREUR_SAISIE, StatutCommandeVente::RETOURNEE], true)) {
            return "Commande {$source->statut->label()} : aucune commission n'est plus due.";
        }

        if ($source instanceof TransfertLogistique && $source->statut === StatutTransfert::ANNULE) {
            return 'Transfert annulé : aucune commission n\'est plus due.';
        }

        if ($enveloppesSource->contains(fn (CommissionEnveloppe $e) => $e->statut === StatutCommission::ANNULEE)) {
            return 'La commission de l\'opération a été annulée (retour ou suppression de l\'encaissement) : elle n\'est jamais complétée.';
        }

        return null;
    }

    /** @return Collection<string, Model> clé "source_type|source_id" */
    private function chargerSources(string $organizationId, Collection $tentatives): Collection
    {
        $parType = $tentatives->groupBy('source_type')->map(fn (Collection $t) => $t->pluck('source_id')->unique()->values());
        $sources = collect();

        if ($ids = $parType->get(CommandeVente::class)) {
            CommandeVente::with(['client', 'vehicule.equipe', 'site:id,nom'])
                ->where('organization_id', $organizationId)
                ->whereIn('id', $ids)
                ->get()
                ->each(fn (CommandeVente $c) => $sources->put(CommandeVente::class.'|'.$c->id, $c));
        }

        if ($ids = $parType->get(TransfertLogistique::class)) {
            TransfertLogistique::with(['vehicule.equipe', 'siteSource:id,nom'])
                ->where('organization_id', $organizationId)
                ->whereIn('id', $ids)
                ->get()
                ->each(fn (TransfertLogistique $t) => $sources->put(TransfertLogistique::class.'|'.$t->id, $t));
        }

        return $sources;
    }

    private function siteId(?Model $source): ?string
    {
        return match (true) {
            $source instanceof CommandeVente => $source->site_id,
            $source instanceof TransfertLogistique => $source->site_source_id,
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function infosSource(string|false $type, ?Model $source): array
    {
        if ($source instanceof CommandeVente) {
            $chemin = $source->nature_operation === NatureOperation::DISTRIBUTION_CLIENT ? 'distributions' : 'ventes';

            return [
                'reference' => $source->reference,
                'source_url' => "/backoffice/{$chemin}/{$source->id}",
                'source_label' => 'Commande',
                'date_operation' => $source->created_at?->format('d/m/Y'),
                'client' => $source->client?->nom_complet,
                'montant_operation' => (float) $source->total_commande,
                'site_id' => $source->site_id,
                'site_nom' => $source->site?->nom,
                'vehicule_nom' => $source->vehicule?->nom_vehicule,
                'equipe_id' => $source->vehicule?->equipe?->id,
            ];
        }

        if ($source instanceof TransfertLogistique) {
            return [
                'reference' => $source->reference,
                'source_url' => "/backoffice/logistique/{$source->id}",
                'source_label' => 'Transfert',
                'date_operation' => ($source->date_depart_reelle ?? $source->created_at)?->format('d/m/Y'),
                'client' => null,
                'montant_operation' => null,
                'site_id' => $source->site_source_id,
                'site_nom' => $source->siteSource?->nom,
                'vehicule_nom' => $source->vehicule?->nom_vehicule,
                'equipe_id' => $source->vehicule?->equipe?->id,
            ];
        }

        return [
            'reference' => '—',
            'source_url' => null,
            'source_label' => $type === 'transfert' ? 'Transfert' : 'Commande',
            'date_operation' => null,
            'client' => null,
            'montant_operation' => null,
            'site_id' => null,
            'site_nom' => null,
            'vehicule_nom' => null,
            'equipe_id' => null,
        ];
    }

    /**
     * Remplace les identifiants du contexte (catégorie, livreur, consultant) par leurs noms —
     * une requête par type, jamais une par anomalie.
     *
     * @param  Collection<int, array<string, mixed>>  $lignes
     * @return Collection<int, array<string, mixed>>
     */
    private function enrichirNoms(Collection $lignes): Collection
    {
        $contextes = $lignes->flatMap(fn (array $l) => array_column($l['erreurs'], 'contexte'));

        $categories = Categorie::whereIn('id', $contextes->pluck('categorie_id')
            ->merge($contextes->pluck('categorie_ids')->flatten())->filter()->unique())
            ->pluck('nom', 'id');
        $livreurs = Livreur::whereIn('id', $contextes->pluck('parts')->flatten(1)->pluck('livreur_id')->filter()->unique())
            ->pluck('nom_complet', 'id');
        $consultants = Prestataire::whereIn('id', $contextes->pluck('consultant_id')->filter()->unique())
            ->get()
            ->mapWithKeys(fn (Prestataire $p) => [$p->id => $p->nom_complet]);

        return $lignes->map(function (array $ligne) use ($categories, $livreurs, $consultants) {
            $ligne['erreurs'] = array_map(function (array $erreur) use ($categories, $livreurs, $consultants) {
                $c = $erreur['contexte'];
                if (isset($c['categorie_id'])) {
                    $c['categorie_nom'] = $categories->get($c['categorie_id']);
                }
                if (isset($c['categorie_ids'])) {
                    $c['categorie_noms'] = collect($c['categorie_ids'])->map(fn ($id) => $categories->get($id))->filter()->values()->all();
                }
                if (isset($c['consultant_id'])) {
                    $c['consultant_nom'] = $consultants->get($c['consultant_id']);
                }
                if (isset($c['parts'])) {
                    $c['parts'] = array_map(fn (array $p) => [...$p, 'livreur_nom' => $livreurs->get($p['livreur_id']) ?? '—'], $c['parts']);
                }
                $erreur['contexte'] = $c;

                return $erreur;
            }, $ligne['erreurs']);

            $ligne['categories'] = collect($ligne['erreurs'])
                ->flatMap(fn (array $e) => array_filter([$e['contexte']['categorie_nom'] ?? null, ...($e['contexte']['categorie_noms'] ?? [])]))
                ->unique()->values()->all();

            return $ligne;
        });
    }
}
