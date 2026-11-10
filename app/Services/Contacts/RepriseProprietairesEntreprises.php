<?php

namespace App\Services\Contacts;

use App\Enums\TypeProprietaire;
use App\Models\ContactsReprise;
use App\Models\ContactsRepriseLigne;
use App\Models\EntrepriseInterlocuteur;
use App\Models\EntrepriseTierce;
use App\Models\Proprietaire;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Reprise des propriétaires de type entreprise déjà enregistrés (ADR 0060, lot 5, CONTACT-031 et
 * 032), exécutée par la commande contacts:reprendre-proprietaires-entreprises.
 *
 * - Simulation par défaut : rien n'est écrit. Idempotente : un propriétaire déjà relié est ignoré.
 * - Sans correspondance par raison sociale normalisée, l'entreprise est créée (raison sociale seule :
 *   ni téléphone ni localisation déduits du contact)
 *   et reliée. Une correspondance n'est JAMAIS reliée d'office : la ligne est « à arbitrer », puis
 *   tranchée explicitement (relier tel propriétaire à telle entreprise, ou créer).
 * - Chaque exécution appliquée reçoit un identifiant ; chaque ligne garde ses valeurs d'avant.
 * - Annulation d'une exécution : seulement si le lien vaut encore ce qu'elle a posé (sinon
 *   « conflit », rien n'est touché) ; raison sociale d'avant restaurée ; interlocuteur créé par
 *   elle désactivé, jamais supprimé ; principal posé par elle retiré ; entreprise créée conservée.
 */
final class RepriseProprietairesEntreprises
{
    public const COMMANDE = 'contacts:reprendre-proprietaires-entreprises';

    public const ORIGINE_ANNULATION = 'annulation_reprise_proprietaires_entreprises';

    public function __construct(
        private readonly ProprietaireEntreprise $liens,
        private readonly InterlocuteurService $interlocuteurs,
    ) {}

    /**
     * Actions prévues. Sans arbitrage : tous les propriétaires entreprise non reliés de
     * l'organisation. Avec arbitrage : seulement les lignes désignées.
     *
     * @param  array<string, string>  $relier  proprietaire_id => entreprise_tierce_id
     * @param  list<string>  $creer  proprietaire_id
     * @return list<array{proprietaire_id: string, raison_sociale: ?string, action: string, entreprise_id?: ?string, motif?: string, correspondances?: list<array{id: string, raison_sociale: string, deja_proprietaire: bool}>}>
     */
    public function planifier(string $organizationId, array $relier = [], array $creer = []): array
    {
        if ($relier === [] && $creer === []) {
            return Proprietaire::with('personne')
                ->where('organization_id', $organizationId)
                ->where('type', TypeProprietaire::ENTREPRISE->value)
                ->whereNull('entreprise_tierce_id')
                ->orderBy('created_at')->orderBy('id')
                ->get()
                ->map(fn (Proprietaire $p) => $this->planifierAutomatique($p))
                ->all();
        }

        $plan = [];
        foreach ($relier as $proprietaireId => $entrepriseId) {
            $plan[] = $this->planifierArbitrage($organizationId, $proprietaireId, $entrepriseId);
        }
        foreach ($creer as $proprietaireId) {
            $plan[] = $this->planifierArbitrage($organizationId, $proprietaireId, null);
        }

        return $plan;
    }

    /**
     * Applique les actions « creer » et « relier » du plan. Chaque ligne a sa transaction : une ligne
     * refusée n'empêche pas les autres.
     *
     * @param  list<array<string, mixed>>  $plan
     * @return array{reprise: ?ContactsReprise, resultats: list<array<string, mixed>>}
     */
    public function appliquer(User $acteur, array $plan): array
    {
        $executables = array_filter($plan, fn (array $l) => in_array($l['action'], ['creer', 'relier'], true));
        if ($executables === []) {
            return ['reprise' => null, 'resultats' => $plan];
        }

        $reprise = ContactsReprise::create([
            'organization_id' => $acteur->organization_id,
            'commande' => self::COMMANDE,
            'mode' => ContactsReprise::MODE_APPLICATION,
            'acteur_id' => $acteur->id,
        ]);

        $resultats = [];
        foreach ($plan as $ligne) {
            if (! in_array($ligne['action'], ['creer', 'relier'], true)) {
                $resultats[] = $ligne;

                continue;
            }
            try {
                $resultats[] = DB::transaction(fn () => $this->appliquerLigne($reprise, $ligne, $acteur));
            } catch (ValidationException $e) {
                $resultats[] = [...$ligne, 'action' => 'refuse', 'motif' => collect($e->errors())->flatten()->first()];
            }
        }

        $reprise->forceFill(['rapport' => $this->rapport($resultats), 'terminee_le' => now()])->save();

        return ['reprise' => $reprise, 'resultats' => $resultats];
    }

    /**
     * @return array{reprise: ContactsReprise, annulation: ?ContactsReprise, resultats: list<array{ligne_id: string, proprietaire_id: string, action: string, motif?: string}>}
     */
    public function annuler(User $acteur, string $repriseId, bool $simulation): array
    {
        $reprise = ContactsReprise::where('organization_id', $acteur->organization_id)
            ->where('commande', self::COMMANDE)
            ->where('mode', ContactsReprise::MODE_APPLICATION)
            ->find($repriseId);
        if ($reprise === null) {
            throw new InvalidArgumentException("Exécution {$repriseId} introuvable dans votre organisation.");
        }

        $lignes = $reprise->lignes()
            ->where(fn ($q) => $q->whereNull('annulation_statut')->orWhere('annulation_statut', ContactsRepriseLigne::CONFLIT))
            ->orderBy('created_at')->orderBy('id')
            ->get();

        $resultats = [];
        foreach ($lignes as $ligne) {
            $resultats[] = $simulation
                ? $this->examinerAnnulation($ligne)
                : DB::transaction(fn () => $this->annulerLigne($ligne, $acteur));
        }

        $annulation = null;
        if (! $simulation && $resultats !== []) {
            $annulation = ContactsReprise::create([
                'organization_id' => $acteur->organization_id,
                'commande' => self::COMMANDE,
                'mode' => ContactsReprise::MODE_ANNULATION,
                'reprise_annulee_id' => $reprise->id,
                'acteur_id' => $acteur->id,
                'rapport' => $this->rapport($resultats),
                'terminee_le' => now(),
            ]);
        }

        return ['reprise' => $reprise, 'annulation' => $annulation, 'resultats' => $resultats];
    }

    // ── Planification ───────────────────────────────────────────────────────

    private function planifierAutomatique(Proprietaire $p): array
    {
        $base = ['proprietaire_id' => $p->id, 'raison_sociale' => $p->raison_sociale];
        $anomalie = $this->anomalie($p);
        if ($anomalie !== null) {
            return [...$base, 'action' => 'anomalie', 'motif' => $anomalie];
        }

        $correspondances = $this->correspondances($p);
        if ($correspondances !== []) {
            return [...$base, 'action' => 'arbitrer', 'correspondances' => $correspondances];
        }

        return [...$base, 'action' => 'creer', 'entreprise_id' => null];
    }

    private function planifierArbitrage(string $organizationId, string $proprietaireId, ?string $entrepriseId): array
    {
        $p = Proprietaire::with('personne')->where('organization_id', $organizationId)->find($proprietaireId);
        $base = ['proprietaire_id' => $proprietaireId, 'raison_sociale' => $p?->raison_sociale];
        if ($p === null) {
            return [...$base, 'action' => 'erreur', 'motif' => 'propriétaire introuvable dans l\'organisation'];
        }
        if ($p->type !== TypeProprietaire::ENTREPRISE) {
            return [...$base, 'action' => 'erreur', 'motif' => 'propriétaire de type personne physique'];
        }
        if ($p->entreprise_tierce_id !== null) {
            return [...$base, 'action' => 'deja_relie', 'entreprise_id' => $p->entreprise_tierce_id];
        }
        $anomalie = $this->anomalie($p);
        if ($anomalie !== null) {
            return [...$base, 'action' => 'anomalie', 'motif' => $anomalie];
        }
        if ($entrepriseId === null) {
            return [...$base, 'action' => 'creer', 'entreprise_id' => null, 'arbitrage' => true];
        }

        $entreprise = EntrepriseTierce::where('organization_id', $organizationId)->find($entrepriseId);
        if ($entreprise === null) {
            return [...$base, 'action' => 'erreur', 'motif' => 'entreprise introuvable dans l\'organisation'];
        }
        if (! $entreprise->is_active) {
            return [...$base, 'action' => 'erreur', 'motif' => 'entreprise désactivée'];
        }
        if (Proprietaire::where('entreprise_tierce_id', $entreprise->id)->exists()) {
            return [...$base, 'action' => 'erreur', 'motif' => 'entreprise déjà propriétaire'];
        }

        return [...$base, 'action' => 'relier', 'entreprise_id' => $entreprise->id];
    }

    /** Anomalies qui empêchent la reprise d'une ligne (rapport). */
    private function anomalie(Proprietaire $p): ?string
    {
        return match (true) {
            trim((string) $p->raison_sociale) === '' => 'raison_sociale_vide',
            $p->personne === null => 'contact_absent',
            $p->personne->organization_id !== $p->organization_id => 'contact_autre_organisation',
            trim((string) $p->personne->telephone) === '' => 'contact_sans_telephone',
            default => null,
        };
    }

    /** @return list<array{id: string, raison_sociale: string, deja_proprietaire: bool}> */
    private function correspondances(Proprietaire $p): array
    {
        $ids = array_keys($this->liens->correspondancesBrutes($p->organization_id, ['raison_sociale' => $p->raison_sociale]));
        if ($ids === []) {
            return [];
        }
        $dejaProprietaire = Proprietaire::whereIn('entreprise_tierce_id', $ids)->pluck('entreprise_tierce_id')->flip()->all();

        return EntrepriseTierce::whereIn('id', $ids)->orderBy('raison_sociale')->get()
            ->map(fn (EntrepriseTierce $e) => ['id' => $e->id, 'raison_sociale' => $e->raison_sociale, 'deja_proprietaire' => isset($dejaProprietaire[$e->id])])
            ->all();
    }

    // ── Application ─────────────────────────────────────────────────────────

    private function appliquerLigne(ContactsReprise $reprise, array $ligne, User $acteur): array
    {
        // Lecture sans verrou : lier() verrouille l'entreprise puis le propriétaire (même ordre que le
        // formulaire) et refuse un propriétaire relié entre-temps.
        /** @var Proprietaire $p */
        $p = Proprietaire::with('personne')->findOrFail($ligne['proprietaire_id']);
        if ($p->entreprise_tierce_id !== null) {
            return [...$ligne, 'action' => 'deja_relie', 'entreprise_id' => $p->entreprise_tierce_id];
        }
        // Création automatique : une entreprise apparue depuis la simulation remet la ligne à arbitrer.
        if ($ligne['action'] === 'creer' && empty($ligne['arbitrage']) && ($correspondances = $this->correspondances($p)) !== []) {
            return [...$ligne, 'action' => 'arbitrer', 'correspondances' => $correspondances];
        }

        $creee = $ligne['action'] === 'creer';
        $entreprise = $creee
            ? $this->liens->creerEntreprise($p, ['raison_sociale' => (string) $p->raison_sociale])
            : EntrepriseTierce::findOrFail($ligne['entreprise_id']);

        $lien = $this->liens->lier($p, $entreprise, $acteur, $creee, ProprietaireEntreprise::ORIGINE_REPRISE, ['contacts_reprise_id' => $reprise->id]);

        ContactsRepriseLigne::create([
            'contacts_reprise_id' => $reprise->id,
            'proprietaire_id' => $p->id,
            'entreprise_tierce_id' => $entreprise->id,
            'entreprise_creee' => $creee,
            'interlocuteur_id' => $lien['interlocuteur_id'],
            'interlocuteur_etat_avant' => $lien['interlocuteur_etat_avant'],
            'principal_pose' => $lien['principal_pose'],
            'valeurs_avant' => $lien['valeurs_avant'],
        ]);

        return [...$ligne, 'action' => $creee ? 'cree' : 'relie', 'entreprise_id' => $entreprise->id];
    }

    // ── Annulation ──────────────────────────────────────────────────────────

    private function examinerAnnulation(ContactsRepriseLigne $ligne): array
    {
        $p = Proprietaire::withTrashed()->find($ligne->proprietaire_id);
        $base = ['ligne_id' => $ligne->id, 'proprietaire_id' => $ligne->proprietaire_id];

        return $p === null || $p->entreprise_tierce_id !== $ligne->entreprise_tierce_id
            ? [...$base, 'action' => 'conflit', 'motif' => 'rattachement modifié depuis la reprise']
            : [...$base, 'action' => 'a_annuler'];
    }

    private function annulerLigne(ContactsRepriseLigne $ligne, User $acteur): array
    {
        $base = ['ligne_id' => $ligne->id, 'proprietaire_id' => $ligne->proprietaire_id];

        EntrepriseTierce::whereKey($ligne->entreprise_tierce_id)->lockForUpdate()->first();
        $p = Proprietaire::withTrashed()->whereKey($ligne->proprietaire_id)->lockForUpdate()->first();

        if ($p === null || $p->entreprise_tierce_id !== $ligne->entreprise_tierce_id) {
            $ligne->forceFill(['annulation_statut' => ContactsRepriseLigne::CONFLIT])->save();

            return [...$base, 'action' => 'conflit', 'motif' => 'rattachement modifié depuis la reprise'];
        }

        $avant = $ligne->valeurs_avant ?? [];
        $this->liens->delier($p, $acteur, self::ORIGINE_ANNULATION,
            ['raison_sociale' => $avant['raison_sociale'] ?? $p->raison_sociale],
            ['contacts_reprise_id' => $ligne->contacts_reprise_id]);

        $interlocuteur = $ligne->interlocuteur_id !== null ? EntrepriseInterlocuteur::find($ligne->interlocuteur_id) : null;
        if ($interlocuteur !== null) {
            if ($ligne->interlocuteur_etat_avant === 'absent') {
                // Créé par la reprise : désactivé (il perd aussi le rôle de principal), jamais supprimé.
                $this->interlocuteurs->desactiver($interlocuteur, $acteur);
            } elseif ($ligne->interlocuteur_etat_avant === 'actif' && $ligne->principal_pose) {
                $this->interlocuteurs->retirerPrincipal($interlocuteur, $acteur, self::ORIGINE_ANNULATION);
            }
        }

        $ligne->forceFill(['annulation_statut' => ContactsRepriseLigne::ANNULEE, 'annulee_le' => now()])->save();

        return [...$base, 'action' => 'annulee'];
    }

    /** @param list<array<string, mixed>> $resultats */
    private function rapport(array $resultats): array
    {
        return [
            'totaux' => collect($resultats)->countBy('action')->all(),
            // Identifiants et codes seulement : jamais de nom ni de téléphone.
            'lignes' => array_map(fn (array $r) => array_filter([
                'proprietaire_id' => $r['proprietaire_id'] ?? null,
                'ligne_id' => $r['ligne_id'] ?? null,
                'action' => $r['action'],
                'entreprise_id' => $r['entreprise_id'] ?? null,
                'motif' => $r['motif'] ?? null,
                'correspondances' => isset($r['correspondances']) ? array_column($r['correspondances'], 'id') : null,
            ], fn ($v) => $v !== null), $resultats),
        ];
    }
}
