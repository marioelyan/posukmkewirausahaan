import { router } from "@inertiajs/react";

/**
 * Step-up replay.
 *
 * Sensitive writes are guarded server-side by the `step_up` middleware. When the
 * password confirmation is stale the middleware redirects the request to the
 * `Auth/ConfirmPassword` page, which means the original action is lost and the
 * user has to redo it after confirming.
 *
 * This module removes that second step: it remembers the last non-GET visit and,
 * once the user has confirmed their password, replays the exact original visit
 * (including file uploads, because the data is captured client-side).
 *
 * It only reacts to the server-driven redirect into the confirmation page, so
 * non-protected requests are never touched.
 */

const CONFIRM_PATH = "/confirm-password";

let lastNonGetVisit = null;
let pendingVisit = null;
let installed = false;

const pathOf = (url) => {
    try {
        if (!url) {
            return "";
        }

        if (typeof url === "string") {
            return url.startsWith("http")
                ? new URL(url).pathname
                : url.split("?")[0];
        }

        return url.pathname ?? "";
    } catch {
        return "";
    }
};

export function installStepUpReplay() {
    if (installed || typeof window === "undefined") {
        return;
    }

    installed = true;

    router.on("start", (event) => {
        const visit = event?.detail?.visit;

        if (!visit) {
            return;
        }

        const method = String(visit.method ?? "get").toLowerCase();
        const path = pathOf(visit.url);

        // A GET to the confirmation screen is the server redirect that lands the
        // user there after the step-up middleware intercepted the original
        // write. Keep that write around so it can be replayed; clearing here is
        // what broke auto-replay under Inertia 3, which resolves the redirect as
        // a fresh GET visit.
        if (method === "get" && path === CONFIRM_PATH) {
            return;
        }

        // Only non-GET visits can be step-up protected. The confirmation POST
        // itself (and any other GET navigation) must never be captured as a
        // replay candidate.
        if (method === "get" || path === CONFIRM_PATH) {
            lastNonGetVisit = null;
            return;
        }

        lastNonGetVisit = {
            method,
            url: visit.url,
            data: visit.data ?? {},
            options: {
                preserveScroll: visit.preserveScroll,
                preserveState: visit.preserveState,
                only: visit.only,
                except: visit.except,
                reset: visit.reset,
                onSuccess: visit.onSuccess,
                onError: visit.onError,
                onFinish: visit.onFinish,
            },
        };
    });

    // `navigate` is suppressed for replace visits, so also listen to
    // `beforeUpdate`, which fires for every page response that swaps a page.
    const rememberConfirmVisit = (event) => {
        const page = event?.detail?.page;

        if (page?.component === "Auth/ConfirmPassword" && lastNonGetVisit) {
            pendingVisit = lastNonGetVisit;
        }
    };

    router.on("beforeUpdate", rememberConfirmVisit);
    router.on("navigate", rememberConfirmVisit);
}

export function hasPendingStepUp() {
    return pendingVisit !== null;
}

export function clearPendingStepUp() {
    pendingVisit = null;
}

/**
 * Re-issue the visit that the step-up middleware intercepted. Must be called
 * once the password has just been confirmed so the server accepts it.
 */
export function replayPendingStepUp() {
    const visit = pendingVisit;
    pendingVisit = null;

    if (!visit) {
        return false;
    }

    if (visit.method === "delete") {
        router.delete(visit.url, { ...visit.options, data: visit.data });
    } else {
        router[visit.method](visit.url, visit.data, visit.options);
    }

    return true;
}
