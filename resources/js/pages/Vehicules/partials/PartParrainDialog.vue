<script setup lang="ts">
import CommissionMontantFixeEditor, {
    type CommissionMontantFixeMembre,
} from '@/components/commission/CommissionMontantFixeEditor.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { router } from '@inertiajs/vue3';
import { Loader2 } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import { computed, ref, watch } from 'vue';

/**
 * Grille « Définir la part du parrain » (PARRAIN-021) : tous les partages où le parrain est
 * rémunéré (processus × catégorie), enregistrés en une fois avec les contrôles serveur du
 * changement de parrain. Une part entièrement nulle doit être confirmée explicitement.
 */
interface Groupe {
    processus_code: string;
    processus_label: string;
    categorie_id: string;
    categorie_nom: string;
    bareme: number;
    part_parrain_actuelle: number;
    membres: Array<{
        livreur_id: string;
        nom_complet: string | null;
        role: string;
        montant_actuel: number;
    }>;
}

const props = defineProps<{
    visible: boolean;
    vehiculeId: string;
    parrainNom: string;
}>();

const emit = defineEmits<{ 'update:visible': [boolean] }>();

const groupes = ref<Groupe[]>([]);
const repartitions = ref<
    Record<
        string,
        { membres: CommissionMontantFixeMembre[]; partParrain: number }
    >
>({});
const parrainId = ref<string | null>(null);
const chargement = ref(false);
const enregistrement = ref(false);
const erreur = ref<string | null>(null);
const confirmerPartNulle = ref(false);

// ── Date de début des droits (PARRAIN-023) ──
function aujourdhuiIso(): string {
    const d = new Date();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const jj = String(d.getDate()).padStart(2, '0');
    return `${d.getFullYear()}-${mm}-${jj}`;
}
const aujourdhui = aujourdhuiIso();
const dateEffet = ref(aujourdhui);
/** Au plus tôt le lendemain de la dernière période clôturée (null : aucune limite). */
const dateEffetMin = ref<string | null>(null);
/** Répartition déjà planifiée (date future) : son remplacement doit être confirmé. */
const planification = ref<string | null>(null);
const remplacerPlanification = ref(false);
const planificationFr = computed(() =>
    planification.value
        ? planification.value.split('-').reverse().join('/')
        : '',
);
const dateEffetFr = computed(() => {
    const [a, m, j] = dateEffet.value.split('-').map(Number);
    return new Date(a, m - 1, j).toLocaleDateString('fr-FR');
});
const dateEffetPassee = computed(
    () => !!dateEffet.value && dateEffet.value < aujourdhui,
);
const dateEffetFuture = computed(
    () => !!dateEffet.value && dateEffet.value > aujourdhui,
);
const dateEffetValide = computed(
    () =>
        !!dateEffet.value &&
        (!dateEffetMin.value || dateEffet.value >= dateEffetMin.value),
);

const cle = (g: Groupe) => `${g.processus_code}|${g.categorie_id}`;

function libelleMembre(m: Groupe['membres'][number]): string {
    const role = m.role === 'chauffeur' ? 'Chauffeur' : 'Convoyeur';
    return m.nom_complet ? `${role} — ${m.nom_complet}` : role;
}

async function charger() {
    chargement.value = true;
    erreur.value = null;
    try {
        const response = await fetch(
            `/backoffice/vehicules/${props.vehiculeId}/parrain/part`,
            { headers: { Accept: 'application/json' } },
        );
        if (!response.ok) {
            erreur.value =
                'Impossible de charger les partages du parrain. Rafraîchissez la page.';
            return;
        }
        const body = await response.json();
        parrainId.value = body.parrain_id;
        dateEffetMin.value = body.date_effet_min ?? null;
        planification.value = body.planification ?? null;
        groupes.value = body.groupes as Groupe[];
        repartitions.value = Object.fromEntries(
            groupes.value.map((g) => [
                cle(g),
                {
                    membres: g.membres.map((m) => ({
                        id: m.livreur_id,
                        label: libelleMembre(m),
                        montant_unitaire: m.montant_actuel,
                    })),
                    partParrain: g.part_parrain_actuelle,
                },
            ]),
        );
    } catch {
        erreur.value =
            'Impossible de charger les partages du parrain. Rafraîchissez la page.';
    } finally {
        chargement.value = false;
    }
}

watch(
    () => props.visible,
    (visible) => {
        if (!visible) return;
        groupes.value = [];
        repartitions.value = {};
        confirmerPartNulle.value = false;
        dateEffet.value = aujourdhui;
        remplacerPlanification.value = false;
        void charger();
    },
    { immediate: true },
);

function total(g: Groupe): number {
    const r = repartitions.value[cle(g)];
    if (!r) return 0;
    return (
        r.membres.reduce((s, m) => s + (m.montant_unitaire || 0), 0) +
        (r.partParrain || 0)
    );
}

const equilibrees = computed(() =>
    groupes.value.every((g) => total(g) === g.bareme),
);
/** PARRAIN-022 : partages où la part du parrain reste à 0 GNF — à confirmer explicitement. */
const groupesPartNulle = computed(() =>
    groupes.value.filter(
        (g) => (repartitions.value[cle(g)]?.partParrain || 0) === 0,
    ),
);
const partNulle = computed(() => groupesPartNulle.value.length > 0);
const peutEnregistrer = computed(
    () =>
        groupes.value.length > 0 &&
        equilibrees.value &&
        (!partNulle.value || confirmerPartNulle.value) &&
        dateEffetValide.value &&
        (!planification.value || remplacerPlanification.value) &&
        !chargement.value &&
        !enregistrement.value,
);

function fermer(valeur: boolean) {
    // Fermeture bloquée pendant l'enregistrement.
    if (!valeur && enregistrement.value) return;
    emit('update:visible', valeur);
}

function enregistrer() {
    if (!peutEnregistrer.value) return;
    router.put(
        `/backoffice/vehicules/${props.vehiculeId}/parrain/part`,
        {
            parrain_id: parrainId.value,
            confirmer_part_nulle: partNulle.value && confirmerPartNulle.value,
            date_effet: dateEffet.value,
            remplacer_planification: remplacerPlanification.value,
            repartitions: groupes.value.map((g) => {
                const r = repartitions.value[cle(g)];
                return {
                    processus_code: g.processus_code,
                    categorie_id: g.categorie_id,
                    parts: r.membres.map((m) => ({
                        livreur_id: m.id,
                        montant_unitaire: m.montant_unitaire || 0,
                    })),
                    part_parrain: r.partParrain || 0,
                };
            }),
        },
        {
            preserveScroll: true,
            onStart: () => {
                enregistrement.value = true;
                erreur.value = null;
            },
            onFinish: () => (enregistrement.value = false),
            onSuccess: () => emit('update:visible', false),
            onError: (errors) => {
                erreur.value =
                    errors.repartitions ??
                    errors.remplacer_planification ??
                    errors.date_effet ??
                    errors.confirmer_part_nulle ??
                    Object.values(errors)[0] ??
                    null;
            },
        },
    );
}
</script>

<template>
    <Dialog
        :visible="visible"
        modal
        header="Part du parrain"
        :style="{ width: '34rem' }"
        :draggable="false"
        :closable="!enregistrement"
        @update:visible="fermer"
    >
        <div class="space-y-4" data-testid="part-parrain-dialog">
            <p class="text-sm text-muted-foreground">
                Répartissez chaque enveloppe entre l'équipe et
                <span class="font-medium text-foreground">{{ parrainNom }}</span
                >. Sa part est prise sur l'enveloppe des livreurs : le total
                reste égal au barème. Effet aujourd'hui, les commissions déjà
                calculées ne changent pas.
            </p>

            <label
                v-if="planification"
                class="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2.5 text-sm dark:border-amber-900/50 dark:bg-amber-950/20"
                data-testid="part-parrain-remplacer-planification"
            >
                <Checkbox
                    :model-value="remplacerPlanification"
                    :disabled="enregistrement"
                    class="mt-0.5"
                    @update:model-value="
                        (v) => (remplacerPlanification = v === true)
                    "
                />
                <span>
                    Une répartition est déjà planifiée à partir du
                    <span class="font-semibold">{{ planificationFr }}</span
                    >. Remplacer cette planification par la répartition
                    ci-dessous.
                </span>
            </label>

            <div class="space-y-1.5" data-testid="part-parrain-date-effet">
                <label for="part-parrain-date-effet" class="text-sm font-medium"
                    >Date de début des droits</label
                >
                <input
                    id="part-parrain-date-effet"
                    v-model="dateEffet"
                    type="date"
                    :min="dateEffetMin ?? undefined"
                    :disabled="enregistrement"
                    class="h-9 w-full rounded-md border bg-background px-3 text-sm sm:w-48"
                    data-testid="part-parrain-date-effet-input"
                />
                <p
                    v-if="!dateEffetValide && dateEffetMin"
                    class="text-xs text-destructive"
                >
                    Au plus tôt le
                    {{ new Date(dateEffetMin).toLocaleDateString('fr-FR') }}
                    : une période de paiement antérieure est clôturée.
                </p>
                <p
                    v-else-if="dateEffetPassee"
                    class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/20 dark:text-amber-200"
                    data-testid="part-parrain-date-passee"
                >
                    Date passée : les commissions non payées des commandes
                    livrées depuis le {{ dateEffetFr }} seront recalculées
                    automatiquement avec cette répartition et l'équipe actuelle
                    — la part du parrain y est prise sur celle des livreurs,
                    ajustements manuels compris. Les périodes déjà validées sont
                    rouvertes pour revalidation.
                </p>
                <p
                    v-else-if="dateEffetFuture"
                    class="text-xs text-muted-foreground"
                >
                    La répartition prendra effet le {{ dateEffetFr }} ; rien ne
                    change avant cette date.
                </p>
            </div>

            <p v-if="chargement" class="text-sm text-muted-foreground">
                Chargement des partages…
            </p>

            <p
                v-else-if="!erreur && groupes.length === 0"
                class="rounded-md border border-dashed px-3 py-4 text-sm text-muted-foreground"
            >
                Aucun partage où répartir la part du parrain : configurez
                d'abord l'équipe du véhicule.
            </p>

            <div
                v-for="g in groupes"
                :key="cle(g)"
                class="space-y-2"
                :data-testid="`part-parrain-${g.processus_code}`"
            >
                <p class="text-sm font-semibold">
                    {{ g.processus_label }} · {{ g.categorie_nom }}
                </p>
                <CommissionMontantFixeEditor
                    v-if="repartitions[cle(g)]"
                    :model-value="repartitions[cle(g)].membres"
                    :enveloppe-unitaire="g.bareme"
                    :parrain="{ nom: parrainNom }"
                    :part-parrain="repartitions[cle(g)].partParrain"
                    :readonly="enregistrement"
                    @update:model-value="repartitions[cle(g)].membres = $event"
                    @update:part-parrain="
                        repartitions[cle(g)].partParrain = $event
                    "
                />
            </div>

            <label
                v-if="partNulle && equilibrees"
                class="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2.5 text-sm dark:border-amber-900/50 dark:bg-amber-950/20"
                data-testid="part-parrain-confirmer-nulle"
            >
                <Checkbox
                    :model-value="confirmerPartNulle"
                    :disabled="enregistrement"
                    class="mt-0.5"
                    @update:model-value="
                        (v) => (confirmerPartNulle = v === true)
                    "
                />
                <span>
                    Le parrain ne reçoit
                    <span class="font-semibold">aucune part</span> pour le
                    moment sur :
                    {{
                        groupesPartNulle
                            .map(
                                (g) =>
                                    `${g.processus_label} · ${g.categorie_nom}`,
                            )
                            .join(', ')
                    }}. Sans cette confirmation, ces partages restent « À
                    répartir ».
                </span>
            </label>

            <p
                v-if="erreur"
                class="rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2 text-xs text-destructive"
                data-testid="part-parrain-erreur"
            >
                {{ erreur }}
            </p>
        </div>

        <template #footer>
            <div class="flex w-full flex-wrap items-center justify-end gap-2">
                <p
                    v-if="!equilibrees && groupes.length > 0"
                    class="mr-auto text-xs text-orange-600"
                    data-testid="part-parrain-indication"
                >
                    Équilibrez chaque enveloppe pour enregistrer.
                </p>
                <p
                    v-else-if="partNulle && !confirmerPartNulle"
                    class="mr-auto text-xs text-amber-700 dark:text-amber-300"
                    data-testid="part-parrain-indication"
                >
                    Part nulle : cochez la confirmation pour enregistrer.
                </p>
                <Button
                    variant="outline"
                    :disabled="enregistrement"
                    @click="fermer(false)"
                    >Annuler</Button
                >
                <Button
                    data-testid="part-parrain-enregistrer-btn"
                    :disabled="!peutEnregistrer"
                    @click="enregistrer"
                >
                    <Loader2
                        v-if="enregistrement"
                        class="mr-1.5 h-4 w-4 animate-spin"
                    />
                    {{ enregistrement ? 'Enregistrement…' : 'Enregistrer' }}
                </Button>
            </div>
        </template>
    </Dialog>
</template>
