<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { router } from '@inertiajs/vue3';
import { Loader2 } from 'lucide-vue-next';
import InputNumber from 'primevue/inputnumber';
import { computed, onMounted, ref } from 'vue';

/**
 * Saisie en place de la part du parrain, dans l'onglet Parrain (PARRAIN-024) : une part par
 * partage où il est rémunéré (processus × catégorie), sans fenêtre ni stepper.
 *  - un seul livreur : il reçoit le reste du barème, rien à refaire ;
 *  - plusieurs livreurs : leurs montants ne changent pas, la répartition est à refaire dans
 *    l'onglet Équipe et les ventes concernées sont refusées d'ici là ; une date passée reste en
 *    attente et s'appliquera à cette répartition (recalcul confirmé dans la modale Équipe) ;
 *  - une part à 0 GNF se confirme explicitement (PARRAIN-022) ;
 *  - date de début des droits passée ou future (PARRAIN-023), au plus tôt le lendemain de la
 *    dernière période clôturée.
 * Le serveur refait tous ces contrôles.
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
    vehiculeId: string;
    /** Le véhicule fait de la logistique : ligne « jamais rémunéré » affichée pour mémoire. */
    transfertsLabel?: string | null;
}>();

const emit = defineEmits<{ fermer: [] }>();

const groupes = ref<Groupe[]>([]);
const parts = ref<Record<string, number>>({});
const alertes = ref<Record<string, string | null>>({});
const parrainId = ref<string | null>(null);
const chargement = ref(true);
const enregistrement = ref(false);
const erreur = ref<string | null>(null);
const confirmerPartNulle = ref(false);

function aujourdhuiIso(): string {
    const d = new Date();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const jj = String(d.getDate()).padStart(2, '0');
    return `${d.getFullYear()}-${mm}-${jj}`;
}
/** Remplacé au chargement par « aujourd'hui » du serveur (fuseau de l'application). */
const aujourdhui = ref(aujourdhuiIso());
const dateEffet = ref(aujourdhui.value);
const dateEffetMin = ref<string | null>(null);
const planification = ref<string | null>(null);
const remplacerPlanification = ref(false);

const enFr = (iso: string) => iso.split('-').reverse().join('/');
const dateEffetPassee = computed(
    () => !!dateEffet.value && dateEffet.value < aujourdhui.value,
);
const dateEffetFuture = computed(
    () => !!dateEffet.value && dateEffet.value > aujourdhui.value,
);
const dateEffetValide = computed(
    () =>
        !!dateEffet.value &&
        (!dateEffetMin.value || dateEffet.value >= dateEffetMin.value),
);

const cle = (g: Groupe) => `${g.processus_code}|${g.categorie_id}`;
const formatGNF = (v: number) =>
    `${new Intl.NumberFormat('fr-FR').format(v)} GNF`;

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
        if (body.aujourdhui) {
            aujourdhui.value = body.aujourdhui;
            dateEffet.value = body.aujourdhui;
        }
        groupes.value = body.groupes as Groupe[];
        parts.value = Object.fromEntries(
            groupes.value.map((g) => [cle(g), g.part_parrain_actuelle]),
        );
    } catch {
        erreur.value =
            'Impossible de charger les partages du parrain. Rafraîchissez la page.';
    } finally {
        chargement.value = false;
    }
}

onMounted(() => void charger());

/** Part entière entre 0 et le barème ; un dépassement est ramené au barème et signalé. */
function saisir(g: Groupe, valeur: number | null) {
    const v = Math.max(0, Math.round(valeur ?? 0));
    if (v > g.bareme) {
        alertes.value[cle(g)] =
            `La part ne peut pas dépasser ${formatGNF(g.bareme)}. Elle a été ramenée à ${formatGNF(g.bareme)}.`;
        parts.value[cle(g)] = g.bareme;
        return;
    }
    if (v < g.bareme) alertes.value[cle(g)] = null;
    parts.value[cle(g)] = v;
}

const unSeulLivreur = (g: Groupe) => g.membres.length === 1;
const totalLivreurs = (g: Groupe) =>
    g.membres.reduce((s, m) => s + (m.montant_actuel || 0), 0);
/** Plusieurs livreurs dont l'enveloppe ne tombe plus juste : répartition à refaire dans Équipe. */
const aRepartirDansEquipe = (g: Groupe) =>
    !unSeulLivreur(g) &&
    totalLivreurs(g) + (parts.value[cle(g)] ?? 0) !== g.bareme;

const groupesARepartir = computed(() =>
    groupes.value.filter(aRepartirDansEquipe),
);
const groupesPartNulle = computed(() =>
    groupes.value.filter((g) => (parts.value[cle(g)] ?? 0) === 0),
);
const modifie = computed(() =>
    groupes.value.some(
        (g) => (parts.value[cle(g)] ?? 0) !== g.part_parrain_actuelle,
    ),
);
/** Une répartition à refaire ne se planifie pas : pas de date future. */
const dateIncompatible = computed(
    () =>
        groupesARepartir.value.length > 0 && dateEffet.value > aujourdhui.value,
);

const peutEnregistrer = computed(
    () =>
        groupes.value.length > 0 &&
        (groupesPartNulle.value.length === 0 || confirmerPartNulle.value) &&
        dateEffetValide.value &&
        !dateIncompatible.value &&
        (!planification.value || remplacerPlanification.value) &&
        !chargement.value &&
        !enregistrement.value,
);

function annuler() {
    if (enregistrement.value) return;
    emit('fermer');
}

function enregistrer() {
    if (!peutEnregistrer.value) return;
    router.put(
        `/backoffice/vehicules/${props.vehiculeId}/parrain/part`,
        {
            parrain_id: parrainId.value,
            confirmer_part_nulle:
                groupesPartNulle.value.length > 0 && confirmerPartNulle.value,
            date_effet: dateEffet.value,
            remplacer_planification: remplacerPlanification.value,
            parts: groupes.value.map((g) => ({
                processus_code: g.processus_code,
                categorie_id: g.categorie_id,
                part_parrain: parts.value[cle(g)] ?? 0,
            })),
        },
        {
            preserveScroll: true,
            onStart: () => {
                enregistrement.value = true;
                erreur.value = null;
            },
            onFinish: () => (enregistrement.value = false),
            onSuccess: () => emit('fermer'),
            onError: (errors) => {
                erreur.value =
                    errors.parts ??
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

defineExpose({ peutEnregistrer, modifie });
</script>

<template>
    <div data-testid="part-parrain-edition">
        <p v-if="chargement" class="px-4 py-4 text-sm text-muted-foreground">
            Chargement des partages…
        </p>
        <p
            v-else-if="!erreur && groupes.length === 0"
            class="px-4 py-4 text-sm text-muted-foreground"
        >
            Aucun partage où saisir la part du parrain : configurez d'abord
            l'équipe du véhicule.
        </p>

        <div v-else-if="groupes.length > 0" class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr
                        class="border-b text-left text-xs text-muted-foreground"
                    >
                        <th class="px-4 py-2 font-medium">Processus</th>
                        <th class="px-4 py-2 font-medium">Catégorie</th>
                        <th class="px-4 py-2 text-right font-medium">
                            Barème livreurs
                        </th>
                        <th class="px-4 py-2 text-right font-medium">
                            Part du parrain (GNF / pack)
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="g in groupes"
                        :key="cle(g)"
                        class="align-top"
                        :data-testid="`part-parrain-ligne-${g.processus_code}`"
                    >
                        <td class="px-4 py-2.5">{{ g.processus_label }}</td>
                        <td class="px-4 py-2.5">{{ g.categorie_nom }}</td>
                        <td
                            class="px-4 py-2.5 text-right font-mono tabular-nums"
                        >
                            {{ formatGNF(g.bareme) }}
                        </td>
                        <td class="px-4 py-2 text-right">
                            <InputNumber
                                :model-value="parts[cle(g)] ?? 0"
                                :min="0"
                                :max-fraction-digits="0"
                                :disabled="enregistrement"
                                class="w-32"
                                :data-testid="`part-parrain-input-${g.processus_code}`"
                                :input-style="{
                                    textAlign: 'right',
                                    width: '100%',
                                }"
                                @input="
                                    saisir(g, $event.value as number | null)
                                "
                                @update:model-value="saisir(g, $event)"
                            />
                            <p
                                v-if="unSeulLivreur(g)"
                                class="mt-1 text-[11px] text-muted-foreground"
                                :data-testid="`part-parrain-livreur-${g.processus_code}`"
                            >
                                Livreur :
                                {{ formatGNF(g.bareme - (parts[cle(g)] ?? 0)) }}
                                (calculé)
                            </p>
                            <p
                                v-else-if="aRepartirDansEquipe(g)"
                                class="mt-1 text-[11px] font-medium text-orange-600"
                                :data-testid="`part-parrain-a-repartir-${g.processus_code}`"
                            >
                                {{ g.membres.length }} livreurs : répartition à
                                refaire dans l'onglet Équipe
                            </p>
                            <p
                                v-else
                                class="mt-1 text-[11px] text-muted-foreground"
                            >
                                Répartition des livreurs inchangée
                            </p>
                            <p
                                v-if="alertes[cle(g)]"
                                class="mt-1 text-[11px] font-medium text-orange-600"
                                :data-testid="`part-parrain-alerte-${g.processus_code}`"
                            >
                                {{ alertes[cle(g)] }}
                            </p>
                        </td>
                    </tr>
                    <tr v-if="transfertsLabel" class="text-muted-foreground">
                        <td class="px-4 py-2.5">{{ transfertsLabel }}</td>
                        <td class="px-4 py-2.5">—</td>
                        <td></td>
                        <td class="px-4 py-2.5 text-right text-xs">
                            Jamais rémunéré (transferts entre sites)
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="!chargement && groupes.length > 0"
            class="space-y-3 border-t px-4 py-3"
        >
            <div
                class="flex flex-wrap items-end gap-3"
                data-testid="part-parrain-date-effet"
            >
                <div class="space-y-1">
                    <label
                        for="part-parrain-date-effet"
                        class="text-xs font-medium"
                        >Date de début des droits</label
                    >
                    <input
                        id="part-parrain-date-effet"
                        v-model="dateEffet"
                        type="date"
                        :min="dateEffetMin ?? undefined"
                        :disabled="enregistrement"
                        class="block h-9 rounded-md border bg-background px-3 text-sm"
                        data-testid="part-parrain-date-effet-input"
                    />
                </div>
                <p
                    v-if="!dateEffetValide && dateEffetMin"
                    class="text-xs text-destructive"
                >
                    Au plus tôt le {{ enFr(dateEffetMin) }} : une période de
                    paiement antérieure est clôturée.
                </p>
                <p
                    v-else-if="dateIncompatible"
                    class="text-xs text-destructive"
                    data-testid="part-parrain-date-incompatible"
                >
                    Une répartition à refaire dans l'onglet Équipe ne se
                    planifie pas : choisissez la date du jour ou une date
                    passée.
                </p>
                <p
                    v-else-if="dateEffetPassee && groupesARepartir.length > 0"
                    class="max-w-xl text-xs text-amber-800 dark:text-amber-300"
                    data-testid="part-parrain-date-en-attente"
                >
                    Date passée : elle s'appliquera à la répartition faite dans
                    l'onglet Équipe, qui recalculera alors les commissions non
                    payées des commandes livrées depuis le
                    {{ enFr(dateEffet) }} (confirmation demandée). Aucun
                    recalcul d'ici là.
                </p>
                <p
                    v-else-if="dateEffetPassee"
                    class="max-w-xl text-xs text-amber-800 dark:text-amber-300"
                    data-testid="part-parrain-date-passee"
                >
                    Date passée : les commissions non payées des commandes
                    livrées depuis le {{ enFr(dateEffet) }} seront recalculées
                    automatiquement. Les périodes déjà validées sont rouvertes
                    pour revalidation.
                </p>
                <p
                    v-else-if="dateEffetFuture"
                    class="text-xs text-muted-foreground"
                >
                    Prendra effet le {{ enFr(dateEffet) }} ; rien ne change
                    avant cette date.
                </p>
            </div>

            <p
                v-if="groupesARepartir.length > 0"
                class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/20 dark:text-amber-200"
                data-testid="part-parrain-avertissement-equipe"
            >
                Plusieurs livreurs se partagent
                {{
                    groupesARepartir
                        .map((g) => `${g.processus_label} · ${g.categorie_nom}`)
                        .join(', ')
                }}
                : leurs montants ne sont pas modifiés d'office. Le partage
                passera « À faire » et les ventes concernées seront refusées
                jusqu'à la nouvelle répartition dans l'onglet Équipe.
            </p>

            <label
                v-if="planification"
                class="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs dark:border-amber-900/50 dark:bg-amber-950/20"
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
                    <span class="font-semibold">{{ enFr(planification) }}</span
                    >. Remplacer cette planification par les parts ci-dessus.
                </span>
            </label>

            <label
                v-if="groupesPartNulle.length > 0"
                class="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs dark:border-amber-900/50 dark:bg-amber-950/20"
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
        </div>

        <p
            v-if="erreur"
            class="mx-4 mb-3 rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2 text-xs text-destructive"
            data-testid="part-parrain-erreur"
        >
            {{ erreur }}
        </p>

        <div class="flex flex-wrap justify-end gap-2 border-t px-4 py-3">
            <Button
                variant="outline"
                size="sm"
                :disabled="enregistrement"
                data-testid="part-parrain-annuler-btn"
                @click="annuler"
                >Annuler</Button
            >
            <Button
                size="sm"
                :disabled="!peutEnregistrer"
                data-testid="part-parrain-enregistrer-btn"
                @click="enregistrer"
            >
                <Loader2
                    v-if="enregistrement"
                    class="mr-1.5 h-4 w-4 animate-spin"
                />
                {{ enregistrement ? 'Enregistrement…' : 'Enregistrer' }}
            </Button>
        </div>
    </div>
</template>
