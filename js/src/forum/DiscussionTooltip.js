import app from 'flarum/forum/app';

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
        // Ukryj przy dowolnym kliknięciu (np. kliknięcie w temat)
        document.addEventListener('click', (e) => {
            this.hide();
            // Kliknięcie w przycisk refresh listy dyskusji - wyczyść cache tooltipa
            if (e.target.closest('.item-refresh')) {
                this.clearCache();
            }
        });

        // Ukryj przy scrollu strony
        document.addEventListener('scroll', () => {
            this.hide();
        }, true);

        // Ukryj przy zmianie trasy (Flarum SPA navigation)
        if (app.history) {
            const originalPush = History.prototype.pushState;
            const self = this;
            History.prototype.pushState = function () {
                self.hide();
                return originalPush.apply(this, arguments);
            };
        }
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

        let response;
        try {
            response = await app.request({
                method: 'GET',
                url: app.forum.attribute('apiUrl') + '/magnet/discussion/' + discussionId,
            });
        } catch (error) {
            // Kontroler zwraca {success:false, error:<typ>} z kodem 403 dla
            // gości / niepotwierdzonych / bez permisji. Chcemy ten obiekt
            // zamiast wyjątku, żeby show() mógł wyrenderować komunikat
            // ("You must be logged in…") w miejsce migającego "Loading...".
            response = (error && error.response) || {
                success: false,
                error: 'load_failed',
            };
        }

        // Zapisz do cache (również odpowiedzi błędne — cache ma timeout).
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
                        <div class="MagnetTooltip-stats">
                            <span class="MagnetTooltip-clicks">
                                <i class="fas fa-mouse-pointer"></i> ${magnet.click_count}
                            </span>
                        </div>
                    `;
                }

                statsHtml = errorHtml + clicksHtml;
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
