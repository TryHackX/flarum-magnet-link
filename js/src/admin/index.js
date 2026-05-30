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
    });
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
