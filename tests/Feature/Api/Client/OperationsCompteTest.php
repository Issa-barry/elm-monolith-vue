<?php

namespace Tests\Feature\Api\Client;

use App\Enums\ModeRemiseGrossiste;
use App\Enums\NatureOperation;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutCommission;
use App\Enums\StatutFactureVente;
use App\Enums\StatutTransfert;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionLogistique;
use App\Models\CommissionLogistiquePart;
use App\Models\CommissionProcessus;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\FactureVente;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Parrain;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\Vehicule;
use App\Services\Client\Operations\PresentationOperation;
use App\Services\Commission\CommissionProcessusDefaults;
use App\Services\Comptes\RattachementCompteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Liste unique « Commandes » (ADR 0058) : une seule ligne par opération, des liens calculés depuis les
 * données du compte, et un contenu limité à ce que chaque lien autorise, jusque dans le JSON.
 */
class OperationsCompteTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-05 10:00:00');
        $this->org = Organization::factory()->create();
    }

    private function compte(?Organization $org = null): User
    {
        return User::factory()->withoutTwoFactor()->create([
            'organization_id' => ($org ?? $this->org)->id,
            'telephone' => '+224'.fake()->unique()->numerify('6########'),
        ]);
    }

    private function clientDe(User $user, array $attributs = []): Client
    {
        return Client::factory()->create(['organization_id' => $this->org->id, 'user_id' => $user->id, ...$attributs]);
    }

    private function proprietaireDe(User $user): Proprietaire
    {
        return Proprietaire::factory()->create(['organization_id' => $this->org->id, 'user_id' => $user->id]);
    }

    private function livreurDe(User $user): Livreur
    {
        return Livreur::factory()->create(['organization_id' => $this->org->id, 'user_id' => $user->id]);
    }

    private function vehicule(?Proprietaire $proprietaire = null): Vehicule
    {
        return Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire?->id ?? Proprietaire::factory()->create(['organization_id' => $this->org->id])->id,
        ]);
    }

    private function equipe(Vehicule $vehicule): EquipeLivraison
    {
        return EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Equipe '.Str::random(6),
            'is_active' => true,
            'taux_commission_proprietaire' => 0,
        ]);
    }

    private function membre(EquipeLivraison $equipe, Livreur $livreur, string $depuis = '2026-11-01 08:00:00'): EquipeLivreur
    {
        $membre = EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => $livreur->id, 'role' => 'chauffeur', 'ordre' => 1]);
        $membre->forceFill(['created_at' => $depuis])->save();

        return $membre;
    }

    private function vente(array $attributs = []): CommandeVente
    {
        return CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'statut' => StatutCommandeVente::LIVRAISON_EN_COURS->value,
            'validated_at' => '2026-11-04 09:00:00',
            ...$attributs,
        ]);
    }

    private function transfert(array $attributs = []): TransfertLogistique
    {
        $site = Site::create(['organization_id' => $this->org->id, 'nom' => 'Site '.Str::random(6), 'type' => 'depot', 'localisation' => 'Test']);

        return TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'reference' => 'TRF-'.Str::random(8),
            'site_source_id' => $site->id,
            'site_destination_id' => $site->id,
            'statut' => StatutTransfert::CLOTURE->value,
            'date_depart_reelle' => '2026-11-04',
            'created_by' => $this->compte()->id,
            ...$attributs,
        ]);
    }

    private function partVente(CommandeVente $vente, Livreur $livreur, StatutCommission $statut = StatutCommission::IMPAYE): CommissionEnveloppePart
    {
        $enveloppe = CommissionEnveloppe::create([
            'organization_id' => $this->org->id,
            'source_type' => CommandeVente::class,
            'source_id' => $vente->id,
            'processus_id' => CommissionProcessusDefaults::resoudreOuCreer($this->org->id, CommissionProcessus::CODE_VENTE)->id,
            'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            'cible_id' => (string) Str::ulid(),
            'montant_total' => 6000,
            'montant_verse' => 0,
            'earned_at' => '2026-10-20',
            'statut' => StatutCommission::IMPAYE,
        ]);

        return CommissionEnveloppePart::create([
            'enveloppe_id' => $enveloppe->id,
            'beneficiaire_type' => CommissionEnveloppePart::TYPE_LIVREUR,
            'beneficiaire_id' => $livreur->id,
            'montant_brut' => 6000,
            'montant_net' => 6000,
            'montant_verse' => 0,
            'statut' => $statut,
        ]);
    }

    private function liste(User $user, array $parametres = []): TestResponse
    {
        Sanctum::actingAs($user, ['*']);

        return $this->getJson(route('client.activite.mine', $parametres))->assertOk();
    }

    /** @return list<string> */
    private function references(User $user, array $parametres = []): array
    {
        return array_column($this->liste($user, $parametres)->json('data'), 'reference');
    }

    private function detail(User $user, string $type, string $id): TestResponse
    {
        Sanctum::actingAs($user, ['*']);

        return $this->getJson(route('client.activite.show', ['type' => $type, 'id' => $id]));
    }

    /** @return array{client: User, livreur: User, proprietaire: User, vente: CommandeVente} */
    private function venteATroisComptes(string $statut = 'livraison_en_cours'): array
    {
        $compteClient = $this->compte();
        $client = $this->clientDe($compteClient, ['telephone' => '+224621000001', 'adresse' => 'Cité Enco-5, Ratoma', 'ville' => 'Conakry']);
        $compteProprietaire = $this->compte();
        $vehicule = $this->vehicule($this->proprietaireDe($compteProprietaire));
        $compteLivreur = $this->compte();
        $this->membre($this->equipe($vehicule), $this->livreurDe($compteLivreur));

        $vente = $this->vente(['client_id' => $client->id, 'vehicule_id' => $vehicule->id, 'statut' => $statut, 'reference' => 'VTE-TROIS', 'total_commande' => 480000]);
        FactureVente::factory()->create(['organization_id' => $this->org->id, 'commande_vente_id' => $vente->id, 'statut_facture' => StatutFactureVente::PARTIEL]);

        return ['client' => $compteClient, 'livreur' => $compteLivreur, 'proprietaire' => $compteProprietaire, 'vente' => $vente];
    }

    // ── La même vente, vue par chaque compte ──────────────────────────────────

    public function test_chaque_compte_voit_la_meme_vente_une_seule_fois_avec_son_propre_lien(): void
    {
        $cas = $this->venteATroisComptes();

        foreach (['client', 'livreur', 'proprietaire'] as $lien) {
            $reponse = $this->liste($cas[$lien]);

            $reponse->assertJsonCount(1, 'data');
            $this->assertSame(1, $reponse->json('meta.total'));
            $this->assertSame('VTE-TROIS', $reponse->json('data.0.reference'));
            $this->assertSame([$lien], $reponse->json('data.0.liens'));
            $this->assertSame('en_cours_de_livraison', $reponse->json('data.0.avancement.code'));
            $this->assertSame('vente_standard', $reponse->json('data.0.nature.code'));
        }
    }

    public function test_un_compte_a_la_fois_client_livreur_et_proprietaire_voit_une_seule_ligne_a_trois_liens(): void
    {
        $compte = $this->compte();
        $client = $this->clientDe($compte);
        $vehicule = $this->vehicule($this->proprietaireDe($compte));
        $this->membre($this->equipe($vehicule), $this->livreurDe($compte));
        $vente = $this->vente(['client_id' => $client->id, 'vehicule_id' => $vehicule->id, 'reference' => 'VTE-CUMUL']);
        FactureVente::factory()->create(['organization_id' => $this->org->id, 'commande_vente_id' => $vente->id, 'statut_facture' => StatutFactureVente::PAYEE]);

        $reponse = $this->liste($compte);

        $reponse->assertJsonCount(1, 'data');
        $this->assertSame(1, $reponse->json('meta.total'));
        $this->assertSame(['client', 'livreur', 'proprietaire'], $reponse->json('data.0.liens'));
        $this->assertSame('payee', $reponse->json('data.0.statut_paiement'));
        $this->detail($compte, 'vente', $vente->id)
            ->assertOk()
            ->assertJsonPath('data.total', (int) $vente->total_commande)
            ->assertJsonPath('data.contact.telephone', $client->telephone);
    }

    public function test_le_filtre_lien_restreint_au_titre_choisi_sans_retirer_les_autres_liens(): void
    {
        $compte = $this->compte();
        $client = $this->clientDe($compte);
        $vehicule = $this->vehicule($this->proprietaireDe($compte));
        $this->vente(['client_id' => $client->id, 'vehicule_id' => $vehicule->id, 'reference' => 'VTE-LES-DEUX']);
        $this->vente(['client_id' => $client->id, 'reference' => 'VTE-CLIENT']);
        $this->vente(['vehicule_id' => $vehicule->id, 'reference' => 'VTE-VEHICULE']);
        $this->transfert(['vehicule_id' => $vehicule->id, 'reference' => 'TRF-VEHICULE']);

        $client = $this->liste($compte, ['lien' => 'client']);
        $this->assertEqualsCanonicalizing(['VTE-LES-DEUX', 'VTE-CLIENT'], array_column($client->json('data'), 'reference'));
        $this->assertSame(2, $client->json('meta.total'));
        $this->assertSame('client', $client->json('filters.lien'));
        $this->assertSame(['client', 'proprietaire'], collect($client->json('data'))->firstWhere('reference', 'VTE-LES-DEUX')['liens']);

        $this->assertEqualsCanonicalizing(['VTE-LES-DEUX', 'VTE-VEHICULE', 'TRF-VEHICULE'], $this->references($compte, ['lien' => 'proprietaire']));
        $this->assertSame([], $this->references($compte, ['lien' => 'livreur']));

        Sanctum::actingAs($compte, ['*']);
        $this->getJson(route('client.activite.mine', ['lien' => 'parrain']))->assertUnprocessable()->assertJsonValidationErrors('lien');
    }

    public function test_le_livreur_seul_ne_recoit_ni_paiement_ni_prix_ni_statut_brut(): void
    {
        $cas = $this->venteATroisComptes(StatutCommandeVente::CLOTUREE->value);

        $ligne = $this->liste($cas['livreur'])->json('data.0');

        $this->assertNull($ligne['statut_paiement']);
        $this->assertNull($ligne['statut_paiement_label']);
        $this->assertSame('terminee', $ligne['statut']);
        $this->assertSame('Terminée', $ligne['statut_label']);

        $detail = $this->detail($cas['livreur'], 'vente', $cas['vente']->id)->assertOk();
        $detail->assertJsonPath('data.total', null)->assertJsonPath('data.statut_paiement', null);
        $this->assertStringNotContainsString('480000', $detail->getContent());
        $this->assertStringNotContainsString('cloturee', $detail->getContent());
    }

    public function test_le_livreur_voit_le_contact_pendant_la_livraison_seulement(): void
    {
        $cas = $this->venteATroisComptes();

        $this->detail($cas['livreur'], 'vente', $cas['vente']->id)
            ->assertOk()
            ->assertJsonPath('data.contact.telephone', '+224621000001')
            ->assertJsonPath('data.contact.adresse', 'Cité Enco-5, Ratoma, Conakry');

        $cas['vente']->update(['statut' => StatutCommandeVente::CLOTUREE]);

        $this->detail($cas['livreur'], 'vente', $cas['vente']->id)->assertOk()->assertJsonPath('data.contact', null);
    }

    public function test_le_proprietaire_voit_le_client_et_le_paiement_sans_prix_ni_contact(): void
    {
        $cas = $this->venteATroisComptes();

        $this->detail($cas['proprietaire'], 'vente', $cas['vente']->id)
            ->assertOk()
            ->assertJsonPath('data.liens', ['proprietaire'])
            ->assertJsonPath('data.statut_paiement', 'partiel')
            ->assertJsonPath('data.total', null)
            ->assertJsonPath('data.contact', null)
            ->assertJsonPath('data.site_destination', $cas['vente']->client->nom_complet);
    }

    public function test_le_client_voit_son_total_et_son_paiement(): void
    {
        $cas = $this->venteATroisComptes();

        $this->detail($cas['client'], 'vente', $cas['vente']->id)
            ->assertOk()
            ->assertJsonPath('data.liens', ['client'])
            ->assertJsonPath('data.total', 480000)
            ->assertJsonPath('data.statut_paiement', 'partiel');
    }

    // ── Historique du livreur ─────────────────────────────────────────────────

    public function test_un_nouveau_membre_ne_recupere_pas_l_ancien_historique_de_l_equipe(): void
    {
        $compte = $this->compte();
        $vehicule = $this->vehicule();
        $equipe = $this->equipe($vehicule);
        $this->membre($equipe, $this->livreurDe($compte), '2026-11-03 08:00:00');

        $this->vente(['vehicule_id' => $vehicule->id, 'reference' => 'AVANT-REPLI', 'statut' => StatutCommandeVente::CLOTUREE->value, 'validated_at' => '2026-10-20 09:00:00']);
        $this->vente(['vehicule_id' => $vehicule->id, 'equipe_livraison_id' => $equipe->id, 'reference' => 'AVANT-FIGEE', 'statut' => StatutCommandeVente::CLOTUREE->value, 'validated_at' => '2026-10-21 09:00:00']);
        $this->vente(['vehicule_id' => $vehicule->id, 'reference' => 'APRES', 'statut' => StatutCommandeVente::CLOTUREE->value, 'validated_at' => '2026-11-03 09:00:00']);
        $this->vente(['vehicule_id' => $vehicule->id, 'reference' => 'EN-COURS', 'statut' => StatutCommandeVente::A_CHARGER->value, 'validated_at' => '2026-10-22 09:00:00']);

        $this->assertEqualsCanonicalizing(['APRES', 'EN-COURS'], $this->references($compte));
    }

    public function test_une_part_de_commission_prouve_la_participation_meme_apres_le_depart(): void
    {
        $compte = $this->compte();
        $livreur = $this->livreurDe($compte);
        $vente = $this->vente(['vehicule_id' => $this->vehicule()->id, 'reference' => 'ANCIENNE', 'statut' => StatutCommandeVente::CLOTUREE->value, 'validated_at' => '2026-01-10 09:00:00']);
        $this->partVente($vente, $livreur);

        $this->assertSame(['ANCIENNE'], $this->references($compte));
        $this->assertSame(['livreur'], $this->liste($compte)->json('data.0.liens'));
    }

    public function test_une_part_annulee_ne_prouve_rien(): void
    {
        $compte = $this->compte();
        $vente = $this->vente(['vehicule_id' => $this->vehicule()->id, 'statut' => StatutCommandeVente::CLOTUREE->value]);
        $this->partVente($vente, $this->livreurDe($compte), StatutCommission::ANNULEE);

        $this->assertSame([], $this->references($compte));
    }

    public function test_la_vente_figee_sur_une_autre_equipe_n_est_pas_celle_de_l_equipe_actuelle_du_vehicule(): void
    {
        $compte = $this->compte();
        $vehicule = $this->vehicule();
        $this->membre($this->equipe($vehicule), $this->livreurDe($compte));
        $autreEquipe = $this->equipe($this->vehicule());

        $this->vente(['vehicule_id' => $vehicule->id, 'equipe_livraison_id' => $autreEquipe->id, 'reference' => 'AUTRE-EQUIPE']);

        $this->assertSame([], $this->references($compte));
    }

    public function test_un_transfert_suit_la_meme_regle_y_compris_l_ancien_moteur_de_commissions(): void
    {
        $compte = $this->compte();
        $livreur = $this->livreurDe($compte);
        $vehicule = $this->vehicule();
        $this->membre($this->equipe($vehicule), $livreur, '2026-11-03 08:00:00');

        $this->transfert(['vehicule_id' => $vehicule->id, 'reference' => 'TRF-AVANT', 'date_depart_reelle' => '2026-10-20']);
        $this->transfert(['vehicule_id' => $vehicule->id, 'reference' => 'TRF-JOUR', 'date_depart_reelle' => '2026-11-03']);
        $this->transfert(['vehicule_id' => $vehicule->id, 'reference' => 'TRF-CHARGEMENT', 'statut' => StatutTransfert::CHARGEMENT->value, 'date_depart_reelle' => null]);

        $ancien = $this->transfert(['vehicule_id' => $this->vehicule()->id, 'reference' => 'TRF-PART', 'date_depart_reelle' => '2026-01-10']);
        $commission = CommissionLogistique::create([
            'organization_id' => $this->org->id,
            'transfert_logistique_id' => $ancien->id,
            'base_calcul' => 'forfait',
            'valeur_base' => 1000,
            'montant_total' => 1000,
        ]);
        CommissionLogistiquePart::create([
            'commission_logistique_id' => $commission->id,
            'type_beneficiaire' => 'livreur',
            'livreur_id' => $livreur->id,
            'beneficiaire_nom' => 'Livreur',
            'taux_commission' => 100,
            'montant_brut' => 1000,
            'montant_net' => 1000,
        ]);

        $this->assertEqualsCanonicalizing(['TRF-JOUR', 'TRF-CHARGEMENT', 'TRF-PART'], $this->references($compte));
    }

    // ── Client, comptes sans lien, isolation ──────────────────────────────────

    public function test_un_client_pur_voit_ses_commandes_sans_ses_brouillons_ni_celles_des_autres(): void
    {
        $compte = $this->compte();
        $client = $this->clientDe($compte);
        $autre = Client::factory()->create(['organization_id' => $this->org->id]);

        $this->vente(['client_id' => $client->id, 'reference' => 'A-MOI']);
        $this->vente(['client_id' => $client->id, 'reference' => 'BROUILLON', 'statut' => StatutCommandeVente::BROUILLON->value]);
        $this->vente(['client_id' => $autre->id, 'reference' => 'AUTRE']);

        $this->assertSame(['A-MOI'], $this->references($compte));
    }

    public function test_compte_a_des_operations_ignore_les_filtres(): void
    {
        $compte = $this->compte();
        $this->vente(['client_id' => $this->clientDe($compte)->id, 'validated_at' => '2026-01-10 09:00:00']);

        $reponse = $this->liste($compte, ['periode' => 'aujourd_hui']);

        $reponse->assertJsonCount(0, 'data');
        $this->assertTrue($reponse->json('compte_a_des_operations'));
    }

    public function test_compte_sans_operation_le_dit(): void
    {
        $compte = $this->compte();
        $this->clientDe($compte);

        $this->assertFalse($this->liste($compte)->json('compte_a_des_operations'));
    }

    public function test_super_admin_et_parrain_seul_n_ont_aucune_visibilite_globale(): void
    {
        $tiers = $this->venteATroisComptes();

        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $superAdmin = $this->compte();
        $superAdmin->assignRole('super_admin');

        $acteur = $this->compte();
        $compteParrain = $this->compte();
        $parrain = Parrain::factory()->create(['organization_id' => $this->org->id]);
        app(RattachementCompteService::class)->rattacher($parrain, $compteParrain, $acteur);
        $tiers['vente']->vehicule->update(['parrain_id' => $parrain->id]);

        foreach ([$superAdmin, $compteParrain->fresh()] as $compte) {
            $this->assertSame([], $this->references($compte));
            $this->assertFalse($this->liste($compte)->json('compte_a_des_operations'));
            $this->detail($compte, 'vente', $tiers['vente']->id)->assertNotFound();
        }
    }

    public function test_le_detail_refuse_ce_qui_est_hors_du_perimetre(): void
    {
        $cas = $this->venteATroisComptes();
        $autreCompte = $this->compte();
        $this->clientDe($autreCompte);

        $this->detail($autreCompte, 'vente', $cas['vente']->id)->assertNotFound()->assertJsonPath('message', 'Opération introuvable.');
        $this->detail($autreCompte, 'vente', (string) Str::ulid())->assertNotFound();
        $this->detail($cas['client'], 'logistique', $cas['vente']->id)->assertNotFound();
        $this->detail($cas['client'], 'autre', $cas['vente']->id)->assertNotFound();
    }

    public function test_une_autre_organisation_ne_voit_jamais_l_operation(): void
    {
        $cas = $this->venteATroisComptes();
        $orgB = Organization::factory()->create();
        $compteB = $this->compte($orgB);
        Proprietaire::factory()->create(['organization_id' => $orgB->id, 'user_id' => $compteB->id]);

        $this->assertSame([], $this->references($compteB));
        $this->detail($compteB, 'vente', $cas['vente']->id)->assertNotFound();
    }

    public function test_le_filtre_vehicule_n_elargit_jamais_le_perimetre(): void
    {
        $compte = $this->compte();
        $client = $this->clientDe($compte);
        $vehicule = $this->vehicule();

        $this->vente(['client_id' => $client->id, 'vehicule_id' => $vehicule->id, 'reference' => 'MA-COMMANDE']);
        $this->vente(['vehicule_id' => $vehicule->id, 'reference' => 'AUTRE-VENTE']);

        $this->assertSame(['MA-COMMANDE'], $this->references($compte, ['vehicule_id' => $vehicule->id]));
    }

    public function test_le_detail_d_un_transfert_ne_porte_aucun_montant(): void
    {
        $compte = $this->compte();
        $transfert = $this->transfert(['vehicule_id' => $this->vehicule($this->proprietaireDe($compte))->id]);

        $this->detail($compte, 'logistique', $transfert->id)
            ->assertOk()
            ->assertJsonPath('data.liens', ['proprietaire'])
            ->assertJsonPath('data.nature.label', 'Transfert logistique')
            ->assertJsonPath('data.avancement.label', 'Terminé')
            ->assertJsonPath('data.total', null)
            ->assertJsonPath('data.statut_paiement', null);
    }

    // ── Avancement, remise physique, paiement ─────────────────────────────────

    /** @return array<string, array{StatutCommandeVente, string, string}> */
    public static function avancementsVenteStandard(): array
    {
        return [
            'à charger' => [StatutCommandeVente::A_CHARGER, 'en_preparation', 'En préparation'],
            'chargement' => [StatutCommandeVente::CHARGEMENT_EN_COURS, 'en_preparation', 'En préparation'],
            'en livraison' => [StatutCommandeVente::LIVRAISON_EN_COURS, 'en_cours_de_livraison', 'En cours de livraison'],
            'premier encaissement' => [StatutCommandeVente::LIVREE, 'en_cours_de_cloture', 'En cours de clôture'],
            'à encaisser' => [StatutCommandeVente::FACTURATION, 'en_cours_de_cloture', 'En cours de clôture'],
            'clôturée' => [StatutCommandeVente::CLOTUREE, 'terminee', 'Terminée'],
            'annulée' => [StatutCommandeVente::ANNULEE, 'annulee', 'Annulée'],
            'erreur de saisie' => [StatutCommandeVente::ANNULEE_ERREUR_SAISIE, 'annulee', 'Annulée'],
            'retournée' => [StatutCommandeVente::RETOURNEE, 'retournee', 'Retournée'],
            'créée' => [StatutCommandeVente::RESERVEE, 'creee', 'Créée'],
            'préparation' => [StatutCommandeVente::A_PREPARER, 'preparation_en_cours', 'Préparation en cours'],
            'prête' => [StatutCommandeVente::PREPAREE, 'prete_au_retrait', 'Prête au retrait'],
        ];
    }

    #[DataProvider('avancementsVenteStandard')]
    public function test_avancement_d_une_vente_standard(StatutCommandeVente $statut, string $code, string $label): void
    {
        $vente = new CommandeVente(['statut' => $statut, 'nature_operation' => NatureOperation::VENTE_STANDARD]);

        $this->assertSame(['code' => $code, 'label' => $label], PresentationOperation::avancementVente($vente));
    }

    public function test_le_premier_encaissement_d_une_vente_standard_n_est_jamais_une_remise_physique(): void
    {
        $compte = $this->compte();
        $vente = $this->vente([
            'client_id' => $this->clientDe($compte)->id,
            'statut' => StatutCommandeVente::LIVREE->value,
            'nature_operation' => NatureOperation::VENTE_STANDARD->value,
            'livree_at' => '2026-11-04 12:00:00',
        ]);

        $this->detail($compte, 'vente', $vente->id)
            ->assertOk()
            ->assertJsonPath('data.avancement.label', 'En cours de clôture')
            ->assertJsonPath('data.remise', null);
    }

    #[DataProvider('commandesAReceptionExplicite')]
    public function test_une_reception_validee_affiche_livree_avec_sa_date(array $attributs): void
    {
        $compte = $this->compte();
        $vente = $this->vente([
            'client_id' => $this->clientDe($compte)->id,
            'statut' => StatutCommandeVente::LIVREE->value,
            'livree_at' => '2026-11-04 12:00:00',
            ...$attributs,
        ]);

        $this->detail($compte, 'vente', $vente->id)
            ->assertOk()
            ->assertJsonPath('data.avancement.label', 'Livrée')
            ->assertJsonPath('data.remise', ['date' => '2026-11-04', 'source' => 'reception']);
    }

    /** @return array<string, array{array<string, string>}> */
    public static function commandesAReceptionExplicite(): array
    {
        return [
            'distribution client' => [['nature_operation' => NatureOperation::DISTRIBUTION_CLIENT->value]],
            'grossiste en livraison' => [['mode_remise_grossiste' => ModeRemiseGrossiste::LIVRAISON->value]],
        ];
    }

    public function test_une_precommande_retiree_garde_son_cycle_et_connait_sa_remise(): void
    {
        $compte = $this->compte();
        $vente = $this->vente([
            'client_id' => $this->clientDe($compte)->id,
            'statut' => StatutCommandeVente::FACTURATION->value,
            'est_precommande' => true,
            'remise_at' => '2026-11-04 12:00:00',
        ]);

        $this->detail($compte, 'vente', $vente->id)
            ->assertOk()
            ->assertJsonPath('data.nature.code', 'precommande')
            ->assertJsonPath('data.avancement.label', 'Retirée')
            ->assertJsonPath('data.remise', ['date' => '2026-11-04', 'source' => 'retrait']);
    }

    public function test_le_chargement_d_une_precommande_livree_n_est_pas_une_remise(): void
    {
        $compte = $this->compte();
        $vente = $this->vente([
            'client_id' => $this->clientDe($compte)->id,
            'vehicule_id' => $this->vehicule()->id,
            'est_precommande' => true,
            'remise_at' => '2026-11-04 12:00:00',
        ]);

        $this->detail($compte, 'vente', $vente->id)->assertOk()->assertJsonPath('data.remise', null);
    }

    public function test_un_acompte_de_precommande_s_affiche_malgre_la_facture_creee(): void
    {
        $compte = $this->compte();
        $vente = $this->vente([
            'client_id' => $this->clientDe($compte)->id,
            'statut' => StatutCommandeVente::RESERVEE->value,
            'est_precommande' => true,
        ]);
        $facture = FactureVente::factory()->create(['organization_id' => $this->org->id, 'commande_vente_id' => $vente->id, 'statut_facture' => StatutFactureVente::CREEE]);

        $this->assertSame('creee', $this->liste($compte)->json('data.0.statut_paiement'));

        $facture->encaissements()->create([
            'montant' => 1000,
            'date_encaissement' => '2026-11-04',
            'mode_paiement' => 'especes',
            'est_acompte' => true,
        ]);

        $ligne = $this->liste($compte)->json('data.0');
        $this->assertSame('acompte_recu', $ligne['statut_paiement']);
        $this->assertSame('Acompte reçu', $ligne['statut_paiement_label']);
    }
}
