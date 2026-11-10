<?php

namespace App\Services\Contacts;

use App\Enums\AuditEvent;
use App\Models\Client;
use App\Models\ContactsReprise;
use App\Models\ContactsRepriseValeur;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Rapport et arbitrage des écarts d'identité entre chaque client et sa Personne (ADR 0060, lot 7a,
 * D16, décision L7-1, CONTACT-045 et 046), avant l'activation de la synchronisation R1.
 *
 * - Un champ vide d'un côté est complété depuis l'autre (sans conflit). Deux clients d'une même
 *   Personne qui proposent deux valeurs différentes pour un champ vide de la Personne : conflit.
 * - Deux valeurs différentes : conflit, jamais tranché automatiquement ; arbitrage explicite client
 *   par client (« la Personne fait foi » ou « le client fait foi »).
 * - Simulation par défaut ; chaque exécution appliquée a un identifiant et journalise les valeurs
 *   d'avant et d'après (`contacts_reprise_valeurs`) ; l'annulation ne restaure un champ que s'il vaut
 *   encore la valeur écrite par l'exécution (sinon « conflit »).
 * - Une différence de casse ou d'espaces n'est pas un conflit : l'activation recopie l'écriture de
 *   la Personne.
 * - Activation refusée tant qu'un conflit reste à arbitrer.
 * - Écritures directes en base (sans événements de modèle) : chaque côté est écrit explicitement.
 */
final class EcartsIdentiteClients
{
    public const COMMANDE = 'contacts:ecarts-clients';

    public const PERSONNE_FAIT_FOI = 'personne_fait_foi';

    public const CLIENT_FAIT_FOI = 'client_fait_foi';

    public function __construct(
        private readonly ClientIdentiteSynchronisation $synchronisation,
        private readonly AuditLogService $journal,
    ) {}

    /**
     * @param  array<string, string>  $arbitrages  client_id => personne_fait_foi | client_fait_foi
     * @return array{lignes: list<array<string, mixed>>, anomalies: list<array{client_id: string, nom: ?string, motif: string}>, conflits: int}
     */
    public function analyser(string $organizationId, array $arbitrages = []): array
    {
        $clients = Client::where('organization_id', $organizationId)->orderBy('created_at')->orderBy('id')->get();
        $personnes = Personne::whereIn('id', $clients->pluck('personne_id')->filter()->unique())->get()->keyBy('id');

        $anomalies = [];
        $lignes = [];
        foreach ($clients->groupBy('personne_id') as $personneId => $groupe) {
            $personne = $personneId === '' ? null : $personnes->get($personneId);
            if ($personne === null) {
                foreach ($groupe as $client) {
                    $anomalies[] = ['client_id' => $client->id, 'nom' => $client->nom_complet, 'motif' => 'client_sans_personne'];
                }

                continue;
            }
            array_push($lignes, ...$this->comparerGroupe($personne, $groupe, $arbitrages));
        }

        return [
            'lignes' => $lignes,
            'anomalies' => $anomalies,
            'conflits' => count(array_filter($lignes, fn (array $l) => $l['action'] === 'conflit')),
        ];
    }

    /**
     * Applique les complétions et les arbitrages de l'analyse (les conflits restent).
     *
     * @param  array{lignes: list<array<string, mixed>>}  $analyse
     * @return array{reprise: ?ContactsReprise, appliquees: int, refusees: list<array{client_id: string, champ: string, motif: string}>}
     */
    public function appliquer(User $acteur, array $analyse): array
    {
        $aEcrire = array_values(array_filter($analyse['lignes'], fn (array $l) => ! in_array($l['action'], ['conflit', 'identique'], true)));
        if ($aEcrire === []) {
            return ['reprise' => null, 'appliquees' => 0, 'refusees' => []];
        }

        $reprise = ContactsReprise::create([
            'organization_id' => $acteur->organization_id,
            'commande' => self::COMMANDE,
            'mode' => ContactsReprise::MODE_APPLICATION,
            'acteur_id' => $acteur->id,
        ]);

        $appliquees = 0;
        $refusees = [];
        foreach (collect($aEcrire)->groupBy('client_id') as $clientId => $champs) {
            try {
                DB::transaction(function () use ($reprise, $champs, &$appliquees) {
                    foreach ($champs as $l) {
                        [$table, $id, $valeur] = in_array($l['action'], ['completer_personne', self::CLIENT_FAIT_FOI], true)
                            ? ['personnes', $l['personne_id'], $l['valeur_client']]
                            : ['clients', $l['client_id'], $l['valeur_personne']];
                        $this->ecrire($reprise, $table, $id, $l['champ'], $valeur);
                        $appliquees++;
                    }
                });
            } catch (InvalidArgumentException $e) {
                $refusees[] = ['client_id' => $clientId, 'champ' => '', 'motif' => $e->getMessage()];
            }
        }

        $reprise->forceFill(['rapport' => ['appliquees' => $appliquees, 'refusees' => $refusees], 'terminee_le' => now()])->save();

        return ['reprise' => $reprise, 'appliquees' => $appliquees, 'refusees' => $refusees];
    }

    /**
     * @return array{annulees: int, conflits: int, annulation: ?ContactsReprise}
     */
    public function annuler(User $acteur, string $repriseId, bool $simulation): array
    {
        $reprise = ContactsReprise::where('organization_id', $acteur->organization_id)
            ->where('commande', self::COMMANDE)->where('mode', ContactsReprise::MODE_APPLICATION)->find($repriseId);
        if ($reprise === null) {
            throw new InvalidArgumentException("Exécution {$repriseId} introuvable dans votre organisation.");
        }

        $valeurs = ContactsRepriseValeur::where('contacts_reprise_id', $reprise->id)
            ->where(fn ($q) => $q->whereNull('annulation_statut')->orWhere('annulation_statut', ContactsRepriseValeur::CONFLIT))
            ->orderByDesc('created_at')->orderByDesc('id')->get();

        $annulees = 0;
        $conflits = 0;
        foreach ($valeurs as $v) {
            $table = $v->cible_type === 'personne' ? 'personnes' : 'clients';
            $actuelle = DB::table($table)->where('id', $v->cible_id)->value($v->champ);
            $intacte = (string) $actuelle === (string) $v->valeur_apres;
            if ($simulation) {
                $intacte ? $annulees++ : $conflits++;

                continue;
            }
            DB::transaction(function () use ($v, $table, $intacte, &$annulees, &$conflits) {
                if (! $intacte) {
                    $v->forceFill(['annulation_statut' => ContactsRepriseValeur::CONFLIT])->save();
                    $conflits++;

                    return;
                }
                $this->ecrireBrut($table, $v->cible_id, $v->champ, $v->valeur_avant);
                $v->forceFill(['annulation_statut' => ContactsRepriseValeur::ANNULEE, 'annulee_le' => now()])->save();
                $annulees++;
            });
        }

        $annulation = null;
        if (! $simulation && $valeurs->isNotEmpty()) {
            $annulation = ContactsReprise::create([
                'organization_id' => $acteur->organization_id, 'commande' => self::COMMANDE, 'mode' => ContactsReprise::MODE_ANNULATION,
                'reprise_annulee_id' => $reprise->id, 'acteur_id' => $acteur->id,
                'rapport' => ['annulees' => $annulees, 'conflits' => $conflits], 'terminee_le' => now(),
            ]);
        }

        return ['annulees' => $annulees, 'conflits' => $conflits, 'annulation' => $annulation];
    }

    /**
     * Active la synchronisation R1 pour l'organisation, si plus aucun conflit ne reste, puis recopie
     * l'identité de chaque Personne sur ses clients (seules des différences de casse ou d'espaces
     * restent à ce stade).
     */
    public function activer(User $acteur): int
    {
        $organisation = Organization::findOrFail($acteur->organization_id);
        if ($organisation->synchronisation_identite_client_le !== null) {
            throw new InvalidArgumentException('La synchronisation est déjà active pour cette organisation.');
        }
        $analyse = $this->analyser($organisation->id);
        $restantes = count(array_filter($analyse['lignes'], fn (array $l) => ! in_array($l['action'], ['identique', 'conflit'], true)));
        if ($analyse['conflits'] > 0 || $restantes > 0) {
            throw new InvalidArgumentException("Activation refusée : {$analyse['conflits']} conflit(s) à arbitrer et {$restantes} complétion(s) à appliquer (relancez avec --apply).");
        }

        return DB::transaction(function () use ($organisation, $acteur) {
            $organisation->forceFill(['synchronisation_identite_client_le' => now()])->save();
            $this->journal->record($organisation, AuditEvent::UPDATED, $acteur, null, null, ['origine' => self::COMMANDE, 'synchronisation_identite_client' => true], $organisation->id);

            $recopies = 0;
            Personne::where('organization_id', $organisation->id)
                ->whereIn('id', Client::where('organization_id', $organisation->id)->whereNotNull('personne_id')->select('personne_id'))
                ->each(function (Personne $p) use (&$recopies) {
                    $recopies += $this->synchronisation->depuisPersonne($p);
                });

            return $recopies;
        });
    }

    // ── Interne ─────────────────────────────────────────────────────────────

    /**
     * @param  Collection<int, Client>  $clients
     * @param  array<string, string>  $arbitrages
     * @return list<array<string, mixed>>
     */
    private function comparerGroupe(Personne $personne, Collection $clients, array $arbitrages): array
    {
        $valeursPersonne = $this->synchronisation->identite($personne);
        $lignes = [];

        foreach (ClientIdentiteSynchronisation::COLONNES as $champ) {
            $cotePersonne = $valeursPersonne[$champ];
            // Complétion de la Personne : une seule valeur proposée par ses clients, sinon conflit.
            $propositions = $clients->reject(fn (Client $c) => $champ === 'nom_complet' && $c->entreprise_tierce_id !== null)
                ->map(fn (Client $c) => $c->getAttribute($champ))->filter(fn ($v) => ! blank($v))
                ->unique(fn ($v) => $this->cle($champ, $v));

            foreach ($clients as $client) {
                // Client professionnel (lot 7b) : son nom vient de l'entreprise, pas de son contact.
                if ($champ === 'nom_complet' && $client->entreprise_tierce_id !== null) {
                    continue;
                }
                $coteClient = $client->getAttribute($champ);
                $base = [
                    'client_id' => $client->id, 'personne_id' => $personne->id, 'nom' => $client->nom_complet,
                    'champ' => $champ, 'valeur_client' => $coteClient, 'valeur_personne' => $cotePersonne,
                ];

                if ($this->cle($champ, $coteClient) === $this->cle($champ, $cotePersonne)) {
                    // Même valeur, seule l'écriture diffère (casse, espaces) : alignée à l'activation.
                    if ((string) $coteClient !== (string) $cotePersonne) {
                        $lignes[] = [...$base, 'action' => 'identique'];
                    }

                    continue;
                }
                if (blank($coteClient)) {
                    $lignes[] = [...$base, 'action' => 'completer_client'];
                } elseif (blank($cotePersonne)) {
                    // Champ vide de la Personne : complété s'il n'y a qu'une proposition ; sinon seul
                    // « le client fait foi » tranche (la Personne n'a rien à imposer).
                    $arbitre = ($arbitrages[$client->id] ?? null) === self::CLIENT_FAIT_FOI ? self::CLIENT_FAIT_FOI : 'conflit';
                    $lignes[] = [...$base, 'action' => $propositions->count() === 1 ? 'completer_personne' : $arbitre];
                } else {
                    $lignes[] = [...$base, 'action' => $arbitrages[$client->id] ?? 'conflit'];
                }
            }
        }

        return $lignes;
    }

    /** Clé de comparaison : chiffres du téléphone, e-mail en minuscules, sinon casse et espaces ignorés. */
    private function cle(string $champ, mixed $valeur): string
    {
        $v = trim((string) $valeur);

        return match ($champ) {
            'telephone' => Personne::normaliserTelephone($v),
            default => mb_strtolower(preg_replace('/\s+/u', ' ', $v) ?? ''),
        };
    }

    private function ecrire(ContactsReprise $reprise, string $table, string $id, string $champ, mixed $valeur): void
    {
        $avant = DB::table($table)->where('id', $id)->value($champ);

        if ($champ === 'telephone' && ! blank($valeur)) {
            $normalise = Personne::normaliserTelephone((string) $valeur);
            $organisation = DB::table($table)->where('id', $id)->value('organization_id');
            $pris = $table === 'personnes'
                ? DB::table('personnes')->where('organization_id', $organisation)->where('telephone_normalise', $normalise)->where('id', '!=', $id)->whereNull('deleted_at')->exists()
                : Client::where('organization_id', $organisation)->whereKeyNot($id)->get(['id', 'telephone'])->contains(fn ($c) => Personne::normaliserTelephone((string) $c->telephone) === $normalise);
            if ($pris) {
                throw new InvalidArgumentException("téléphone déjà porté par une autre fiche ({$table})");
            }
        }

        $this->ecrireBrut($table, $id, $champ, $valeur);
        ContactsRepriseValeur::create([
            'contacts_reprise_id' => $reprise->id,
            'cible_type' => $table === 'personnes' ? 'personne' : 'client',
            'cible_id' => $id,
            'champ' => $champ,
            'valeur_avant' => $avant,
            'valeur_apres' => $valeur,
        ]);
    }

    private function ecrireBrut(string $table, string $id, string $champ, mixed $valeur): void
    {
        $colonnes = [$champ => $valeur, 'updated_at' => now()];
        if ($table === 'personnes' && $champ === 'telephone') {
            $colonnes['telephone_normalise'] = blank($valeur) ? null : Personne::normaliserTelephone((string) $valeur);
        }
        DB::table($table)->where('id', $id)->update($colonnes);
    }
}
