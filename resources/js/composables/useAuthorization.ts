import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

// UI visibility only. Routes and policies must authorize every backend action.
export function useAuthorization() {
    const page = usePage();
    const roles = computed(() => page.props.auth.user?.roles ?? []);
    const permissions = computed(() => page.props.auth.user?.permissions ?? []);
    const hasRole = (role: string): boolean => roles.value.includes(role);
    const hasAnyRole = (candidates: string[]): boolean =>
        candidates.some(hasRole);
    const can = (permission: string): boolean =>
        permissions.value.includes(permission);
    const canAny = (candidates: string[]): boolean => candidates.some(can);

    return { roles, permissions, hasRole, hasAnyRole, can, canAny };
}
