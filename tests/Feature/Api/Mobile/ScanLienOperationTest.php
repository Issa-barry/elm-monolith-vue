<?php

namespace Tests\Feature\Api\Mobile;

use App\Enums\StatutCommandeVente;
use App\Enums\StatutCommission;
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
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\Vehicule;
use App\Services\Commission\CommissionProcessusDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Le lien qui ouvre le scan : un compte cumulant plusieurs liens reçoit leur union, et un livreur n'est
 * lié qu'aux opérations où sa participation est établie. Aucun historique des membres d'équipe n'existe :
 * une part de commission à son nom, une opération en cours de son équipe, ou une opération de son équipe
 * datée de son entrée au plus tôt.
 */
class ScanLienOperationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-05 10:00:00');
        $this->org = Organization::factory()->create();
    }

    private function compte(): User
    {
        return User::factory()->withoutTwoFactor()->create([
            'organization_id' => $this->org->id,
            'telephone' => '+224'.fake()->unique()->numerify('6########'),
        ]);
    }

    private function livreurDe(User $compte): Livreur
    {
        return Livreur::factory()->create(['organization_id' => $this->org->id, 'user_id' => $compte->id]);
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

    private function membre(EquipeLivraison $equipe, Livreur $livreur, string $depuis): void
    {
        $membre = EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => $livreur->id, 'role' => 'chauffeur', 'ordre' => 1]);
        $membre->forceFill(['created_at' => $depuis])->save();
    }

    private function vente(string $reference, array $attributs = []): CommandeVente
    {
        return CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'reference' => $reference,
            'statut' => StatutCommandeVente::LIVRAISON_EN_COURS->value,
            'validated_at' => '2026-11-04 09:00:00',
            ...$attributs,
        ]);
    }

    private function transfert(string $reference, array $attributs = []): TransfertLogistique
    {
        $site = Site::create(['organization_id' => $this->org->id, 'nom' => 'Site '.Str::random(6), 'type' => 'depot', 'localisation' => 'Test']);

        return TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'reference' => $reference,
            'site_source_id' => $site->id,
            'site_destination_id' => $site->id,
            'statut' => StatutTransfert::CLOTURE->value,
            'date_depart_reelle' => '2026-11-04',
            'created_by' => $this->compte()->id,
            ...$attributs,
        ]);
    }

    private function partVente(CommandeVente $vente, Livreur $livreur, StatutCommission $statut = StatutCommission::IMPAYE): void
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

        CommissionEnveloppePart::create([
            'enveloppe_id' => $enveloppe->id,
            'beneficiaire_type' => CommissionEnveloppePart::TYPE_LIVREUR,
            'beneficiaire_id' => $livreur->id,
            'montant_brut' => 6000,
            'montant_net' => 6000,
            'montant_verse' => 0,
            'statut' => $statut,
        ]);
    }

    private function scan(User $compte, string $reference): TestResponse
    {
        Sanctum::actingAs($compte->fresh(), ['*']);

        return $this->getJson(route('client.livraisons.scan', ['reference' => $reference]));
    }

    public function test_un_compte_cumulant_plusieurs_liens_recoit_leur_union(): void
    {
        $compte = $this->compte();
        $client = Client::factory()->create(['organization_id' => $this->org->id, 'user_id' => $compte->id, 'telephone' => '+224621000001']);
        $vehicule = $this->vehicule(Proprietaire::factory()->create(['organization_id' => $this->org->id, 'user_id' => $compte->id]));
        $this->membre($this->equipe($vehicule), $this->livreurDe($compte), '2026-11-01 08:00:00');
        $this->vente('VTE-CUMUL', ['client_id' => $client->id, 'vehicule_id' => $vehicule->id, 'total_commande' => 480000]);

        $this->scan($compte, 'VTE-CUMUL')->assertOk()->assertJson(['total' => 480000.0, 'client_telephone' => '+224621000001']);
    }

    public function test_un_nouveau_membre_ne_recupere_pas_l_ancien_historique_de_l_equipe(): void
    {
        $compte = $this->compte();
        $vehicule = $this->vehicule();
        $equipe = $this->equipe($vehicule);
        $this->membre($equipe, $this->livreurDe($compte), '2026-11-03 08:00:00');

        $terminee = StatutCommandeVente::CLOTUREE->value;
        $this->vente('VTE-AVANT-REPLI', ['vehicule_id' => $vehicule->id, 'statut' => $terminee, 'validated_at' => '2026-10-20 09:00:00']);
        $this->vente('VTE-AVANT-FIGEE', ['vehicule_id' => $vehicule->id, 'equipe_livraison_id' => $equipe->id, 'statut' => $terminee, 'validated_at' => '2026-10-21 09:00:00']);
        $this->vente('VTE-APRES', ['vehicule_id' => $vehicule->id, 'statut' => $terminee, 'validated_at' => '2026-11-03 09:00:00']);
        $this->vente('VTE-EN-COURS', ['vehicule_id' => $vehicule->id, 'statut' => StatutCommandeVente::A_CHARGER->value, 'validated_at' => '2026-10-22 09:00:00']);

        $this->scan($compte, 'VTE-AVANT-REPLI')->assertNotFound();
        $this->scan($compte, 'VTE-AVANT-FIGEE')->assertNotFound();
        $this->scan($compte, 'VTE-APRES')->assertOk();
        $this->scan($compte, 'VTE-EN-COURS')->assertOk();
    }

    public function test_une_part_de_commission_prouve_la_participation_meme_apres_le_depart(): void
    {
        $compte = $this->compte();
        $vente = $this->vente('VTE-ANCIENNE', ['vehicule_id' => $this->vehicule()->id, 'statut' => StatutCommandeVente::CLOTUREE->value, 'validated_at' => '2026-01-10 09:00:00']);
        $this->partVente($vente, $this->livreurDe($compte));

        $this->scan($compte, 'VTE-ANCIENNE')->assertOk()->assertJson(['total' => null, 'client_telephone' => null]);
    }

    public function test_une_part_annulee_ne_prouve_rien(): void
    {
        $compte = $this->compte();
        $vente = $this->vente('VTE-ANNULEE', ['vehicule_id' => $this->vehicule()->id, 'statut' => StatutCommandeVente::CLOTUREE->value]);
        $this->partVente($vente, $this->livreurDe($compte), StatutCommission::ANNULEE);

        $this->scan($compte, 'VTE-ANNULEE')->assertNotFound();
    }

    public function test_une_vente_figee_sur_une_autre_equipe_n_est_pas_celle_de_l_equipe_actuelle(): void
    {
        $compte = $this->compte();
        $vehicule = $this->vehicule();
        $this->membre($this->equipe($vehicule), $this->livreurDe($compte), '2026-11-01 08:00:00');
        $this->vente('VTE-AUTRE-EQUIPE', ['vehicule_id' => $vehicule->id, 'equipe_livraison_id' => $this->equipe($this->vehicule())->id]);

        $this->scan($compte, 'VTE-AUTRE-EQUIPE')->assertNotFound();
    }

    public function test_un_transfert_suit_la_meme_regle_y_compris_l_ancien_moteur_de_commissions(): void
    {
        $compte = $this->compte();
        $livreur = $this->livreurDe($compte);
        $vehicule = $this->vehicule();
        $this->membre($this->equipe($vehicule), $livreur, '2026-11-03 08:00:00');

        $this->transfert('TRF-AVANT', ['vehicule_id' => $vehicule->id, 'date_depart_reelle' => '2026-10-20']);
        $this->transfert('TRF-JOUR', ['vehicule_id' => $vehicule->id, 'date_depart_reelle' => '2026-11-03']);
        $this->transfert('TRF-CHARGEMENT', ['vehicule_id' => $vehicule->id, 'statut' => StatutTransfert::CHARGEMENT->value, 'date_depart_reelle' => null]);

        $ancien = $this->transfert('TRF-PART', ['vehicule_id' => $this->vehicule()->id, 'date_depart_reelle' => '2026-01-10']);
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

        $this->scan($compte, 'TRF-AVANT')->assertNotFound();
        $this->scan($compte, 'TRF-JOUR')->assertOk();
        $this->scan($compte, 'TRF-CHARGEMENT')->assertOk();
        $this->scan($compte, 'TRF-PART')->assertOk();
    }

    public function test_un_client_ne_scanne_pas_son_brouillon(): void
    {
        $compte = $this->compte();
        $client = Client::factory()->create(['organization_id' => $this->org->id, 'user_id' => $compte->id]);
        $this->vente('VTE-BROUILLON', ['client_id' => $client->id, 'statut' => StatutCommandeVente::BROUILLON->value]);

        $this->scan($compte, 'VTE-BROUILLON')->assertNotFound();
    }

    public function test_tous_les_refus_repondent_exactement_comme_une_reference_inconnue(): void
    {
        $this->vente('VTE-051126-001', ['vehicule_id' => $this->vehicule()->id]);
        $this->transfert('TRF-051126-001');
        $sansLien = $this->compte();
        $sansOrganisation = User::factory()->create(['organization_id' => null]);

        $attendu = ['message' => 'Référence introuvable.'];

        foreach (['VTE-051126-001', 'TRF-051126-001', 'VTE-000000-999', 'TRF-000000-999', 'XYZ-000000-001'] as $reference) {
            $this->scan($sansLien, $reference)->assertNotFound()->assertExactJson($attendu);
            $this->scan($sansOrganisation, $reference)->assertNotFound()->assertExactJson($attendu);
        }
    }
}
