<script setup lang="ts">
import InfoTooltip from '@/components/InfoTooltip.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFlashToast } from '@/composables/useFlashToast';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    Download,
    Eye,
    FileSignature,
    Loader2,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import { computed, ref } from 'vue';

interface Version {
    id: string;
    version: number;
    statut: string;
    statut_label: string;
    genere_le: string;
    genere_le_iso: string;
    genere_par: string | null;
    signe_le: string | null;
    signature_enregistree_par: string | null;
    modele_revision: number | null;
}

const props = defineProps<{
    contrat: {
        id: string;
        employe_id: string;
        employe_nom_complet: string | null;
        employe_matricule: string | null;
        type_contrat: string;
        type_contrat_label: string;
        statut_contrat: string;
        statut_contrat_label: string;
        est_actif: boolean;
        date_debut: string | null;
        date_fin: string | null;
    };
    modele: {
        titre: string;
        statut: string;
        statut_label: string;
        actif: boolean;
        revision: number;
    };
    versions: Version[];
    peut_generer: boolean;
    variables: { cle: string; libelle: string; valeur: string | null }[];
    champs: Record<string, string | null>;
    champs_utilises: { champ: string; libelle: string }[];
    avantages: { id: string; texte: string }[];
    avantages_retenus: string[];
    utilise_avantages?: boolean;
}>();

useFlashToast();
const page = usePage();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Contrats', href: '/backoffice/contrats' },
    {
        title: 'Contrat de travail',
        href: `/backoffice/contrats/${props.contrat.id}/document`,
    },
];

const AIDE_CHAMP: Record<string, string> = {
    representant_nom: 'Ex. Mamadou BAH',
    representant_qualite: 'Ex. Directeur général',
    lieu_travail: 'Ex. Agence de Matoto, Conakry',
    periode_essai:
        'Ex. trois (3) mois, renouvelable une fois — ou « sans période d’essai »',
    horaires: 'Ex. 40 heures par semaine, du lundi au samedi',
    motif_cdd: 'Ex. accroissement temporaire d’activité',
    lieu_signature: 'Ex. Conakry',
};

const form = useForm({
    champs: Object.fromEntries(
        props.champs_utilises.map((c) => [
            c.champ,
            props.champs[c.champ] ?? '',
        ]),
    ) as Record<string, string>,
    avantages: props.avantages
        .filter((a) => props.avantages_retenus.includes(a.id))
        .map((a) => a.id),
});

const derniere = computed(() => props.versions[0] ?? null);
const dossierIncomplet = computed(() =>
    props.variables.filter((v) => !v.valeur),
);
const champsVides = computed(() =>
    props.champs_utilises.filter((c) => !form.champs[c.champ]?.trim()),
);
const peutLancer = computed(
    () =>
        dossierIncomplet.value.length === 0 &&
        champsVides.value.length === 0 &&
        !form.processing,
);
const erreurs = computed(
    () => (page.props.errors ?? {}) as Record<string, string>,
);

function generer() {
    form.post(`/backoffice/contrats/${props.contrat.id}/documents`, {
        preserveScroll: true,
    });
}

// ── Marquer comme signé ──────────────────────────────────────────────────────
const aSigner = ref<Version | null>(null);
const aujourdhui = new Date().toLocaleDateString('en-CA');
const signature = useForm({ signe_le: aujourdhui });

function ouvrirSignature(v: Version) {
    aSigner.value = v;
    signature.signe_le = aujourdhui;
    signature.clearErrors();
}

function enregistrerSignature() {
    if (!aSigner.value) return;
    signature.post(
        `/backoffice/contrats/${props.contrat.id}/documents/${aSigner.value.id}/signature`,
        {
            preserveScroll: true,
            onSuccess: () => (aSigner.value = null),
        },
    );
}
</script>

<template>
    <Head><title>Contrat de travail</title></Head>
    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 p-6">
            <div class="space-y-2">
                <Link
                    href="/backoffice/contrats"
                    class="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft class="h-4 w-4" /> Contrats
                </Link>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Contrat de travail
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{ contrat.employe_nom_complet }}
                    <span class="font-mono">{{
                        contrat.employe_matricule
                    }}</span>
                    · {{ contrat.type_contrat_label }} du
                    {{ contrat.date_debut }}
                    <template v-if="contrat.date_fin">
                        au {{ contrat.date_fin }}</template
                    >
                    ·
                    <StatusDot
                        class="inline-flex"
                        :status="contrat.statut_contrat"
                        :label="contrat.statut_contrat_label"
                    />
                </p>
            </div>

            <!-- Génération -->
            <section
                v-if="peut_generer"
                class="space-y-5 rounded-xl border bg-card p-5"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="font-medium">
                            {{
                                derniere
                                    ? 'Générer une nouvelle version'
                                    : 'Générer le contrat'
                            }}
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            Modèle : {{ modele.titre }} (révision
                            {{ modele.revision }})
                        </p>
                    </div>
                    <StatusDot
                        :status="modele.statut"
                        :label="`Modèle ${modele.statut_label.toLowerCase()}`"
                    />
                </div>

                <p
                    v-if="!contrat.est_actif"
                    class="rounded-lg border bg-muted/40 px-4 py-3 text-sm text-muted-foreground"
                >
                    Ce contrat n’est plus actif : aucune nouvelle version ne
                    peut être générée. Les versions existantes restent
                    consultables ci-dessous.
                </p>

                <p
                    v-else-if="!modele.actif"
                    class="flex gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-300"
                    data-testid="modele-inactif"
                >
                    <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
                    Aucun modèle de {{ contrat.type_contrat_label }} actif. Un
                    administrateur doit le relire et l’activer dans Paramètres →
                    RH → Modèles de contrat.
                </p>

                <template v-else>
                    <p
                        v-if="derniere?.statut === 'signe'"
                        class="rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800 dark:border-sky-900/50 dark:bg-sky-950/30 dark:text-sky-300"
                    >
                        La version {{ derniere.version }} est signée : elle
                        reste conservée telle quelle. Une nouvelle génération
                        crée la version {{ derniere.version + 1 }}, à faire
                        signer.
                    </p>
                    <p
                        v-else-if="derniere?.statut === 'a_signer'"
                        class="rounded-lg border bg-muted/40 px-4 py-3 text-sm text-muted-foreground"
                    >
                        La version {{ derniere.version }} n’est pas encore
                        signée : la régénérer la remplace par la version
                        {{ derniere.version + 1 }}.
                    </p>

                    <div>
                        <h3 class="mb-2 text-sm font-medium">
                            Données reprises du dossier RH
                        </h3>
                        <dl
                            class="grid gap-x-6 gap-y-1.5 text-sm sm:grid-cols-2"
                        >
                            <div
                                v-for="v in variables"
                                :key="v.cle"
                                class="flex justify-between gap-3 border-b py-1"
                            >
                                <dt class="text-muted-foreground">
                                    {{ v.libelle }}
                                </dt>
                                <dd
                                    class="text-right"
                                    :class="
                                        v.valeur
                                            ? ''
                                            : 'font-medium text-destructive'
                                    "
                                >
                                    {{ v.valeur ?? 'Non renseigné' }}
                                </dd>
                            </div>
                        </dl>
                        <p
                            v-if="dossierIncomplet.length"
                            class="mt-2 text-xs text-destructive"
                            data-testid="dossier-incomplet"
                        >
                            Complétez le dossier du salarié ou le contrat avant
                            de générer :
                            {{
                                dossierIncomplet
                                    .map((v) => v.libelle.toLowerCase())
                                    .join(', ')
                            }}.
                        </p>
                    </div>

                    <div v-if="champs_utilises.length" class="space-y-3">
                        <h3 class="text-sm font-medium">À compléter</h3>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div
                                v-for="c in champs_utilises"
                                :key="c.champ"
                                :class="
                                    c.champ === 'periode_essai' ||
                                    c.champ === 'horaires' ||
                                    c.champ === 'motif_cdd'
                                        ? 'sm:col-span-2'
                                        : ''
                                "
                            >
                                <div class="mb-1.5 flex items-center gap-1.5">
                                    <Label
                                        :for="`champ-${c.champ}`"
                                        class="text-xs font-medium"
                                        >{{ c.libelle }}</Label
                                    >
                                    <InfoTooltip
                                        v-if="c.champ === 'periode_essai'"
                                        label="Aide : période d'essai"
                                    >
                                        Durée de la période d'essai prévue au
                                        contrat, telle qu'elle figurera dans le
                                        document (ex. « trois (3) mois,
                                        renouvelable une fois »). Écrivez « sans
                                        période d'essai » si elle ne s'applique
                                        pas.
                                    </InfoTooltip>
                                </div>
                                <Input
                                    :id="`champ-${c.champ}`"
                                    v-model="form.champs[c.champ]"
                                    maxlength="500"
                                    :placeholder="AIDE_CHAMP[c.champ]"
                                />
                                <p
                                    v-if="
                                        form.errors[`champs.${c.champ}` as any]
                                    "
                                    class="mt-1 text-xs text-destructive"
                                >
                                    {{
                                        form.errors[`champs.${c.champ}` as any]
                                    }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div v-if="utilise_avantages" class="space-y-2">
                        <h3 class="text-sm font-medium">
                            Primes et avantages à faire figurer
                        </h3>
                        <p
                            v-if="!avantages.length"
                            class="text-sm text-muted-foreground"
                        >
                            Aucune prime ni aucun avantage validé pour ce
                            salarié : le contrat indiquera « aucun ».
                        </p>
                        <label
                            v-for="a in avantages"
                            :key="a.id"
                            class="flex items-start gap-2 text-sm"
                        >
                            <input
                                v-model="form.avantages"
                                type="checkbox"
                                :value="a.id"
                                class="mt-0.5 h-4 w-4 rounded border-input"
                            />
                            {{ a.texte }}
                        </label>
                        <p class="text-xs text-muted-foreground">
                            Seuls les primes et avantages validés (écran «
                            Primes des salariés ») sont proposés.
                        </p>
                    </div>

                    <div
                        v-for="cle in [
                            'manquantes',
                            'modele',
                            'contrat',
                            'avantages',
                        ]"
                        :key="cle"
                    >
                        <p
                            v-if="erreurs[cle]"
                            class="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive"
                        >
                            {{ erreurs[cle] }}
                        </p>
                    </div>

                    <div class="flex justify-end">
                        <Button
                            :disabled="!peutLancer"
                            data-testid="generer-contrat"
                            @click="generer"
                        >
                            <Loader2
                                v-if="form.processing"
                                class="mr-2 h-4 w-4 animate-spin"
                            />
                            Générer le PDF
                        </Button>
                    </div>
                </template>
            </section>

            <!-- Historique -->
            <section class="rounded-xl border bg-card">
                <div class="border-b px-5 py-3">
                    <h2 class="font-medium">Versions générées</h2>
                </div>
                <p
                    v-if="!versions.length"
                    class="px-5 py-8 text-center text-sm text-muted-foreground"
                >
                    Aucun contrat généré pour le moment.
                </p>
                <ul v-else class="divide-y">
                    <li
                        v-for="v in versions"
                        :key="v.id"
                        class="flex flex-wrap items-center justify-between gap-3 px-5 py-3"
                        :data-testid="`version-${v.version}`"
                    >
                        <div class="space-y-0.5">
                            <div class="flex items-center gap-3">
                                <span class="font-medium"
                                    >Version {{ v.version }}</span
                                >
                                <StatusDot
                                    :status="v.statut"
                                    :label="v.statut_label"
                                />
                            </div>
                            <p class="text-xs text-muted-foreground">
                                Générée le {{ v.genere_le }}
                                <template v-if="v.genere_par">
                                    par {{ v.genere_par }}</template
                                >
                                <template v-if="v.modele_revision">
                                    · modèle révision
                                    {{ v.modele_revision }}</template
                                >
                                <template v-if="v.signe_le">
                                    · signée le {{ v.signe_le }}
                                    <template v-if="v.signature_enregistree_par"
                                        >(enregistré par
                                        {{
                                            v.signature_enregistree_par
                                        }})</template
                                    >
                                </template>
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <a
                                :href="`/backoffice/contrats/${contrat.id}/documents/${v.id}/pdf?preview=1`"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <Button variant="outline" size="sm">
                                    <Eye class="mr-1.5 h-4 w-4" /> Voir
                                </Button>
                            </a>
                            <a
                                :href="`/backoffice/contrats/${contrat.id}/documents/${v.id}/pdf`"
                            >
                                <Button variant="outline" size="sm">
                                    <Download class="mr-1.5 h-4 w-4" />
                                    Télécharger
                                </Button>
                            </a>
                            <Button
                                v-if="
                                    peut_generer &&
                                    v.statut === 'a_signer' &&
                                    v.id === derniere?.id
                                "
                                size="sm"
                                data-testid="marquer-signe"
                                @click="ouvrirSignature(v)"
                            >
                                <FileSignature class="mr-1.5 h-4 w-4" />
                                Marquer comme signé
                            </Button>
                        </div>
                    </li>
                </ul>
            </section>
        </div>

        <Dialog
            :visible="aSigner !== null"
            modal
            :closable="!signature.processing"
            :header="`Version ${aSigner?.version} signée`"
            :style="{ width: '28rem' }"
            @update:visible="
                (v: boolean) => {
                    if (!v && !signature.processing) aSigner = null;
                }
            "
        >
            <div class="space-y-4">
                <p class="text-sm text-muted-foreground">
                    Indiquez la date à laquelle le contrat papier a été signé
                    par les deux parties. La version sera figée : toute
                    modification ultérieure créera une nouvelle version.
                </p>
                <div>
                    <Label for="signe-le" class="mb-1.5 block text-xs"
                        >Date de signature</Label
                    >
                    <Input
                        id="signe-le"
                        v-model="signature.signe_le"
                        type="date"
                        :min="aSigner?.genere_le_iso"
                        :max="aujourdhui"
                    />
                    <p
                        v-if="signature.errors.signe_le"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ signature.errors.signe_le }}
                    </p>
                </div>
                <div class="flex justify-end gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="signature.processing"
                        @click="aSigner = null"
                        >Annuler</Button
                    >
                    <Button
                        size="sm"
                        :disabled="signature.processing || !signature.signe_le"
                        data-testid="confirmer-signature"
                        @click="enregistrerSignature"
                    >
                        <Loader2
                            v-if="signature.processing"
                            class="mr-1.5 h-3.5 w-3.5 animate-spin"
                        />
                        Confirmer
                    </Button>
                </div>
            </div>
        </Dialog>
    </AppLayout>
</template>
