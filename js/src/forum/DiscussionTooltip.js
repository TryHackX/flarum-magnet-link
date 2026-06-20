import app from 'flarum/forum/app';
import HoverFetchManager from './HoverFetchManager';

/**
 * Tooltip wyświetlający statystyki magnet linków na liście dyskusji
 */
export default class DiscussionTooltip {
    constructor() {
        // Cache danych per discussionId
        this.tooltipCache = new Map();
        // Element tooltipa
        this.tooltipElement = null;
        // Aktualnie wyświetlana dyskusja
        this.activeDiscussionId = null;
        // Licznik generacji show() - zapobiega race condition
        this.showGeneration = 0;
        // Ogranicznik współbieżności hoverów (metoda B): max równoczesnych żądań
        // (admin: tooltip_max_concurrent, domyślnie 2) + abort-oldest + abort przy
        // mouseleave/wejściu w temat. Chroni workera PHP-FPM przed pile-upem.
        this.fetchManager = new HoverFetchManager(app.forum.attribute('magnetTooltipMaxConcurrent'));
        // Tworzy element tooltipa
        this.createTooltipElement();
        // Globalne listenery do ukrywania tooltipa
        this.setupGlobalListeners();
    }

    /**
     * Utwórz element DOM tooltipa
     */
    createTooltipElement() {
        this.tooltipElement = document.createElement('div');
        this.tooltipElement.className = 'MagnetTooltip';
        document.body.appendChild(this.tooltipElement);
    }

    /**
     * Globalne listenery - ukryj tooltip przy kliknięciu, scrollu, nawigacji
     */
    setupGlobalListeners() {
        // Ukryj przy dowolnym kliknięciu (np. kliknięcie w temat). Wejście w temat
        // przerywa też WSZYSTKIE oczekujące żądania hovera (zwalnia workery) — i tak
        // chowamy dymek, więc ich wynik nie jest potrzebny.
        document.addEventListener('click', (e) => {
            this.hide();
            this.fetchManager.abortAll();
            // Kliknięcie w przycisk refresh listy dyskusji - wyczyść cache tooltipa
            if (e.target.closest('.item-refresh')) {
                this.clearCache();
            }
        });

        // Ukryj przy scrollu strony
        document.addEventListener('scroll', () => {
            this.hide();
        }, true);

        // Ukryj przy nawigacji wstecz/naprzód (SPA). Nawigację przez kliknięcie
        // linku obsługuje już globalny listener 'click' powyżej, więc tu wystarczy
        // popstate — bez monkey-patcha globalnego History.prototype.
        window.addEventListener('popstate', () => {
            this.hide();
            this.fetchManager.abortAll();
        });
    }

    /** Przerwij oczekujące żądanie hovera dla danej dyskusji (mouseleave/zmiana targetu). */
    cancel(discussionId) {
        this.fetchManager.abort(discussionId);
    }

    /** Przerwij wszystkie oczekujące żądania hovera (wejście w temat / nawigacja). */
    cancelAll() {
        this.fetchManager.abortAll();
    }

    /**
     * Pokaż tooltip dla dyskusji
     */
    async show(discussionId, targetElement) {
        // Element mógł zniknąć z DOM (np. kliknięcie szybsze niż 300ms delay)
        if (!targetElement.isConnected) return;

        this.activeDiscussionId = discussionId;
        const generation = ++this.showGeneration;

        // Pozycjonuj tooltip
        this.positionTooltip(targetElement);

        // Pokaż z loading
        this.tooltipElement.innerHTML = this.renderLoading();
        this.tooltipElement.classList.add('MagnetTooltip--visible');

        try {
            const data = await this.fetchDiscussionMagnets(discussionId);

            // Sprawdź czy to wciąż aktualne wywołanie show()
            if (this.showGeneration !== generation) return;

            if (data.success === false) {
                // Brak uprawnień (gość / niepotwierdzony email / brak permisji):
                // pokaż czytelny komunikat w dymku, jeśli admin to włączył.
                const PERM_ERRORS = ['guest_not_allowed', 'email_not_confirmed', 'permission_denied'];
                if (PERM_ERRORS.includes(data.error)
                    && app.forum.attribute('magnetTooltipShowPermissionErrors') !== false) {
                    this.tooltipElement.innerHTML = this.renderPermissionMessage(data.error);
                    this.positionTooltip(targetElement);
                    return;
                }
                this.hide();
                return;
            }

            if (!data.magnets || data.magnets.length === 0) {
                this.hide();
                return;
            }

            this.tooltipElement.innerHTML = this.renderContent(data.magnets);
            // Ponownie pozycjonuj po zmianie zawartości
            this.positionTooltip(targetElement);
        } catch (error) {
            // Przerwane (mouseleave / zmiana targetu / wejście w temat) — to NIE
            // błąd: nie dotykaj UI (mógł je przejąć nowszy hover albo już schowane).
            if (error && error.name === 'AbortError') return;
            if (this.showGeneration !== generation) return;
            this.hide();
        }
    }

    /**
     * Ukryj tooltip
     */
    hide() {
        this.activeDiscussionId = null;
        this.tooltipElement.classList.remove('MagnetTooltip--visible');
    }

    /**
     * Wyczyść cache tooltipa
     */
    clearCache() {
        this.tooltipCache.clear();
    }

    /**
     * Czy cache jest włączony (sterowane ustawieniem admina).
     */
    cacheEnabled() {
        return app.forum.attribute('magnetCacheEnabled') !== false;
    }

    /**
     * Czas ważności cache w ms (z ustawień; domyślnie 5 minut).
     */
    cacheTtlMs() {
        const ttl = parseInt(app.forum.attribute('magnetCacheTtl'), 10);
        return (Number.isFinite(ttl) && ttl > 0 ? ttl : 300) * 1000;
    }

    /**
     * Pobierz dane magnet linków dla dyskusji
     */
    async fetchDiscussionMagnets(discussionId) {
        // Sprawdź cache (jeśli włączony)
        if (this.cacheEnabled()) {
            const cached = this.tooltipCache.get(discussionId);
            if (cached && Date.now() - cached.timestamp < this.cacheTtlMs()) {
                return cached.data;
            }
        }

        const url = app.forum.attribute('apiUrl') + '/magnet/discussion/' + discussionId;

        let response;
        try {
            // Przez ogranicznik współbieżności (metoda B). Natywny fetch zwraca też
            // body 403 jako {success:false, error:<typ>} (kontroler dla gości /
            // niepotwierdzonych / bez permisji), więc show() wyrenderuje komunikat
            // ("You must be logged in…") zamiast migać "Loading...".
            response = await this.fetchManager.request(discussionId, url);
        } catch (error) {
            // Przerwane (mouseleave / zmiana targetu / wejście w temat) — przepuść
            // dalej jako AbortError, żeby show() nic nie renderowało i NIE zatruwało
            // cache pustym wynikiem po przerwaniu.
            if (error && error.name === 'AbortError') {
                throw error;
            }
            // Twardy błąd sieci — sentinel jak dotąd.
            response = { success: false, error: 'load_failed' };
        }

        // Zapisz do cache (również odpowiedzi błędne — cache ma timeout). Aborty tu
        // nie docierają (rzucone wyżej), więc nie cache'ujemy pustki po przerwaniu.
        if (this.cacheEnabled()) {
            this.tooltipCache.set(discussionId, {
                data: response,
                timestamp: Date.now()
            });
        }

        return response;
    }

    /**
     * Pozycjonuj tooltip względem elementu docelowego
     */
    positionTooltip(targetElement) {
        const rect = targetElement.getBoundingClientRect();
        const tooltipRect = this.tooltipElement.getBoundingClientRect();

        let top = rect.bottom + 8;
        let left = rect.left;

        // Sprawdź czy tooltip nie wychodzi poza viewport
        if (top + tooltipRect.height > window.innerHeight) {
            top = rect.top - tooltipRect.height - 8;
        }

        if (left + tooltipRect.width > window.innerWidth) {
            left = window.innerWidth - tooltipRect.width - 16;
        }

        if (left < 8) {
            left = 8;
        }

        this.tooltipElement.style.top = top + 'px';
        this.tooltipElement.style.left = left + 'px';
    }

    /**
     * Renderuj stan ładowania
     */
    renderLoading() {
        return `
            <div class="MagnetTooltip-loading">
                <i class="fas fa-magnet fa-spin"></i>
                <span>${app.translator.trans('tryhackx-magnet-link.forum.tooltip.loading')}</span>
            </div>
        `;
    }

    /**
     * Renderuj zawartość tooltipa
     */
    renderContent(magnets) {
        const escapeHtml = (text) => {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        };

        const truncate = (name, maxLen) => {
            if (!name) return '';
            if (name.length <= maxLen) return name;
            return name.substring(0, maxLen - 3) + '...';
        };

        let itemsHtml = magnets.map(magnet => {
            let statsHtml = '';
            if (magnet.scrape && magnet.scrape.success) {
                statsHtml = `
                    <div class="MagnetTooltip-stats">
                        <span class="MagnetTooltip-seeds">
                            <i class="fas fa-arrow-up"></i> ${magnet.scrape.seeders}
                        </span>
                        <span class="MagnetTooltip-leeches">
                            <i class="fas fa-arrow-down"></i> ${magnet.scrape.leechers}
                        </span>
                        <span class="MagnetTooltip-completed">
                            <i class="fas fa-check"></i> ${magnet.scrape.completed}
                        </span>
                        <span class="MagnetTooltip-clicks">
                            <i class="fas fa-mouse-pointer"></i> ${magnet.click_count || 0}
                        </span>
                    </div>
                `;
            } else {
                // Błąd trackerów (np. "No tracker responded") — pokaż komunikat
                // tak jak na stronie tematu (MagnetLinkManager).
                let errorHtml = '';
                if (magnet.scrape && magnet.scrape.error_type) {
                    const errorKey = 'tryhackx-magnet-link.forum.errors.' + magnet.scrape.error_type;
                    const errorMessage = app.translator.trans(errorKey) || magnet.scrape.message;
                    if (errorMessage) {
                        errorHtml = `
                            <div class="MagnetTooltip-info">
                                <i class="fas fa-info-circle"></i>
                                <span>${escapeHtml(errorMessage)}</span>
                            </div>
                        `;
                    }
                }

                // Nawet bez scrape pokaż kliknięcia jeśli są
                let clicksHtml = '';
                if (magnet.click_count > 0) {
                    clicksHtml = `
                        <span class="MagnetTooltip-clicks">
                            <i class="fas fa-mouse-pointer"></i> ${magnet.click_count}
                        </span>
                    `;
                }

                if (errorHtml && clicksHtml) {
                    // Komunikat o błędzie po lewej, licznik kliknięć PO PRAWEJ w tym
                    // samym wierszu (zamiast pod spodem).
                    statsHtml = `
                        <div class="MagnetTooltip-info-row">
                            ${errorHtml}
                            ${clicksHtml}
                        </div>
                    `;
                } else if (errorHtml) {
                    statsHtml = errorHtml;
                } else if (clicksHtml) {
                    // Sam licznik (bez komunikatu) — jak dotąd, w wierszu statystyk.
                    statsHtml = `<div class="MagnetTooltip-stats">${clicksHtml}</div>`;
                }
            }

            return `
                <div class="MagnetTooltip-item">
                    <div class="MagnetTooltip-name">
                        <i class="fas fa-magnet MagnetTooltip-magnet-icon"></i>
                        ${escapeHtml(truncate(magnet.name, 60))}
                    </div>
                    ${statsHtml}
                </div>
            `;
        }).join('');

        return `
            <div class="MagnetTooltip-content">
                ${itemsHtml}
            </div>
        `;
    }

    /**
     * Renderuj komunikat o braku uprawnień (gość / niepotwierdzony email /
     * brak permisji) — analogicznie do renderowania w poście (MagnetLinkManager).
     */
    renderPermissionMessage(errorType) {
        const escapeHtml = (text) => {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        };

        let message;
        if (errorType === 'guest_not_allowed') {
            message =
                app.translator.trans('tryhackx-magnet-link.forum.guest_not_allowed') +
                ' ' +
                app.translator.trans('tryhackx-magnet-link.forum.login') +
                ' ' +
                app.translator.trans('tryhackx-magnet-link.forum.or') +
                ' ' +
                app.translator.trans('tryhackx-magnet-link.forum.register');
        } else if (errorType === 'email_not_confirmed') {
            message = app.translator.trans('tryhackx-magnet-link.forum.email_not_confirmed');
        } else {
            message = app.translator.trans('tryhackx-magnet-link.forum.permission_denied');
        }

        return `
            <div class="MagnetTooltip-info MagnetTooltip-info--permission">
                <i class="fas fa-lock"></i>
                <span>${escapeHtml(message)}</span>
            </div>
        `;
    }
}
