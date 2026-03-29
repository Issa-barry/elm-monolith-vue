<script setup lang="ts">
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { usePermissions } from '@/composables/usePermissions';
import { Link } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    Eye,
    MoreVertical,
    Package,
    Pencil,
    Plus,
    Search,
    Trash2,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Produit {
    id: number;
    nom: string;
    code_interne: string | null;
    image_url: string | null;
    is_critique: boolean;
    statut: string;
    statut_label: string;
    qte_stock: number | null;
    in_stock: boolean;
    is_low_stock: boolean;
    has_stock: boolean;
}

const props = defineProps<{
    produits: Produit[];
    onDelete: (produit: Produit) => void;
}>();

const { can } = usePermissions();

const search = ref('');

const filteredProduits = computed(() => {
    const query = search.value.trim().toLowerCase();
    if (!query) return props.produits;
    return props.produits.filter((p) =>
        [p.nom, p.code_interne ?? ''].join(' ').toLowerCase().includes(query),
    );
});
</script>

<template>
    <div class="flex min-h-full flex-col bg-background">
        <!-- Header sticky style app native -->
        <div
            class="sticky top-0 z-20 border-b border-border/60 bg-background/95 backdrop-blur-sm"
        >
            <div
                class="relative flex items-center justify-center px-4 pt-4 pb-3"
            >
                <!-- Bouton retour -->
                <Link
                    href="/dashboard"
                    class="absolute left-4 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground transition-transform active:scale-95"
                >
                    <ArrowLeft class="h-4 w-4" />
                </Link>

                <!-- Titre centré -->
                <div class="text-center">
                    <h1 class="text-[17px] leading-tight font-semibold">
                        Produits
                    </h1>
                    <p class="text-[11px] text-muted-foreground">
                        {{ filteredProduits.length }} dans le catalogue
                    </p>
                </div>

                <!-- Bouton nouveau -->
                <Link
                    v-if="can('produits.create')"
                    href="/produits/create"
                    class="absolute right-4 shrink-0"
                >
                    <button
                        class="inline-flex items-center gap-1.5 rounded-full bg-primary px-3.5 py-2 text-xs font-semibold text-primary-foreground shadow-sm transition-transform active:scale-95"
                    >
                        <Plus class="h-3.5 w-3.5" />
                        Nouveau
                    </button>
                </Link>
            </div>

            <!-- Barre de recherche -->
            <div class="px-4 pb-3">
                <div class="relative flex items-center">
                    <Search
                        class="pointer-events-none absolute left-3 h-4 w-4 text-muted-foreground"
                    />
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Rechercher un produit..."
                        class="w-full rounded-xl border-0 bg-muted py-2.5 pr-4 pl-9 text-sm placeholder:text-muted-foreground/60 focus:ring-2 focus:ring-primary/30 focus:outline-none"
                    />
                </div>
            </div>
        </div>

        <!-- Liste -->
        <div v-if="filteredProduits.length" class="divide-y divide-border/50">
            <div
                v-for="data in filteredProduits"
                :key="data.id"
                class="flex items-center gap-3.5 px-4 py-3.5 transition-colors active:bg-muted/40"
            >
                <!-- Image -->
                <div
                    class="h-12 w-12 shrink-0 overflow-hidden rounded-xl border bg-muted shadow-sm"
                >
                    <img
                        v-if="data.image_url"
                        :src="data.image_url"
                        :alt="data.nom"
                        class="h-full w-full object-cover"
                    />
                    <div
                        v-else
                        class="flex h-full w-full items-center justify-center"
                    >
                        <Package class="h-5 w-5 text-muted-foreground/30" />
                    </div>
                </div>

                <!-- Infos -->
                <Link :href="`/produits/${data.id}`" class="min-w-0 flex-1">
                    <div class="flex items-center gap-1.5">
                        <p
                            class="truncate text-[13px] leading-tight font-semibold"
                        >
                            {{ data.nom }}
                        </p>
                        <AlertTriangle
                            v-if="data.is_critique"
                            class="h-3.5 w-3.5 shrink-0 text-amber-500"
                        />
                    </div>
                    <p
                        class="mt-0.5 font-mono text-[11px] text-muted-foreground"
                    >
                        {{ data.code_interne || '—' }}
                    </p>
                </Link>

                <!-- Actions -->
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <button
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-muted-foreground transition-colors active:bg-muted"
                        >
                            <MoreVertical class="h-4 w-4" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-44">
                        <DropdownMenuItem as-child>
                            <Link
                                :href="`/produits/${data.id}`"
                                class="flex w-full items-center gap-2"
                            >
                                <Eye class="h-4 w-4" />
                                Voir le détail
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuSeparator v-if="can('produits.update')" />
                        <DropdownMenuItem
                            v-if="can('produits.update')"
                            as-child
                        >
                            <Link
                                :href="`/produits/${data.id}/edit`"
                                class="flex w-full items-center gap-2"
                            >
                                <Pencil class="h-4 w-4" />
                                Modifier
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuSeparator
                            v-if="
                                can('produits.update') && can('produits.delete')
                            "
                        />
                        <DropdownMenuItem
                            v-if="can('produits.delete')"
                            class="cursor-pointer text-destructive focus:text-destructive"
                            @click="onDelete(data)"
                        >
                            <Trash2 class="h-4 w-4" />
                            Supprimer
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </div>

        <!-- Empty state -->
        <div
            v-else
            class="flex flex-col items-center gap-4 px-6 py-16 text-center"
        >
            <div
                class="flex h-16 w-16 items-center justify-center rounded-2xl bg-muted"
            >
                <Package class="h-8 w-8 text-muted-foreground/40" />
            </div>
            <div>
                <p class="font-medium text-foreground">Aucun produit</p>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{
                        search
                            ? 'Aucun résultat pour cette recherche.'
                            : 'Le catalogue est vide pour le moment.'
                    }}
                </p>
            </div>
            <Link
                v-if="can('produits.create') && !search"
                href="/produits/create"
            >
                <button
                    class="inline-flex items-center gap-2 rounded-full bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground shadow-sm transition-transform active:scale-95"
                >
                    <Plus class="h-4 w-4" />
                    Créer le premier produit
                </button>
            </Link>
        </div>
    </div>
</template>
