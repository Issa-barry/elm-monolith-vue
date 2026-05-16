<?php

namespace Database\Seeders;

use App\Enums\StatutContrat;
use App\Enums\StatutEmploye;
use App\Enums\TypeContrat;
use App\Enums\TypeEmploye;
use App\Models\Contrat;
use App\Models\Employe;
use App\Models\Organization;
use App\Models\Site;
use App\Services\MatriculeService;
use Illuminate\Database\Seeder;

class EmployesSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::where('slug', 'elm')->firstOrFail();

        $matoto    = Site::where('organization_id', $org->id)->where('nom', 'Matoto')->firstOrFail();
        $lansanaya = Site::where('organization_id', $org->id)->where('nom', 'Lansanaya')->firstOrFail();

        $employes = [
            // ── Matoto (siège) ────────────────────────────────────────────────
            [
                'employe' => [
                    'nom'          => 'DIALLO',
                    'prenom'       => 'Mamadou',
                    'email'        => 'mamadou.diallo@eaulamamam.com',
                    'telephone'    => '+224621100001',
                    'type_employe' => TypeEmploye::INTERNE->value,
                    'statut'       => StatutEmploye::ACTIF->value,
                    'site'         => $matoto,
                ],
                'contrat' => [
                    'type_contrat'   => TypeContrat::CDI->value,
                    'date_debut'     => '2024-01-01',
                    'date_fin'       => null,
                    'salaire_base'   => 2_500_000,
                    'statut_contrat' => StatutContrat::ACTIF->value,
                ],
            ],

            // ── Lansanaya (usine) ─────────────────────────────────────────────
            [
                'employe' => [
                    'nom'          => 'CAMARA',
                    'prenom'       => 'Fatoumata',
                    'email'        => 'fatoumata.camara@eaulamamam.com',
                    'telephone'    => '+224621100002',
                    'type_employe' => TypeEmploye::INTERNE->value,
                    'statut'       => StatutEmploye::ACTIF->value,
                    'site'         => $lansanaya,
                ],
                'contrat' => [
                    'type_contrat'   => TypeContrat::CDI->value,
                    'date_debut'     => '2024-03-01',
                    'date_fin'       => null,
                    'salaire_base'   => 1_800_000,
                    'statut_contrat' => StatutContrat::ACTIF->value,
                ],
            ],
        ];

        $service = app(MatriculeService::class);

        foreach ($employes as $data) {
            $siteModel = $data['employe']['site'];

            $employe = Employe::firstOrCreate(
                [
                    'organization_id' => $org->id,
                    'telephone'       => $data['employe']['telephone'],
                ],
                [
                    'nom'          => mb_strtoupper($data['employe']['nom'], 'UTF-8'),
                    'prenom'       => mb_convert_case($data['employe']['prenom'], MB_CASE_TITLE, 'UTF-8'),
                    'email'        => $data['employe']['email'],
                    'telephone'    => $data['employe']['telephone'],
                    'type_employe' => $data['employe']['type_employe'],
                    'statut'       => $data['employe']['statut'],
                    'site_id'      => $siteModel->id,
                    'matricule'    => $service->generate($org->id, Employe::class),
                ]
            );

            // Contrat — créé uniquement si aucun contrat actif n'existe déjà
            $dejaActif = Contrat::where('employe_id', $employe->id)
                ->where('statut_contrat', StatutContrat::ACTIF->value)
                ->exists();

            if (! $dejaActif) {
                Contrat::create([
                    'organization_id' => $org->id,
                    'employe_id'      => $employe->id,
                    'type_contrat'    => $data['contrat']['type_contrat'],
                    'date_debut'      => $data['contrat']['date_debut'],
                    'date_fin'        => $data['contrat']['date_fin'],
                    'salaire_base'    => $data['contrat']['salaire_base'],
                    'statut_contrat'  => $data['contrat']['statut_contrat'],
                ]);
            }
        }

        $this->command->info('✓ Employés seedés :');
        $this->command->table(
            ['Matricule', 'Nom', 'Site', 'Contrat', 'Salaire'],
            Employe::where('organization_id', $org->id)
                ->with(['site:id,nom', 'contratActif'])
                ->get()
                ->map(fn ($e) => [
                    $e->matricule,
                    $e->nom_complet,
                    $e->site?->nom ?? '—',
                    $e->contratActif?->type_contrat->label() ?? '—',
                    number_format((float) ($e->contratActif?->salaire_base ?? 0), 0, ',', ' ') . ' GNF',
                ])
                ->toArray()
        );
    }
}
