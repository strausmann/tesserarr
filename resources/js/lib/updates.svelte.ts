// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * "New version available": the server runs another version than the one this
 * bundle was built for (after Labelyard, frontend/src/app/updates.ts).
 *
 * Every answer of the server names its version in the header
 * `X-Tesserarr-Version` (App\Http\Middleware\AddVersionHeader): Inertia
 * visits and background reloads, the session API under /api/v1/app, errors
 * included. The bundle knows its own version from the build
 * (`__TESSERARR_BUILD_VERSION__`, vite.config.ts); both come from
 * TESSERARR_VERSION. When they differ, the notice appears in the navigation
 * and as a toast. The page never reloads by itself: the user may be typing,
 * and a reload would throw that away. "Reload" reloads; "Later" closes the
 * toast for that version, remembered in localStorage for every tab of the
 * browser, and a newer version opens it again. The box in the navigation
 * stays either way.
 *
 * Inertia reloads the page on the next visit the user starts (its asset
 * version is the same TESSERARR_VERSION), but not on a background request
 * (router.reload is async). The notice covers the time in between. An open
 * tab also asks GET /api/v1/app/version every five minutes, when it becomes
 * visible again and when the browser is back online, so a tab nobody touches
 * learns it too.
 */

declare const __TESSERARR_BUILD_VERSION__: string | undefined;

export const BUILD_VERSION: string =
    typeof __TESSERARR_BUILD_VERSION__ === 'string'
        ? __TESSERARR_BUILD_VERSION__
        : '0.0.0-dev';

export const VERSION_HEADER = 'x-tesserarr-version';
/** localStorage: the version whose toast was closed with "Later". */
export const DISMISSED_KEY = 'tesserarr.update.dismissed';
export const VERSION_URL = '/api/v1/app/version';
export const VERSION_CHECK_INTERVAL_MS = 5 * 60 * 1000;

export interface UpdateState {
    /** The server runs another version than this bundle. */
    waiting: boolean;
    /** The version the server reported while it differs; null otherwise. */
    version: string | null;
    /** The toast is open (it is closed by "Later" for that version). */
    toastOpen: boolean;
}

export interface UpdateDeps {
    buildVersion: string;
    reload: () => void;
    storage: () => Storage | null;
}

function browserStorage(): Storage | null {
    try {
        return window.localStorage;
    } catch {
        return null;
    }
}

/** One tab's view of the update; `notice` below is the one the app uses. */
export class UpdateNotice {
    #server = $state<string | null>(null);
    #dismissed = $state<string | null>(null);
    readonly #deps: UpdateDeps;

    constructor(deps: Partial<UpdateDeps> = {}) {
        this.#deps = {
            buildVersion: BUILD_VERSION,
            reload: () => window.location.reload(),
            storage: browserStorage,
            ...deps,
        };
        this.#dismissed = this.#readDismissed();
    }

    get state(): UpdateState {
        const waiting = this.#server !== null;

        return {
            waiting,
            version: this.#server,
            toastOpen: waiting && this.#dismissed !== this.#server,
        };
    }

    /** A version the server named; null, empty or the bundle's own changes nothing or takes the notice down. */
    note(reported: string | null | undefined): void {
        const version = reported?.trim() ?? '';
        if (version === '') {
            return;
        }
        if (version === this.#deps.buildVersion) {
            this.#server = null;

            return;
        }
        this.#server = version;
    }

    /** "Later": close the toast for the version that waits, in every tab. */
    dismiss(): void {
        if (this.#server === null) {
            return;
        }
        this.#dismissed = this.#server;
        try {
            this.#deps.storage()?.setItem(DISMISSED_KEY, this.#server);
        } catch {
            // Blocked storage: "Later" holds for this tab only.
        }
    }

    /** Another tab closed the toast (the `storage` event). */
    syncDismissed(): void {
        this.#dismissed = this.#readDismissed();
    }

    /** "Reload": only when the user asks for it. */
    reload(): void {
        this.#deps.reload();
    }

    #readDismissed(): string | null {
        try {
            return this.#deps.storage()?.getItem(DISMISSED_KEY) ?? null;
        } catch {
            return null;
        }
    }
}

export const notice = new UpdateNotice();

export function noteServerVersion(reported: string | null): void {
    notice.note(reported);
}

export function updateState(): UpdateState {
    return notice.state;
}

export function dismissForVersion(): void {
    notice.dismiss();
}

type HeaderBag = Headers | Record<string, string | undefined> | undefined;

/** The version an answer names (fetch `Headers` or Inertia's lower-case record). */
export function readVersion(headers: HeaderBag): string | null {
    if (!headers) {
        return null;
    }
    if (typeof (headers as Headers).get === 'function') {
        return (headers as Headers).get(VERSION_HEADER);
    }
    const record = headers as Record<string, string | undefined>;

    return record[VERSION_HEADER] ?? null;
}

/** The part of Inertia's `http` the watch hooks into. */
export interface InertiaHttp {
    onResponse: (
        handler: <R extends { headers: Record<string, string> }>(
            response: R,
        ) => R,
    ) => () => void;
    onError: (handler: (error: unknown) => void) => () => void;
}

/**
 * Reads the version from every Inertia answer: visits, `router.reload`
 * and the 409 of an outdated background reload (an error for Inertia).
 */
export function watchInertia(
    http: InertiaHttp,
    target: UpdateNotice = notice,
): () => void {
    const offResponse = http.onResponse((response) => {
        target.note(readVersion(response.headers));

        return response;
    });
    const offError = http.onError((error) => {
        const response = (
            error as { response?: { headers?: Record<string, string> } }
        )?.response;
        target.note(readVersion(response?.headers));
    });

    return () => {
        offResponse();
        offError();
    };
}

/** `fetch` for the session API that reads the version of every answer, errors included. */
export async function appFetch(
    input: RequestInfo | URL,
    init?: RequestInit,
    target: UpdateNotice = notice,
): Promise<Response> {
    const response = await fetch(input, init);
    target.note(readVersion(response.headers));

    return response;
}

/** Asks the server for its version once; an unreachable server changes nothing. */
export async function checkVersion(
    target: UpdateNotice = notice,
): Promise<void> {
    try {
        await appFetch(
            VERSION_URL,
            {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            },
            target,
        );
    } catch {
        // Offline or the server restarts: ask again later.
    }
}

/**
 * Asks every five minutes, when the tab becomes visible and when the browser
 * is online again; follows "Later" of other tabs. Returns the stop function.
 */
export function startVersionChecks(
    target: UpdateNotice = notice,
    interval: number = VERSION_CHECK_INTERVAL_MS,
): () => void {
    const check = () => void checkVersion(target);
    const onVisible = () => {
        if (document.visibilityState === 'visible') {
            check();
        }
    };
    const onStorage = (event: StorageEvent) => {
        if (event.key === DISMISSED_KEY || event.key === null) {
            target.syncDismissed();
        }
    };
    const timer = setInterval(check, interval);
    document.addEventListener('visibilitychange', onVisible);
    window.addEventListener('online', check);
    window.addEventListener('storage', onStorage);

    return () => {
        clearInterval(timer);
        document.removeEventListener('visibilitychange', onVisible);
        window.removeEventListener('online', check);
        window.removeEventListener('storage', onStorage);
    };
}
