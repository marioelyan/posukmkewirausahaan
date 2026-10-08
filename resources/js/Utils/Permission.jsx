import { useAuthorization } from "./authorization";

export default function hasAnyPermission(permissions, givenPermissions = null) {
    const { canAny, isSuperAdmin } = useAuthorization();

    if (givenPermissions) {
        return (
            isSuperAdmin() ||
            permissions.some((permission) => givenPermissions?.[permission] === true)
        );
    }

    return canAny(permissions);
}
