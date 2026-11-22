<?php

namespace Tests\Feature\Performance;

use App\Enums\StatutCommission;
use App\Enums\StatutDepense;
use App\Models\CommandeVente;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionProcessus;
use App\Models\Depense;
use App\Models\DepenseType;
use App\Models\Livreur;
use App\Models\Personne;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\Vehicule;
use App\Services\CommissionVenteCalculatorService;
use App\Support\Commission\CommissionKpiBuckets;
use App\Support\Commission\CommissionProcessusFilter;
use App\Support\Commission\SyntheseCommissionsLivreurs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Commissions des livreurs (lot 8) : ce que l'écran lit en lignes légères et en SQL
 * (SyntheseCommissionsLivreurs, CommissionKpiBuckets::sommesParBeneficiaire()) est identique à ce
 * que donnaient les parts chargées avec leurs relations : véhicules, agences, processus, nombre de
 * commandes, première échéance, montants et compartiments, frais par période.
 */
class CommissionsLivreursSyntheseTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private Site $agenceB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['comptabilite.read', 'comptabilite.payer']);
    }

    /**
     * Jeu varié : ventes et transferts, véhicule supprimé (repli sur le site de la source), source
     * supprimée, sans véhicule, montants ajustés et centimes, tous les statuts, quatre dates, dépenses.
     */
    private function jeu(int $ventesParLivreur = 10, string $p = ''): void
    {
        $org = $this->org->id;
        $a = Site::where('organization_id', $org)->firstOrFail();
        $this->agenceB = Site::create(['organization_id' => $org, 'nom' => 'Agence B'.$p, 'type' => 'depot', 'localisation' => 'Kindia']);
        $c = Site::create(['organization_id' => $org, 'nom' => 'Agence C'.$p, 'type' => 'depot', 'localisation' => 'Labé']);
        $veh = fn (string $nom, Site $s) => Vehicule::create(['organization_id' => $org, 'nom_vehicule' => $nom, 'immatriculation' => 'GN-'.$nom.$p, 'site_id' => $s->id, 'categorie' => 'interne', 'capacite_packs' => 500, 'is_active' => true]);
        $vehicules = [$veh('V1', $a), $veh('V2', $this->agenceB), $veh('V3', $c), null];
        $proc = fn (string $code) => CommissionProcessus::firstOrCreate(['organization_id' => $org, 'code' => $code], ['libelle' => $code, 'declencheur' => 'chargement_valide', 'strategie_ancrage_site' => 'operation', 'statut' => 'actif'])->id;
        $vente = $proc(CommissionProcessus::CODE_VENTE);
        $logistique = $proc(CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT);
        $distribution = $proc(CommissionProcessus::CODE_DISTRIBUTION_CLIENT);
        $livreurs = collect(range(1, 6))->map(fn ($i) => Livreur::create(['organization_id' => $org, 'is_active' => true, 'nom_complet' => "Prenom$i NOM$i",
            'personne_id' => Personne::create(['organization_id' => $org, 'nom' => "NOM$i", 'prenom' => "Prenom$i", 'telephone' => "6220{$p}000{$i}"])->id]));
        $type = DepenseType::create(['organization_id' => $org, 'code' => 'AV'.$p, 'libelle' => 'Avance', 'categorie' => 'interne', 'commentaire_obligatoire' => false, 'justificatif_obligatoire' => false, 'is_active' => true]);
        $dates = [now(), now()->subDays(20), now()->subDays(45), now()->subMonths(4)];
        $statuts = ['creee', 'impaye', 'partiel', 'paye', 'annulee'];

        $n = 0;
        foreach ($livreurs as $li => $livreur) {
            foreach (range(0, $ventesParLivreur - 1) as $k) {
                $n++;
                $date = $dates[($li + $k) % 4];
                $statut = $statuts[($li * 3 + $k) % 5];
                $vehicule = $vehicules[($li + $k * 2) % 4];
                if ($k % 4 === 3) {
                    $source = TransfertLogistique::create(['organization_id' => $org, 'site_source_id' => $c->id, 'site_destination_id' => $a->id, 'vehicule_id' => $vehicule?->id, 'created_by' => $this->user->id]);
                    $processus = $logistique;
                } else {
                    $source = CommandeVente::withoutEvents(fn () => CommandeVente::create(['organization_id' => $org, 'reference' => sprintf('CLS%s-%05d', $p, $n), 'site_id' => ($k % 2 ? $this->agenceB : $a)->id, 'vehicule_id' => $vehicule?->id, 'statut' => 'livree', 'total_commande' => 500000]));
                    $processus = $k % 5 === 0 ? $distribution : $vente;
                }
                $enveloppe = CommissionEnveloppe::create(['organization_id' => $org, 'source_type' => $source::class, 'source_id' => $source->id, 'processus_id' => $processus,
                    'cible_type' => 'equipe_livraison', 'cible_id' => (string) Str::ulid(), 'montant_total' => 90000, 'earned_at' => $date->toDateString(), 'statut' => $statut]);
                foreach ([$livreur, $livreurs[($li + 1) % 6]] as $j => $beneficiaire) {
                    $montant = 30000 + 1234.56 * $k + 10 * $j;
                    CommissionEnveloppePart::create(['enveloppe_id' => $enveloppe->id, 'beneficiaire_type' => 'livreur', 'beneficiaire_id' => $beneficiaire->id,
                        'taux_repartition_snapshot' => 50, 'montant_brut' => $montant, 'montant_net' => $montant,
                        'montant_actuel' => $k % 3 === 0 ? $montant - 500.25 : null,
                        'montant_verse' => match ($statut) {
                            'paye' => $k % 3 === 0 ? $montant - 500.25 : $montant, 'partiel' => round($montant / 3, 2), default => 0
                        },
                        'statut' => $statut, 'validated_at' => $statut === 'creee' && $k % 2 ? null : $date]);
                }
                if ($k === 4) {
                    $source->delete();
                }
            }
            Depense::create(['organization_id' => $org, 'site_id' => $a->id, 'user_id' => $this->user->id, 'depense_type_id' => $type->id, 'beneficiaire_type' => 'livreur',
                'beneficiaire_id' => $livreur->id, 'montant' => 7000 + $li * 1000, 'date_depense' => $dates[$li % 4]->toDateString(), 'statut' => StatutDepense::VALIDE->value]);
        }
        $vehicules[2]->delete();
    }

    /** @return Builder<CommissionEnveloppePart> */
    private function requete(array $statuts = []): Builder
    {
        $query = CommissionEnveloppePart::query()
            ->where('beneficiaire_type', CommissionEnveloppePart::TYPE_LIVREUR)
            ->where('statut', '!=', StatutCommission::ANNULEE->value)
            ->whereHas('enveloppe', fn ($q) => $q->where('organization_id', $this->org->id));
        if ($statuts !== []) {
            $query->whereIn('statut', $statuts);
        }

        return $query;
    }

    /**
     * Référence : les parts chargées avec leurs relations, comme l'écran le faisait avant le lot 8.
     *
     * @return Collection<string, Collection<int, CommissionEnveloppePart>>
     */
    private function partsChargees(Builder $query): Collection
    {
        return (clone $query)->with(['enveloppe.source.vehicule', 'enveloppe.processus'])->get()->groupBy('beneficiaire_id');
    }

    /** @return list<array<int, string>> */
    public static function filtresStatut(): array
    {
        return [[[]], [['impaye']], [['creee', 'partiel']], [['paye']]];
    }

    /** @dataProvider filtresStatut */
    public function test_la_synthese_est_identique_aux_parts_chargees(array $statuts): void
    {
        $this->jeu();
        $query = $this->requete($statuts);
        $reference = $this->partsChargees($query);
        $nomsSites = Site::where('organization_id', $this->org->id)->pluck('nom', 'id');

        $synthese = SyntheseCommissionsLivreurs::parLivreur($query);

        $this->assertSame($reference->keys()->map(fn ($id) => (string) $id)->all(), array_keys($synthese), 'ordre des livreurs');
        foreach ($reference as $livreurId => $parts) {
            $s = $synthese[(string) $livreurId];
            $this->assertSame($parts->pluck('enveloppe.source.vehicule')->filter()->unique('id')->pluck('id')->values()->all(), $s['vehicule_ids']);
            $this->assertSame(
                $parts->map(fn (CommissionEnveloppePart $p) => $nomsSites->get((string) $p->enveloppe?->siteResponsableId()))->filter()->unique()->sort()->implode(', '),
                collect($s['site_ids'])->map(fn ($id) => $nomsSites->get($id))->filter()->unique()->sort()->implode(', '),
            );
            $this->assertSame(CommissionProcessusFilter::labelsPresents($parts), CommissionProcessusFilter::labelsPourCodes($s['processus_codes']));
            $this->assertSame($parts->pluck('enveloppe_id')->unique()->count(), $s['nb_commandes']);
            $this->assertSame($parts->contains(fn ($p) => $p->statut !== StatutCommission::CREEE), $s['a_une_part_payable']);
            $premiere = $parts->filter(fn ($p) => in_array($p->statut, [StatutCommission::IMPAYE, StatutCommission::PARTIEL], true))
                ->pluck('enveloppe.earned_at')->filter()->sort()->first();
            $this->assertSame($premiere?->toDateString(), $s['premiere_echeance']?->toDateString());
        }
        // Au moins une agence par repli (véhicule supprimé) et une source supprimée sans agence.
        $this->assertNotEmpty($synthese);
    }

    /** @dataProvider filtresStatut */
    public function test_les_montants_et_compartiments_sont_ceux_des_accesseurs(array $statuts): void
    {
        $this->jeu();
        $query = $this->requete($statuts);
        $reference = $this->partsChargees($query);

        $sommes = CommissionKpiBuckets::sommesParBeneficiaire($query);

        $this->assertEqualsCanonicalizing($reference->keys()->map(fn ($id) => (string) $id)->all(), $sommes->keys()->all());
        foreach ($reference as $livreurId => $parts) {
            $s = $sommes[(string) $livreurId];
            $this->assertEqualsWithDelta((float) $parts->sum('montant_brut'), $s['montant_brut'], 0.001);
            $this->assertEqualsWithDelta((float) $parts->sum(fn ($p) => $p->montant_a_payer), $s['montant_a_payer'], 0.001);
            $this->assertEqualsWithDelta((float) $parts->sum('montant_verse'), $s['montant_verse'], 0.001);
            $attendus = CommissionKpiBuckets::calculer($parts);
            foreach (['total_genere', 'en_attente_periode', 'payable', 'deja_paye'] as $cle) {
                $this->assertSame($attendus[$cle], $s[$cle], $cle);
            }
        }
    }

    public function test_les_frais_par_periode_sont_ceux_des_parts(): void
    {
        $this->jeu();
        $query = $this->requete(['impaye', 'partiel']);

        $attendus = CommissionVenteCalculatorService::fraisDepensesSurPeriodesDesParts($this->org->id, $this->partsChargees($query));
        $obtenus = CommissionVenteCalculatorService::fraisDepensesSurPeriodes(
            $this->org->id,
            collect(SyntheseCommissionsLivreurs::parLivreur($query))->map(fn (array $s) => $s['dates']),
        );

        $this->assertNotEmpty($attendus);
        $this->assertEqualsCanonicalizing($attendus, $obtenus);
    }

    public function test_l_ecran_ne_charge_que_les_parts_creees_et_garde_un_nombre_de_requetes_fixe(): void
    {
        $compter = function (): array {
            $this->actingAs($this->user)->get(route('comptabilite.commissions.vente.index'))->assertOk();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->user)->get(route('comptabilite.commissions.vente.index'))->assertOk();
            $log = DB::getQueryLog();
            DB::disableQueryLog();

            return [count($log), collect($log)->filter(fn ($q) => str_starts_with($q['query'], 'select * from "commission_enveloppe_parts"'))->count()];
        };

        $this->jeu(4);
        [$avant, $chargementsAvant] = $compter();
        $this->jeu(12, '9');
        [$apres, $chargementsApres] = $compter();

        $this->assertSame($avant, $apres);
        // Une seule lecture complète de parts : celles encore « Créée ».
        $this->assertSame(1, $chargementsApres);
        $this->assertSame($chargementsAvant, $chargementsApres);
    }
}
