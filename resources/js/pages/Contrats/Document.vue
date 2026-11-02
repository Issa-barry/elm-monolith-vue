<script setup lang="ts">
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
    CheckCircle2,
    Download,
    Eye,
    FileSignature,
    Info,
    Loader2,
    Pencil,
    RefreshCw,
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

type CodeEtat =
    | 'a_jour'
    | 'a_mettre_a_jour'
    | 'a_generer'
    | 'a_completer'
    | 'signe_different'
    | 'inactif';

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
    etat: { code: CodeEtat; message: string };
    versions: Version[];
    peut_generer: boolean;
    variables: { cle: string; libelle: string; valeur: string | null }[];
    avantages: string[];
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

const derniere = computed(() => props.versions[0] ?? null);
const erreurs = computed(
    () => (page.props.errors ?? {}) as Record<string, string>,
);

// Couleur du bandeau : vert = PDF à jour, orange = action utile mais rien de bloqué, rouge = PDF
// impossible tant qu'une information manque, gris = contrat terminé.
const bandeau = computed(() => {
    switch (props.etat.code) {
        case 'a_jour':
            return {
                icone: CheckCircle2,
                classe: 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-300',
            };
        case 'a_completer':
            return {
                icone: AlertTriangle,
                classe: 'border-red-200 bg-red-50 text-red-800 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-300',
            };
        case 'inactif':
            return {
                icone: Info,
                classe: 'bg-muted/40 text-muted-foreground',
            };
        default:
            return {
                icone: AlertTriangle,
                classe: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-300',
            };
    }
});

// « Mettre à jour le PDF » : nouvelle tentative, ou nouvelle version après une version signée.
const peutMettreAJour = computed(
    () =>
        props.peut_generer &&
        ['a_generer', 'a_mettre_a_jour', 'signe_different'].includes(
            props.etat.code,
        ),
);
const miseAJour = useForm({});

function mettreAJour() {
    miseAJour.post(`/backoffice/contrats/${props.contrat.id}/documents`, {
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
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Contrat de travail
                    </h1>
                    <div class="flex flex-wrap gap-2">
                        <template v-if="derniere">
                            <a
                                :href="`/backoffice/contrats/${contrat.id}/pdf?preview=1`"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <Button size="sm" data-testid="voir-pdf">
                                    <Eye class="mr-1.5 h-4 w-4" /> Voir le PDF
                                </Button>
                            </a>
                            <a :href="`/backoffice/contrats/${contrat.id}/pdf`">
                                <Button variant="outline" size="sm">
                                    <Download class="mr-1.5 h-4 w-4" />
                                    Télécharger
                                </Button>
                            </a>
                        </template>
                        <Link
                            v-if="peut_generer && contrat.est_actif"
                            :href="`/backoffice/contrats/${contrat.id}/edit`"
                        >
                            <Button variant="outline" size="sm">
                                <Pencil class="mr-1.5 h-4 w-4" /> Modifier le
                                contrat
                            </Button>
                        </Link>
                    </div>
                </div>
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

            <!-- État du PDF -->
            <section
                class="flex flex-wrap items-start justify-between gap-3 rounded-lg border px-4 py-3 text-sm"
                :class="bandeau.classe"
                data-testid="etat-pdf"
                :data-etat="etat.code"
            >
                <p class="flex gap-2">
                    <component
                        :is="bandeau.icone"
                        class="mt-0.5 h-4 w-4 shrink-0"
                    />
                    <span>{{ etat.message }}</span>
                </p>
                <Button
                    v-if="peutMettreAJour"
                    size="sm"
                    :disabled="miseAJour.processing"
                    data-testid="mettre-a-jour-pdf"
                    @click="mettreAJour"
                >
                    <Loader2
                        v-if="miseAJour.processing"
                        class="mr-1.5 h-4 w-4 animate-spin"
                    />
                    <RefreshCw v-else class="mr-1.5 h-4 w-4" />
                    {{
                        etat.code === 'signe_different'
                            ? 'Préparer une nouvelle version'
                            : 'Mettre à jour le PDF'
                    }}
                </Button>
            </section>
            <template
                v-for="cle in ['pdg', 'manquantes', 'modele', 'contrat']"
                :key="cle"
            >
                <p
                    v-if="erreurs[cle]"
                    class="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive"
                >
                    {{ erreurs[cle] }}
                </p>
            </template>

            <!-- Informations reprises automatiquement -->
            <section
                v-if="variables.length"
                class="space-y-3 rounded-xl border bg-card p-5"
            >
                <div>
                    <h2 class="font-medium">
                        Informations reprises dans le contrat
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        Reprises automatiquement de l’organisation, de la fiche
                        du salarié, du contrat et du PDG. Pour corriger une
                        information, modifiez-la à sa source : le PDF suit.
                    </p>
                </div>
                <dl class="grid gap-x-6 gap-y-1.5 text-sm sm:grid-cols-2">
                    <div
                        v-for="v in variables"
                        :key="v.cle"
                        class="flex justify-between gap-3 border-b py-1"
                    >
                        <dt class="text-muted-foreground">{{ v.libelle }}</dt>
                        <dd
                            class="text-right"
                            :class="
                                v.valeur ? '' : 'font-medium text-destructive'
                            "
                        >
                            {{ v.valeur ?? 'Non renseigné' }}
                        </dd>
                    </div>
                </dl>
                <div class="text-sm">
                    <p class="text-muted-foreground">Primes et avantages</p>
                    <p v-if="!avantages.length">
                        Aucune prime ni aucun avantage.
                    </p>
                    <ul v-else class="list-inside list-disc">
                        <li v-for="a in avantages" :key="a">{{ a }}</li>
                    </ul>
                </div>
                <p class="text-xs text-muted-foreground">
                    Le lieu de signature est laissé en blanc dans le PDF (« Fait
                    à …… ») : il s’écrit à la main au moment de la signature.
                </p>
            </section>

            <!-- Historique -->
            <section class="rounded-xl border bg-card">
                <div class="border-b px-5 py-3">
                    <h2 class="font-medium">Versions du PDF</h2>
                    <p class="text-xs text-muted-foreground">
                        Préparer le PDF ne vaut pas signature : une fois le
                        papier signé par les deux parties, cliquez sur « Marquer
                        comme signé ».
                    </p>
                </div>
                <p
                    v-if="!versions.length"
                    class="px-5 py-8 text-center text-sm text-muted-foreground"
                >
                    Aucun PDF pour le moment.
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
                                Préparée le {{ v.genere_le }}
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
                    par les deux parties. Cette version sera conservée telle
                    quelle : si le contrat change ensuite, une nouvelle version
                    à faire signer sera préparée.
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
