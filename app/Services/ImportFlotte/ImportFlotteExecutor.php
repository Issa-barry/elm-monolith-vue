<?php

namespace App\Services\ImportFlotte;

use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\ImportFlotte;
use App\Models\Livreur;
use App\Models\Personne;
use App\Models\Proprietaire;
use App\Models\Vehicule;
use App\Models\VehiculeCapacite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Exécute la création réelle des entités à partir d'une analyse déjà validée
 * (voir ImportFlotteParser). Tout-ou-rien : si un seul groupe est en erreur,
 * rien n'est enregistré.
 *
 * Exception à la règle "une ligne véhicule déjà existant ne sert que d'ancrage, jamais
 * modifiée" (cf. executerGroupe()) : la capacité (upsertCapacite()) est mise à jour même pour un
 * véhicule déjà en base, sur les groupes de capacité effectivement renseignés dans le fichier —
 * une ré-importation avec des valeurs corrigées doit pouvoir corriger la flotte déjà configurée,
 * contrairement aux autres champs (identité, équipe...).
 */
class ImportFlotteExecutor
{
    public function __construct(private readonly ImportFlotteParser $parser) {}

    public function executer(ImportFlotte $import): array
    {
        // Ré-analyse à l'instant T (et non réutilisation du rapport d'aperçu) pour
        // éviter tout écart avec un changement survenu entre l'aperçu et la confirmation.
        $absolutePath = Storage::disk('local')->path($import->fichier_path);
        $analyse = $this->parser->analyser($absolutePath, $import->organization_id, $import->type);

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

    private function upsertCapacite(string $orgId, string $vehiculeId, ?string $categorieId, ?int $capaciteMax): void
    {
        if ($categorieId === null || $capaciteMax === null) {
            return;
        }

        VehiculeCapacite::updateOrCreate(
            ['vehicule_id' => $vehiculeId, 'categorie_id' => $categorieId],
            ['organization_id' => $orgId, 'capacite_max' => $capaciteMax]
        );
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
                $personne = Personne::resoudreOuCreer($orgId, [
                    'nom' => $p['nom'],
                    'prenom' => $p['prenom'],
                    'telephone' => $p['telephone'],
                    'code_pays' => $p['code_pays'],
                    'pays' => $p['pays'],
                    'code_phone_pays' => $p['code_phone_pays'],
                ]);
                $proprietaire = Proprietaire::create([
                    'organization_id' => $orgId,
                    'personne_id' => $personne->id,
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
                'livraison_vente' => $vData['livraison_vente'],
                'livraison_logistique' => $vData['livraison_logistique'],
                'site_id' => $vData['site_id'],
                'categorie' => $vData['categorie'],
                // Propriété indépendante de l'usage : propriétaire tiers si résolu depuis le
                // fichier, sinon propriétaire par défaut (organisation).
                'proprietaire_id' => $proprietaireId ?? Proprietaire::interneParDefautId($orgId),
                'is_active' => false,
            ]);
            $vehiculeId = $vehicule->id;
            $compteurs['vehicules_crees']++;
        }

        // ── Capacités (propres à ce véhicule, aucun héritage depuis le type) ───
        // Contrairement au reste de ce bloc (une ligne "véhicule déjà existant" ne sert que
        // d'ancrage pour ses livreurs/équipe, cf. docblock de classe), les capacités SONT mises
        // à jour même pour un véhicule déjà en base : une ré-importation avec des valeurs
        // corrigées doit pouvoir corriger la flotte déjà configurée. Une entrée par colonne
        // "capacite__<REFERENCE>" non vide sur la ligne (cf. ImportFlotteParser::analyserGroupe())
        // — une valeur invalide OU une référence catégorie introuvable bloque tout le groupe dès
        // l'analyse (jamais atteint ici) : chaque entrée de $vData['capacites'] est donc déjà valide.
        foreach ($vData['capacites'] ?? [] as $capacite) {
            $this->upsertCapacite($orgId, $vehiculeId, $capacite['categorie_id'], $capacite['valeur']);
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
                $aUnProprietaireTiers = $proprietaireId !== null;
                $commission = (float) $eData['commission_unitaire_par_pack'];
                $montantProp = $aUnProprietaireTiers ? (float) $eData['montant_par_pack_proprietaire'] : 0.0;
                $tauxProp = $aUnProprietaireTiers && $commission > 0
                    ? round($montantProp / $commission * 100, 2)
                    : 0.0;

                $equipe = EquipeLivraison::create([
                    'organization_id' => $orgId,
                    'vehicule_id' => $vehiculeId,
                    'proprietaire_id' => $aUnProprietaireTiers ? $proprietaireId : null,
                    'is_active' => false,
                    'commission_unitaire_par_pack' => $commission,
                    'montant_par_pack_proprietaire' => $aUnProprietaireTiers ? $montantProp : null,
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

        // Position par rôle parmi les membres de l'équipe (existants inclus),
        // pour numéroter la désignation par défaut des nouveaux livreurs sans
        // nom (ex: "Chauffeur-2 Baba Ousou") — cf. Livreur::designationParDefaut().
        $roleCounts = $eData['existe']
            ? EquipeLivreur::where('equipe_id', $equipeId)->get()->countBy('role')->all()
            : [];

        foreach ($groupe['livreurs'] as $l) {
            $roleCounts[$l['role']] = ($roleCounts[$l['role']] ?? 0) + 1;

            if ($l['existe']) {
                $livreurId = $l['id'];
            } else {
                // Identité civile jamais demandée dans ce projet — voir
                // ImportFlotteParser / Livreur::$fillable. Jamais de nom_complet
                // vide en base : repli sur la désignation par défaut.
                $nomComplet = $l['nom_complet'] ?? Livreur::designationParDefaut($l['role'], $roleCounts[$l['role']], $vData['nom_vehicule']);

                $personneLivreur = Personne::resoudreOuCreer($orgId, ['telephone' => $l['telephone']]);
                $livreur = Livreur::create([
                    'organization_id' => $orgId,
                    'personne_id' => $personneLivreur->id,
                    'nom_complet' => $nomComplet,
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
