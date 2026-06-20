/**
 * Ogranicznik współbieżności żądań scrapingu wywoływanych najeżdżaniem na listę
 * dyskusji (metoda B z planu perf). Najwyżej `max` żądań naraz; nowy hover ponad
 * limit przerywa NAJSTARSZE w locie (abort-oldest). Każde żądanie ma własny
 * AbortController; dodatkowo przerywamy je przy mouseleave / zmianie najechanego
 * tematu (DiscussionListItem) oraz przy wejściu w temat / nawigacji (abortAll).
 *
 * Po co: scrape biegnie SYNCHRONICZNIE na workerze PHP-FPM, więc każde najechanie
 * wiąże workera na czas budżetu (tooltip_scrape_budget). Bez limitu szybkie
 * przejeżdżanie listy odpala po żądaniu na hover i piętrzy je (audyt: pile-up).
 * Limit + abort-oldest + abort przy mouseleave/wejściu w temat zwalniają żądania,
 * których wynik i tak nie zostanie pokazany.
 *
 * UWAGA: przerwany fetch NIE kończy się i NIE zapisze wyniku do cache tooltipa —
 * świadomy kompromis (priorytet: zwolnić workera) zgodny z decyzją użytkownika.
 * Sam abort po stronie przeglądarki nie kończy natychmiast blokującego cURL/
 * socketu na workerze — to ogranicza przede wszystkim LICZBĘ równoległych żądań.
 */
export default class HoverFetchManager {
    constructor(maxConcurrent = 2) {
        this.max = HoverFetchManager.normalizeMax(maxConcurrent);
        // key(discussionId) -> { controller, promise }. Map zachowuje kolejność
        // wstawiania → pierwszy wpis = najstarsze żądanie (do abort-oldest).
        this.inflight = new Map();
    }

    static normalizeMax(value) {
        const n = parseInt(value, 10);
        return Number.isFinite(n) && n > 0 ? n : 2;
    }

    setMax(maxConcurrent) {
        this.max = HoverFetchManager.normalizeMax(maxConcurrent);
    }

    /**
     * Wykonaj (lub dołącz do istniejącego) żądanie GET JSON dla danego klucza.
     * Natywny fetch zamiast app.request: pozwala na AbortController i nie wyświetla
     * globalnych alertów o błędzie (tooltip obsługuje błędy po cichu). Sesja idzie
     * cookie (credentials:same-origin); GET nie wymaga CSRF.
     *
     * @param {string|number} key  identyfikator (discussionId) — klucz dedupe/abort.
     * @param {string} url
     * @returns {Promise<any>} sparsowany JSON (również body 403 z {success:false});
     *   odrzuca AbortError przy przerwaniu, TypeError przy błędzie sieci.
     */
    request(key, url) {
        const k = String(key);

        // Dedupe: to samo żądanie wciąż w locie → współdziel jego promise
        // (ponowny hover tej samej dyskusji nie odpala drugiego scrape).
        const existing = this.inflight.get(k);
        if (existing) return existing.promise;

        // Ponad limit → przerwij NAJSTARSZE (pierwszy wpis w Map), aż zwolni się slot.
        while (this.inflight.size >= this.max) {
            const oldestKey = this.inflight.keys().next().value;
            if (oldestKey === undefined) break;
            this.abort(oldestKey);
        }

        const controller = new AbortController();
        const promise = fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then((res) => res.json().catch(() => ({ success: false, error: 'load_failed' })))
            .finally(() => {
                // Sprzątnij tylko jeśli to wciąż TEN wpis (mógł zostać podmieniony).
                const cur = this.inflight.get(k);
                if (cur && cur.controller === controller) this.inflight.delete(k);
            });

        this.inflight.set(k, { controller, promise });
        return promise;
    }

    /** Przerwij konkretne żądanie (mouseleave / zmiana najechanego tematu). */
    abort(key) {
        const k = String(key);
        const entry = this.inflight.get(k);
        if (entry) {
            this.inflight.delete(k);
            entry.controller.abort();
        }
    }

    /** Przerwij WSZYSTKIE oczekujące (wejście w temat / nawigacja SPA). */
    abortAll() {
        for (const entry of this.inflight.values()) {
            entry.controller.abort();
        }
        this.inflight.clear();
    }
}
