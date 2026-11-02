<?php

namespace App\Contracts\Rh;

use App\Models\TransmissionPaie;
use App\Models\TransmissionPaieLigne;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Frontière RH → Comptabilité (ADR 0040) : la Comptabilité ne lit jamais les tables RH
 * (contrats, affectations) directement, seulement la version transmise du mois, figée.
 */
interface SourceDonneesPaie
{
    /**
     * Brouillon RH du mois, en lecture seule et provisoire (ADR 0047) : la Comptabilité le voit sans
     * pouvoir le reprendre ni le payer. Null s'il n'y en a pas.
     *
     * @return array{version: int, nb_salaries: int, total_verse: float, total_valorise: float, nb_anomalies_bloquantes: int, a_jour: bool, preparee_le: ?string}|null
     */
    public function brouillonEnCours(string $organizationId, int $annee, int $mois): ?array;

    /** Version validée par les RH en vigueur pour le mois (null si aucune). */
    public function transmissionCourante(string $organizationId, int $annee, int $mois): ?TransmissionPaie;

    /**
     * Lignes figées d'une version transmise (ou remplacée, pour l'historique). Refus si le contenu ne
     * correspond plus à l'empreinte enregistrée à la validation.
     *
     * @return Collection<int, TransmissionPaieLigne>
     */
    public function lignes(TransmissionPaie $transmission): Collection;

    /**
     * Estimation des montants versés du mois par salarié, pour la prévision de trésorerie tant que la
     * Comptabilité n'a rien repris : version transmise si elle existe, sinon calcul RH du moment.
     * Jamais utilisée pour payer.
     *
     * @return Collection<int, array{employe_id: string, nom: string, site_id: ?string, montant: float}>
     */
    public function estimationVersements(string $organizationId, int $annee, int $mois): Collection;

    /**
     * Marque une version transmise comme reprise par la Comptabilité. Une même version n'est
     * jamais reprise deux fois : le second appel, même simultané, est refusé.
     *
     * @param  string  $reference  ce qui a repris la version (ex. identifiant de la période de paie)
     */
    public function prendreEnCharge(TransmissionPaie $transmission, User $acteur, string $reference): TransmissionPaie;
}
