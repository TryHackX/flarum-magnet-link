import app from 'flarum/forum/app';

/**
 * Tooltip wyświetlający statystyki magnet linków na liście dyskusji
 */
export default class DiscussionTooltip {
    constructor() {
        // Cache danych per discussionId
        this.tooltipCache = new Map();
        // Czas ważności cache (5 minut)
        this.cacheTimeout = 5 * 60 * 1000;
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

            if (!data.success || !data.magnets || data.magnets.length === 0) {
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
     * Pobierz dane magnet linków dla dyskusji
     */
    async fetchDiscussionMagnets(discussionId) {
        // Sprawdź cache
        const cached = this.tooltipCache.get(discussionId);
        if (cached && Date.now() - cached.timestamp < this.cacheTimeout) {
            return cached.data;
        }

        const response = await app.request({
            method: 'GET',
            url: app.forum.attribute('apiUrl') + '/magnet/discussion/' + discussionId,
            errorHandler(error) {
                throw error;
            }
        });

        // Zapisz do cache
        this.tooltipCache.set(discussionId, {
            data: response,
            timestamp: Date.now()
        });

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
                // Nawet bez scrape pokaż kliknięcia jeśli są
                if (magnet.click_count > 0) {
                    statsHtml = `
                        <div class="MagnetTooltip-stats">
                            <span class="MagnetTooltip-clicks">
                                <i class="fas fa-mouse-pointer"></i> ${magnet.click_count}
                            </span>
                        </div>
                    `;
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
}
