<script setup lang="ts">
/**
 * Wizard de changement de véhicule d'un livreur (transfert d'équipe) — un livreur
 * n'appartenant qu'à une seule équipe active à la fois (cf. EquipeLivraisonController),
 * "changer de véhicule" déplace le livreur de son équipe actuelle vers celle du véhicule
 * cible. Règle métier (décision AMOA, 02/09/2026) : le partage de commission par
 * catégorie doit être intégralement refait des deux côtés (départ ET arrivée), jamais
 * repris automatiquement — chaque écran de répartition démarre donc à 0.
 *
 * Un seul POST final (submit()) porte tout le transfert : rien n'est écrit en base tant
 * que les deux répartitions ne sont pas validées (cf. EquipeLivraisonController::transferer(),
 * transaction unique). Fermer le wizard en cours de route ne laisse donc aucune trace.
 */
import CommissionMontantFixeEditor, {
    type CommissionMontantFixeMembre,
} from '@/components/commission/CommissionMontantFixeEditor.vue';
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, Info } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import { computed, reactive, ref, watch } from 'vue';

const ROLES = [
    { value: 'chauffeur', label: 'Chauffeur' },
    { value: 'convoyeur', label: 'Convoyeur' },
];

interface CategorieAPartager {
    categorie_id: string;
    categorie_nom: string;
    enveloppe: number;
}

interface PartageProcessus {
    processus_code: string;
    processus_label: string;
    categories: CategorieAPartager[];
}

interface MembreRoster {
    livreur_id: string;
    nom_complet: string | null;
    role: string;
}

interface DonneesDepart {
    livreur: {
        id: string;
        nom_complet: string;
        role_actuel: string;
        taux_commission_logistique_actuel: number | null;
    };
    equipe_depart: {
        id: string;
        vehicule_id: string;
        vehicule_nom: string;
        vehicule_immatriculation: string;
        sera_dissoute: boolean;
        membres_restants: MembreRoster[];
        partages: PartageProcessus[];
    };
    vehicules_options: Array<{
        id: string;
        nom_vehicule: string;
        immatriculation: string;
        a_une_equipe: boolean;
        nb_membres: number;
    }>;
}

interface DonneesArrivee {
    nouvelle_equipe: boolean;
    equipe_id?: string;
    membres_actuels: MembreRoster[];
    partages: PartageProcessus[];
}

const props = defineProps<{
    visible: boolean;
    livreurId: string;
}>();

const emit = defineEmits<{
    'update:visible': [boolean];
}>();

// ── Chargement des données ──────────────────────────────────────────────────

const loading = ref(false);
const loadingArrivee = ref(false);
const serverErrors = reactive<Record<string, string>>({});

const donneesDepart = ref<DonneesDepart | null>(null);
const donneesArrivee = ref<DonneesArrivee | null>(null);
const vehiculeCibleId = ref<string | null>(null);
const role = ref<string>('');
const step = ref<'vehicule' | 'depart' | 'arrivee' | 'recap'>('vehicule');
const isSubmitting = ref(false);

async function fetchJson<T>(url: string): Promise<T | null> {
    try {
        const res = await fetch(url, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        if (!res.ok) return null;
        return (await res.json()) as T;
    } catch {
        return null;
    }
}

watch(
    () => props.visible,
    async (val) => {
        if (!val) return;
        step.value = 'vehicule';
        vehiculeCibleId.value = null;
        donneesArrivee.value = null;
        Object.keys(serverErrors).forEach((k) => delete serverErrors[k]);
        loading.value = true;
        donneesDepart.value = await fetchJson<DonneesDepart>(
            `/backoffice/equipes-livraison/transfert-livreur/${props.livreurId}`,
        );
        role.value = donneesDepart.value?.livreur.role_actuel ?? 'chauffeur';
        loading.value = false;
    },
);

async function onVehiculeCibleChange(id: string | null) {
    vehiculeCibleId.value = id;
    donneesArrivee.value = null;
    partagesArrivee.value = {};
    if (!id) return;

    loadingArrivee.value = true;
    donneesArrivee.value = await fetchJson<DonneesArrivee>(
        `/backoffice/equipes-livraison/transfert-livreur/${props.livreurId}/vehicule/${id}`,
    );
    loadingArrivee.value = false;
    if (donneesArrivee.value)
        initPartages(
            partagesArrivee,
            donneesArrivee.value.partages,
            arriveeRoster.value,
        );
}

const vehiculeCibleLabel = computed(() => {
    const v = donneesDepart.value?.vehicules_options.find(
        (v) => v.id === vehiculeCibleId.value,
    );
    return v ? `${v.nom_vehicule} (${v.immatriculation})` : '';
});

// ── Répartitions (départ / arrivée) ──────────────────────────────────────────
// Toujours initialisées à 0 : jamais de reprise automatique de l'ancien partage
// (règle métier explicite, cf. docblock ci-dessus).

const partagesDepart = ref<
    Record<string, Record<string, CommissionMontantFixeMembre[]>>
>({});
const partagesArrivee = ref<
    Record<string, Record<string, CommissionMontantFixeMembre[]>>
>({});

function rosterLabel(m: MembreRoster): string {
    return (
        m.nom_complet?.trim() ||
        (m.role === 'chauffeur' ? 'Chauffeur' : 'Convoyeur')
    );
}

function initPartages(
    target: typeof partagesDepart,
    groupes: PartageProcessus[],
    roster: MembreRoster[],
) {
    const result: Record<
        string,
        Record<string, CommissionMontantFixeMembre[]>
    > = {};
    for (const g of groupes) {
        result[g.processus_code] = {};
        for (const cat of g.categories) {
            result[g.processus_code][cat.categorie_id] = roster.map((m) => ({
                id: m.livreur_id,
                label: rosterLabel(m),
                montant_unitaire: 0,
            }));
        }
    }
    target.value = result;
}

watch(
    () => donneesDepart.value,
    (val) => {
        if (val && !val.equipe_depart.sera_dissoute) {
            initPartages(
                partagesDepart,
                val.equipe_depart.partages,
                val.equipe_depart.membres_restants,
            );
        } else {
            partagesDepart.value = {};
        }
    },
);

const arriveeRoster = computed<MembreRoster[]>(() => {
    if (!donneesArrivee.value || donneesArrivee.value.nouvelle_equipe)
        return [];
    const entrant: MembreRoster = {
        livreur_id: props.livreurId,
        nom_complet: donneesDepart.value?.livreur.nom_complet ?? null,
        role: role.value,
    };
    return [...donneesArrivee.value.membres_actuels, entrant];
});

// Les refs ne peuvent pas être passées en argument depuis le template (Vue les déballe
// automatiquement à l'affichage) — deux wrappers minces plutôt qu'une fonction générique.
function setPart(
    target: typeof partagesDepart,
    processusCode: string,
    categorieId: string,
    list: CommissionMontantFixeMembre[],
) {
    target.value = {
        ...target.value,
        [processusCode]: {
            ...target.value[processusCode],
            [categorieId]: list,
        },
    };
}

function updatePartDepart(
    processusCode: string,
    categorieId: string,
    list: CommissionMontantFixeMembre[],
) {
    setPart(partagesDepart, processusCode, categorieId, list);
}

function updatePartArrivee(
    processusCode: string,
    categorieId: string,
    list: CommissionMontantFixeMembre[],
) {
    setPart(partagesArrivee, processusCode, categorieId, list);
}

function sideComplete(
    target: typeof partagesDepart,
    groupes: PartageProcessus[] | undefined,
): boolean {
    if (!groupes || groupes.length === 0) return true;
    return groupes.every((g) =>
        g.categories.every((cat) => {
            const list =
                target.value[g.processus_code]?.[cat.categorie_id] ?? [];
            const total = list.reduce(
                (s, p) => s + (p.montant_unitaire || 0),
                0,
            );
            return total === cat.enveloppe;
        }),
    );
}

const departAFaire = computed(
    () =>
        !!donneesDepart.value &&
        !donneesDepart.value.equipe_depart.sera_dissoute &&
        donneesDepart.value.equipe_depart.partages.length > 0,
);
const arriveeAFaire = computed(
    () =>
        !!donneesArrivee.value &&
        !donneesArrivee.value.nouvelle_equipe &&
        donneesArrivee.value.partages.length > 0,
);

const departValide = computed(() =>
    sideComplete(partagesDepart, donneesDepart.value?.equipe_depart.partages),
);
const arriveeValide = computed(() =>
    sideComplete(partagesArrivee, donneesArrivee.value?.partages),
);

// ── Navigation ────────────────────────────────────────────────────────────

function goNext() {
    if (step.value === 'vehicule') {
        step.value = departAFaire.value
            ? 'depart'
            : arriveeAFaire.value
              ? 'arrivee'
              : 'recap';
    } else if (step.value === 'depart') {
        step.value = arriveeAFaire.value ? 'arrivee' : 'recap';
    } else if (step.value === 'arrivee') {
        step.value = 'recap';
    }
}

function goBack() {
    if (step.value === 'recap') {
        step.value = arriveeAFaire.value
            ? 'arrivee'
            : departAFaire.value
              ? 'depart'
              : 'vehicule';
    } else if (step.value === 'arrivee') {
        step.value = departAFaire.value ? 'depart' : 'vehicule';
    } else if (step.value === 'depart') {
        step.value = 'vehicule';
    }
}

const peutContinuerVehicule = computed(
    () =>
        !!vehiculeCibleId.value &&
        !loadingArrivee.value &&
        !!donneesArrivee.value,
);

// ── Soumission ────────────────────────────────────────────────────────────

function flatten(
    target: Record<string, Record<string, CommissionMontantFixeMembre[]>>,
    groupes: PartageProcessus[],
) {
    const out: Array<{
        processus_code: string;
        categorie_id: string;
        parts: Array<{ livreur_id: string; montant_unitaire: number }>;
    }> = [];
    for (const g of groupes) {
        for (const cat of g.categories) {
            const list = target[g.processus_code]?.[cat.categorie_id] ?? [];
            out.push({
                processus_code: g.processus_code,
                categorie_id: cat.categorie_id,
                parts: list.map((p) => ({
                    livreur_id: p.id,
                    montant_unitaire: p.montant_unitaire,
                })),
            });
        }
    }
    return out;
}

function submit() {
    if (isSubmitting.value || !vehiculeCibleId.value) return;
    isSubmitting.value = true;
    Object.keys(serverErrors).forEach((k) => delete serverErrors[k]);

    const payload = {
        nouveau_vehicule_id: vehiculeCibleId.value,
        role: role.value,
        partages_depart: departAFaire.value
            ? flatten(
                  partagesDepart.value,
                  donneesDepart.value!.equipe_depart.partages,
              )
            : [],
        partages_arrivee: arriveeAFaire.value
            ? flatten(partagesArrivee.value, donneesArrivee.value!.partages)
            : [],
    };

    router.post(
        `/backoffice/equipes-livraison/transfert-livreur/${props.livreurId}`,
        payload,
        {
            preserveScroll: true,
            onSuccess: () => {
                isSubmitting.value = false;
                emit('update:visible', false);
            },
            onError: (errors: Record<string, string>) => {
                isSubmitting.value = false;
                Object.assign(serverErrors, errors);
            },
        },
    );
}
</script>

<template>
    <Dialog
        :visible="visible"
        modal
        header="Changer de véhicule"
        :style="{ width: 'min(900px, 96vw)' }"
        :dismissable-mask="false"
        @update:visible="(val) => !val && emit('update:visible', false)"
    >
        <div
            v-if="loading"
            class="py-12 text-center text-sm text-muted-foreground"
        >
            Chargement…
        </div>

        <div v-else-if="donneesDepart" class="space-y-4">
            <div
                v-if="Object.keys(serverErrors).length > 0"
                class="rounded-lg border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive"
            >
                <p v-for="(msg, key) in serverErrors" :key="key">{{ msg }}</p>
            </div>

            <!-- ── Étape : véhicule cible ──────────────────────────────────── -->
            <div v-if="step === 'vehicule'" class="space-y-4">
                <p class="text-sm text-muted-foreground">
                    <span class="font-medium text-foreground">{{
                        donneesDepart.livreur.nom_complet
                    }}</span>
                    est actuellement sur
                    <span class="font-medium text-foreground">{{
                        donneesDepart.equipe_depart.vehicule_nom
                    }}</span>
                    ({{
                        donneesDepart.equipe_depart.vehicule_immatriculation
                    }}).
                </p>

                <div class="space-y-1.5">
                    <label class="text-sm font-medium">Nouveau véhicule</label>
                    <Dropdown
                        :model-value="vehiculeCibleId"
                        :options="donneesDepart.vehicules_options"
                        option-label="nom_vehicule"
                        option-value="id"
                        placeholder="Sélectionner un véhicule…"
                        class="w-full"
                        append-to="body"
                        @update:model-value="onVehiculeCibleChange"
                    >
                        <template #option="slotProps">
                            <div
                                class="flex items-center justify-between gap-2"
                            >
                                <span
                                    >{{ slotProps.option.nom_vehicule }} ({{
                                        slotProps.option.immatriculation
                                    }})</span
                                >
                                <span class="text-xs text-muted-foreground">
                                    {{
                                        slotProps.option.a_une_equipe
                                            ? `Équipe existante (${slotProps.option.nb_membres} membre${slotProps.option.nb_membres > 1 ? 's' : ''})`
                                            : 'Nouvelle équipe sera créée'
                                    }}
                                </span>
                            </div>
                        </template>
                    </Dropdown>
                    <p
                        v-if="loadingArrivee"
                        class="text-xs text-muted-foreground"
                    >
                        Chargement de l'équipe cible…
                    </p>
                </div>

                <div class="space-y-1.5">
                    <label class="text-sm font-medium"
                        >Rôle sur la nouvelle équipe</label
                    >
                    <Dropdown
                        v-model="role"
                        :options="ROLES"
                        option-label="label"
                        option-value="value"
                        class="w-full sm:w-64"
                        append-to="body"
                    />
                </div>

                <div
                    v-if="donneesDepart.equipe_depart.sera_dissoute"
                    class="flex items-start gap-3 rounded-lg border border-primary/15 bg-primary/5 px-4 py-3"
                >
                    <Info class="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                    <p class="text-xs leading-5 text-muted-foreground">
                        {{ donneesDepart.livreur.nom_complet }} est le dernier
                        membre de l'équipe de
                        {{ donneesDepart.equipe_depart.vehicule_nom }} : elle
                        sera dissoute et le véhicule désactivé.
                    </p>
                </div>
            </div>

            <!-- ── Étape : répartition équipe de départ ───────────────────── -->
            <div v-else-if="step === 'depart'" class="space-y-5">
                <div
                    class="flex items-start gap-3 rounded-lg border border-orange-500/20 bg-orange-500/5 px-4 py-3"
                >
                    <Info class="mt-0.5 h-4 w-4 shrink-0 text-orange-600" />
                    <p class="text-xs leading-5 text-muted-foreground">
                        {{ donneesDepart.livreur.nom_complet }} quitte l'équipe
                        de {{ donneesDepart.equipe_depart.vehicule_nom }} : la
                        répartition doit être refaite pour les membres restants,
                        catégorie par catégorie.
                    </p>
                </div>

                <div
                    v-for="g in donneesDepart.equipe_depart.partages"
                    :key="g.processus_code"
                    class="space-y-3"
                >
                    <p class="text-sm font-semibold text-foreground">
                        {{ g.processus_label }}
                    </p>
                    <div
                        v-for="cat in g.categories"
                        :key="cat.categorie_id"
                        class="space-y-1.5"
                    >
                        <p class="text-xs font-medium text-muted-foreground">
                            {{ cat.categorie_nom }}
                        </p>
                        <CommissionMontantFixeEditor
                            :model-value="
                                partagesDepart[g.processus_code]?.[
                                    cat.categorie_id
                                ] ?? []
                            "
                            :enveloppe-unitaire="cat.enveloppe"
                            @update:model-value="
                                (list) =>
                                    updatePartDepart(
                                        g.processus_code,
                                        cat.categorie_id,
                                        list,
                                    )
                            "
                        />
                    </div>
                </div>
            </div>

            <!-- ── Étape : répartition équipe d'arrivée ────────────────────── -->
            <div
                v-else-if="step === 'arrivee' && donneesArrivee"
                class="space-y-5"
            >
                <div
                    class="flex items-start gap-3 rounded-lg border border-primary/15 bg-primary/5 px-4 py-3"
                >
                    <Info class="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                    <p class="text-xs leading-5 text-muted-foreground">
                        {{ donneesDepart.livreur.nom_complet }} rejoint l'équipe
                        de {{ vehiculeCibleLabel }} : la répartition doit être
                        refaite en tenant compte de son arrivée.
                    </p>
                </div>

                <div
                    v-for="g in donneesArrivee.partages"
                    :key="g.processus_code"
                    class="space-y-3"
                >
                    <p class="text-sm font-semibold text-foreground">
                        {{ g.processus_label }}
                    </p>
                    <div
                        v-for="cat in g.categories"
                        :key="cat.categorie_id"
                        class="space-y-1.5"
                    >
                        <p class="text-xs font-medium text-muted-foreground">
                            {{ cat.categorie_nom }}
                        </p>
                        <CommissionMontantFixeEditor
                            :model-value="
                                partagesArrivee[g.processus_code]?.[
                                    cat.categorie_id
                                ] ?? []
                            "
                            :enveloppe-unitaire="cat.enveloppe"
                            @update:model-value="
                                (list) =>
                                    updatePartArrivee(
                                        g.processus_code,
                                        cat.categorie_id,
                                        list,
                                    )
                            "
                        />
                    </div>
                </div>
            </div>

            <!-- ── Étape : récapitulatif ───────────────────────────────────── -->
            <div v-else-if="step === 'recap'" class="space-y-3">
                <div class="rounded-lg border bg-muted/30 p-4 text-sm">
                    <p>
                        <span class="font-medium">{{
                            donneesDepart.livreur.nom_complet
                        }}</span>
                    </p>
                    <p
                        class="mt-1 flex items-center gap-2 text-muted-foreground"
                    >
                        {{ donneesDepart.equipe_depart.vehicule_nom }}
                        <ChevronRight class="h-3.5 w-3.5" />
                        <span class="font-medium text-foreground">{{
                            vehiculeCibleLabel
                        }}</span>
                    </p>
                    <p class="mt-2 text-xs text-muted-foreground">
                        Rôle :
                        <span class="font-medium text-foreground">{{
                            ROLES.find((r) => r.value === role)?.label
                        }}</span>
                    </p>
                    <p
                        v-if="donneesDepart.equipe_depart.sera_dissoute"
                        class="mt-2 text-xs text-orange-600"
                    >
                        L'équipe de
                        {{ donneesDepart.equipe_depart.vehicule_nom }} sera
                        dissoute (dernier membre).
                    </p>
                </div>
            </div>
        </div>

        <template #footer>
            <div class="flex w-full items-center justify-between">
                <Button
                    v-if="step !== 'vehicule'"
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="goBack"
                >
                    <ChevronLeft class="mr-1 h-4 w-4" />
                    Retour
                </Button>
                <span v-else></span>

                <Button
                    v-if="step === 'vehicule'"
                    type="button"
                    size="sm"
                    :disabled="!peutContinuerVehicule"
                    @click="goNext"
                >
                    Suivant
                    <ChevronRight class="ml-1 h-4 w-4" />
                </Button>
                <Button
                    v-else-if="step === 'depart'"
                    type="button"
                    size="sm"
                    :disabled="!departValide"
                    @click="goNext"
                >
                    Suivant
                    <ChevronRight class="ml-1 h-4 w-4" />
                </Button>
                <Button
                    v-else-if="step === 'arrivee'"
                    type="button"
                    size="sm"
                    :disabled="!arriveeValide"
                    @click="goNext"
                >
                    Suivant
                    <ChevronRight class="ml-1 h-4 w-4" />
                </Button>
                <Button
                    v-else
                    type="button"
                    size="sm"
                    :disabled="isSubmitting"
                    @click="submit"
                >
                    {{
                        isSubmitting
                            ? 'Enregistrement…'
                            : 'Valider le changement'
                    }}
                </Button>
            </div>
        </template>
    </Dialog>
</template>
