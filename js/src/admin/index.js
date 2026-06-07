export { default as extend } from './extend';

import app from 'flarum/admin/app';
import SupportModal from './components/SupportModal';

// Add Flarum's standard `Button--inverted` to the Cancel button in core's
// "Reset extension settings" modal so it doesn't render as a plain
// borderless button. We use a MutationObserver instead of extending the
// modal's prototype because the modal class is lazy-loaded by core and
// not statically importable through `flarum/admin/components/...` at
// module load. Each TryHackX extension registers this independently;
// repeated classList.add of the same class is a no-op.
app.initializers.add('tryhackx-magnet-link-cancel-inverted', () => {
    const invertCancel = (modal) => {
        const cancel = modal.querySelector('.Form-controls .Button:not(.Button--danger):not(.Button--primary)');
        if (cancel) cancel.classList.add('Button--inverted');
    };
    const observer = new MutationObserver((mutations) => {
        for (const mut of mutations) {
            for (const node of mut.addedNodes) {
                if (node.nodeType !== 1) continue;
                if (node.classList && node.classList.contains('ResetExtensionSettingsModal')) {
                    invertCancel(node);
                } else if (node.querySelectorAll) {
                    node.querySelectorAll('.ResetExtensionSettingsModal').forEach(invertCancel);
                }
            }
        }
    });
    observer.observe(document.body, { childList: true, subtree: true });
});

app.initializers.add('tryhackx-magnet-link-support', () => {
    app.registry.for('tryhackx-magnet-link').registerSetting(function () {
        return m('div', { className: 'MagnetLink-support' }, [
            m('button', {
                className: 'Button',
                onclick: () => app.modal.show(SupportModal),
            }, [
                m('i', { className: 'fas fa-heart Button-icon icon' }),
                app.translator.trans('tryhackx-magnet-link.admin.support.button'),
            ]),
        ]);
    }, 100); // wysoki priorytet — przycisk wsparcia zawsze na samej górze
});

app.initializers.add('tryhackx-magnet-link-reparse', () => {
    let reparsing = false;

    app.registry.for('tryhackx-magnet-link').registerSetting(function () {
        return m('div', { className: 'MagnetLink-reparse Form-group' }, [
            m('label', app.translator.trans('tryhackx-magnet-link.admin.reparse.label')),
            m('p', { className: 'helpText' }, app.translator.trans('tryhackx-magnet-link.admin.reparse.help')),
            m('button', {
                className: 'Button' + (reparsing ? ' disabled' : ''),
                disabled: reparsing,
                onclick: () => {
                    if (reparsing) return;
                    reparsing = true;
                    m.redraw();

                    app.request({
                        method: 'POST',
                        url: app.forum.attribute('apiUrl') + '/magnet/reparse',
                    })
                        .then((res) => {
                            app.alerts.show(
                                { type: 'success' },
                                app.translator.trans('tryhackx-magnet-link.admin.reparse.success', { count: (res && res.count) || 0 })
                            );
                        })
                        .catch(() => {
                            app.alerts.show({ type: 'error' }, app.translator.trans('tryhackx-magnet-link.admin.reparse.error'));
                        })
                        .then(() => {
                            reparsing = false;
                            m.redraw();
                        });
                },
            }, [
                m('i', { className: 'fas fa-rotate Button-icon icon' + (reparsing ? ' fa-spin' : '') }),
                ' ',
                app.translator.trans('tryhackx-magnet-link.admin.reparse.button'),
            ]),
        ]);
    });
});

// Reaktywna grupa ustawień wyglądu karty:
//  - wybór stylu desktopu (standard / mobile),
//  - zmienny opis różnic po wyborze,
//  - gdy wybrano "mobile": limit linii nazwy + wyrównanie statystyk.
// Renderowana jako funkcja, więc `this` = strona rozszerzenia (mamy
// this.setting / this.buildSettingComponent). Zmiana selecta przerysowuje
// blok, więc pola warunkowe pojawiają się / znikają na żywo.
app.initializers.add('tryhackx-magnet-link-display-style', () => {
    const t = (key) => app.translator.trans('tryhackx-magnet-link.admin.settings.' + key, {}, true);

    app.registry.for('tryhackx-magnet-link').registerSetting(function () {
        const isMobile = this.setting('tryhackx-magnet-link.desktop_style', 'standard')() === 'mobile';

        const items = [
            this.buildSettingComponent({
                type: 'select',
                setting: 'tryhackx-magnet-link.desktop_style',
                options: {
                    standard: t('desktop_style_standard'),
                    mobile: t('desktop_style_mobile'),
                },
                label: t('desktop_style_label'),
                help: t('desktop_style_help'),
                default: 'standard',
            }),
            m(
                'div',
                {
                    className: 'MagnetLink-styleDesc helpText',
                    style: 'margin:-4px 0 14px;padding:8px 10px;border-left:3px solid #e74c3c;background:rgba(231,76,60,0.06);border-radius:4px;',
                },
                isMobile ? t('desktop_style_desc_mobile') : t('desktop_style_desc_standard')
            ),
        ];

        if (isMobile) {
            items.push(
                this.buildSettingComponent({
                    type: 'number',
                    setting: 'tryhackx-magnet-link.name_max_lines',
                    label: t('name_max_lines_label'),
                    help: t('name_max_lines_help'),
                    min: 1,
                    max: 20,
                    default: 3,
                }),
                this.buildSettingComponent({
                    type: 'select',
                    setting: 'tryhackx-magnet-link.stats_justify',
                    options: {
                        'space-between': t('stats_justify_space_between'),
                        'space-around': t('stats_justify_space_around'),
                        center: t('stats_justify_center'),
                        'flex-start': t('stats_justify_none'),
                    },
                    label: t('stats_justify_label'),
                    help: t('stats_justify_help'),
                    default: 'space-between',
                })
            );
        }

        return m('div', { className: 'MagnetLink-displayStyle Form-group' }, items);
    }, 30);
});

// Przycisk „Zabezpiecz tokeny" — widoczny TYLKO gdy istniejące tokeny są na
// starszym (publiczny salt) schemacie (token_scheme < 2). Po sukcesie schemat
// lokalnie skacze do 2 i przycisk znika; w trakcie jest zablokowany (spinner).
// Równoległe uruchomienia blokuje też serwer (cache lock w kontrolerze).
app.initializers.add('tryhackx-magnet-link-retokenize', () => {
    let running = false;

    app.registry.for('tryhackx-magnet-link').registerSetting(function () {
        const scheme = parseInt(app.data.settings['tryhackx-magnet-link.token_scheme'], 10) || 1;
        if (scheme >= 2) return null; // już zabezpieczone — nic nie pokazuj

        return m('div', { className: 'MagnetLink-retokenize Form-group' }, [
            m('label', app.translator.trans('tryhackx-magnet-link.admin.retokenize.label')),
            m('p', {
                className: 'helpText',
                style: 'padding:8px 10px;border-left:3px solid #e74c3c;background:rgba(231,76,60,0.06);border-radius:4px;',
            }, app.translator.trans('tryhackx-magnet-link.admin.retokenize.help')),
            m('button', {
                className: 'Button Button--primary' + (running ? ' disabled' : ''),
                disabled: running,
                onclick: () => {
                    if (running) return;
                    running = true;
                    m.redraw();

                    app.request({
                        method: 'POST',
                        url: app.forum.attribute('apiUrl') + '/magnet/retokenize',
                    })
                        .then((res) => {
                            // Schemat skacze do bieżącego → przycisk znika.
                            app.data.settings['tryhackx-magnet-link.token_scheme'] = String((res && res.scheme) || 2);
                            app.alerts.show(
                                { type: 'success' },
                                app.translator.trans('tryhackx-magnet-link.admin.retokenize.success', { count: (res && res.count) || 0 })
                            );
                        })
                        .catch(() => {
                            app.alerts.show({ type: 'error' }, app.translator.trans('tryhackx-magnet-link.admin.retokenize.error'));
                        })
                        .then(() => {
                            running = false;
                            m.redraw();
                        });
                },
            }, [
                m('i', { className: 'fas fa-shield-halved Button-icon icon' + (running ? ' fa-spin' : '') }),
                ' ',
                app.translator.trans('tryhackx-magnet-link.admin.retokenize.button'),
            ]),
        ]);
    }, 95);
});
