import { usePage } from "@inertiajs/react";

/**
 * Layout roles shared by the backend (`auth.businessRole`).
 *
 * `super-admin` is the platform path (no business membership); the other three
 * mirror `BusinessUser::ROLE_*`. `null` means the role could not be resolved —
 * the UI then degrades to pure capability filtering, exactly like the legacy
 * single-tenant install.
 */
export const BUSINESS_ROLES = {
    OWNER: "owner",
    MANAGER: "manager",
    STAFF: "staff",
    SUPER_ADMIN: "super-admin",
};

export function resolveBusinessRole(auth = {}) {
    const role = auth?.businessRole;

    return typeof role === "string" && role.length > 0 ? role : null;
}

export function resolveAuthorizationState(auth = {}) {
    return {
        permissions: auth?.permissions ?? {},
        super: auth?.super === true,
        businessRole: resolveBusinessRole(auth),
    };
}

export function isSuperAdmin(auth = {}) {
    return resolveAuthorizationState(auth).super;
}

export function can(permission, auth = {}) {
    const state = resolveAuthorizationState(auth);

    return state.super || !!state.permissions?.[permission];
}

export function canAny(permissions = [], auth = {}) {
    if (!Array.isArray(permissions) || permissions.length === 0) {
        return false;
    }

    return permissions.some((permission) => can(permission, auth));
}

export function canAll(permissions = [], auth = {}) {
    if (!Array.isArray(permissions) || permissions.length === 0) {
        return false;
    }

    return permissions.every((permission) => can(permission, auth));
}

/**
 * Whether the caller's layout role is one of `roles`.
 *
 * This is a *presentational* gate only — it never grants or revokes a
 * capability, it just decides how the navigation groups itself. It is
 * deliberately permissive in two cases so the menu can never become stricter
 * than the server:
 *
 *  - an empty/absent `roles` list means "no role restriction";
 *  - a platform super-admin always passes;
 *  - an unresolvable role (`null`, e.g. a legacy install with a custom Spatie
 *    role) passes, leaving the item capability-driven as before.
 */
export function hasRole(roles, auth = {}) {
    const wanted = Array.isArray(roles) ? roles.filter(Boolean) : roles ? [roles] : [];

    if (wanted.length === 0) {
        return true;
    }

    if (isSuperAdmin(auth)) {
        return true;
    }

    const current = resolveBusinessRole(auth);

    return current === null || wanted.includes(current);
}

export function useAuthorization() {
    const { auth } = usePage().props;

    return {
        auth,
        can: (permission) => can(permission, auth),
        canAny: (permissions) => canAny(permissions, auth),
        canAll: (permissions) => canAll(permissions, auth),
        isSuperAdmin: () => isSuperAdmin(auth),
        businessRole: resolveBusinessRole(auth),
        hasRole: (roles) => hasRole(roles, auth),
    };
}
