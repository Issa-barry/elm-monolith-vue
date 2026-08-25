<?php

namespace Tests\Feature\Settings;

use App\Enums\CommissionMode;
use App\Enums\CommissionRegleStatut;
use App\Enums\PrestataireType;
use App\Models\Categorie;
use App\Models\CommissionCibleType;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\Personne;
use App\Models\Prestataire;
use App\Models\TypeVehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Paramètres → Commissions (Phase 2) : CRUD des barèmes fixes, versionné —
 * "modifier" un montant crée toujours une nouvelle ligne et clôture
 * l'ancienne, ne l'écrase jamais (décision AMOA "aucune modification rétroactive").
 */
class CommissionRegleControllerTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['parametres.read', 'parametres.update']);
    }

    /** @test */
    public function affiche_les_categories_comme_options_sans_creer_de_ligne_par_defaut(): void
    {
        Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachets', 'statut' => 'actif']);

        $this->actingAs($this->user)
            ->get('/settings/commissions')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/CommissionRegles/Index')
                ->has('lignes', 0)
                ->has('categories', 1)
                ->where('categories.0.label', 'Sachets')
                ->has('cibles', 4) // propriétaire + livraison + site + consultant
            );
    }

    /** @test */
    public function redirige_un_get_accidentel_de_lendpoint_configuration_vers_la_page_commissions(): void
    {
        $this->actingAs($this->user)
            ->get('/settings/commissions/configuration')
            ->assertRedirect('/settings/commissions');
    }

    /** @test */
    public function enregistre_atomiquement_le_consultant_et_les_montants_dune_categorie(): void
    {
        $categorie = Categorie::create([
            'organization_id' => $this->org->id,
            'nom' => 'Bouteilles',
            'statut' => 'actif',
        ]);
        $personne = Personne::create([
            'organization_id' => $this->org->id,
            'nom' => 'Diallo',
            'prenom' => 'Aminata',
        ]);
        $consultant = Prestataire::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
            'type' => PrestataireType::CONSULTANT->value,
            'is_active' => true,
        ]);

        $this->actingAs($this->user)
            ->post('/settings/commissions/configuration', [
                'lignes' => [[
                    'categorie_id' => $categorie->id,
                    'beneficiaires' => [
                        CommissionCibleType::CODE_PROPRIETAIRE,
                        CommissionCibleType::CODE_EQUIPE_LIVRAISON,
                        CommissionCibleType::CODE_SITE,
                        CommissionCibleType::CODE_CONSULTANT,
                    ],
                    'consultant_id' => $consultant->id,
                    'montants_standard' => [
                        CommissionCibleType::CODE_PROPRIETAIRE => 800,
                        CommissionCibleType::CODE_EQUIPE_LIVRAISON => 950,
                        CommissionCibleType::CODE_SITE => 200,
                        CommissionCibleType::CODE_CONSULTANT => 50,
                    ],
                    'exceptions' => [],
                ]],
            ])
            ->assertRedirect(route('settings.commissions.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('commission_regles', [
            'organization_id' => $this->org->id,
            'scope_id' => $categorie->id,
            'cible_type' => CommissionCibleType::CODE_CONSULTANT,
            'consultant_id' => $consultant->id,
            'montant' => 50,
        ]);
        $this->assertSame(4, CommissionRegle::where('organization_id', $this->org->id)
            ->where('scope_type', 'categorie')
            ->where('scope_id', $categorie->id)
            ->where('statut', CommissionRegleStatut::ACTIVE->value)
            ->count());
    }

    /** @test */
    public function refuse_toute_la_configuration_sans_consultant(): void
    {
        $categorie = Categorie::create([
            'organization_id' => $this->org->id,
            'nom' => 'Bouteilles',
            'statut' => 'actif',
        ]);

        $this->actingAs($this->user)
            ->post('/settings/commissions/configuration', [
                'lignes' => [[
                    'categorie_id' => $categorie->id,
                    'beneficiaires' => [CommissionCibleType::CODE_CONSULTANT],
                    'consultant_id' => null,
                    'montants_standard' => [
                        CommissionCibleType::CODE_CONSULTANT => 50,
                    ],
                    'exceptions' => [],
                ]],
            ])
            ->assertRedirect(route('settings.commissions.index'))
            ->assertSessionHasErrors('lignes.0.consultant_id');

        $this->assertDatabaseCount('commission_regles', 0);
    }

    /** @test */
    public function une_configuration_explicitement_enregistree_clot_les_regles_globales(): void
    {
        $categorie = Categorie::create([
            'organization_id' => $this->org->id,
            'nom' => 'Bouteilles',
            'statut' => 'actif',
        ]);
        $personne = Personne::create([
            'organization_id' => $this->org->id,
            'nom' => 'Bah',
            'prenom' => 'Mariam',
        ]);
        $consultant = Prestataire::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
            'type' => PrestataireType::CONSULTANT->value,
            'is_active' => true,
        ]);

        $this->actingAs($this->user)->post('/settings/commissions', [
            'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
            'scope_type' => 'global',
            'montant' => 600,
        ]);

        $this->actingAs($this->user)->post('/settings/commissions/configuration', [
            'lignes' => [[
                'categorie_id' => $categorie->id,
                'beneficiaires' => [
                    CommissionCibleType::CODE_PROPRIETAIRE,
                    CommissionCibleType::CODE_EQUIPE_LIVRAISON,
                    CommissionCibleType::CODE_SITE,
                    CommissionCibleType::CODE_CONSULTANT,
                ],
                'consultant_id' => $consultant->id,
                'montants_standard' => [
                    CommissionCibleType::CODE_PROPRIETAIRE => 800,
                    CommissionCibleType::CODE_EQUIPE_LIVRAISON => 950,
                    CommissionCibleType::CODE_SITE => 200,
                    CommissionCibleType::CODE_CONSULTANT => 50,
                ],
                'exceptions' => [],
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('commission_regles', [
            'organization_id' => $this->org->id,
            'scope_type' => 'global',
            'statut' => CommissionRegleStatut::ACTIVE->value,
        ]);
        $this->assertDatabaseHas('commission_regles', [
            'scope_id' => $categorie->id,
            'cible_type' => CommissionCibleType::CODE_CONSULTANT,
            'consultant_id' => $consultant->id,
            'statut' => CommissionRegleStatut::ACTIVE->value,
        ]);
    }

    /** @test */
    public function un_type_de_vehicule_peut_remplacer_le_bareme_general_par_une_exception(): void
    {
        $categorie = Categorie::create([
            'organization_id' => $this->org->id,
            'nom' => 'Bouteilles',
            'statut' => 'actif',
        ]);
        $tricycle = TypeVehicule::where('organization_id', $this->org->id)
            ->where('nom', 'Tricycle')
            ->firstOrFail();
        $camion = TypeVehicule::where('organization_id', $this->org->id)
            ->where('nom', 'Camion')
            ->firstOrFail();

        $this->actingAs($this->user)
            ->post('/settings/commissions/configuration', [
                'lignes' => [[
                    'categorie_id' => $categorie->id,
                    'beneficiaires' => [
                        CommissionCibleType::CODE_PROPRIETAIRE,
                        CommissionCibleType::CODE_SITE,
                    ],
                    'consultant_id' => null,
                    'montants_standard' => [
                        CommissionCibleType::CODE_PROPRIETAIRE => 500,
                        CommissionCibleType::CODE_SITE => 200,
                    ],
                    'exceptions' => [[
                        'type_vehicule_id' => $tricycle->id,
                        'montants' => [
                            CommissionCibleType::CODE_PROPRIETAIRE => 600,
                            CommissionCibleType::CODE_SITE => 250,
                        ],
                    ]],
                ]],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('commission_regles', [
            'scope_id' => $categorie->id,
            'type_vehicule_id' => null,
            'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
            'montant' => 500,
            'statut' => CommissionRegleStatut::ACTIVE->value,
        ]);
        $this->assertDatabaseHas('commission_regles', [
            'scope_id' => $categorie->id,
            'type_vehicule_id' => $tricycle->id,
            'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
            'montant' => 600,
            'statut' => CommissionRegleStatut::ACTIVE->value,
        ]);
        $this->assertDatabaseHas('commission_regles', [
            'scope_id' => $categorie->id,
            'type_vehicule_id' => $tricycle->id,
            'cible_type' => CommissionCibleType::CODE_SITE,
            'montant' => 250,
            'statut' => CommissionRegleStatut::ACTIVE->value,
        ]);
        $this->assertDatabaseMissing('commission_regles', [
            'scope_id' => $categorie->id,
            'type_vehicule_id' => $camion->id,
            'statut' => CommissionRegleStatut::ACTIVE->value,
        ]);
    }

    /** @test */
    public function ajoute_une_categorie_avec_exception_sans_effacer_la_configuration_existante(): void
    {
        $bouteille = Categorie::create([
            'organization_id' => $this->org->id,
            'nom' => "Bouteille d'eau",
            'statut' => 'actif',
        ]);
        $sachet = Categorie::create([
            'organization_id' => $this->org->id,
            'nom' => "Sachet d'eau",
            'statut' => 'actif',
        ]);
        $personne = Personne::create([
            'organization_id' => $this->org->id,
            'nom' => 'Barry',
            'prenom' => 'Fello',
        ]);
        $consultant = Prestataire::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
            'type' => PrestataireType::CONSULTANT->value,
            'is_active' => true,
        ]);
        $tricycle = TypeVehicule::where('organization_id', $this->org->id)
            ->where('nom', 'Tricycle')
            ->firstOrFail();

        $bouteillePayload = [
            'categorie_id' => $bouteille->id,
            'beneficiaires' => [
                CommissionCibleType::CODE_PROPRIETAIRE,
                CommissionCibleType::CODE_EQUIPE_LIVRAISON,
                CommissionCibleType::CODE_SITE,
                CommissionCibleType::CODE_CONSULTANT,
            ],
            'consultant_id' => $consultant->id,
            'montants_standard' => [
                CommissionCibleType::CODE_PROPRIETAIRE => 800,
                CommissionCibleType::CODE_EQUIPE_LIVRAISON => 950,
                CommissionCibleType::CODE_SITE => 200,
                CommissionCibleType::CODE_CONSULTANT => 50,
            ],
            'exceptions' => [],
        ];

        $this->actingAs($this->user)
            ->post('/settings/commissions/configuration', ['lignes' => [$bouteillePayload]])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->user)
            ->post('/settings/commissions/configuration', [
                'lignes' => [
                    $bouteillePayload,
                    [
                        'categorie_id' => $sachet->id,
                        'beneficiaires' => [
                            CommissionCibleType::CODE_PROPRIETAIRE,
                            CommissionCibleType::CODE_EQUIPE_LIVRAISON,
                            CommissionCibleType::CODE_CONSULTANT,
                        ],
                        'consultant_id' => $consultant->id,
                        'montants_standard' => [
                            CommissionCibleType::CODE_PROPRIETAIRE => 600,
                            CommissionCibleType::CODE_EQUIPE_LIVRAISON => 230,
                            CommissionCibleType::CODE_CONSULTANT => 50,
                        ],
                        'exceptions' => [[
                            'type_vehicule_id' => $tricycle->id,
                            'montants' => [
                                CommissionCibleType::CODE_PROPRIETAIRE => 650,
                                CommissionCibleType::CODE_EQUIPE_LIVRAISON => 250,
                                CommissionCibleType::CODE_CONSULTANT => 50,
                            ],
                        ]],
                    ],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(4, CommissionRegle::where('scope_id', $bouteille->id)
            ->where('statut', CommissionRegleStatut::ACTIVE->value)
            ->count());
        $this->assertSame(6, CommissionRegle::where('scope_id', $sachet->id)
            ->where('statut', CommissionRegleStatut::ACTIVE->value)
            ->count());
        $this->assertDatabaseHas('commission_regles', [
            'scope_id' => $sachet->id,
            'type_vehicule_id' => $tricycle->id,
            'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
            'montant' => 650,
            'statut' => CommissionRegleStatut::ACTIVE->value,
        ]);
    }

    /** @test */
    public function cree_une_regle_globale_pour_le_proprietaire(): void
    {
        $this->actingAs($this->user)
            ->post('/settings/commissions', [
                'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
                'scope_type' => 'global',
                'montant' => 600,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('commission_regles', [
            'organization_id' => $this->org->id,
            'cible_type' => 'proprietaire',
            'scope_type' => 'global',
            'montant' => 600.00,
            'statut' => CommissionRegleStatut::ACTIVE->value,
        ]);

        // Enregistrer un barème active immédiatement le processus vente — plus de
        // notion d'« activation » séparée : configurer un barème suffit.
        $processus = CommissionProcessus::where('organization_id', $this->org->id)->firstOrFail();
        $this->assertTrue($processus->isActif());
    }

    /** @test */
    public function modifier_un_montant_cree_une_nouvelle_version_et_cloture_lancienne(): void
    {
        $this->actingAs($this->user)->post('/settings/commissions', [
            'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
            'scope_type' => 'global',
            'montant' => 600,
        ]);

        $ancienne = CommissionRegle::where('montant', 600)->firstOrFail();

        $this->actingAs($this->user)->post('/settings/commissions', [
            'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
            'scope_type' => 'global',
            'montant' => 650,
            'effective_from' => now()->addDay()->toDateString(),
        ]);

        $ancienne->refresh();
        $this->assertEquals(CommissionRegleStatut::REMPLACEE, $ancienne->statut);
        $this->assertNotNull($ancienne->effective_to);

        $nouvelle = CommissionRegle::where('montant', 650)->firstOrFail();
        $this->assertEquals(CommissionRegleStatut::ACTIVE, $nouvelle->statut);
        $this->assertEquals($ancienne->id, $nouvelle->remplace_regle_id);

        // Toujours une seule règle ACTIVE pour ce (organisation, cible, scope).
        $this->assertEquals(
            1,
            CommissionRegle::where('organization_id', $this->org->id)
                ->where('cible_type', 'proprietaire')
                ->where('scope_type', 'global')
                ->where('statut', CommissionRegleStatut::ACTIVE->value)
                ->count(),
        );
    }

    /** @test */
    public function une_regle_categorie_et_une_regle_globale_coexistent_sans_se_remplacer(): void
    {
        $categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachets', 'statut' => 'actif']);

        $this->actingAs($this->user)->post('/settings/commissions', [
            'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
            'scope_type' => 'global',
            'montant' => 600,
        ]);

        $this->actingAs($this->user)->post('/settings/commissions', [
            'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
            'scope_type' => 'categorie',
            'categorie_id' => $categorie->id,
            'montant' => 800,
        ]);

        $this->assertEquals(
            2,
            CommissionRegle::where('organization_id', $this->org->id)
                ->where('statut', CommissionRegleStatut::ACTIVE->value)
                ->count(),
        );
    }

    /** @test */
    public function cree_une_regle_globale_pour_le_site_en_mode_direct(): void
    {
        $this->actingAs($this->user)
            ->post('/settings/commissions', [
                'cible_type' => CommissionCibleType::CODE_SITE,
                'scope_type' => 'global',
                'montant' => 1000,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('commission_regles', [
            'organization_id' => $this->org->id,
            'cible_type' => CommissionCibleType::CODE_SITE,
            'scope_type' => 'global',
            'montant' => 1000.00,
            // DIRECT au même titre que propriétaire : un seul bénéficiaire déterministe (le
            // site lui-même), jamais de répartition à calculer.
            'mode' => CommissionMode::DIRECT->value,
            'statut' => CommissionRegleStatut::ACTIVE->value,
        ]);
    }

    /** @test */
    public function la_colonne_du_tableau_expose_le_libelle_site(): void
    {
        $response = $this->actingAs($this->user)->get('/settings/commissions');

        $response->assertInertia(fn ($page) => $page
            ->where('cibles.2.code', CommissionCibleType::CODE_SITE)
            ->where('cibles.2.libelle', 'Site')
        );
    }

    /** @test */
    public function refuse_la_creation_sans_permission_parametres_update(): void
    {
        $this->initOrgAndUser(['parametres.read']); // pas .update

        $this->actingAs($this->user)
            ->post('/settings/commissions', [
                'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
                'scope_type' => 'global',
                'montant' => 600,
            ])
            ->assertForbidden();
    }

    /** @test */
    public function categorie_id_obligatoire_si_scope_type_categorie(): void
    {
        $this->actingAs($this->user)
            ->post('/settings/commissions', [
                'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
                'scope_type' => 'categorie',
                'montant' => 600,
            ])
            ->assertSessionHasErrors('categorie_id');
    }

    // ── montant : entier positif ou nul uniquement (GNF sans subdivision) ───
    // 0 est une valeur métier légitime (ex: exclure explicitement le
    // propriétaire d'une catégorie précise plutôt que d'hériter silencieusement
    // du barème global) — jamais ambigu avec "—" (non configuré), puisque
    // c'est l'EXISTENCE de la règle qui distingue les deux états, pas sa valeur.

    /** @test */
    public function accepte_un_montant_a_zero(): void
    {
        $this->actingAs($this->user)
            ->post('/settings/commissions', [
                'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
                'scope_type' => 'global',
                'montant' => 0,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('commission_regles', [
            'organization_id' => $this->org->id,
            'montant' => 0,
        ]);
    }

    /** @test */
    public function refuse_un_montant_negatif(): void
    {
        $this->actingAs($this->user)
            ->post('/settings/commissions', [
                'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
                'scope_type' => 'global',
                'montant' => -50,
            ])
            ->assertSessionHasErrors('montant');
    }

    /** @test */
    public function refuse_un_montant_forme_intervalle(): void
    {
        $this->actingAs($this->user)
            ->post('/settings/commissions', [
                'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
                'scope_type' => 'global',
                'montant' => '0-5',
            ])
            ->assertSessionHasErrors('montant');
    }

    /** @test */
    public function refuse_un_montant_decimal_avec_point(): void
    {
        $this->actingAs($this->user)
            ->post('/settings/commissions', [
                'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
                'scope_type' => 'global',
                'montant' => '600.50',
            ])
            ->assertSessionHasErrors('montant');
    }

    /** @test */
    public function refuse_un_montant_decimal_avec_virgule(): void
    {
        $this->actingAs($this->user)
            ->post('/settings/commissions', [
                'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
                'scope_type' => 'global',
                'montant' => '600,50',
            ])
            ->assertSessionHasErrors('montant');
    }

    /** @test */
    public function refuse_un_montant_texte(): void
    {
        $this->actingAs($this->user)
            ->post('/settings/commissions', [
                'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
                'scope_type' => 'global',
                'montant' => 'abc',
            ])
            ->assertSessionHasErrors('montant');
    }

    /** @test */
    public function refuse_un_montant_vide(): void
    {
        $this->actingAs($this->user)
            ->post('/settings/commissions', [
                'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
                'scope_type' => 'global',
                'montant' => '',
            ])
            ->assertSessionHasErrors('montant');
    }

    /** @test */
    public function accepte_un_montant_avec_espaces_en_bordure_car_trimme_globalement(): void
    {
        // Le middleware TrimStrings (global, tout le formulaire) trime les espaces
        // en début/fin AVANT la validation — comportement standard Laravel, jamais
        // à contourner pour ce seul champ : " 600 " est une saisie légitime.
        $this->actingAs($this->user)
            ->post('/settings/commissions', [
                'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
                'scope_type' => 'global',
                'montant' => ' 600 ',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('commission_regles', [
            'organization_id' => $this->org->id,
            'montant' => 600,
        ]);
    }

    /** @test */
    public function refuse_un_montant_avec_espace_interne(): void
    {
        // Un espace INTERNE ("6 00") n'est jamais retiré par le trim global
        // (trim() ne touche que les bordures) — doit rester rejeté.
        $this->actingAs($this->user)
            ->post('/settings/commissions', [
                'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
                'scope_type' => 'global',
                'montant' => '6 00',
            ])
            ->assertSessionHasErrors('montant');
    }

    /** @test */
    public function accepte_un_entier_positif_valide(): void
    {
        $this->actingAs($this->user)
            ->post('/settings/commissions', [
                'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
                'scope_type' => 'global',
                'montant' => '600',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('commission_regles', [
            'organization_id' => $this->org->id,
            'montant' => 600,
        ]);
    }
}
