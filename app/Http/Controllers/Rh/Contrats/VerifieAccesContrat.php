<?php

namespace App\Http\Controllers\Rh\Contrats;

use App\Models\Contrat;
use App\Models\DocumentContrat;
use App\Models\User;
use App\Services\Rh\DocumentContratService;
use App\Services\SiteScopeService;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Accès aux contrats de travail générés (ADR 0044) : policy du contrat, même organisation et agence
 * actuelle du salarié dans le périmètre — de consultation pour lire, d'écriture (agences de
 * rattachement) pour générer ou enregistrer une signature. Organisation et agence vérifiées
 * explicitement : le Gate::before du super administrateur court-circuite les policies.
 */
trait VerifieAccesContrat
{
    protected function verifierAccesContrat(User $user, Contrat $contrat, bool $ecriture = false): void
    {
        abort_unless($this->aAccesContrat($user, $contrat, $ecriture), 403);
    }

    protected function aAccesContrat(User $user, Contrat $contrat, bool $ecriture = false): bool
    {
        if ($contrat->organization_id !== $user->organization_id || ! $user->can($ecriture ? 'update' : 'view', $contrat)) {
            return false;
        }

        // Le contrat contient le salaire : générer ou enregistrer une signature exige de pouvoir le voir.
        if ($ecriture && ! $user->can('rh-remunerations.read')) {
            return false;
        }

        if ($ecriture ? $user->agitSurToutesLesAgences() : $user->voitToutesLesAgences()) {
            return true;
        }

        $perimetre = app(SiteScopeService::class);
        $siteIds = $ecriture ? $perimetre->assignedSiteIds($user) : $perimetre->accessibleSiteIds($user);
        $siteId = app(DocumentContratService::class)->employe($contrat)?->site_id;

        return $siteId !== null && $siteIds->contains($siteId);
    }

    /** PDF stocké de la version : aperçu dans le navigateur, sinon téléchargement. */
    protected function reponsePdf(DocumentContrat $document, bool $apercu): Response
    {
        return response(app(DocumentContratService::class)->contenu($document), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition($apercu ? 'inline' : 'attachment', $document->nomFichier()),
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
