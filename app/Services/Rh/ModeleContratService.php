<?php

namespace App\Services\Rh;

use App\Enums\StatutModeleContrat;
use App\Enums\TypeContrat;
use App\Models\ModeleContrat;
use App\Models\User;
use App\Support\Rh\Contrats\TrameModeleContrat;
use Illuminate\Support\Facades\DB;

/**
 * Modèles de contrat, un par type et par organisation (ADR 0044). Tant qu'aucun modèle n'est
 * enregistré, l'écran présente la trame de l'application (non enregistrée, en brouillon).
 */
class ModeleContratService
{
    /** Modèle enregistré, sinon la trame de départ (instance non enregistrée, en brouillon). */
    public function pourType(string $organisationId, TypeContrat $type): ModeleContrat
    {
        return ModeleContrat::where('organization_id', $organisationId)->where('type_contrat', $type->value)->first()
            ?? $this->trame($organisationId, $type);
    }

    public function actif(string $organisationId, TypeContrat $type): ?ModeleContrat
    {
        return ModeleContrat::where('organization_id', $organisationId)
            ->where('type_contrat', $type->value)
            ->where('statut', StatutModeleContrat::ACTIF->value)
            ->first();
    }

    /**
     * Enregistre le texte (articles déjà validés) ; le statut est conservé. Chaque enregistrement
     * incrémente la révision, tracée dans les contrats générés ensuite.
     *
     * @param  list<array{titre: string, texte: string}>  $articles
     */
    public function enregistrer(string $organisationId, TypeContrat $type, string $titre, array $articles, User $auteur): ModeleContrat
    {
        return DB::transaction(function () use ($organisationId, $type, $titre, $articles, $auteur) {
            $modele = $this->verrouiller($organisationId, $type);
            $nouveau = ! $modele->exists;

            $modele->fill([
                'titre' => $titre,
                'articles' => array_values($articles),
                'modifie_par' => $auteur->id,
                'revision' => $nouveau ? 1 : $modele->revision + 1,
            ])->save();

            return $modele;
        });
    }

    /** Active le modèle (la trame est enregistrée telle quelle si elle ne l'était pas encore). */
    public function activer(string $organisationId, TypeContrat $type, User $auteur): ModeleContrat
    {
        return DB::transaction(function () use ($organisationId, $type, $auteur) {
            $modele = $this->verrouiller($organisationId, $type);
            if (! $modele->exists) {
                $modele->modifie_par = $auteur->id;
            }

            $modele->fill([
                'statut' => StatutModeleContrat::ACTIF,
                'active_par' => $auteur->id,
                'active_le' => now(),
            ])->save();

            return $modele;
        });
    }

    /** Repasse en brouillon : plus aucune génération avec ce modèle ; les contrats générés restent. */
    public function desactiver(string $organisationId, TypeContrat $type): void
    {
        ModeleContrat::where('organization_id', $organisationId)
            ->where('type_contrat', $type->value)
            ->update(['statut' => StatutModeleContrat::BROUILLON->value, 'active_par' => null, 'active_le' => null]);
    }

    private function verrouiller(string $organisationId, TypeContrat $type): ModeleContrat
    {
        return ModeleContrat::where('organization_id', $organisationId)->where('type_contrat', $type->value)->lockForUpdate()->first()
            ?? $this->trame($organisationId, $type);
    }

    private function trame(string $organisationId, TypeContrat $type): ModeleContrat
    {
        return new ModeleContrat([
            'organization_id' => $organisationId,
            'type_contrat' => $type,
            'titre' => TrameModeleContrat::titre($type),
            'articles' => TrameModeleContrat::articles($type),
            'statut' => StatutModeleContrat::BROUILLON,
            'revision' => 1,
        ]);
    }
}
