import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import CommentPost from 'flarum/forum/components/CommentPost';
import MagnetLinkManager from './MagnetLinkManager';
import addTextEditorButton from './addTextEditorButton';

app.initializers.add('tryhackx-magnet-link', () => {
    // Inicjalizacja managera magnet linków
    app.magnetLinkManager = new MagnetLinkManager();

    // Dodaj przycisk BBCode do edytora
    addTextEditorButton();

    // Extend CommentPost to initialize magnet links after render
    extend(CommentPost.prototype, 'oncreate', function () {
        this.initMagnetLinks();
    });

    extend(CommentPost.prototype, 'onupdate', function () {
        this.initMagnetLinks();
    });

    CommentPost.prototype.initMagnetLinks = function () {
        const element = this.element;
        if (!element) return;

        // Szukamy elementów z data-token (nie data-magnet!)
        const magnetElements = element.querySelectorAll('.MagnetLink:not([data-initialized])');
        
        magnetElements.forEach((el) => {
            el.setAttribute('data-initialized', 'true');
            
            const token = el.getAttribute('data-token');
            
            // Sprawdź czy token jest prawidłowy (64 znaki hex)
            if (token && token !== 'invalid' && token !== '' && /^[a-f0-9]{64}$/i.test(token)) {
                const postId = this.attrs?.post?.id?.() || null;
                app.magnetLinkManager.initializeMagnetLink(el, token, postId);
            } else {
                // Invalid lub pusty token
                el.innerHTML = '<div class="MagnetLink-container MagnetLink-error">' +
                    '<span class="MagnetLink-icon"><i class="fas fa-exclamation-triangle"></i></span>' +
                    '<span class="MagnetLink-text">Invalid magnet link</span>' +
                '</div>';
            }
        });
    };

    // Obserwuj zmiany DOM dla dynamicznie ładowanych postów
    if (typeof MutationObserver !== 'undefined') {
        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                mutation.addedNodes.forEach((node) => {
                    if (node.nodeType === Node.ELEMENT_NODE) {
                        const magnetElements = node.querySelectorAll?.('.MagnetLink:not([data-initialized])') || [];
                        magnetElements.forEach((el) => {
                            el.setAttribute('data-initialized', 'true');
                            const token = el.getAttribute('data-token');
                            
                            if (token && token !== 'invalid' && token !== '' && /^[a-f0-9]{64}$/i.test(token)) {
                                app.magnetLinkManager.initializeMagnetLink(el, token, null);
                            } else {
                                el.innerHTML = '<div class="MagnetLink-container MagnetLink-error">' +
                                    '<span class="MagnetLink-icon"><i class="fas fa-exclamation-triangle"></i></span>' +
                                    '<span class="MagnetLink-text">Invalid magnet link</span>' +
                                '</div>';
                            }
                        });
                    }
                });
            });
        });

        // Obserwuj body dla dynamicznych zmian
        if (document.body) {
            observer.observe(document.body, {
                childList: true,
                subtree: true
            });
        }
    }
});
