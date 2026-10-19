<?php

namespace Tests\Feature;

use App\Enums\MotifRetourTransfert;
use App\Enums\StatutTransfert;
use App\Enums\TypeEcartLogistique;
use App\Features\ModuleFeature;
use App\Http\Resources\Api\Mobile\NotificationResource;
use App\Jobs\DispatchPushNotificationsJob;
use App\Models\Organization;
use App\Models\Produit;
use App\Models\Site;
use App\Models\TransfertLigne;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\VarianteStock;
use App\Models\Vehicule;
use App\Notifications\EcartReceptionTransfertNotification;
use App\Services\TransfertLogistiqueService;
use App\Services\TransfertRetourService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\HasProduitVariante;
use Tests\TestCase;

/**
 * Signalement des écarts de réception d'un transfert logistique (ADR 0032) : notification dans
 * l'application, email et push au réceptionnaire, au créateur, à l'agence source et aux
 * administrateurs — sans aucune régularisation de stock.
 */
class TransfertEcartReceptionNotificationTest extends TestCase
{
    use HasProduitVariante, RefreshDatabase;

    private const PERMISSIONS = ['logistique.read', 'logistique.update', 'logistique.enregistrer_retour'];

    private Organization $org;

    private Site $source;

    private Site $destination;

    private Vehicule $vehicule;

    private string $varianteA;

    private string $varianteB;

    private User $createur;

    private User $collegueSource;

    private User $lecteurSource;

    private User $receptionnaire;

    private User $collegueDestination;

    private User $admin;

    private User $adminAutreOrg;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Bus::fake([DispatchPushNotificationsJob::class]);

        $this->org = Organization::factory()->create();
        Feature::for($this->org)->activate(ModuleFeature::LOGISTIQUE);

        foreach (self::PERMISSIONS as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        foreach (['super_admin', 'admin_entreprise', 'manager'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->source = $this->makeSite('Cba');
        $this->destination = $this->makeSite('Sonfonia');
        $this->vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'livraison_vente' => false,
            'livraison_logistique' => true,
            'is_active' => true,
        ]);

        $this->varianteA = $this->variante('Pack bouteille 500 ml');
        $this->varianteB = $this->variante('Bidon 19 L');

        $this->createur = $this->makeUser('manager', $this->source);
        $this->collegueSource = $this->makeUser('manager', $this->source);
        $this->lecteurSource = $this->makeUser('manager', $this->source, ['logistique.read']);
        $this->receptionnaire = $this->makeUser('manager', $this->destination);
        $this->collegueDestination = $this->makeUser('manager', $this->destination);
        $this->admin = $this->makeUser('admin_entreprise', $this->destination);

        $autreOrg = Organization::factory()->create();
        $this->adminAutreOrg = $this->makeUser('admin_entreprise', null, self::PERMISSIONS, $autreOrg);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeSite(string $nom): Site
    {
        return Site::create([
            'organization_id' => $this->org->id,
            'nom' => $nom,
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
    }

    private function makeUser(string $role, ?Site $site, array $permissions = self::PERMISSIONS, ?Organization $org = null): User
    {
        $user = User::factory()->create([
            'organization_id' => ($org ?? $this->org)->id,
            'email' => fake()->unique()->safeEmail(),
        ]);
        $user->assignRole($role);
        $user->givePermissionTo($permissions);
        if ($site) {
            $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);
        }

        return $user;
    }

    private function variante(string $nom): string
    {
        /** @var Produit $produit */
        $produit = $this->makeProduitAvecVariante($this->org, ['nom' => $nom]);
        $varianteId = $produit->variantePrincipale()->first()->id;

        VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $varianteId,
            'site_id' => $this->source->id,
            'qte_stock' => 500,
        ]);

        return $varianteId;
    }

    /**
     * Transfert créé par $this->createur et parti (TRANSIT).
     *
     * @param  array<string, int>  $chargements  variante_id => quantité chargée
     */
    private function transfertEnTransit(array $chargements): TransfertLogistique
    {
        $this->actingAs($this->createur);

        $transfert = TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'site_source_id' => $this->source->id,
            'site_destination_id' => $this->destination->id,
            'vehicule_id' => $this->vehicule->id,
            'statut' => StatutTransfert::CHARGEMENT,
        ]);

        foreach ($chargements as $varianteId => $quantite) {
            TransfertLigne::create([
                'transfert_logistique_id' => $transfert->id,
                'variante_id' => $varianteId,
                'quantite_demandee' => $quantite,
                'quantite_chargee' => $quantite,
            ]);
        }

        TransfertLogistiqueService::avancerStatut($transfert);

        return $transfert->fresh();
    }

    /**
     * Réception saisie et validée depuis l'écran web par $this->receptionnaire.
     *
     * @param  array<string, array{0: int, 1: TypeEcartLogistique, 2?: string}>  $receptions  variante_id => [reçu, type, motif]
     */
    private function receptionnerParLeWeb(TransfertLogistique $transfert, array $receptions, ?User $par = null)
    {
        $lignes = $transfert->lignes()->get()->map(function (TransfertLigne $l) use ($receptions) {
            [$recue, $type] = $receptions[$l->variante_id];

            return [
                'id' => $l->id,
                'quantite_recue' => $recue,
                'ecart_type' => $type->value,
                'ecart_motif' => $receptions[$l->variante_id][2] ?? null,
            ];
        })->all();

        return $this->actingAs($par ?? $this->receptionnaire)
            ->post("/backoffice/logistique/{$transfert->id}/statut/avancer", ['lignes' => $lignes]);
    }

    private function signalements(): Collection
    {
        return DatabaseNotification::where('type', EcartReceptionTransfertNotification::class)->get();
    }

    /** @return array<int, string> */
    private function destinatairesAttendus(): array
    {
        return [$this->receptionnaire->id, $this->createur->id, $this->collegueSource->id, $this->admin->id];
    }

    // ── Déclenchement ─────────────────────────────────────────────────────────

    public function test_reception_conforme_n_envoie_aucun_signalement(): void
    {
        Notification::fake();
        $transfert = $this->transfertEnTransit([$this->varianteA => 100]);

        $this->receptionnerParLeWeb($transfert, [$this->varianteA => [100, TypeEcartLogistique::CONFORME]])
            ->assertRedirect("/backoffice/logistique/{$transfert->id}");

        $this->assertSame(StatutTransfert::RECEPTION, $transfert->fresh()->statut);
        Notification::assertNotSentTo(User::all(), EcartReceptionTransfertNotification::class);
    }

    public function test_manquant_notifie_reception_naire_createur_agence_source_et_admins_dans_l_application_et_par_email(): void
    {
        Notification::fake();
        $transfert = $this->transfertEnTransit([$this->varianteA => 100]);

        $this->receptionnerParLeWeb($transfert, [$this->varianteA => [80, TypeEcartLogistique::MANQUANT, 'Cartons absents']]);

        foreach ($this->destinatairesAttendus() as $userId) {
            $user = User::find($userId);
            Notification::assertSentToTimes($user, EcartReceptionTransfertNotification::class, 2);
            Notification::assertSentTo($user, EcartReceptionTransfertNotification::class, fn ($n, array $canaux) => $canaux === ['database']);
            Notification::assertSentTo($user, EcartReceptionTransfertNotification::class, fn ($n, array $canaux) => $canaux === ['mail']);
        }

        // Ni l'agent source sans droit de gestion, ni l'agence destination (hors réceptionnaire),
        // ni une autre organisation.
        Notification::assertNotSentTo([$this->lecteurSource, $this->collegueDestination, $this->adminAutreOrg], EcartReceptionTransfertNotification::class);

        Bus::assertDispatched(DispatchPushNotificationsJob::class, fn (DispatchPushNotificationsJob $job) => $job->payload['data']['type'] === 'transfer.reception_discrepancy'
            && $job->payload['data']['transfert_id'] === $transfert->id
            && collect($job->userIds)->sort()->values()->all() === collect($this->destinatairesAttendus())->sort()->values()->all());
    }

    public function test_contenu_du_signalement_et_de_l_email(): void
    {
        $transfert = $this->transfertEnTransit([$this->varianteA => 100]);
        $this->receptionnerParLeWeb($transfert, [$this->varianteA => [80, TypeEcartLogistique::MANQUANT, 'Cartons absents']]);

        $emails = collect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())
            ->map(fn ($m) => $m->getOriginalMessage());
        $this->assertCount(4, $emails);
        $emailAdmin = $emails->first(fn ($m) => $m->getTo()[0]->getAddress() === $this->admin->email);
        $this->assertNotNull($emailAdmin);
        $this->assertSame("Écart de réception détecté — Transfert {$transfert->reference}", $emailAdmin->getSubject());

        $signalement = $this->signalements()->firstWhere('notifiable_id', $this->admin->id);
        $data = $signalement->data;

        $this->assertSame('transfert_ecart_reception', $data['type']);
        $this->assertSame(['type' => 'transfert_logistique', 'id' => $transfert->id], $data['resource']);
        $this->assertSame($transfert->reference, $data['reference']);
        $this->assertSame('Cba', $data['site_source']);
        $this->assertSame('Sonfonia', $data['site_destination']);
        $this->assertSame($this->receptionnaire->name, $data['receptionnaire']);
        $this->assertFalse($data['nouvelle_reception']);
        $this->assertSame([[
            'produit' => 'Pack bouteille 500 ml',
            'variante' => null,
            'quantite_chargee' => 100,
            'quantite_recue' => 80,
            'quantite_retournee' => 0,
            'ecart' => -20,
            'ecart_type' => 'manquant',
            'ecart_type_label' => 'Manquant',
            'ecart_motif' => 'Cartons absents',
        ]], $data['lignes']);

        $mail = (new EcartReceptionTransfertNotification($this->constatDe($data, $transfert), ['mail']))->toMail($this->admin);
        $texte = implode("\n", array_merge($mail->introLines, $mail->outroLines));

        $this->assertSame("Écart de réception détecté — Transfert {$transfert->reference}", $mail->subject);
        $this->assertStringContainsString('Pack bouteille 500 ml — chargé : 100, réceptionné : 80, non réceptionné : 20. Type d\'écart : Manquant — Cartons absents.', $texte);
        $this->assertStringContainsString('Agence source : Cba.', $texte);
        $this->assertStringContainsString('Agence destinataire : Sonfonia.', $texte);
        $this->assertStringContainsString("Réception effectuée par : {$this->receptionnaire->name}.", $texte);
        $this->assertStringContainsString('Une vérification et un traitement manuel de cet écart sont nécessaires.', $texte);
        $this->assertSame(route('logistique.show', $transfert->id), $mail->actionUrl);
    }

    /** @param  array<string, mixed>  $data */
    private function constatDe(array $data, TransfertLogistique $transfert): array
    {
        return [
            'transfert_id' => $transfert->id,
            'reference' => $data['reference'],
            'site_source' => $data['site_source'],
            'site_destination' => $data['site_destination'],
            'receptionnaire' => $data['receptionnaire'],
            'receptionne_le' => $data['receptionne_le'],
            'nouvelle_reception' => $data['nouvelle_reception'],
            'lignes' => $data['lignes'],
        ];
    }

    public function test_plusieurs_lignes_en_ecart_sont_regroupees_dans_un_seul_signalement(): void
    {
        $transfert = $this->transfertEnTransit([$this->varianteA => 100, $this->varianteB => 40]);

        $this->receptionnerParLeWeb($transfert, [
            $this->varianteA => [80, TypeEcartLogistique::MANQUANT],
            $this->varianteB => [35, TypeEcartLogistique::CASSE, 'Bidons fendus'],
        ]);

        $recus = $this->signalements()->where('notifiable_id', $this->admin->id);
        $this->assertCount(1, $recus);
        $lignes = collect($recus->first()->data['lignes'])->keyBy('produit');
        $this->assertSame(-20, $lignes['Pack bouteille 500 ml']['ecart']);
        $this->assertSame(-5, $lignes['Bidon 19 L']['ecart']);
        $this->assertSame('casse', $lignes['Bidon 19 L']['ecart_type']);
        $this->assertSame('Bidons fendus', $lignes['Bidon 19 L']['ecart_motif']);
    }

    public function test_seules_les_lignes_en_ecart_figurent_dans_le_signalement(): void
    {
        $transfert = $this->transfertEnTransit([$this->varianteA => 100, $this->varianteB => 40]);

        $this->receptionnerParLeWeb($transfert, [
            $this->varianteA => [100, TypeEcartLogistique::CONFORME],
            $this->varianteB => [30, TypeEcartLogistique::MANQUANT],
        ]);

        $lignes = $this->signalements()->firstWhere('notifiable_id', $this->admin->id)->data['lignes'];
        $this->assertSame(['Bidon 19 L'], array_column($lignes, 'produit'));
    }

    public function test_surplus_est_signale(): void
    {
        $transfert = $this->transfertEnTransit([$this->varianteA => 100]);

        $this->receptionnerParLeWeb($transfert, [$this->varianteA => [105, TypeEcartLogistique::SURPLUS]]);

        $ligne = $this->signalements()->firstWhere('notifiable_id', $this->admin->id)->data['lignes'][0];
        $this->assertSame(5, $ligne['ecart']);
        $this->assertStringContainsString('surplus : 5', EcartReceptionTransfertNotification::ligneTexte($ligne));
    }

    public function test_casse_declaree_sans_ecart_chiffre_est_signalee(): void
    {
        $transfert = $this->transfertEnTransit([$this->varianteA => 100]);

        $this->receptionnerParLeWeb($transfert, [$this->varianteA => [100, TypeEcartLogistique::CASSE, '3 packs écrasés']]);

        $ligne = $this->signalements()->firstWhere('notifiable_id', $this->admin->id)->data['lignes'][0];
        $this->assertSame(0, $ligne['ecart']);
        $this->assertSame('casse', $ligne['ecart_type']);
    }

    public function test_marchandise_revenue_au_site_source_n_est_pas_un_ecart(): void
    {
        $transfert = $this->transfertEnTransit([$this->varianteA => 100]);
        $ligne = $transfert->lignes()->firstOrFail();
        $this->actingAs($this->collegueSource);
        TransfertRetourService::enregistrer($transfert, [['id' => $ligne->id, 'quantite' => 30]], MotifRetourTransfert::ERREUR_CHARGEMENT, null);

        $this->receptionnerParLeWeb($transfert->fresh(), [$this->varianteA => [70, TypeEcartLogistique::CONFORME]]);

        $this->assertSame(StatutTransfert::RECEPTION, $transfert->fresh()->statut);
        $this->assertCount(0, $this->signalements());
    }

    public function test_reception_refusee_ne_signale_rien(): void
    {
        Notification::fake();
        $transfert = $this->transfertEnTransit([$this->varianteA => 100]);
        $transfert->lignes()->update(['quantite_recue' => 80]);

        $this->actingAs($this->receptionnaire);
        try {
            TransfertLogistiqueService::avancerStatut($transfert->fresh());
            $this->fail('La réception aurait dû être refusée (type d\'écart manquant).');
        } catch (ValidationException) {
        }

        $this->assertSame(StatutTransfert::TRANSIT, $transfert->fresh()->statut);
        Notification::assertNotSentTo(User::all(), EcartReceptionTransfertNotification::class);
    }

    public function test_double_soumission_ne_signale_qu_une_fois(): void
    {
        $transfert = $this->transfertEnTransit([$this->varianteA => 100]);
        $transfert->lignes()->update(['quantite_recue' => 80, 'ecart_type' => TypeEcartLogistique::MANQUANT->value]);

        $this->actingAs($this->receptionnaire);
        TransfertLogistiqueService::avancerStatut($transfert->fresh());
        TransfertLogistiqueService::avancerStatut($transfert->fresh());

        $this->assertCount(1, $this->signalements()->where('notifiable_id', $this->admin->id));
    }

    public function test_invalidation_retire_le_signalement_et_une_nouvelle_reception_conforme_ne_signale_rien(): void
    {
        $transfert = $this->transfertEnTransit([$this->varianteA => 100]);
        $this->receptionnerParLeWeb($transfert, [$this->varianteA => [80, TypeEcartLogistique::MANQUANT]]);
        $this->assertTrue($this->signalements()->every(fn ($n) => $n->read_at === null));

        $this->actingAs($this->admin)
            ->post("/backoffice/logistique/{$transfert->id}/validation-reception", ['decision' => 'invalider'])
            ->assertRedirect("/backoffice/logistique/{$transfert->id}");

        $this->assertTrue($this->signalements()->every(fn ($n) => $n->read_at !== null));

        $this->receptionnerParLeWeb($transfert->fresh(), [$this->varianteA => [100, TypeEcartLogistique::CONFORME]]);

        $this->assertSame(StatutTransfert::RECEPTION, $transfert->fresh()->statut);
        $this->assertCount(4, $this->signalements());
    }

    public function test_nouvelle_reception_avec_ecart_apres_invalidation_est_signalee_comme_telle(): void
    {
        $transfert = $this->transfertEnTransit([$this->varianteA => 100]);
        $this->receptionnerParLeWeb($transfert, [$this->varianteA => [80, TypeEcartLogistique::MANQUANT]]);
        $this->actingAs($this->admin)->post("/backoffice/logistique/{$transfert->id}/validation-reception", ['decision' => 'invalider']);

        $this->receptionnerParLeWeb($transfert->fresh(), [$this->varianteA => [90, TypeEcartLogistique::MANQUANT]]);

        $nonLus = $this->signalements()->where('notifiable_id', $this->admin->id)->whereNull('read_at');
        $this->assertCount(1, $nonLus);
        $this->assertTrue($nonLus->first()->data['nouvelle_reception']);
        $this->assertSame(90, $nonLus->first()->data['lignes'][0]['quantite_recue']);
    }

    public function test_invalidation_par_l_api_retire_aussi_le_signalement(): void
    {
        $transfert = $this->transfertEnTransit([$this->varianteA => 100]);
        $this->receptionnerParLeWeb($transfert, [$this->varianteA => [80, TypeEcartLogistique::MANQUANT]]);

        $this->actingAs($this->admin)
            ->postJson(route('api.backoffice.logistique.transferts.validation-admin', $transfert), ['decision' => 'invalider'])
            ->assertOk();

        $this->assertTrue($this->signalements()->every(fn ($n) => $n->read_at !== null));
    }

    public function test_reception_par_l_api_mobile_signale_l_ecart(): void
    {
        $transfert = $this->transfertEnTransit([$this->varianteA => 100]);
        $ligne = $transfert->lignes()->firstOrFail();

        $this->actingAs($this->receptionnaire)
            ->putJson(route('api.backoffice.logistique.transferts.reception', $transfert), ['lignes' => [[
                'id' => $ligne->id,
                'quantite_recue' => 60,
                'ecart_type' => TypeEcartLogistique::MANQUANT->value,
            ]]])
            ->assertOk();
        $this->postJson(route('api.backoffice.logistique.transferts.valider-reception', $transfert))->assertOk();

        $recus = $this->signalements()->pluck('notifiable_id')->sort()->values()->all();
        $this->assertSame(collect($this->destinatairesAttendus())->sort()->values()->all(), $recus);
        $this->assertSame($this->receptionnaire->name, $this->signalements()->first()->data['receptionnaire']);
    }

    // ── Destinataires ─────────────────────────────────────────────────────────

    public function test_destinataire_de_plusieurs_categories_ne_recoit_qu_un_signalement(): void
    {
        Notification::fake();
        // L'administrateur crée le transfert depuis son agence et le réceptionne lui-même.
        $this->admin->sites()->attach($this->source->id, ['role' => 'employe', 'is_default' => false]);
        $this->createur = $this->admin;
        $transfert = $this->transfertEnTransit([$this->varianteA => 100]);

        $this->receptionnerParLeWeb($transfert, [$this->varianteA => [80, TypeEcartLogistique::MANQUANT]], $this->admin);

        Notification::assertSentToTimes($this->admin, EcartReceptionTransfertNotification::class, 2);
        Notification::assertSentTo($this->admin, EcartReceptionTransfertNotification::class, fn ($n, array $canaux) => $canaux === ['mail']);
    }

    public function test_preference_livraisons_desactivee_respectee(): void
    {
        Notification::fake();
        $this->admin->update(['notification_preferences' => ['livraisons' => false]]);
        $transfert = $this->transfertEnTransit([$this->varianteA => 100]);

        $this->receptionnerParLeWeb($transfert, [$this->varianteA => [80, TypeEcartLogistique::MANQUANT]]);

        Notification::assertNotSentTo($this->admin, EcartReceptionTransfertNotification::class);
        Notification::assertSentTo($this->createur, EcartReceptionTransfertNotification::class);
    }

    public function test_echec_de_l_email_ne_prive_personne_du_signalement_ni_n_annule_la_reception(): void
    {
        config(['mail.default' => 'mailer-inexistant']);
        Log::spy();
        $transfert = $this->transfertEnTransit([$this->varianteA => 100]);

        $this->receptionnerParLeWeb($transfert, [$this->varianteA => [80, TypeEcartLogistique::MANQUANT]])
            ->assertRedirect("/backoffice/logistique/{$transfert->id}");

        $this->assertSame(StatutTransfert::RECEPTION, $transfert->fresh()->statut);
        $this->assertSame(
            collect($this->destinatairesAttendus())->sort()->values()->all(),
            $this->signalements()->pluck('notifiable_id')->sort()->values()->all(),
        );
        Log::shouldHaveReceived('error')->with('EcartReceptionTransfertNotification : email non envoyé', \Mockery::any())->times(4);
    }

    // ── Affichage ─────────────────────────────────────────────────────────────

    public function test_cloche_du_back_office_affiche_et_ouvre_le_signalement(): void
    {
        $transfert = $this->transfertEnTransit([$this->varianteA => 100]);
        $this->receptionnerParLeWeb($transfert, [$this->varianteA => [80, TypeEcartLogistique::MANQUANT]]);
        $signalement = $this->signalements()->firstWhere('notifiable_id', $this->admin->id);

        $this->actingAs($this->admin)->get("/backoffice/logistique/{$transfert->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('ecarts_reception.total', 1)
                ->where('ecarts_reception.recents.0.id', $signalement->id)
                ->where('ecarts_reception.recents.0.message', $signalement->data['message']));

        $this->actingAs($this->admin)
            ->post("/backoffice/logistique/ecarts-reception/{$signalement->id}/ouvrir")
            ->assertRedirect("/backoffice/logistique/{$transfert->id}");

        $this->assertNotNull($signalement->fresh()->read_at);
        $this->actingAs($this->admin)->get("/backoffice/logistique/{$transfert->id}")
            ->assertInertia(fn (Assert $page) => $page->where('ecarts_reception.total', 0));
    }

    public function test_signalement_d_un_autre_utilisateur_introuvable(): void
    {
        $transfert = $this->transfertEnTransit([$this->varianteA => 100]);
        $this->receptionnerParLeWeb($transfert, [$this->varianteA => [80, TypeEcartLogistique::MANQUANT]]);
        $signalement = $this->signalements()->firstWhere('notifiable_id', $this->admin->id);

        $this->actingAs($this->collegueDestination)
            ->post("/backoffice/logistique/ecarts-reception/{$signalement->id}/ouvrir")
            ->assertNotFound();

        $this->assertNull($signalement->fresh()->read_at);
    }

    public function test_api_mobile_expose_un_type_stable(): void
    {
        $transfert = $this->transfertEnTransit([$this->varianteA => 100]);
        $this->receptionnerParLeWeb($transfert, [$this->varianteA => [80, TypeEcartLogistique::MANQUANT]]);

        $ressource = (new NotificationResource($this->signalements()->first()))->toArray(request());

        $this->assertSame('transfer.reception_discrepancy', $ressource['type']);
        $this->assertSame('Écart de réception', $ressource['titre']);
        $this->assertSame(['type' => 'transfert_logistique', 'id' => $transfert->id], $ressource['resource']);
    }
}
