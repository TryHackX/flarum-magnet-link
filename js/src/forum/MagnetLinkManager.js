import app from 'flarum/forum/app';

/**
 * Manager odpowiedzialny za obsługę magnet linków
 * WAŻNE: Magnet link NIE jest przechowywany w DOM - tylko token SHA256
 */
export default class MagnetLinkManager {
    constructor() {
        // Cache danych per token:postId
        this.dataCache = new Map();
        // Elementy DOM per token (do aktualizacji)
        this.elements = new Map();
        // Czas ważności cache (5 minut)
        this.cacheTimeout = 5 * 60 * 1000;
    }

    /**
     * Klucz cache uwzględniający postId (dla niestandardowych nazw)
     */
    getCacheKey(token, postId) {
        return postId ? `${token}:${postId}` : token;
    }

    /**
     * Inicjalizuj magnet link w elemencie DOM
     * @param {HTMLElement} element - Element DOM
     * @param {string} token - Token SHA256 (NIE magnet link!)
     * @param {number|null} postId - ID posta
     * @param {number|null} postUserId - ID autora posta
     */
    async initializeMagnetLink(element, token, postId, postUserId) {
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
            const data = await this.fetchMagnetData(token, postId);

            // Renderuj kompletny magnet link
            this.renderMagnetLink(element, token, data, postId, postUserId);
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
    async fetchMagnetData(token, postId) {
        const cacheKey = this.getCacheKey(token, postId);

        // Sprawdź cache
        const cached = this.dataCache.get(cacheKey);
        if (cached && Date.now() - cached.timestamp < this.cacheTimeout) {
            return cached.data;
        }

        let url = app.forum.attribute('apiUrl') + '/magnet/info/' + token;
        if (postId) {
            url += '?post_id=' + postId;
        }

        const response = await app.request({
            method: 'GET',
            url: url,
            errorHandler: this.silentErrorHandler.bind(this)
        });

        // Zapisz do cache
        this.dataCache.set(cacheKey, {
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
    renderMagnetLink(element, token, data, postId, postUserId) {
        if (!data.success) {
            // Sprawdź specjalne typy błędów
            if (data.error === 'email_not_confirmed') {
                this.renderEmailNotConfirmed(element);
                return;
            }
            this.renderError(element, data.message || 'Error loading data');
            return;
        }

        const originalName = data.name || 'Unknown';
        const displayName = data.custom_name || originalName;
        const hasCustomName = data.has_custom_name || false;
        const scrapeEnabled = app.forum.attribute('magnetScraperEnabled');
        const clickTracking = app.forum.attribute('magnetClickTracking');
        const renameEnabled = app.forum.attribute('magnetRenameEnabled');

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

        // Przycisk zmiany nazwy (tylko dla autora posta)
        let renameButtonHtml = '';
        if (renameEnabled && postId) {
            const currentUserId = app.session.user ? app.session.user.id() : null;
            const isAuthor = postUserId && currentUserId && String(postUserId) === String(currentUserId);
            if (isAuthor) {
                renameButtonHtml = `
                    <button class="MagnetLink-rename Button Button--icon Button--link" title="${app.translator.trans('tryhackx-magnet-link.forum.rename.tooltip')}">
                        <i class="fas fa-pen"></i>
                    </button>
                `;
            }
        }

        // Główny HTML - UWAGA: brak magnet linka w HTML!
        element.innerHTML = `
            <div class="MagnetLink-container">
                <div class="MagnetLink-header">
                    <span class="MagnetLink-icon">
                        <i class="fas fa-magnet"></i>
                    </span>
                    <a href="#" class="MagnetLink-name" data-token="${token}" data-post-id="${postId || ''}" title="${this.escapeHtml(displayName)}">
                        ${this.escapeHtml(this.truncateName(displayName, 80))}
                    </a>
                    ${renameButtonHtml}
                    ${sizeHtml}
                    ${clicksHtml}
                    <button class="MagnetLink-copy Button Button--icon Button--link" title="${app.translator.trans('tryhackx-magnet-link.forum.copy_link')}">
                        <i class="fas fa-copy"></i>
                    </button>
                    <button class="MagnetLink-refresh Button Button--icon Button--link" title="${app.translator.trans('tryhackx-magnet-link.forum.refresh')}">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                </div>
                ${statsHtml}
            </div>
        `;

        // Dodaj event listenery
        this.attachEventListeners(element, token, postId, postUserId);

        // Animacja rozwijania statystyk/info
        requestAnimationFrame(() => {
            const stats = element.querySelector('.MagnetLink-stats');
            if (stats) {
                stats.classList.add('MagnetLink-stats--visible');
            }
            const info = element.querySelector('.MagnetLink-info');
            if (info) {
                info.classList.add('MagnetLink-info--visible');
            }
        });
    }

    /**
     * Dołącz event listenery
     */
    attachEventListeners(element, token, postId, postUserId) {
        // Kliknięcie w link - pobiera magnet URI z API i otwiera
        const linkElement = element.querySelector('.MagnetLink-name');
        if (linkElement) {
            linkElement.addEventListener('click', async (e) => {
                e.preventDefault();
                await this.handleClick(token, postId, element);
            });
        }

        // Przycisk kopiowania
        const copyButton = element.querySelector('.MagnetLink-copy');
        if (copyButton) {
            copyButton.addEventListener('click', async (e) => {
                e.preventDefault();
                e.stopPropagation();
                await this.handleCopy(token, postId, element);
            });
        }

        // Przycisk odświeżenia
        const refreshButton = element.querySelector('.MagnetLink-refresh');
        if (refreshButton) {
            refreshButton.addEventListener('click', async (e) => {
                e.preventDefault();
                e.stopPropagation();
                await this.handleRefresh(element, token, postId, postUserId);
            });
        }

        // Przycisk zmiany nazwy
        const renameButton = element.querySelector('.MagnetLink-rename');
        if (renameButton) {
            renameButton.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                this.showRenameModal(token, postId, element, postUserId);
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
     * Obsłuż kopiowanie - pobierz magnet URI z API i skopiuj do schowka
     */
    async handleCopy(token, postId, element) {
        const copyBtn = element.querySelector('.MagnetLink-copy i');
        try {
            // Wywołaj API click - zwraca magnet_uri (nalicza kliknięcie)
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

                // Skopiuj do schowka
                await navigator.clipboard.writeText(response.magnet_uri);

                // Pokaż potwierdzenie - zmień ikonę na check
                if (copyBtn) {
                    copyBtn.classList.remove('fa-copy');
                    copyBtn.classList.add('fa-check');
                    setTimeout(() => {
                        copyBtn.classList.remove('fa-check');
                        copyBtn.classList.add('fa-copy');
                    }, 1500);
                }
            } else {
                console.error('Copy error:', response.message);
            }
        } catch (error) {
            console.error('Copy request error:', error);
        }
    }

    /**
     * Obsłuż odświeżenie
     */
    async handleRefresh(element, token, postId, postUserId) {
        // Wyczyść cache
        this.dataCache.delete(this.getCacheKey(token, postId));

        // Renderuj loading
        const refreshBtn = element.querySelector('.MagnetLink-refresh i');
        if (refreshBtn) {
            refreshBtn.classList.add('fa-spin');
        }

        try {
            const data = await this.fetchMagnetData(token, postId);
            this.renderMagnetLink(element, token, data, postId, postUserId);
        } catch (error) {
            console.error('Refresh error:', error);
        } finally {
            if (refreshBtn) {
                refreshBtn.classList.remove('fa-spin');
            }
        }
    }

    /**
     * Pokaż modal zmiany nazwy
     */
    showRenameModal(token, postId, element, postUserId) {
        const cacheKey = this.getCacheKey(token, postId);
        const cached = this.dataCache.get(cacheKey);
        const currentName = cached?.data?.custom_name || cached?.data?.name || '';
        const originalName = cached?.data?.name || '';
        const hasCustomName = cached?.data?.has_custom_name || false;

        // Utwórz overlay modala
        const overlay = document.createElement('div');
        overlay.className = 'MagnetRenameModal-overlay';

        overlay.innerHTML = `
            <div class="MagnetRenameModal">
                <div class="MagnetRenameModal-header">
                    <h3>${app.translator.trans('tryhackx-magnet-link.forum.rename.modal_title')}</h3>
                    <button class="MagnetRenameModal-close Button Button--icon Button--link">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="MagnetRenameModal-body">
                    <label>${app.translator.trans('tryhackx-magnet-link.forum.rename.label')}</label>
                    <input type="text" class="FormControl MagnetRenameModal-input"
                           value="${this.escapeHtml(currentName)}"
                           maxlength="500"
                           placeholder="${this.escapeHtml(originalName)}">
                    ${hasCustomName ? `
                        <button class="Button MagnetRenameModal-restore">
                            <i class="fas fa-undo"></i>
                            ${app.translator.trans('tryhackx-magnet-link.forum.rename.restore')}
                        </button>
                    ` : ''}
                </div>
                <div class="MagnetRenameModal-footer">
                    <button class="Button Button--primary MagnetRenameModal-save">
                        ${app.translator.trans('tryhackx-magnet-link.forum.rename.save')}
                    </button>
                    <button class="Button MagnetRenameModal-cancel">
                        ${app.translator.trans('tryhackx-magnet-link.forum.rename.cancel')}
                    </button>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);

        // Focus na input
        const input = overlay.querySelector('.MagnetRenameModal-input');
        setTimeout(() => input.focus(), 50);

        // Zamknij modal
        const closeModal = () => overlay.remove();

        overlay.querySelector('.MagnetRenameModal-close').addEventListener('click', closeModal);
        overlay.querySelector('.MagnetRenameModal-cancel').addEventListener('click', closeModal);
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) closeModal();
        });

        // Escape zamyka modal
        const escHandler = (e) => {
            if (e.key === 'Escape') {
                closeModal();
                document.removeEventListener('keydown', escHandler);
            }
        };
        document.addEventListener('keydown', escHandler);

        // Zapisz nową nazwę
        overlay.querySelector('.MagnetRenameModal-save').addEventListener('click', async () => {
            const newName = input.value.trim();
            if (!newName) return;

            try {
                await app.request({
                    method: 'POST',
                    url: app.forum.attribute('apiUrl') + '/magnet/rename',
                    body: { token, post_id: postId, custom_name: newName }
                });
                // Wyczyść cache i przerenderuj
                this.dataCache.delete(cacheKey);
                // Wyczyść też cache tooltipa dyskusji
                if (app.magnetTooltip) app.magnetTooltip.clearCache();
                const data = await this.fetchMagnetData(token, postId);
                this.renderMagnetLink(element, token, data, postId, postUserId);
                closeModal();
            } catch (error) {
                console.error('Rename error:', error);
            }
        });

        // Enter zapisuje
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                overlay.querySelector('.MagnetRenameModal-save').click();
            }
        });

        // Przywróć oryginalną nazwę
        const restoreBtn = overlay.querySelector('.MagnetRenameModal-restore');
        if (restoreBtn) {
            restoreBtn.addEventListener('click', async () => {
                try {
                    await app.request({
                        method: 'DELETE',
                        url: app.forum.attribute('apiUrl') + '/magnet/rename',
                        body: { token, post_id: postId }
                    });
                    this.dataCache.delete(cacheKey);
                    // Wyczyść też cache tooltipa dyskusji
                    if (app.magnetTooltip) app.magnetTooltip.clearCache();
                    const data = await this.fetchMagnetData(token, postId);
                    this.renderMagnetLink(element, token, data, postId, postUserId);
                    closeModal();
                } catch (error) {
                    console.error('Restore error:', error);
                }
            });
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
