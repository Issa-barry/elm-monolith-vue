<script lang="ts">
export interface SavedView {
    id: string;
    name: string;
    visibility: 'personal' | 'shared';
    filters: Record<string, string | string[]>;
    owner_name: string;
    can_manage: boolean;
}

interface SavedViewsState {
    views: SavedView[];
    default_id: string | null;
    can_share: boolean;
}

// Partagé entre toutes les instances et les navigations Inertia : le menu s'ouvre sur la
// dernière liste connue pendant que le serveur est réinterrogé en arrière-plan.
const cache = new Map<string, SavedViewsState>();
const inflight = new Map<string, Promise<SavedViewsState>>();
</script>

<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Bookmark,
    ChevronDown,
    Pencil,
    Plus,
    Star,
    Trash2,
    X,
} from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';

const props = defineProps<{
    scope: string;
    hideAgenceSelector?: boolean;
    active?: SavedView | null;
    getFilters: () => Record<string, string | string[]>;
    describe: (filters: Record<string, string | string[]>) => string;
}>();
const emit = defineEmits<{ apply: [view: SavedView]; clear: [] }>();
const opened = ref(false);
const views = ref<SavedView[]>([]);
const defaultId = ref<string | null>(null);
const canShare = ref(false);
const loading = ref(false);
const busy = ref(false);
const error = ref('');
const dialogOpen = ref(false);
const mode = ref<'create' | 'edit' | 'delete'>('create');
const target = ref<SavedView | null>(null);
const name = ref('');
const visibility = ref<'personal' | 'shared'>('personal');
const isDefault = ref(false);
const dynamicAgency = ref(false);
const draft = ref<Record<string, string | string[]>>({});
const groups = computed(() => [
    {
        label: 'Mes vues personnelles',
        items: views.value.filter((v) => v.visibility === 'personal'),
    },
    {
        label: 'Vues partagées',
        items: views.value.filter((v) => v.visibility === 'shared'),
    },
]);
const endpoint = computed(
    () => `/backoffice/saved-filters/${encodeURIComponent(props.scope)}`,
);

async function request(path = '', method = 'GET', body?: unknown) {
    const response = await fetch(endpoint.value + path, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(
                document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)?.[1] ??
                    '',
            ),
        },
        ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
    });
    const data = await response.json().catch(() => null);
    if (!response.ok) {
        const firstError = Object.values(data?.errors ?? {}).flat()[0];
        throw new Error(
            typeof firstError === 'string'
                ? firstError
                : (data?.message ??
                  'Impossible de traiter cette vue. Réessayez.'),
        );
    }
    return data;
}

const loaded = ref(false);

function applyState(state: SavedViewsState) {
    views.value = state.views;
    defaultId.value = state.default_id;
    canShare.value = state.can_share;
    loaded.value = true;
}

async function load() {
    const cached = cache.get(props.scope);
    if (cached) applyState(cached);
    // « Chargement » seulement quand aucune liste n'est encore connue.
    loading.value = !loaded.value;
    error.value = '';
    try {
        let pending = inflight.get(props.scope);
        if (!pending) {
            pending = request() as Promise<SavedViewsState>;
            inflight.set(props.scope, pending);
        }
        const state = await pending;
        cache.set(props.scope, state);
        applyState(state);
    } catch (e) {
        error.value =
            e instanceof Error ? e.message : 'Impossible de charger les vues.';
    } finally {
        inflight.delete(props.scope);
        loading.value = false;
    }
}
onMounted(() => void load());
watch(opened, (value) => {
    if (value) void load();
});

async function startCreate() {
    if (!loaded.value) await load();
    if (error.value) {
        opened.value = true;
        return;
    }
    // Copie JSON : les critères contiennent des tableaux réactifs (Proxy, ex. site_ids) que structuredClone refuse.
    draft.value = JSON.parse(JSON.stringify(props.getFilters()));
    name.value = '';
    visibility.value = 'personal';
    isDefault.value = false;
    dynamicAgency.value = false;
    target.value = null;
    mode.value = 'create';
    opened.value = false;
    dialogOpen.value = true;
}
function manage(view: SavedView, action: 'edit' | 'delete') {
    target.value = view;
    name.value = view.name;
    visibility.value = view.visibility;
    mode.value = action;
    error.value = '';
    opened.value = false;
    dialogOpen.value = true;
}
async function setDefault(view: SavedView) {
    busy.value = true;
    error.value = '';
    try {
        const data = await request('/default', 'PUT', {
            id: defaultId.value === view.id ? null : view.id,
        });
        defaultId.value = data.default_id;
        const cached = cache.get(props.scope);
        if (cached)
            cache.set(props.scope, { ...cached, default_id: data.default_id });
    } catch (e) {
        error.value =
            e instanceof Error
                ? e.message
                : 'Impossible de modifier la vue par défaut.';
    } finally {
        busy.value = false;
    }
}
async function submit() {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    try {
        if (mode.value === 'delete' && target.value) {
            await request(`/${target.value.id}`, 'DELETE');
            if (props.active?.id === target.value.id) emit('clear');
        } else if (mode.value === 'edit' && target.value) {
            const view = await request(`/${target.value.id}`, 'PATCH', {
                name: name.value,
                visibility: visibility.value,
            });
            if (props.active?.id === view.id) emit('apply', view);
        } else {
            const filters = { ...draft.value };
            if (dynamicAgency.value) {
                delete filters.site_ids;
                filters.site_scope = 'mine';
            }
            const view = await request('', 'POST', {
                name: name.value,
                visibility: visibility.value,
                filters,
                is_default: isDefault.value,
            });
            emit('apply', view);
        }
        dialogOpen.value = false;
        void load();
    } catch (e) {
        error.value =
            e instanceof Error ? e.message : 'Impossible d’enregistrer la vue.';
    } finally {
        busy.value = false;
    }
}
defineExpose({ startCreate });
</script>

<template>
    <div class="flex min-w-0 flex-wrap items-center gap-2">
        <DropdownMenu v-model:open="opened">
            <DropdownMenuTrigger as-child>
                <Button variant="outline"
                    ><Bookmark class="mr-2 h-4 w-4" />Mes vues<ChevronDown
                        class="ml-2 h-4 w-4"
                /></Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="end"
                class="w-[min(24rem,calc(100vw-2rem))] p-2"
            >
                <p
                    v-if="loading"
                    class="p-3 text-sm text-muted-foreground"
                    role="status"
                >
                    Chargement des vues…
                </p>
                <div v-else class="max-h-[55vh] overflow-y-auto">
                    <p
                        v-if="!views.length"
                        class="p-3 text-sm text-muted-foreground"
                    >
                        Enregistrez vos critères pour les retrouver en un clic.
                    </p>
                    <section v-for="group in groups" :key="group.label">
                        <p
                            v-if="group.items.length"
                            class="px-2 py-2 text-xs font-semibold text-muted-foreground"
                        >
                            {{ group.label }}
                        </p>
                        <div
                            v-for="view in group.items"
                            :key="view.id"
                            class="flex items-center gap-1 rounded-md p-1 hover:bg-muted"
                        >
                            <button
                                class="min-w-0 flex-1 rounded p-2 text-left focus-visible:outline-2 focus-visible:outline-ring"
                                :aria-current="
                                    active?.id === view.id ? 'true' : undefined
                                "
                                @click="
                                    emit('apply', view);
                                    opened = false;
                                "
                            >
                                <span
                                    class="block truncate text-sm font-medium"
                                    >{{ view.name }}</span
                                >
                                <span
                                    class="mt-1 block text-xs text-muted-foreground"
                                    >{{ describe(view.filters) }}</span
                                >
                                <span
                                    v-if="view.visibility === 'shared'"
                                    class="block text-xs text-muted-foreground"
                                    >Par {{ view.owner_name }}</span
                                >
                            </button>
                            <Button
                                variant="ghost"
                                size="icon"
                                :disabled="busy"
                                :aria-label="
                                    defaultId === view.id
                                        ? `Retirer ${view.name} des vues par défaut`
                                        : `Définir ${view.name} comme vue par défaut`
                                "
                                :title="
                                    defaultId === view.id
                                        ? 'Ma vue par défaut'
                                        : 'Définir comme ma vue par défaut'
                                "
                                @click="setDefault(view)"
                                ><Star
                                    class="h-4 w-4"
                                    :class="
                                        defaultId === view.id
                                            ? 'fill-current text-primary'
                                            : 'text-muted-foreground'
                                    "
                            /></Button>
                            <template v-if="view.can_manage">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="`Renommer ${view.name}`"
                                    @click="manage(view, 'edit')"
                                    ><Pencil class="h-4 w-4"
                                /></Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="`Supprimer ${view.name}`"
                                    @click="manage(view, 'delete')"
                                    ><Trash2 class="h-4 w-4"
                                /></Button>
                            </template>
                        </div>
                    </section>
                </div>
                <p
                    v-if="error && !dialogOpen"
                    role="alert"
                    class="p-2 text-sm text-destructive"
                >
                    {{ error }}
                </p>
                <Button
                    variant="ghost"
                    class="mt-2 w-full justify-start border-t"
                    :disabled="loading"
                    @click="startCreate"
                    ><Plus class="mr-2 h-4 w-4" />Enregistrer la vue
                    actuelle</Button
                >
            </DropdownMenuContent>
        </DropdownMenu>
        <span
            v-if="active"
            class="inline-flex max-w-full items-center gap-1 rounded-md border bg-muted px-2 py-1 text-xs"
        >
            <span class="max-w-48 truncate" :title="active.name"
                >Vue : {{ active.name }}</span
            >
            <button
                class="rounded p-1 hover:bg-background"
                aria-label="Retirer la vue active"
                @click="emit('clear')"
            >
                <X class="h-3 w-3" />
            </button>
        </span>
    </div>
    <Dialog v-model:open="dialogOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{
                    mode === 'delete'
                        ? 'Supprimer la vue'
                        : mode === 'edit'
                          ? 'Modifier la vue'
                          : 'Enregistrer la vue'
                }}</DialogTitle>
                <DialogDescription>{{
                    mode === 'delete'
                        ? `Supprimer « ${name} » ? Les données de la liste seront conservées.`
                        : 'Retrouvez cette sélection sans reconfigurer vos filtres.'
                }}</DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="submit">
                <template v-if="mode !== 'delete'">
                    <div class="space-y-2">
                        <Label for="saved-view-name">Nom de la vue</Label
                        ><Input
                            id="saved-view-name"
                            v-model="name"
                            required
                            maxlength="80"
                            placeholder="Bouteilles — Mon agence"
                        />
                    </div>
                    <fieldset class="space-y-2">
                        <legend class="mb-2 text-sm font-medium">
                            Visibilité
                        </legend>
                        <label class="flex items-center gap-2 text-sm"
                            ><input
                                v-model="visibility"
                                type="radio"
                                value="personal"
                                name="view-visibility"
                            />Personnelle — uniquement moi</label
                        >
                        <label
                            v-if="canShare"
                            class="flex items-center gap-2 text-sm"
                            ><input
                                v-model="visibility"
                                type="radio"
                                value="shared"
                                name="view-visibility"
                            />Partagée — utilisateurs autorisés de
                            l’organisation</label
                        >
                    </fieldset>
                    <template v-if="mode === 'create'">
                        <p
                            class="rounded-md bg-muted p-3 text-sm text-muted-foreground"
                        >
                            {{
                                describe(draft) || 'Aucun critère sélectionné.'
                            }}
                        </p>
                        <label
                            v-if="!hideAgenceSelector"
                            class="flex items-start gap-2 text-sm"
                            ><input
                                v-model="dynamicAgency"
                                class="mt-1"
                                type="checkbox"
                            />Adapter la vue aux agences de chaque
                            utilisateur</label
                        >
                        <label class="flex items-center gap-2 text-sm"
                            ><input
                                v-model="isDefault"
                                type="checkbox"
                            />Définir comme ma vue par défaut</label
                        >
                    </template>
                </template>
                <p v-if="error" role="alert" class="text-sm text-destructive">
                    {{ error }}
                </p>
                <DialogFooter
                    ><Button
                        type="button"
                        variant="outline"
                        :disabled="busy"
                        @click="dialogOpen = false"
                        >Annuler</Button
                    ><Button
                        type="submit"
                        :variant="mode === 'delete' ? 'destructive' : 'default'"
                        :disabled="busy || (mode !== 'delete' && !name.trim())"
                        >{{
                            busy
                                ? 'En cours…'
                                : mode === 'delete'
                                  ? 'Supprimer'
                                  : 'Enregistrer'
                        }}</Button
                    ></DialogFooter
                >
            </form>
        </DialogContent>
    </Dialog>
</template>
