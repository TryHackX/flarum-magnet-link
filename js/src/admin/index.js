export { default as extend } from './extend';

import app from 'flarum/admin/app';
import SupportModal from './components/SupportModal';

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
