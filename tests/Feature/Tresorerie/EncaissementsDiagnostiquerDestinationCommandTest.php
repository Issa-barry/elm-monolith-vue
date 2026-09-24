<?php

namespace Tests\Feature\Tresorerie;

use App\Models\CompteComptable;
use App\Models\CompteTresorerie;
use App\Models\EcritureComptable;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use App\Models\Organization;
use App\Models\PieceComptable;
use App\Models\Site;
use App\Models\User;
use App\Services\Tresorerie\EncaissementDestinationDiagnostic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Diagnostic en lecture seule des encaissements déjà enregistrés (`encaissements:diagnostiquer-
 * destination`) : sur quel compte de trésorerie chaque encaissement est-il allé, et lesquels
 * appellent une action (espèces hors caisse dédiée, compte sans support, pas d'écriture).
 */
class EncaissementsDiagnostiquerDestinationCommandTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasOrgAndUser, RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['tresorerie.read']);
        $this->site = $this->user->sites()->firstOrFail();
    }

    // Kulu n'a pas de wallet dédié dans le plan par défaut : l'encaissement Mobile Money reste
    // sur le compte générique 561000 (Orange Money, lui, vise 561100).
    private function encaisser(?User $auteur, string $mode = 'especes', float $montant = 100_000, ?Site $site = null, ?Organization $org = null, string $operateur = 'kulu'): EncaissementVente
    {
        $facture = FactureVente::factory()->create([
            'organization_id' => ($org ?? $this->org)->id,
            'site_id' => ($site ?? $this->site)->id,
            'montant_net' => 500_000,
        ]);

        return EncaissementVente::create([
            'facture_vente_id' => $facture->id,
            'montant' => $montant,
            'date_encaissement' => now()->toDateString(),
            'mode_paiement' => $mode,
            'operateur_mobile_money' => $mode === 'mobile_money' ? $operateur : null,
            'reference_paiement' => in_array($mode, ['mobile_money', 'virement'], true) ? 'REF-TEST' : null,
            'created_by' => $auteur?->id,
        ]);
    }

    /** @param  array<string, mixed>  $options */
    private function lancer(array $options = []): string
    {
        Artisan::call('encaissements:diagnostiquer-destination', ['--organization' => [$this->org->id], ...$options]);

        return Artisan::output();
    }

    private function compte(string $numero): CompteComptable
    {
        return CompteComptable::where('organization_id', $this->org->id)->where('numero', $numero)->firstOrFail();
    }

    public function test_les_especes_d_un_agent_sans_caisse_sont_signalees_avec_l_agent_a_equiper(): void
    {
        $agent = $this->creerAgent($this->site, 'Ousmane', 'Sidibé');
        $this->encaisser($agent, 'especes', 100_000);
        $this->encaisser($agent, 'especes', 50_000);

        $sortie = $this->lancer();

        $this->assertStringContainsString(EncaissementDestinationDiagnostic::LIBELLES[EncaissementDestinationDiagnostic::ESPECES_HORS_CAISSE_DEDIEE], $sortie);
        $this->assertStringContainsString('à traiter', $sortie);
        $this->assertStringContainsString('Ousmane', $sortie);
        $this->assertStringContainsString('150 000', $sortie);
        $this->assertStringContainsString('NON', $sortie, 'l\'agent n\'a pas de caisse dédiée : elle est à créer');
    }

    public function test_un_agent_qui_a_maintenant_une_caisse_est_indique_comme_equipe(): void
    {
        $agent = $this->creerAgent($this->site, 'Ousmane', 'Sidibé');
        $this->encaisser($agent, 'especes', 100_000);
        $this->creerCaisseActive($this->site->id, $agent->id);

        $sortie = $this->lancer();

        $this->assertStringContainsString('Ousmane', $sortie);
        $this->assertStringNotContainsString('NON — à créer', $sortie);
        $this->assertStringContainsString('oui', $sortie);
    }

    public function test_les_especes_d_un_agent_avec_caisse_active_sont_une_destination_claire(): void
    {
        $agent = $this->creerAgent($this->site);
        $this->creerCaisseActive($this->site->id, $agent->id);
        $this->encaisser($agent, 'especes', 100_000);

        $sortie = $this->lancer();

        $this->assertStringContainsString(EncaissementDestinationDiagnostic::LIBELLES[EncaissementDestinationDiagnostic::CAISSE_DEDIEE], $sortie);
        $this->assertStringNotContainsString('⚠ à traiter', $sortie);
    }

    public function test_un_compte_sans_support_est_signale_puis_le_mobile_money_est_marque_generique(): void
    {
        $agent = $this->creerAgent($this->site);
        $this->encaisser($agent, 'mobile_money', 100_000);

        $this->assertStringContainsString(EncaissementDestinationDiagnostic::LIBELLES[EncaissementDestinationDiagnostic::COMPTE_SANS_SUPPORT], $this->lancer());

        CompteTresorerie::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'compte_comptable_id' => $this->compte('561000')->id,
            'type' => 'mobile_money',
            'libelle' => 'Mobile Money agence',
        ]);

        // Un support existe maintenant (l'argent est visible) mais le compte reste générique :
        // l'opérateur (Orange, Kulu...) n'est pas distingué.
        $sortie = $this->lancer();
        $this->assertStringContainsString(EncaissementDestinationDiagnostic::LIBELLES[EncaissementDestinationDiagnostic::COMPTE_GENERIQUE], $sortie);
        $this->assertStringNotContainsString(EncaissementDestinationDiagnostic::LIBELLES[EncaissementDestinationDiagnostic::COMPTE_SANS_SUPPORT], $sortie);
    }

    public function test_un_encaissement_orange_money_sur_son_wallet_dedie_est_conforme(): void
    {
        CompteTresorerie::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'compte_comptable_id' => $this->compte('561100')->id,
            'type' => 'mobile_money',
            'libelle' => 'Orange Money agence',
        ]);
        $this->encaisser($this->creerAgent($this->site), 'mobile_money', 100_000, operateur: 'orange_money');

        $sortie = $this->lancer();

        $this->assertStringContainsString(EncaissementDestinationDiagnostic::LIBELLES[EncaissementDestinationDiagnostic::SUPPORT_IDENTIFIE], $sortie);
        $this->assertStringNotContainsString('⚠ à traiter', $sortie);
    }

    public function test_un_virement_sur_un_support_bancaire_du_site_est_conforme(): void
    {
        CompteTresorerie::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'compte_comptable_id' => $this->compte('521000')->id,
            'type' => 'banque',
            'libelle' => 'Banque agence',
        ]);
        $this->encaisser($this->creerAgent($this->site), 'virement', 100_000);

        $sortie = $this->lancer();

        $this->assertStringContainsString(EncaissementDestinationDiagnostic::LIBELLES[EncaissementDestinationDiagnostic::SUPPORT_IDENTIFIE], $sortie);
        $this->assertStringNotContainsString('⚠ à traiter', $sortie);
    }

    public function test_un_compte_incoherent_avec_le_moyen_de_paiement_est_signale(): void
    {
        $encaissement = $this->encaisser($this->creerAgent($this->site), 'especes', 100_000);
        $piece = PieceComptable::where('source_id', $encaissement->id)->firstOrFail();
        EcritureComptable::where('piece_comptable_id', $piece->id)->where('debit', '>', 0)
            ->update(['compte_comptable_id' => $this->compte('561000')->id]);

        $sortie = $this->lancer();

        $this->assertStringContainsString(EncaissementDestinationDiagnostic::LIBELLES[EncaissementDestinationDiagnostic::COMPTE_INCOHERENT], $sortie);
        $this->assertStringContainsString('Espèces → 561000', $sortie);
    }

    public function test_un_montant_comptabilise_different_du_montant_encaisse_est_signale(): void
    {
        $encaissement = $this->encaisser($this->creerAgent($this->site), 'cheque', 100_000);
        $piece = PieceComptable::where('source_id', $encaissement->id)->firstOrFail();
        EcritureComptable::where('piece_comptable_id', $piece->id)->where('debit', '>', 0)->update(['debit' => 90_000]);

        $this->assertStringContainsString(EncaissementDestinationDiagnostic::LIBELLES[EncaissementDestinationDiagnostic::ECART_MONTANT], $this->lancer());
    }

    public function test_la_synthese_donne_les_totaux_et_les_ventilations(): void
    {
        $ousmane = $this->creerAgent($this->site, 'Ousmane', 'Sidibé');
        $awa = $this->creerAgent($this->site, 'Awa', 'Diallo');
        $this->creerCaisseActive($this->site->id, $awa->id);
        $this->encaisser($ousmane, 'especes', 100_000);
        $this->encaisser($awa, 'especes', 50_000);
        $this->encaisser($ousmane, 'mobile_money', 20_000);

        $sortie = $this->lancer();

        $this->assertStringContainsString('3 encaissement(s) analysé(s) — 170 000 GNF', $sortie);
        $this->assertStringContainsString('Conformes : 1 (50 000 GNF) — à traiter : 2 (120 000 GNF)', $sortie);
        foreach (['Par agent', 'Par agence', 'Par moyen de paiement', 'Ousmane', 'Awa', 'Site Principal', 'Mobile Money / Kulu'] as $attendu) {
            $this->assertStringContainsString($attendu, $sortie);
        }
    }

    public function test_l_option_tout_inclut_les_encaissements_conformes_dans_le_csv(): void
    {
        $awa = $this->creerAgent($this->site, 'Awa', 'Diallo');
        $this->creerCaisseActive($this->site->id, $awa->id);
        $this->encaisser($awa, 'especes', 20_000);
        $this->encaisser($this->creerAgent($this->site, 'Ousmane', 'Sidibé'), 'especes', 75_000);

        $chemin = tempnam(sys_get_temp_dir(), 'diag');
        try {
            $this->lancer(['--csv' => $chemin, '--tout' => true]);
            $contenu = (string) file_get_contents($chemin);
        } finally {
            @unlink($chemin);
        }

        $this->assertStringContainsString('Awa', $contenu);
        $this->assertStringContainsString('Conforme', $contenu);
        $this->assertStringContainsString('Ousmane', $contenu);
        $this->assertStringContainsString('À traiter', $contenu);
    }

    public function test_un_support_d_un_autre_site_ne_rend_pas_visible_l_argent_de_ce_site(): void
    {
        $autreSite = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kindia', 'type' => 'depot', 'localisation' => 'Kindia']);
        CompteTresorerie::create([
            'organization_id' => $this->org->id,
            'site_id' => $autreSite->id,
            'compte_comptable_id' => $this->compte('561000')->id,
            'type' => 'mobile_money',
            'libelle' => 'Mobile Money Kindia',
        ]);
        $this->encaisser($this->creerAgent($this->site), 'mobile_money', 100_000);

        $this->assertStringContainsString(EncaissementDestinationDiagnostic::LIBELLES[EncaissementDestinationDiagnostic::COMPTE_SANS_SUPPORT], $this->lancer());
    }

    public function test_un_encaissement_sans_ecriture_comptable_est_signale(): void
    {
        $encaissement = $this->encaisser($this->creerAgent($this->site), 'cheque', 100_000);
        $piece = PieceComptable::where('source_id', $encaissement->id)->firstOrFail();
        EcritureComptable::where('piece_comptable_id', $piece->id)->delete();
        $piece->delete();

        $this->assertStringContainsString(EncaissementDestinationDiagnostic::LIBELLES[EncaissementDestinationDiagnostic::SANS_PIECE], $this->lancer());
    }

    public function test_la_commande_ne_modifie_aucune_donnee(): void
    {
        $agent = $this->creerAgent($this->site);
        $this->encaisser($agent, 'especes');
        $this->encaisser($agent, 'mobile_money');

        $avant = [
            EncaissementVente::count(), PieceComptable::count(), EcritureComptable::count(),
            CompteTresorerie::count(), (float) EcritureComptable::sum('debit'),
        ];

        $this->lancer(['--detail' => true]);

        $this->assertSame($avant, [
            EncaissementVente::count(), PieceComptable::count(), EcritureComptable::count(),
            CompteTresorerie::count(), (float) EcritureComptable::sum('debit'),
        ]);
    }

    public function test_l_option_detail_liste_chaque_encaissement_a_traiter(): void
    {
        $this->encaisser($this->creerAgent($this->site, 'Ousmane', 'Sidibé'), 'especes', 75_000);

        $this->assertStringNotContainsString('Constat', $this->lancer());
        $sortie = $this->lancer(['--detail' => true]);

        $this->assertStringContainsString('Constat', $sortie);
        $this->assertStringContainsString('571000', $sortie);
        $this->assertStringContainsString('75 000', $sortie);
    }

    public function test_l_export_csv_contient_les_encaissements_a_traiter_uniquement(): void
    {
        $agent = $this->creerAgent($this->site, 'Ousmane', 'Sidibé');
        $this->encaisser($agent, 'especes', 75_000);
        $equipe = $this->creerAgent($this->site, 'Awa', 'Diallo');
        $this->creerCaisseActive($this->site->id, $equipe->id);
        $this->encaisser($equipe, 'especes', 20_000);

        $chemin = tempnam(sys_get_temp_dir(), 'diag');
        try {
            $this->lancer(['--csv' => $chemin]);
            $contenu = (string) file_get_contents($chemin);
        } finally {
            @unlink($chemin);
        }

        $this->assertStringContainsString('Ousmane', $contenu);
        $this->assertStringContainsString('75000', $contenu);
        $this->assertStringNotContainsString('Awa', $contenu, 'une destination claire n\'est pas exportée');
    }

    public function test_les_encaissements_d_une_autre_organisation_ne_sont_pas_melanges(): void
    {
        $autreOrg = Organization::factory()->create();
        $autreSite = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Autre site', 'type' => 'depot', 'localisation' => 'Labé']);
        $autreAgent = User::factory()->create(['organization_id' => $autreOrg->id, 'prenom' => 'Intrus', 'nom' => 'Autre']);
        $autreAgent->sites()->attach($autreSite->id, ['role' => 'employe', 'is_default' => true]);
        $this->encaisser($autreAgent, 'especes', 999_000, $autreSite, $autreOrg);

        $sortie = $this->lancer();

        $this->assertStringNotContainsString('Intrus', $sortie);
        $this->assertStringNotContainsString('999 000', $sortie);
    }

    public function test_une_date_invalide_est_refusee(): void
    {
        $this->assertSame(1, Artisan::call('encaissements:diagnostiquer-destination', ['--depuis' => '23/09/2026']));
    }
}
