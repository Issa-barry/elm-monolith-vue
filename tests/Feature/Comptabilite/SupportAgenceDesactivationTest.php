<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\AuditEvent;
use App\Enums\EvenementComptable;
use App\Enums\StatutMouvementFonds;
use App\Models\AuditLog;
use App\Models\CompteComptable;
use App\Models\CompteTresorerie;
use App\Models\MouvementFonds;
use App\Models\Site;
use App\Services\Comptabilite\EcritureComptableService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Désactivation d'un support d'AGENCE (ADR 0028) : même règle qu'un support dédié — solde nul au
 * grand livre et aucun mouvement de fonds en transit. Un support désactivé ne doit pas « cacher »
 * de l'argent. Désactivation et réactivation sont tracées dans le journal d'audit.
 */
class SupportAgenceDesactivationTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasOrgAndUser, RefreshDatabase;

    private Site $site;

    private CompteComptable $compteCaisse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['tresorerie.read', 'tresorerie.gerer_soldes_ouverture']);
        $this->site = $this->user->sites()->first();
        $this->compteCaisse = CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail();
    }

    private function caisseAgence(string $libelle = 'Caisse de Matoto'): CompteTresorerie
    {
        return $this->creerSupportAgence($this->site->id, 'caisse', '571000', null, $libelle);
    }

    private function basculer(CompteTresorerie $support, bool $actif)
    {
        return $this->actingAs($this->user)
            ->from(route('comptabilite.tresorerie.supports.index'))
            ->put(route('comptabilite.tresorerie.supports.update', $support), [
                'libelle' => $support->libelle,
                'type' => $support->type->value,
                'compte_comptable_id' => $support->compte_comptable_id,
                'moyen_paiement_defaut' => $support->moyen_paiement_defaut,
                'actif' => $actif,
            ]);
    }

    private function mouvement(CompteTresorerie $origine, ?CompteTresorerie $destination, StatutMouvementFonds $statut): MouvementFonds
    {
        $autre = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kouria '.uniqid(), 'type' => 'agence', 'localisation' => 'Coyah']);

        return MouvementFonds::create([
            'organization_id' => $this->org->id,
            'nature' => 'inter_sites',
            'site_origine_id' => $origine->site_id,
            'site_destination_id' => $destination?->site_id ?? $autre->id,
            'compte_tresorerie_origine_id' => $origine->id,
            'compte_tresorerie_destination_id' => $destination?->id,
            'montant' => 100_000,
            'statut' => $statut->value,
        ]);
    }

    public function test_un_support_d_agence_vide_se_desactive_et_la_desactivation_est_tracee(): void
    {
        $caisse = $this->caisseAgence();

        $this->basculer($caisse, false)->assertSessionHasNoErrors();

        $this->assertFalse($caisse->fresh()->actif);
        $trace = AuditLog::where('auditable_id', $caisse->id)->where('event_code', AuditEvent::STATUS_CHANGED->value)->sole();
        $this->assertSame($this->user->id, $trace->actor_id);
        $this->assertSame(['actif' => true], $trace->old_values);
        $this->assertSame(['actif' => false], $trace->new_values);
    }

    public function test_un_support_d_agence_qui_detient_de_l_argent_ne_se_desactive_pas(): void
    {
        $caisse = $this->caisseAgence();
        $this->alimenterCaisse($caisse, 250_000);

        $this->basculer($caisse, false)->assertSessionHasErrors(['actif' => '« Caisse de Matoto » détient encore 250 000 GNF : régularisez ce solde (mouvement vers un autre support) avant de le désactiver.']);

        $this->assertTrue($caisse->fresh()->actif);
        $this->assertSame(0, AuditLog::where('auditable_id', $caisse->id)->count());
    }

    public function test_un_solde_negatif_bloque_aussi_la_desactivation(): void
    {
        $caisse = $this->caisseAgence();
        app(EcritureComptableService::class)->comptabiliser(
            evenement: EvenementComptable::SOLDE_OUVERTURE_TRESORERIE,
            source: $caisse,
            organizationId: $this->org->id,
            dateComptable: Carbon::now(),
            libelle: 'Découvert test',
            lignes: [
                ['compte_comptable_id' => $caisse->compte_comptable_id, 'sens' => 'credit', 'montant' => 50_000],
                ['role' => 'contrepartie_ouverture', 'sens' => 'debit', 'montant' => 50_000],
            ],
            siteId: $caisse->site_id,
            createdBy: $this->user->id,
        );

        $this->basculer($caisse, false)->assertSessionHasErrors(['actif' => '« Caisse de Matoto » présente un solde négatif de 50 000 GNF : régularisez ce solde (mouvement vers un autre support) avant de le désactiver.']);

        $this->assertTrue($caisse->fresh()->actif);
    }

    public function test_une_fois_le_solde_regularise_la_desactivation_passe(): void
    {
        $caisse = $this->caisseAgence();
        $piece = $this->alimenterCaisse($caisse, 250_000);
        $this->basculer($caisse, false)->assertSessionHasErrors('actif');

        $this->viderCaisse($piece);

        $this->basculer($caisse, false)->assertSessionHasNoErrors();
        $this->assertFalse($caisse->fresh()->actif);
    }

    public function test_un_mouvement_envoye_ou_conteste_depuis_ou_vers_le_support_bloque_la_desactivation(): void
    {
        foreach ([StatutMouvementFonds::ENVOYE, StatutMouvementFonds::CONTESTE] as $statut) {
            $source = $this->caisseAgence("Source {$statut->value}");
            $this->mouvement($source, null, $statut);
            $this->basculer($source, false)->assertSessionHasErrors('actif');
            $this->assertTrue($source->fresh()->actif);

            $kouria = Site::create(['organization_id' => $this->org->id, 'nom' => "Origine {$statut->value}", 'type' => 'agence', 'localisation' => 'Coyah']);
            $origine = $this->creerSupportAgence($kouria->id, 'caisse', '571000');
            $destination = $this->caisseAgence("Destination {$statut->value}");
            $this->mouvement($origine, $destination, $statut);
            $this->basculer($destination, false)->assertSessionHasErrors('actif');
            $this->assertTrue($destination->fresh()->actif);
        }
    }

    public function test_un_mouvement_termine_ne_bloque_pas_la_desactivation(): void
    {
        $caisse = $this->caisseAgence();
        foreach ([StatutMouvementFonds::BROUILLON, StatutMouvementFonds::RECU, StatutMouvementFonds::RETOURNE, StatutMouvementFonds::ANNULE] as $statut) {
            $this->mouvement($caisse, null, $statut);
        }

        $this->basculer($caisse, false)->assertSessionHasNoErrors();
        $this->assertFalse($caisse->fresh()->actif);
    }

    public function test_un_compte_partage_avec_un_autre_support_actif_ne_bloque_pas_la_desactivation(): void
    {
        // Le grand livre ne distingue pas deux supports d'une agence sur le même compte : le solde
        // reste affiché sur le support encore actif, rien n'est caché.
        $principale = $this->caisseAgence('Caisse de Matoto');
        $doublon = $this->caisseAgence('Caisse de Matoto (2)');
        $this->alimenterCaisse($principale, 300_000);

        $this->basculer($doublon, false)->assertSessionHasNoErrors();
        $this->assertFalse($doublon->fresh()->actif);

        // Dernier support actif sur ce compte : le solde est de nouveau contrôlé.
        $this->basculer($principale, false)->assertSessionHasErrors('actif');
        $this->assertTrue($principale->fresh()->actif);
    }

    public function test_la_reactivation_n_est_pas_soumise_au_controle_et_est_tracee(): void
    {
        $caisse = $this->caisseAgence();
        $this->basculer($caisse, false)->assertSessionHasNoErrors();

        $this->basculer($caisse, true)->assertSessionHasNoErrors();

        $this->assertTrue($caisse->fresh()->actif);
        $this->assertSame(2, AuditLog::where('auditable_id', $caisse->id)->where('event_code', AuditEvent::STATUS_CHANGED->value)->count());
    }

    public function test_une_modification_sans_changement_d_activation_n_est_ni_controlee_ni_tracee(): void
    {
        $caisse = $this->caisseAgence();
        $this->alimenterCaisse($caisse, 250_000);

        $this->actingAs($this->user)
            ->put(route('comptabilite.tresorerie.supports.update', $caisse), [
                'libelle' => 'Caisse principale de Matoto',
                'type' => 'caisse',
                'compte_comptable_id' => $caisse->compte_comptable_id,
                'actif' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Caisse principale de Matoto', $caisse->fresh()->libelle);
        $this->assertSame(0, AuditLog::where('auditable_id', $caisse->id)->count());
    }

    public function test_la_desactivation_d_une_caisse_dediee_est_aussi_tracee(): void
    {
        $agent = $this->creerAgent($this->site);
        $this->user->givePermissionTo('tresorerie.valider_supports');
        $caisse = $this->creerCaisseActive($this->site->id, $agent->id);

        $this->actingAs($this->user)
            ->put(route('comptabilite.tresorerie.supports.update', $caisse), ['libelle' => $caisse->libelle, 'actif' => false])
            ->assertSessionHasNoErrors();

        $this->assertFalse($caisse->fresh()->actif);
        $this->assertSame(1, AuditLog::where('auditable_id', $caisse->id)->where('event_code', AuditEvent::STATUS_CHANGED->value)->count());
    }
}
