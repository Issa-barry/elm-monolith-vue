<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\AuditEvent;
use App\Enums\StatutPeriodePaiement;
use App\Enums\TypePeriodePaiement;
use App\Features\ModuleFeature;
use App\Models\AuditLog;
use App\Models\CompteTresorerie;
use App\Models\Livreur;
use App\Models\PaiementFiche;
use App\Models\PaiementFichePaiement;
use App\Models\PaiementPeriode;
use App\Models\PieceComptable;
use App\Models\Site;
use App\Models\User;
use App\Services\Tresorerie\TresorerieDisponibiliteService;
use App\Support\Paiements\OperationPaiement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Tests\Feature\Concerns\AmorceNumerotationComptable;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Preuve de concurrence RÉELLE des paiements de fiche (ADR 0069) : plusieurs requêtes, bouton Payer
 * ou paiement groupé, lancées au même instant, chacune dans son propre processus PHP et sur sa propre
 * connexion MySQL. SQLite, qui fait tourner le reste de la suite, ignore lockForUpdate() : seule une
 * vraie base montre que les verrous départagent les envois.
 *
 * Même infrastructure que Tresorerie\CompteMobileMoneyAgentConcurrenceTest : base dédiée
 * `elm_monolithe_concurrency_test` (connexion mysql_testing), jamais la base de dev. Se SKIP si
 * elle n'est pas disponible ou pas migrée (php artisan migrate --database=mysql_testing).
 */
class PaiementFicheConcurrenceTest extends TestCase
{
    use AmorceNumerotationComptable, HasAdminSetup, HasCaissesDediees, HasOrgAndUser;

    private const CONNECTION = 'mysql_testing';

    /** Paiement en cours d'écriture, verrous tenus, assez longtemps pour que l'autre envoi arrive. */
    private const DELAI_MS = 1500;

    /** Amorçage du framework par chaque sous-processus : long sur une machine locale chargée. */
    private const ATTENTE_DEMARRAGE_S = 180;

    /**
     * Réponse après le départ commun. Au-delà du délai d'attente de verrou MySQL (50 s par défaut) :
     * un verrou bloqué ou un interblocage remonte avant, en erreur 1205 ou 1213 de la requête.
     */
    private const ATTENTE_REPONSE_S = 90;

    private ?string $dossier = null;

    private ?string $defaut = null;

    private Site $agence;

    private CompteTresorerie $orange;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->baseDisponible()) {
            $this->markTestSkipped(
                'Connexion mysql_testing indisponible ou base non migrée — vérifier qu\'un serveur MySQL local '.
                'tourne et que elm_monolithe_concurrency_test est migrée (php artisan migrate --database=mysql_testing).'
            );
        }

        // Tout le test sur la base partagée avec les sous-processus : ils doivent voir ses données.
        $this->defaut = config('database.default');
        config(['database.default' => self::CONNECTION]);

        $this->initOrgAndUser(['comptabilite.read', 'comptabilite.payer']);
        Feature::for($this->org)->activate(ModuleFeature::COMPTABILITE);
        $this->agence = $this->user->sites()->wherePivot('is_default', true)->firstOrFail();
        $this->orange = $this->creerSupportAgence($this->agence->id, 'mobile_money', '561100', 'orange_money');
        $this->amorcerNumerotation($this->org->id);
    }

    protected function tearDown(): void
    {
        if (isset($this->org)) {
            $this->supprimerOrganisation($this->org->id);
        }

        if ($this->dossier && is_dir($this->dossier)) {
            foreach (glob($this->dossier.'/*') ?: [] as $fichier) {
                @unlink($fichier);
            }
            @rmdir($this->dossier);
        }

        if ($this->defaut !== null) {
            config(['database.default' => $this->defaut]);
        }
        DB::purge(self::CONNECTION);

        parent::tearDown();
    }

    private function baseDisponible(): bool
    {
        try {
            return Schema::connection(self::CONNECTION)->hasTable('paiement_fiche_paiements_annules');
        } catch (\Throwable) {
            return false;
        }
    }

    /** Lignes de cette organisation seulement : la base est partagée avec les autres tests de concurrence. */
    private function supprimerOrganisation(string $organizationId): void
    {
        $connexion = DB::connection(self::CONNECTION);
        $tables = $connexion->select(
            'SELECT TABLE_NAME AS nom FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = ?',
            [$connexion->getDatabaseName(), 'organization_id'],
        );

        $connexion->statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $table) {
            $connexion->table($table->nom)->where('organization_id', $organizationId)->delete();
        }
        $connexion->table('organizations')->where('id', $organizationId)->delete();
        $connexion->statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function fiche(float $net = 300_000, ?Site $site = null): PaiementFiche
    {
        $periode = PaiementPeriode::create([
            'organization_id' => $this->org->id,
            'reference' => 'PAY-CONC-'.Str::upper(Str::random(8)),
            'type' => TypePeriodePaiement::LIVREUR->value,
            'date_debut' => now()->startOfMonth()->toDateString(),
            'date_fin' => now()->startOfMonth()->addDays(14)->toDateString(),
            'statut' => StatutPeriodePaiement::VALIDEE->value,
            'created_by' => $this->user->id,
        ]);

        return PaiementFiche::create([
            'organization_id' => $this->org->id,
            'periode_id' => $periode->id,
            'reference' => 'FICHE-CONC-'.Str::upper(Str::random(8)),
            'beneficiaire_type' => 'livreur',
            'beneficiaire_id' => Livreur::factory()->create(['organization_id' => $this->org->id])->id,
            'beneficiaire_nom' => 'Livreur '.Str::random(4),
            'site_id' => ($site ?? $this->agence)->id,
            'montant_brut' => $net,
            'total_deductions' => 0,
            'montant_net' => $net,
            'montant_paye' => 0,
            'statut' => 'a_payer',
        ]);
    }

    private function autrePayeur(): User
    {
        return $this->creerUtilisateurNonAdmin($this->agence, ['comptabilite.read', 'comptabilite.payer'], 'Fatou', 'Payeuse');
    }

    private function unitaire(User $payeur, PaiementFiche $fiche, string $cle, float $montant, ?CompteTresorerie $support = null): array
    {
        return [
            'user_id' => $payeur->id,
            'uri' => route('comptabilite.fiches.paiements.store', $fiche, false),
            'json' => false,
            'entetes' => [OperationPaiement::ENTETE => $cle],
            'corps' => [
                'montant' => $montant,
                'mode_paiement' => 'mobile_money',
                'compte_tresorerie_id' => ($support ?? $this->orange)->id,
                'reference_paiement' => 'OM-CONC',
                'date_paiement' => now()->toDateString(),
            ],
        ];
    }

    /** @param  list<PaiementFiche>  $fiches */
    private function lot(User $payeur, array $fiches, string $cle): array
    {
        return [
            'user_id' => $payeur->id,
            'uri' => route('comptabilite.fiches.paiements.lot', [], false),
            'json' => true,
            'entetes' => [OperationPaiement::ENTETE => $cle],
            'corps' => [
                'fiches' => array_map(fn (PaiementFiche $f) => ['id' => $f->id, 'montant' => $f->montant_restant], $fiches),
                'mode_paiement' => 'mobile_money',
                'compte_tresorerie_id' => $this->orange->id,
                'reference_paiement' => 'LOT-CONC',
                'date_paiement' => now()->toDateString(),
            ],
        ];
    }

    /**
     * Lance les requêtes dans des processus distincts, toutes au même instant, et rend le résultat
     * de chacune.
     *
     * @param  list<array<string, mixed>>  $ordres
     * @return list<array<string, mixed>>
     */
    private function enMemeTemps(array $ordres): array
    {
        $this->dossier = sys_get_temp_dir().'/elm_concurrence_paiement_'.bin2hex(random_bytes(6));
        mkdir($this->dossier);

        $processus = [];
        foreach ($ordres as $i => $ordre) {
            file_put_contents("{$this->dossier}/p{$i}_ordre.json", json_encode([...$ordre, 'delai_ms' => self::DELAI_MS]));
            $commande = sprintf(
                '%s %s %s %s',
                escapeshellarg(PHP_BINARY),
                escapeshellarg(base_path('tests/Support/concurrence_paiement_fiche.php')),
                escapeshellarg($this->dossier),
                escapeshellarg("p{$i}"),
            );
            $processus[$i] = proc_open($commande, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes, base_path());
            $this->assertIsResource($processus[$i], 'Impossible de lancer le sous-processus de concurrence.');
        }

        try {
            $demarrages = [];
            foreach (array_keys($ordres) as $i) {
                $this->attendre("{$this->dossier}/p{$i}_pret", self::ATTENTE_DEMARRAGE_S,
                    "DÉMARRAGE (pas une erreur fonctionnelle) : le processus {$i} n'a pas fini d'amorcer le framework en ".self::ATTENTE_DEMARRAGE_S.' s. Machine trop chargée : relancer.');
                $demarrages[$i] = json_decode((string) file_get_contents("{$this->dossier}/p{$i}_pret"), true)['demarrage'] ?? null;
            }

            file_put_contents($this->dossier.'/go', '1');

            $resultats = [];
            foreach (array_keys($ordres) as $i) {
                $this->attendre("{$this->dossier}/p{$i}_fini", self::ATTENTE_REPONSE_S,
                    "FONCTIONNEL : la requête {$i} n'a pas répondu ".self::ATTENTE_REPONSE_S.' s après le départ commun, au-delà du délai d\'attente de verrou MySQL : blocage réel à analyser.');
                $resultats[$i] = json_decode((string) file_get_contents("{$this->dossier}/p{$i}_fini"), true);
                $this->assertNull($resultats[$i]['exception'] ?? null, "Processus {$i} : ".json_encode($resultats[$i], JSON_UNESCAPED_UNICODE));
            }
        } finally {
            foreach ($processus as $p) {
                proc_close($p);
            }
        }

        // Preuve que les envois se sont chevauchés : chacun a commencé avant la fin de l'autre.
        $this->assertLessThan(min(array_column($resultats, 'fin')), max(array_column($resultats, 'debut')), 'Les requêtes ne se sont pas chevauchées.');

        if (getenv('CONCURRENCE_TRACE')) {
            fwrite(STDERR, sprintf(
                "\n[%s] démarrage %s s ; requêtes %s s\n",
                $this->name(),
                implode(' / ', array_map(fn ($d) => number_format((float) $d, 1), $demarrages)),
                implode(' / ', array_map(fn (array $r) => number_format($r['fin'] - $r['debut'], 2), $resultats)),
            ));
        }

        return $resultats;
    }

    private function attendre(string $fichier, int $secondes, string $messageEchec): void
    {
        $limite = microtime(true) + $secondes;
        while (! file_exists($fichier) && microtime(true) < $limite) {
            usleep(20000);
        }

        $this->assertFileExists($fichier, $messageEchec);
    }

    private function solde(): float
    {
        return app(TresorerieDisponibiliteService::class)->soldePourSupport($this->orange->fresh(), now());
    }

    private function nombrePaiements(PaiementFiche ...$fiches): int
    {
        return PaiementFichePaiement::whereIn('fiche_id', array_map(fn (PaiementFiche $f) => $f->id, $fiches))->count();
    }

    /** Aucun doublon : une écriture et un historique par paiement, et jamais plus que le dû. */
    private function assertTraceUnique(PaiementFiche $fiche): void
    {
        $paiements = PaiementFichePaiement::where('fiche_id', $fiche->id)->pluck('id');
        $this->assertSame($paiements->count(), PieceComptable::whereIn('source_id', $paiements)->count(), 'Une écriture comptable par paiement.');
        $this->assertSame($paiements->count(), AuditLog::where('auditable_id', $fiche->id)->where('event_code', AuditEvent::PAID->value)->count(), 'Un historique par paiement.');
        $fiche->refresh();
        $this->assertLessThanOrEqual((float) $fiche->montant_net, (float) $fiche->montant_paye, 'Jamais plus que le dû.');
        $this->assertSame((float) PaiementFichePaiement::where('fiche_id', $fiche->id)->sum('montant'), (float) $fiche->montant_paye);
    }

    public function test_meme_cle_deux_envois_simultanes_dun_paiement_partiel(): void
    {
        $this->alimenterCaisse($this->orange, 1_000_000);
        $fiche = $this->fiche();
        $cle = (string) Str::uuid();

        $resultats = $this->enMemeTemps([
            $this->unitaire($this->user, $fiche, $cle, 50_000),
            $this->unitaire($this->user, $fiche, $cle, 50_000),
        ]);

        // L'un enregistre, l'autre attend le verrou puis restitue ce paiement : deux succès, un paiement.
        foreach ($resultats as $i => $r) {
            $this->assertSame([], $r['erreurs'], "Envoi {$i}");
            $this->assertSame('Paiement enregistré avec succès.', $r['succes'], "Envoi {$i}");
        }
        $this->assertSame(1, $this->nombrePaiements($fiche));
        $this->assertTraceUnique($fiche);
        $this->assertSame(950_000.0, $this->solde());
    }

    public function test_cles_differentes_paiements_partiels_simultanes_ne_depassent_pas_le_du(): void
    {
        $this->alimenterCaisse($this->orange, 1_000_000);
        $fiche = $this->fiche(300_000);

        $resultats = $this->enMemeTemps([
            $this->unitaire($this->user, $fiche, (string) Str::uuid(), 200_000),
            $this->unitaire($this->autrePayeur(), $fiche, (string) Str::uuid(), 200_000),
        ]);

        // Sans verrou, chacun aurait vu 300 000 restants et payé : 400 000 versés pour 300 000 dus.
        $this->assertSame(1, $this->nombrePaiements($fiche));
        $this->assertCount(1, array_filter($resultats, fn (array $r) => isset($r['erreurs']['montant'])));
        $this->assertTraceUnique($fiche);
        $this->assertSame(800_000.0, $this->solde());
    }

    public function test_deux_payeurs_lancent_le_meme_lot_simultanement(): void
    {
        $this->alimenterCaisse($this->orange, 1_000_000);
        $a = $this->fiche(300_000);
        $b = $this->fiche(200_000);

        $resultats = $this->enMemeTemps([
            $this->lot($this->user, [$a, $b], (string) Str::uuid().'.0'),
            $this->lot($this->autrePayeur(), [$a, $b], (string) Str::uuid().'.0'),
        ]);

        // Le second, servi après le premier, trouve les deux fiches soldées : rien à payer.
        $payees = array_map(fn (array $r) => $r['json']['payees'] ?? null, $resultats);
        sort($payees);
        $this->assertSame([0, 2], $payees, json_encode($resultats, JSON_UNESCAPED_UNICODE));
        $this->assertSame(2, $this->nombrePaiements($a, $b));
        $this->assertTraceUnique($a);
        $this->assertTraceUnique($b);
        $this->assertSame(500_000.0, $this->solde());
    }

    public function test_meme_cle_de_lot_deux_envois_simultanes(): void
    {
        $this->alimenterCaisse($this->orange, 1_000_000);
        $a = $this->fiche(300_000);
        $b = $this->fiche(200_000);
        $cle = (string) Str::uuid().'.0';

        $resultats = $this->enMemeTemps([
            $this->lot($this->user, [$a, $b], $cle),
            $this->lot($this->user, [$a, $b], $cle),
        ]);

        // Même réponse pour les deux : l'un a payé, l'autre restitue ce paiement.
        foreach ($resultats as $i => $r) {
            $this->assertSame(200, $r['statut'], "Envoi {$i} : ".json_encode($r['json'], JSON_UNESCAPED_UNICODE));
            $this->assertSame(2, $r['json']['payees']);
            $this->assertEquals(500_000, $r['json']['montant_total']);
        }
        $this->assertEqualsCanonicalizing([false, true], array_map(fn (array $r) => $r['json']['rejeu'], $resultats));
        $this->assertSame(2, $this->nombrePaiements($a, $b));
        $this->assertTraceUnique($a);
        $this->assertTraceUnique($b);
        $this->assertSame(500_000.0, $this->solde());
    }

    public function test_deux_lots_sur_le_meme_compte_ne_le_rendent_jamais_negatif(): void
    {
        $this->alimenterCaisse($this->orange, 400_000);
        $x = $this->fiche(300_000);
        $y = $this->fiche(300_000);

        $resultats = $this->enMemeTemps([
            $this->lot($this->user, [$x], (string) Str::uuid().'.0'),
            $this->lot($this->autrePayeur(), [$y], (string) Str::uuid().'.0'),
        ]);

        // Le compte ne couvre qu'un des deux : le second relit le solde après le premier et est refusé.
        $statuts = array_column($resultats, 'statut');
        sort($statuts);
        $this->assertSame([200, 422], $statuts, json_encode($resultats, JSON_UNESCAPED_UNICODE));
        $refus = collect($resultats)->firstWhere('statut', 422);
        $this->assertStringStartsWith('Solde insuffisant', $refus['json']['errors']['montant'][0]);
        $this->assertSame(1, $this->nombrePaiements($x, $y));
        $this->assertTraceUnique($x);
        $this->assertTraceUnique($y);
        $this->assertSame(100_000.0, $this->solde());
    }

    public function test_bouton_payer_et_lot_simultanes_sur_la_meme_fiche(): void
    {
        $this->alimenterCaisse($this->orange, 1_000_000);
        $fiche = $this->fiche(300_000);

        $this->enMemeTemps([
            $this->unitaire($this->user, $fiche, (string) Str::uuid(), 50_000),
            $this->lot($this->autrePayeur(), [$fiche], (string) Str::uuid().'.0'),
        ]);

        // Payer d'abord : le lot voit un reste changé et refuse tout. Lot d'abord : plus rien à payer.
        $this->assertSame(1, $this->nombrePaiements($fiche));
        $this->assertTraceUnique($fiche);
        $this->assertContains($this->solde(), [950_000.0, 700_000.0]);
    }

    public function test_deux_agences_payent_en_meme_temps_sur_le_meme_compte_comptable(): void
    {
        // Deux supports Orange Money de deux agences : même compte 561100, soldes distincts par agence.
        $this->alimenterCaisse($this->orange, 1_000_000);
        $kindia = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kindia', 'type' => 'depot', 'localisation' => 'Kindia']);
        $this->user->sites()->attach($kindia->id);
        $orangeKindia = $this->creerSupportAgence($kindia->id, 'mobile_money', '561100', 'orange_money');
        $this->alimenterCaisse($orangeKindia, 1_000_000);
        $a = $this->fiche(300_000);
        $b = $this->fiche(300_000, $kindia);

        $resultats = $this->enMemeTemps([
            $this->unitaire($this->user, $a, (string) Str::uuid(), 100_000),
            $this->unitaire($this->user, $b, (string) Str::uuid(), 100_000, $orangeKindia),
        ]);

        // La lecture verrouillante du solde porte sur le compte comptable : les deux envois peuvent
        // s'attendre, mais aucun ne doit échouer (interblocage) ni lire le solde de l'autre agence.
        foreach ($resultats as $i => $r) {
            $this->assertSame([], $r['erreurs'], "Envoi {$i}");
            $this->assertSame('Paiement enregistré avec succès.', $r['succes'], "Envoi {$i}");
        }
        $this->assertTraceUnique($a);
        $this->assertTraceUnique($b);
        $this->assertSame(900_000.0, $this->solde());
        $this->assertSame(900_000.0, app(TresorerieDisponibiliteService::class)->soldePourSupport($orangeKindia->fresh(), now()));
    }

    public function test_suppression_et_rejeu_simultanes_ne_recreent_jamais_le_paiement(): void
    {
        $this->alimenterCaisse($this->orange, 1_000_000);
        $fiche = $this->fiche(300_000);
        $cle = (string) Str::uuid();
        $ordrePaiement = $this->unitaire($this->user, $fiche, $cle, 100_000);
        $this->actingAs($this->user)->withHeader(OperationPaiement::ENTETE, $cle)
            ->post(route('comptabilite.fiches.paiements.store', $fiche), $ordrePaiement['corps'])
            ->assertSessionHasNoErrors();
        $paiement = PaiementFichePaiement::where('fiche_id', $fiche->id)->firstOrFail();

        $this->enMemeTemps([
            [
                'user_id' => $this->user->id,
                'methode' => 'DELETE',
                'uri' => route('comptabilite.fiches.paiements.destroy', $paiement, false),
                'json' => false,
                'entetes' => [],
                'corps' => [],
            ],
            $ordrePaiement,
        ]);

        // Suppression d'abord : le rejeu est refusé. Rejeu d'abord : il restitue le paiement, que la
        // suppression retire ensuite. Dans les deux ordres, rien n'est recréé.
        $this->assertSame(0, $this->nombrePaiements($fiche));
        $this->assertSame(1, DB::table('paiement_fiche_paiements_annules')->where('operation_id', $cle)->count());
        $this->assertSame(1, PieceComptable::where('source_id', $paiement->id)->whereNotNull('piece_origine_id')->count());
        $this->assertSame(0.0, (float) $fiche->fresh()->montant_paye);
        $this->assertSame(1_000_000.0, $this->solde());
    }

    public function test_deux_suppressions_simultanees_du_meme_paiement(): void
    {
        $this->alimenterCaisse($this->orange, 1_000_000);
        $fiche = $this->fiche(300_000);
        $this->actingAs($this->user)->withHeader(OperationPaiement::ENTETE, (string) Str::uuid())
            ->post(route('comptabilite.fiches.paiements.store', $fiche), $this->unitaire($this->user, $fiche, 'inutile', 100_000)['corps'])
            ->assertSessionHasNoErrors();
        $paiement = PaiementFichePaiement::where('fiche_id', $fiche->id)->firstOrFail();
        $suppression = fn (User $auteur) => [
            'user_id' => $auteur->id,
            'methode' => 'DELETE',
            'uri' => route('comptabilite.fiches.paiements.destroy', $paiement, false),
            'json' => false,
            'entetes' => [],
            'corps' => [],
        ];

        $resultats = $this->enMemeTemps([$suppression($this->user), $suppression($this->autrePayeur())]);

        // Le second attend le verrou de la fiche, trouve le paiement supprimé et n'écrit rien.
        foreach ($resultats as $i => $r) {
            $this->assertTrue($r['succes'] === 'Paiement supprimé.' || $r['statut'] === 404, "Envoi {$i} : ".json_encode($r, JSON_UNESCAPED_UNICODE));
        }
        $this->assertModelMissing($paiement);
        $this->assertSame(1, PieceComptable::where('source_id', $paiement->id)->whereNotNull('piece_origine_id')->count(), 'Une seule contrepassation.');
        $this->assertSame(1, AuditLog::where('auditable_id', $fiche->id)->where('event_code', AuditEvent::PAYMENT_CANCELLED->value)->count(), 'Un seul historique « Paiement annulé ».');
        $this->assertSame(0.0, (float) $fiche->fresh()->montant_paye);
        $this->assertSame(1_000_000.0, $this->solde());
    }
}
