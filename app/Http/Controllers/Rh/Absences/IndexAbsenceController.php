<?php

namespace App\Http\Controllers\Rh\Absences;

use App\Http\Controllers\Controller;
use App\Models\Absence;
use App\Models\MotifAbsence;
use App\Models\Site;
use App\Models\User;
use App\Support\Periodes\Periode;
use App\Support\Rh\AbsenceListing;
use App\Support\Rh\AbsencePresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** RH → Absences : liste filtrée et paginée (docs/rh-absences.md). */
class IndexAbsenceController extends Controller
{
    public function __invoke(Request $request, AbsenceListing $listing): Response
    {
        $this->authorize('viewAny', Absence::class);

        $user = $request->user();
        $orgId = $user->organization_id;
        $periode = Periode::depuisRequeteValidee($request);
        $filtres = $request->only(['recherche', 'motif_id', 'auteur_id', 'statut']);
        if (! in_array($filtres['statut'] ?? '', ['', ...AbsenceListing::ETATS], true)) {
            $filtres['statut'] = '';
        }
        // Filtre Agence libre seulement avec la vision de toutes les agences (sinon : ses agences).
        $siteIds = $user->voitToutesLesAgences()
            ? array_values(array_filter((array) $request->input('site_ids', []), 'is_string'))
            : [];

        $presenter = new AbsencePresenter($user);
        $absences = $listing->query($user, $filtres, $siteIds, $periode)
            ->with(AbsencePresenter::RELATIONS)
            ->paginate(30)
            ->withQueryString()
            ->through(fn (Absence $a) => $presenter->ligne($a));

        return Inertia::render('Absences/Index', [
            'absences' => $absences,
            'filters' => [...$filtres, 'site_ids' => $siteIds, ...$periode->valeursFiltre()],
            'sites' => $this->sites($user),
            // Filtre Motif : motifs désactivés compris, des absences anciennes peuvent les porter.
            'motifs' => MotifAbsence::where('organization_id', $orgId)->ordonnes()->get(['id', 'libelle', 'is_active'])
                ->map(fn (MotifAbsence $m) => ['value' => $m->id, 'label' => $m->libelle.($m->is_active ? '' : ' (désactivé)')])
                ->values(),
            'auteurs' => $listing->auteurs($user),
            'statuts' => [
                ['value' => 'en_cours', 'label' => Absence::libelleEtat('en_cours')],
                ['value' => 'a_venir', 'label' => Absence::libelleEtat('a_venir')],
                ['value' => 'terminee', 'label' => Absence::libelleEtat('terminee')],
                ['value' => 'annulee', 'label' => Absence::libelleEtat('annulee')],
            ],
            // Formulaire « Nouvelle absence » : seulement avec la permission (vide sinon).
            'motifs_saisissables' => $user->can('rh-absences.create') ? AbsencePresenter::motifsSaisissables($orgId) : [],
            'salaries' => AbsencePresenter::salariesSaisissables($user),
        ]);
    }

    /** @return list<array{id: string, nom: string}> */
    private function sites(User $user): array
    {
        $sites = $user->voitToutesLesAgences()
            ? Site::where('organization_id', $user->organization_id)->orderBy('nom')->get(['id', 'nom'])
            : $user->sites()->orderBy('sites.nom')->get(['sites.id', 'sites.nom']);

        return $sites->map(fn (Site $s) => ['id' => $s->id, 'nom' => $s->nom])->values()->all();
    }
}
