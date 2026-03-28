<script setup lang="ts">
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuAction,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { useSidebar } from '@/components/ui/sidebar/utils';
import { toUrl } from '@/lib/utils';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronDown } from 'lucide-vue-next';
import { reactive } from 'vue';

const props = defineProps<{
    items: NavItem[];
}>();

const page = usePage();
const { isMobile, setOpenMobile } = useSidebar();

function closeMobileSidebar() {
    if (isMobile.value) setOpenMobile(false);
}
const openMenus = reactive<Record<string, boolean>>({});

function isItemActive(href: NavItem['href']) {
    const url = toUrl(href);
    if (!url) return false;

    return page.url === url || page.url.startsWith(`${url}/`) || page.url.startsWith(`${url}?`);
}

function isParentActive(item: NavItem) {
    return isItemActive(item.href) || !!item.items?.some((subItem) => isItemActive(subItem.href));
}

function menuKey(item: NavItem) {
    return `${item.title}:${toUrl(item.href) ?? ''}`;
}

function isMenuOpen(item: NavItem) {
    const key = menuKey(item);
    if (!(key in openMenus)) {
        openMenus[key] = isParentActive(item);
    }

    return openMenus[key];
}

function toggleMenu(item: NavItem) {
    const key = menuKey(item);
    openMenus[key] = !isMenuOpen(item);
}
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel>Platform</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in props.items" :key="item.title">
                <SidebarMenuButton
                    v-if="!item.items?.length"
                    as-child
                    :is-active="isItemActive(item.href)"
                    :tooltip="item.title"
                >
                    <Link :href="item.href" @click="closeMobileSidebar">
                        <component
                            v-if="item.icon"
                            :is="item.icon"
                            class="text-sidebar-primary"
                        />
                        <span>{{ item.title }}</span>
                        <span
                            v-if="item.badge"
                            class="ml-auto flex h-5 min-w-5 items-center justify-center rounded-full bg-destructive px-1 text-[10px] font-semibold text-destructive-foreground"
                        >{{ item.badge }}</span>
                    </Link>
                </SidebarMenuButton>

                <template v-else>
                    <SidebarMenuButton :is-active="isParentActive(item)" :tooltip="item.title" @click="toggleMenu(item)">
                        <component
                            v-if="item.icon"
                            :is="item.icon"
                            class="text-sidebar-primary"
                        />
                        <span>{{ item.title }}</span>
                    </SidebarMenuButton>
                    <SidebarMenuAction @click.stop="toggleMenu(item)">
                        <ChevronDown
                            class="h-4 w-4 text-sidebar-primary opacity-70 transition-transform"
                            :class="isMenuOpen(item) ? 'rotate-180' : ''"
                        />
                    </SidebarMenuAction>

                    <SidebarMenuSub v-if="isMenuOpen(item)">
                        <SidebarMenuSubItem v-for="subItem in item.items" :key="`${item.title}-${subItem.title}`">
                            <SidebarMenuSubButton as-child :is-active="isItemActive(subItem.href)">
                                <Link :href="subItem.href" @click="closeMobileSidebar">
                                    <span>{{ subItem.title }}</span>
                                </Link>
                            </SidebarMenuSubButton>
                        </SidebarMenuSubItem>
                    </SidebarMenuSub>
                </template>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
