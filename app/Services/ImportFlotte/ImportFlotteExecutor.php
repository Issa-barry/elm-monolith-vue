<?php

namespace App\Services\ImportFlotte;

use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\ImportFlotte;
use App\Models\Livreur;
use App\Models\Proprietaire;
use App\Models\Vehicule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Exécute la création réelle des entités à partir d'une analyse déjà validée
 * (voir ImportFlotteParser). Tout-ou-rien : si un seul groupe est en erreur,
 * rien n'est enregistré.
 */
class ImportFlotteExecutor
{
    public function __construct(private readonly ImportFlotteParser $parser) {}

    public function executer(ImportFlotte $import): array
    {
        // Ré-analyse à l'instant T (et non réutilisation du rapport d'aperçu) pour
        // éviter tout écart avec un changement survenu entre l'aperçu et la confirmation.
        $absolutePath = Storage::disk('local')->path($import->fichier_path);
        $analyse = $this->parser->analyser($absolutePath, $import->organization_id);

        $groupesErreur = array_filter($analyse['groupes'], fn ($g) => $g['statut'] === 'erreur');

        if (! empty($groupesErreur)) {
            return [
                'succes' => false,
                'rapport' => $analyse,
                'compteurs' => null,
            ];
        }

        $compteurs = [
            'proprietaires_crees' => 0,
            'vehicules_crees' => 0,
            'livreurs_crees' => 0,
            'equipes_creees' => 0,
        ];

        // Un même propriétaire (même téléphone) peut apparaître sur plusieurs
        // groupes (plusieurs véhicules) : mémorisé ici pour ne le créer qu'une
        // fois — voir executerGroupe().
        $proprietairesParTelephone = [];

        DB::transaction(function () use ($analyse, $import, &$compteurs, &$proprietairesParTelephone) {
            foreach ($analyse['groupes'] as $groupe) {
                $this->executerGroupe($groupe, $import->organization_id, $compteurs, $proprietairesParTelephone);
            }
        });

        return [
            'succes' => true,
            'rapport' => $analyse,
            'compteurs' => $compteurs,
        ];
    }

    private function executerGroupe(array $groupe, string $orgId, array &$compteurs, array &$proprietairesParTelephone): void
    {
        $vData = $groupe['vehicule'];

        // ── Propriétaire ─────────────────────────────────────────────────────
        // Un même propriétaire peut posséder plusieurs véhicules du fichier :
        // $proprietairesParTelephone évite de le recréer pour chaque groupe
        // (l'analyse marque chaque ligne comme "nouvelle" indépendamment, tant
        // que rien n'est encore en base).
        $proprietaireId = null;
        if ($groupe['proprietaire']) {
            $p = $groupe['proprietaire'];
            if ($p['existe']) {
                $proprietaireId = $p['id'];
            } elseif (isset($proprietairesParTelephone[$p['telephone']])) {
                $proprietaireId = $proprietairesParTelephone[$p['telephone']];
            } else {
                $proprietaire = Proprietaire::create([
                    'organization_id' => $orgId,
                    'nom' => $p['nom'],
                    'prenom' => $p['prenom'],
                    'telephone' => $p['telephone'],
                    'code_pays' => $p['code_pays'],
                    'pays' => $p['pays'],
                    'code_phone_pays' => $p['code_phone_pays'],
                    'is_active' => true,
                ]);
                $proprietaireId = $proprietaire->id;
                $proprietairesParTelephone[$p['telephone']] = $proprietaireId;
                $compteurs['proprietaires_crees']++;
            }
        }

        // ── Véhicule ─────────────────────────────────────────────────────────
        // Inactif à la création, comme VehiculeController::store() — un véhicule
        // ne devient actif que lorsqu'une équipe à la répartition valide lui est
        // attribuée (jamais le cas ici : l'équipe créée par l'import est toujours
        // un brouillon à 0 %, cf. ImportFlotteParser).
        if ($vData['existe']) {
            $vehiculeId = $vData['id'];
        } else {
            $vehicule = Vehicule::create([
                'organization_id' => $orgId,
                'nom_vehicule' => $vData['nom_vehicule'],
                'immatriculation' => $groupe['immatriculation'],
                'type_vehicule_id' => $vData['type_vehicule_id'],
                'categorie' => $vData['categorie'],
                'site_id' => $vData['site_id'],
                'proprietaire_id' => $vData['categorie'] === 'externe' ? $proprietaireId : null,
                'pris_en_charge_par_usine' => $vData['pris_en_charge_par_usine'],
                'is_active' => false,
            ]);
            $vehiculeId = $vehicule->id;
            $compteurs['vehicules_crees']++;
        }

        // ── Équipe ───────────────────────────────────────────────────────────
        // Créée inactive : commission/montants à 0 (brouillon, cf.
        // ImportFlotteParser), donc pas encore une équipe fonctionnelle. Elle
        // (et le véhicule) ne deviennent actifs que lorsque l'admin finalise la
        // répartition dans Équipes de livraison (EquipeLivraisonController::update()
        // active alors les deux, comme pour toute équipe créée manuellement).
        //
        // $groupe['equipe'] === null signifie "aucune équipe pour ce groupe" :
        // nouveau véhicule sans aucun livreur dans le fichier. On ne crée
        // jamais d'équipe vide — voir ImportFlotteParser.
        $eData = $groupe['equipe'];
        $equipeId = null;

        if ($eData) {
            if ($eData['existe']) {
                $equipeId = $eData['id'];
            } else {
                $commission = (float) $eData['commission_unitaire_par_pack'];
                $montantProp = $vData['categorie'] === 'externe' ? (float) $eData['montant_par_pack_proprietaire'] : 0.0;
                $tauxProp = $vData['categorie'] === 'externe' && $commission > 0
                    ? round($montantProp / $commission * 100, 2)
                    : 0.0;

                $equipe = EquipeLivraison::create([
                    'organization_id' => $orgId,
                    'vehicule_id' => $vehiculeId,
                    'proprietaire_id' => $vData['categorie'] === 'externe' ? $proprietaireId : null,
                    'is_active' => false,
                    'commission_unitaire_par_pack' => $commission,
                    'montant_par_pack_proprietaire' => $vData['categorie'] === 'externe' ? $montantProp : null,
                    'taux_commission_proprietaire' => $tauxProp,
                ]);
                $equipeId = $equipe->id;
                $compteurs['equipes_creees']++;

                // Le véhicule reste (ou redevient) inactif tant que cette équipe
                // brouillon n'est pas finalisée — y compris s'il existait déjà et
                // était actif (il n'avait alors pas encore d'équipe).
                Vehicule::whereKey($vehiculeId)->update(['is_active' => false]);
            }
        }

        // ── Livreurs ─────────────────────────────────────────────────────────
        // Rien à faire si le groupe n'a pas d'équipe (donc pas de livreur, cf.
        // ImportFlotteParser : 'equipe' n'est null que quand 'livreurs' est vide).
        if ($equipeId === null) {
            return;
        }

        $ordreDepart = EquipeLivreur::where('equipe_id', $equipeId)->max('ordre');
        $ordre = $ordreDepart !== null ? $ordreDepart + 1 : 0;

        foreach ($groupe['livreurs'] as $l) {
            if ($l['existe']) {
                $livreurId = $l['id'];
            } else {
                $livreur = Livreur::create([
                    'organization_id' => $orgId,
                    'nom' => $l['nom'],
                    'prenom' => $l['prenom'],
                    'telephone' => $l['telephone'],
                    'is_active' => true,
                ]);
                $livreurId = $livreur->id;
                $compteurs['livreurs_crees']++;
            }

            $commission = (float) $eData['commission_unitaire_par_pack'];
            $taux = $commission > 0 ? round($l['montant_par_pack'] / $commission * 100, 2) : 0.0;

            EquipeLivreur::create([
                'equipe_id' => $equipeId,
                'livreur_id' => $livreurId,
                'role' => $l['role'],
                'montant_par_pack' => $l['montant_par_pack'],
                'taux_commission' => $taux,
                'ordre' => $ordre++,
            ]);
        }
    }
}
