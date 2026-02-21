import app from 'flarum/forum/app';

/**
 * Manager odpowiedzialny za obsługę magnet linków
 * WAŻNE: Magnet link NIE jest przechowywany w DOM - tylko token SHA256
 */
export default class MagnetLinkManager {
    constructor() {
        // Cache danych per token
        this.dataCache = new Map();
        // Elementy DOM per token (do aktualizacji)
        this.elements = new Map();
        // Czas ważności cache (5 minut)
        this.cacheTimeout = 5 * 60 * 1000;
    }

    /**
     * Inicjalizuj magnet link w elemencie DOM
     * @param {HTMLElement} element - Element DOM
     * @param {string} token - Token SHA256 (NIE magnet link!)
     * @param {number|null} postId - ID posta
     */
    async initializeMagnetLink(element, token, postId) {
        // Walidacja tokena (64 znaki hex)
        if (!token || !/^[a-f0-9]{64}$/i.test(token)) {
            this.renderError(element, 'Invalid token');
            return;
        }

        // Zapisz element do mapy
        if (!this.elements.has(token)) {
            this.elements.set(token, new Set());
        }
        this.elements.get(token).add(element);

        // Sprawdź uprawnienia gościa po stronie klienta
        if (app.session.user === null && !app.forum.attribute('magnetGuestVisible')) {
            this.renderGuestNotAllowed(element);
            return;
        }

        // Renderuj loading state
        this.renderLoading(element);

        try {
            // Pobierz dane z API (używając tokena)
            const data = await this.fetchMagnetData(token);
            
            // Renderuj kompletny magnet link
            this.renderMagnetLink(element, token, data, postId);
        } catch (error) {
            // Sprawdź czy to błąd uprawnień
            if (error.status === 403) {
                const response = error.response || {};
                if (response.error === 'guest_not_allowed') {
                    this.renderGuestNotAllowed(element);
                } else if (response.error === 'email_not_confirmed') {
                    this.renderEmailNotConfirmed(element);
                } else if (response.error === 'permission_denied') {
                    this.renderPermissionDenied(element);
                } else {
                    this.renderError(element, response.message || 'Access denied');
                }
                return;
            }
            
            console.error('Magnet Link Error:', error);
            this.renderError(element, 'Failed to load magnet info');
        }
    }

    /**
     * Pobierz dane magnetu z API używając tokena
     */
    async fetchMagnetData(token) {
        // Sprawdź cache
        const cached = this.dataCache.get(token);
        if (cached && Date.now() - cached.timestamp < this.cacheTimeout) {
            return cached.data;
        }

        const response = await app.request({
            method: 'GET',
            url: app.forum.attribute('apiUrl') + '/magnet/info/' + token,
            errorHandler: this.silentErrorHandler.bind(this)
        });

        // Zapisz do cache
        this.dataCache.set(token, {
            data: response,
            timestamp: Date.now()
        });

        return response;
    }

    /**
     * Cichy handler błędów - nie pokazuje alertów
     */
    silentErrorHandler(error) {
        // Nie pokazuj alertów dla błędów 403
        if (error.status === 403) {
            throw error;
        }
        // Dla innych błędów też nie pokazuj alertów, tylko rzuć wyjątek
        throw error;
    }

    /**
     * Renderuj loading state
     */
    renderLoading(element) {
        element.innerHTML = `
            <div class="MagnetLink-container MagnetLink-loading">
                <span class="MagnetLink-icon">
                    <i class="fas fa-magnet fa-spin"></i>
                </span>
                <span class="MagnetLink-text">${app.translator.trans('tryhackx-magnet-link.forum.loading')}</span>
            </div>
        `;
    }

    /**
     * Renderuj kompletny magnet link
     */
    renderMagnetLink(element, token, data, postId) {
        if (!data.success) {
            // Sprawdź specjalne typy błędów
            if (data.error === 'email_not_confirmed') {
                this.renderEmailNotConfirmed(element);
                return;
            }
            this.renderError(element, data.message || 'Error loading data');
            return;
        }

        const name = data.name || 'Unknown';
        const scrapeEnabled = app.forum.attribute('magnetScraperEnabled');
        const clickTracking = app.forum.attribute('magnetClickTracking');
        
        let statsHtml = '';
        
        // Statystyki scrapera lub komunikat o błędzie
        if (scrapeEnabled && data.scrape) {
            if (data.scrape.success) {
                statsHtml = `
                    <div class="MagnetLink-stats">
                        <span class="MagnetLink-stat MagnetLink-seeds">
                            <i class="fas fa-arrow-up"></i>
                            <span>${app.translator.trans('tryhackx-magnet-link.forum.seeds')}</span>
                            <strong>${data.scrape.seeders}</strong>
                        </span>
                        <span class="MagnetLink-stat MagnetLink-leeches">
                            <i class="fas fa-arrow-down"></i>
                            <span>${app.translator.trans('tryhackx-magnet-link.forum.leeches')}</span>
                            <strong>${data.scrape.leechers}</strong>
                        </span>
                        <span class="MagnetLink-stat MagnetLink-completed">
                            <i class="fas fa-check"></i>
                            <span>${app.translator.trans('tryhackx-magnet-link.forum.completed')}</span>
                            <strong>${data.scrape.completed}</strong>
                        </span>
                    </div>
                `;
            } else if (data.scrape.error_type) {
                // Pokaż komunikat o błędzie trackerów
                const errorKey = 'tryhackx-magnet-link.forum.errors.' + data.scrape.error_type;
                const errorMessage = app.translator.trans(errorKey) || data.scrape.message;
                statsHtml = `
                    <div class="MagnetLink-info">
                        <i class="fas fa-info-circle"></i>
                        <span>${this.escapeHtml(errorMessage)}</span>
                    </div>
                `;
            }
        }

        // Rozmiar pliku
        let sizeHtml = '';
        if (data.file_size_formatted) {
            sizeHtml = `
                <span class="MagnetLink-size">
                    <i class="fas fa-hdd"></i>
                    <span>${this.escapeHtml(data.file_size_formatted)}</span>
                </span>
            `;
        }

        // Licznik kliknięć
        let clicksHtml = '';
        if (clickTracking && data.click_count !== undefined) {
            clicksHtml = `
                <span class="MagnetLink-clicks" data-token="${token}">
                    <i class="fas fa-mouse-pointer"></i>
                    <span>${data.click_count}</span>
                </span>
            `;
        }

        // Główny HTML - UWAGA: brak magnet linka w HTML!
        element.innerHTML = `
            <div class="MagnetLink-container">
                <div class="MagnetLink-header">
                    <span class="MagnetLink-icon">
                        <i class="fas fa-magnet"></i>
                    </span>
                    <a href="#" class="MagnetLink-name" data-token="${token}" data-post-id="${postId || ''}" title="${this.escapeHtml(name)}">
                        ${this.escapeHtml(this.truncateName(name, 80))}
                    </a>
                    ${sizeHtml}
                    ${clicksHtml}
                    <button class="MagnetLink-refresh Button Button--icon Button--link" title="${app.translator.trans('tryhackx-magnet-link.forum.refresh')}">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                </div>
                ${statsHtml}
            </div>
        `;

        // Dodaj event listenery
        this.attachEventListeners(element, token, postId);
    }

    /**
     * Dołącz event listenery
     */
    attachEventListeners(element, token, postId) {
        // Kliknięcie w link - pobiera magnet URI z API i otwiera
        const linkElement = element.querySelector('.MagnetLink-name');
        if (linkElement) {
            linkElement.addEventListener('click', async (e) => {
                e.preventDefault();
                await this.handleClick(token, postId, element);
            });
        }

        // Przycisk odświeżenia
        const refreshButton = element.querySelector('.MagnetLink-refresh');
        if (refreshButton) {
            refreshButton.addEventListener('click', async (e) => {
                e.preventDefault();
                e.stopPropagation();
                await this.handleRefresh(element, token, postId);
            });
        }
    }

    /**
     * Obsłuż kliknięcie - pobierz magnet URI z API i otwórz
     */
    async handleClick(token, postId, element) {
        try {
            // Wywołaj API click - zwraca magnet_uri
            const response = await app.request({
                method: 'POST',
                url: app.forum.attribute('apiUrl') + '/magnet/click',
                body: {
                    token: token,
                    post_id: postId
                }
            });

            if (response.success && response.magnet_uri) {
                // Aktualizuj licznik kliknięć
                if (response.click_count !== undefined) {
                    this.updateClickCountDisplay(token, response.click_count);
                }

                // Otwórz magnet link
                window.location.href = response.magnet_uri;
            } else {
                console.error('Click error:', response.message);
            }
        } catch (error) {
            console.error('Click request error:', error);
            // Nie pokazuj błędu użytkownikowi - po prostu nie otwieraj linku
        }
    }

    /**
     * Obsłuż odświeżenie
     */
    async handleRefresh(element, token, postId) {
        // Wyczyść cache
        this.dataCache.delete(token);
        
        // Renderuj loading
        const refreshBtn = element.querySelector('.MagnetLink-refresh i');
        if (refreshBtn) {
            refreshBtn.classList.add('fa-spin');
        }

        try {
            const data = await this.fetchMagnetData(token);
            this.renderMagnetLink(element, token, data, postId);
        } catch (error) {
            console.error('Refresh error:', error);
        } finally {
            if (refreshBtn) {
                refreshBtn.classList.remove('fa-spin');
            }
        }
    }

    /**
     * Aktualizuj wyświetlaną liczbę kliknięć dla wszystkich elementów z danym tokenem
     */
    updateClickCountDisplay(token, count) {
        const elements = this.elements.get(token);
        if (!elements) return;

        elements.forEach((el) => {
            const clicksSpan = el.querySelector('.MagnetLink-clicks span');
            if (clicksSpan) {
                clicksSpan.textContent = count;
                // Animacja
                const clicksContainer = clicksSpan.closest('.MagnetLink-clicks');
                if (clicksContainer) {
                    clicksContainer.classList.add('MagnetLink-clicks--updated');
                    setTimeout(() => {
                        clicksContainer.classList.remove('MagnetLink-clicks--updated');
                    }, 500);
                }
            }
        });
    }

    /**
     * Renderuj błąd
     */
    renderError(element, message) {
        element.innerHTML = `
            <div class="MagnetLink-container MagnetLink-error">
                <span class="MagnetLink-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </span>
                <span class="MagnetLink-text">${this.escapeHtml(message)}</span>
            </div>
        `;
        element.classList.add('MagnetLink-error');
    }

    /**
     * Renderuj komunikat dla gościa
     */
    renderGuestNotAllowed(element) {
        element.innerHTML = `
            <div class="MagnetLink-container">
                <span class="MagnetLink-icon">
                    <i class="fas fa-lock"></i>
                </span>
                <span class="MagnetLink-text">
                    ${app.translator.trans('tryhackx-magnet-link.forum.guest_not_allowed')}
                    <a href="${app.forum.attribute('baseUrl')}/login">
                        ${app.translator.trans('tryhackx-magnet-link.forum.login')}
                    </a>
                    ${app.translator.trans('tryhackx-magnet-link.forum.or')}
                    <a href="${app.forum.attribute('baseUrl')}/register">
                        ${app.translator.trans('tryhackx-magnet-link.forum.register')}
                    </a>
                </span>
            </div>
        `;
        element.classList.add('MagnetLink-guest');
    }

    /**
     * Renderuj komunikat dla niepotwierdzonych użytkowników
     */
    renderEmailNotConfirmed(element) {
        element.innerHTML = `
            <div class="MagnetLink-container">
                <span class="MagnetLink-icon">
                    <i class="fas fa-envelope"></i>
                </span>
                <span class="MagnetLink-text">
                    ${app.translator.trans('tryhackx-magnet-link.forum.email_not_confirmed')}
                </span>
            </div>
        `;
        element.classList.add('MagnetLink-guest');
    }

    /**
     * Renderuj komunikat dla użytkowników bez uprawnień
     */
    renderPermissionDenied(element) {
        element.innerHTML = `
            <div class="MagnetLink-container">
                <span class="MagnetLink-icon">
                    <i class="fas fa-ban"></i>
                </span>
                <span class="MagnetLink-text">
                    ${app.translator.trans('tryhackx-magnet-link.forum.permission_denied')}
                </span>
            </div>
        `;
        element.classList.add('MagnetLink-guest');
    }

    /**
     * Escape HTML
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Skróć nazwę
     */
    truncateName(name, maxLength) {
        if (!name) return '';
        if (name.length <= maxLength) return name;
        return name.substring(0, maxLength - 3) + '...';
    }
}
