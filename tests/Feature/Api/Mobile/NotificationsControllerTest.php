<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\Organization;
use App\Models\User;
use App\Notifications\CommandeValideeNotification;
use App\Notifications\CommissionGenereeNotification;
use App\Notifications\CommissionManquanteNotification;
use App\Notifications\CommissionPayeeNotification;
use App\Notifications\DepenseValideeNotification;
use App\Notifications\TransfertCreeNotification;
use App\Notifications\TransfertReceptionneeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Contrat unique de la cloche de notifications (phase "finalisation API",
 * 2026-08-27, cf. rapport) : NotificationResource normalise 7 classes
 * Notification différentes (2 historiques, 5 phase 1) vers une seule forme
 * stable — le frontend ne doit jamais brancher sur le nom de classe PHP.
 */
class NotificationsControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private const EXPECTED_KEYS = ['id', 'type', 'titre', 'message', 'montant', 'resource', 'lu', 'read_at', 'created_at'];

    public function test_toutes_les_classes_notification_partagent_la_meme_structure(): void
    {
        $user = $this->actor();

        $user->notify(new CommandeValideeNotification('cmd-1', 'CMD-001', 'Site A'));
        $user->notify(new CommissionManquanteNotification('cmd-2', 'CMD-002', 5000.0, null));
        $user->notify(new CommissionGenereeNotification('commande_vente', 'cmd-3', 'CMD-003', 175500.0));
        $user->notify(new CommissionPayeeNotification(50000.0, 'especes', null, 'commission_payment', 'pay-1'));
        $user->notify(new DepenseValideeNotification('dep-1', 'IV-029', 30000.0));
        $user->notify(new TransfertCreeNotification('tr-1', 'TR-001'));
        $user->notify(new TransfertReceptionneeNotification('tr-2', 'TR-002'));

        $response = $this->getJson(route('client.notifications.index'))->assertOk();

        $rows = $response->json('data');
        $this->assertCount(7, $rows);
        foreach ($rows as $row) {
            $this->assertEqualsCanonicalizing(self::EXPECTED_KEYS, array_keys($row));
        }
    }

    public function test_mapping_des_types_techniques(): void
    {
        $user = $this->actor();

        $user->notify(new CommandeValideeNotification('cmd-1', 'CMD-001', 'Site A'));
        $user->notify(new CommissionManquanteNotification('cmd-2', 'CMD-002', 5000.0, null));
        $user->notify(new CommissionGenereeNotification('commande_vente', 'cmd-3', 'CMD-003', 175500.0));
        $user->notify(new CommissionPayeeNotification(50000.0, 'especes'));
        $user->notify(new DepenseValideeNotification('dep-1', 'IV-029', 30000.0));
        $user->notify(new TransfertCreeNotification('tr-1', 'TR-001'));
        $user->notify(new TransfertReceptionneeNotification('tr-2', 'TR-002'));

        $types = $this->getJson(route('client.notifications.index'))
            ->assertOk()
            ->json('data.*.type');

        $this->assertEqualsCanonicalizing([
            'delivery.assigned',
            'commission.missing',
            'commission.generated',
            'commission.paid',
            'expense.validated',
            'transfer.created',
            'transfer.received',
        ], $types);
    }

    public function test_resource_synthetise_pour_les_notifications_historiques(): void
    {
        $user = $this->actor();
        $user->notify(new CommandeValideeNotification('cmd-42', 'CMD-042', 'Site A'));

        $row = $this->getJson(route('client.notifications.index'))->assertOk()->json('data.0');

        $this->assertSame(['type' => 'commande_vente', 'id' => 'cmd-42'], $row['resource']);
        $this->assertNull($row['montant']);
    }

    public function test_resource_et_montant_passthrough_pour_les_notifications_phase1(): void
    {
        $user = $this->actor();
        $user->notify(new CommissionGenereeNotification('commande_vente', 'cmd-9', 'CMD-009', 175500.0));

        $row = $this->getJson(route('client.notifications.index'))->assertOk()->json('data.0');

        $this->assertSame(['type' => 'commande_vente', 'id' => 'cmd-9'], $row['resource']);
        // JSON n'a qu'un type "number" : PHP décode 175500.0 en int côté test, ce qui
        // est correct (le contrat exige un nombre brut, pas une distinction int/float).
        $this->assertEquals(175500.0, $row['montant']);
    }

    /**
     * Nettoyage des messages (2026-08-28, cf. rapport) : `montant` est déjà exposé
     * séparément — `message` ne doit plus jamais le répéter en toutes lettres
     * ("432 000 GNF — ..."), sous peine de duplication visuelle côté cloche/dashboard
     * (titre + message + montant affichés l'un sous l'autre).
     */
    public function test_message_ne_duplique_jamais_le_montant_deja_expose_separement(): void
    {
        $user = $this->actor();
        $user->notify(new CommissionGenereeNotification('commande_vente', 'cmd-280826-011', 'CMD-280826-011', 432000.0));
        $user->notify(new CommissionPayeeNotification(432000.0, 'especes'));
        $user->notify(new DepenseValideeNotification('dep-1', 'ABDOULAYE', 50000.0));

        $rows = collect($this->getJson(route('client.notifications.index'))->assertOk()->json('data'));

        $genere = $rows->firstWhere('type', 'commission.generated');
        $this->assertSame('Commission générée', $genere['titre']);
        $this->assertSame('Réf. CMD-280826-011', $genere['message']);
        $this->assertEquals(432000.0, $genere['montant']);
        $this->assertStringNotContainsString('GNF', $genere['message']);

        $paye = $rows->firstWhere('type', 'commission.paid');
        $this->assertSame('Commission payée', $paye['titre']);
        $this->assertEquals(432000.0, $paye['montant']);
        $this->assertStringNotContainsString('GNF', $paye['message']);
        $this->assertStringNotContainsString((string) 432000, $paye['message']);

        $depense = $rows->firstWhere('type', 'expense.validated');
        $this->assertSame('Dépense validée', $depense['titre']);
        $this->assertSame('Véhicule ABDOULAYE', $depense['message']);
        $this->assertEquals(50000.0, $depense['montant']);
        $this->assertStringNotContainsString('GNF', $depense['message']);
    }

    /**
     * `created_at` sur `notifications` n'a que la précision de la seconde
     * (migration Laravel par défaut, hors périmètre de ce chantier) : on
     * force des instants distincts ici pour tester l'ordre sans dépendre
     * d'un tie-break non garanti par le schéma sur des envois à la même
     * seconde (cf. rapport, limite documentée).
     */
    public function test_tri_du_plus_recent_au_plus_ancien(): void
    {
        $user = $this->actor();

        Carbon::setTestNow(now()->subMinutes(2));
        $user->notify(new TransfertCreeNotification('tr-1', 'TR-001'));
        Carbon::setTestNow(now()->addMinute());
        $user->notify(new TransfertCreeNotification('tr-2', 'TR-002'));
        Carbon::setTestNow(now()->addMinute());
        $user->notify(new TransfertCreeNotification('tr-3', 'TR-003'));
        Carbon::setTestNow();

        $ids = $this->getJson(route('client.notifications.index'))
            ->assertOk()
            ->json('data.*.resource.id');

        $this->assertSame(['tr-3', 'tr-2', 'tr-1'], $ids);
    }

    public function test_pagination_standard_laravel(): void
    {
        $user = $this->actor();
        foreach (range(1, 25) as $i) {
            $user->notify(new TransfertCreeNotification("tr-{$i}", "TR-{$i}"));
        }

        $page1 = $this->getJson(route('client.notifications.index', ['per_page' => 10]))->assertOk();
        $this->assertCount(10, $page1->json('data'));
        $this->assertSame(25, $page1->json('meta.total'));
        $this->assertNotNull($page1->json('links.next'));

        $page3 = $this->getJson(route('client.notifications.index', ['per_page' => 10, 'page' => 3]))->assertOk();
        $this->assertCount(5, $page3->json('data'));
    }

    public function test_unread_count_ignore_les_notifications_deja_lues(): void
    {
        $user = $this->actor();
        $user->notify(new TransfertCreeNotification('tr-1', 'TR-001'));
        $user->notify(new TransfertCreeNotification('tr-2', 'TR-002'));
        $user->notifications()->first()->markAsRead();

        $response = $this->getJson(route('client.notifications.index'))->assertOk();

        $this->assertSame(1, $response->json('unread_count'));
    }

    public function test_marquer_lu_est_idempotent_et_isole_par_utilisateur(): void
    {
        $userA = $this->actor();
        $userA->notify(new TransfertCreeNotification('tr-1', 'TR-001'));
        $notifA = $userA->notifications()->first();

        $this->postJson(route('client.notifications.mark-read', $notifA->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.lu', true);

        $this->assertNotNull($notifA->fresh()->read_at);

        // Idempotent : un second appel ne casse rien.
        $this->postJson(route('client.notifications.mark-read', $notifA->id))
            ->assertOk()
            ->assertJsonPath('success', true);

        // Isolation : User B ne peut jamais marquer la notification de User A.
        $org = Organization::factory()->create();
        $userB = User::factory()->create(['organization_id' => $org->id]);
        Sanctum::actingAs($userB, ['*']);

        $this->postJson(route('client.notifications.mark-read', $notifA->id))
            ->assertNotFound();

        // La tentative refusée de User B n'a rien modifié sur la notification de User A.
        $this->assertNotNull($notifA->fresh()->read_at);
    }

    public function test_userb_ne_voit_jamais_les_notifications_de_usera(): void
    {
        $userA = $this->actor();
        $userA->notify(new TransfertCreeNotification('tr-1', 'TR-001'));

        $org = Organization::factory()->create();
        $userB = User::factory()->create(['organization_id' => $org->id]);
        Sanctum::actingAs($userB, ['*']);

        $response = $this->getJson(route('client.notifications.index'))->assertOk();

        $this->assertCount(0, $response->json('data'));
        $this->assertSame(0, $response->json('unread_count'));
    }

    public function test_mark_all_read_ne_touche_que_lutilisateur_courant(): void
    {
        $userA = $this->actor();
        $userA->notify(new TransfertCreeNotification('tr-1', 'TR-001'));
        $userA->notify(new TransfertCreeNotification('tr-2', 'TR-002'));

        $org = Organization::factory()->create();
        $userB = User::factory()->create(['organization_id' => $org->id]);
        $userB->notify(new TransfertCreeNotification('tr-3', 'TR-003'));

        Sanctum::actingAs($userA, ['*']);
        $this->postJson(route('client.notifications.mark-all-read'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('unread_count', 0);

        $this->assertSame(0, $userA->fresh()->unreadNotifications()->count());
        $this->assertSame(1, $userB->fresh()->unreadNotifications()->count());
    }

    /**
     * Préférence désactivée APRÈS la génération : l'historique déjà créé
     * reste visible (cf. contrat documenté depuis le 26/08/2026) — seules
     * les générations futures sont concernées par la préférence.
     */
    public function test_desactiver_une_preference_ne_masque_jamais_lhistorique_deja_genere(): void
    {
        $user = $this->actor();
        $user->notify(new TransfertCreeNotification('tr-1', 'TR-001'));

        $user->update(['notification_preferences' => ['livraisons' => false]]);

        $response = $this->getJson(route('client.notifications.index'))->assertOk();

        $this->assertCount(1, $response->json('data'));
    }
}
