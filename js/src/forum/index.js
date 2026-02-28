import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import CommentPost from 'flarum/forum/components/CommentPost';
import DiscussionListItem from 'flarum/forum/components/DiscussionListItem';
import MagnetLinkManager from './MagnetLinkManager';
import DiscussionTooltip from './DiscussionTooltip';
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
                const postUserId = this.attrs?.post?.user?.()?.id?.() || this.attrs?.post?.data?.relationships?.user?.data?.id || null;
                app.magnetLinkManager.initializeMagnetLink(el, token, postId, postUserId);
            } else {
                // Invalid lub pusty token
                el.innerHTML = '<div class="MagnetLink-container MagnetLink-error">' +
                    '<span class="MagnetLink-icon"><i class="fas fa-exclamation-triangle"></i></span>' +
                    '<span class="MagnetLink-text">Invalid magnet link</span>' +
                '</div>';
            }
        });
    };

    // Tooltip na liście dyskusji (lazy init - app.forum niedostępne przy inicjalizacji)
    extend(DiscussionListItem.prototype, 'oncreate', function () {
        if (!app.forum.attribute('magnetTooltipEnabled')) return;
        if (!app.magnetTooltip) app.magnetTooltip = new DiscussionTooltip();
        this.setupMagnetTooltip(app.magnetTooltip);
    });

    extend(DiscussionListItem.prototype, 'onupdate', function () {
        if (!app.forum.attribute('magnetTooltipEnabled')) return;
        if (!app.magnetTooltip) app.magnetTooltip = new DiscussionTooltip();
        this.setupMagnetTooltip(app.magnetTooltip);
    });

    DiscussionListItem.prototype.setupMagnetTooltip = function (tooltip) {
        const element = this.element;
        if (!element || element.hasAttribute('data-magnet-tooltip')) return;
        element.setAttribute('data-magnet-tooltip', 'true');

        const mainArea = element.querySelector('.DiscussionListItem-main');
        if (!mainArea) return;

        const discussionId = this.attrs.discussion.id();
        let hoverTimeout;

        mainArea.addEventListener('mouseenter', () => {
            hoverTimeout = setTimeout(() => {
                tooltip.show(discussionId, mainArea);
            }, 300);
        });

        mainArea.addEventListener('mouseleave', () => {
            clearTimeout(hoverTimeout);
            tooltip.hide();
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
                                app.magnetLinkManager.initializeMagnetLink(el, token, null, null);
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
