<script setup lang="ts">
import NavMainItem from '@/components/NavMainItem.vue';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
} from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { computed } from 'vue';

const props = defineProps<{
    items: NavItem[];
}>();

interface NavSection {
    label: string | null;
    items: NavItem[];
}

/**
 * Regroupe les items top-level par `group` en conservant l'ordre de première
 * apparition de chaque groupe (les items sans `group` restent dans une
 * section sans label en tête de liste).
 */
const sections = computed((): NavSection[] => {
    const sections: NavSection[] = [];
    const byKey = new Map<string, NavSection>();

    for (const item of props.items) {
        const key = item.group ?? '__root__';
        let section = byKey.get(key);
        if (!section) {
            section = { label: item.group ?? null, items: [] };
            byKey.set(key, section);
            sections.push(section);
        }
        section.items.push(item);
    }

    return sections;
});
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <template v-for="section in sections" :key="section.label ?? 'root'">
            <!-- Proportions reprises d'Apollo (.layout-menuitem-root-text, mesurées) :
                 titre 12 px / 700 / majuscules / couleur primaire sur un bloc de 42 px,
                 éléments collés (pas d'espace entre eux, cf. NavMainItem). -->
            <SidebarGroupLabel
                v-if="section.label"
                class="h-10.5 items-end pb-2 text-xs font-bold text-sidebar-primary uppercase group-data-[collapsible=icon]:-mt-10.5"
            >
                {{ section.label }}
            </SidebarGroupLabel>
            <SidebarMenu class="gap-0">
                <NavMainItem
                    v-for="item in section.items"
                    :key="item.title"
                    :item="item"
                />
            </SidebarMenu>
        </template>
    </SidebarGroup>
</template>
