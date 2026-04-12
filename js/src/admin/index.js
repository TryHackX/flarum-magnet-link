import app from 'flarum/admin/app';
import SupportModal from './components/SupportModal';

app.initializers.add('tryhackx-magnet-link-support', () => {
    app.extensionData.for('tryhackx-magnet-link').registerSetting(function () {
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

app.initializers.add('tryhackx-magnet-link', () => {
    app.extensionData
        .for('tryhackx-magnet-link')
        .registerSetting({
            setting: 'tryhackx-magnet-link.guest_visible',
            type: 'boolean',
            label: app.translator.trans('tryhackx-magnet-link.admin.settings.guest_visible_label'),
            help: app.translator.trans('tryhackx-magnet-link.admin.settings.guest_visible_help'),
        })
        .registerSetting({
            setting: 'tryhackx-magnet-link.activated_only',
            type: 'boolean',
            label: app.translator.trans('tryhackx-magnet-link.admin.settings.activated_only_label'),
            help: app.translator.trans('tryhackx-magnet-link.admin.settings.activated_only_help'),
        })
        .registerSetting({
            setting: 'tryhackx-magnet-link.scraper_enabled',
            type: 'boolean',
            label: app.translator.trans('tryhackx-magnet-link.admin.settings.scraper_enabled_label'),
            help: app.translator.trans('tryhackx-magnet-link.admin.settings.scraper_enabled_help'),
        })
        .registerSetting({
            setting: 'tryhackx-magnet-link.http_only',
            type: 'boolean',
            label: app.translator.trans('tryhackx-magnet-link.admin.settings.http_only_label'),
            help: app.translator.trans('tryhackx-magnet-link.admin.settings.http_only_help'),
        })
        .registerSetting({
            setting: 'tryhackx-magnet-link.check_all',
            type: 'boolean',
            label: app.translator.trans('tryhackx-magnet-link.admin.settings.check_all_label'),
            help: app.translator.trans('tryhackx-magnet-link.admin.settings.check_all_help'),
        })
        .registerSetting({
            setting: 'tryhackx-magnet-link.display_type',
            type: 'select',
            label: app.translator.trans('tryhackx-magnet-link.admin.settings.display_type_label'),
            help: app.translator.trans('tryhackx-magnet-link.admin.settings.display_type_help'),
            options: {
                average: app.translator.trans('tryhackx-magnet-link.admin.settings.display_type_average'),
                average_max_downloads: app.translator.trans('tryhackx-magnet-link.admin.settings.display_type_average_max'),
                max_all: app.translator.trans('tryhackx-magnet-link.admin.settings.display_type_max_all'),
            },
            default: 'average',
        })
        .registerSetting({
            setting: 'tryhackx-magnet-link.tracker_timeout',
            type: 'number',
            label: app.translator.trans('tryhackx-magnet-link.admin.settings.tracker_timeout_label'),
            help: app.translator.trans('tryhackx-magnet-link.admin.settings.tracker_timeout_help'),
            min: 1,
            max: 30,
            default: 2,
        })
        .registerSetting({
            setting: 'tryhackx-magnet-link.max_trackers',
            type: 'number',
            label: app.translator.trans('tryhackx-magnet-link.admin.settings.max_trackers_label'),
            help: app.translator.trans('tryhackx-magnet-link.admin.settings.max_trackers_help'),
            min: 0,
            max: 50,
            default: 0,
        })
        .registerSetting({
            setting: 'tryhackx-magnet-link.click_tracking',
            type: 'boolean',
            label: app.translator.trans('tryhackx-magnet-link.admin.settings.click_tracking_label'),
            help: app.translator.trans('tryhackx-magnet-link.admin.settings.click_tracking_help'),
        })
        .registerSetting({
            setting: 'tryhackx-magnet-link.tooltip_enabled',
            type: 'boolean',
            label: app.translator.trans('tryhackx-magnet-link.admin.settings.tooltip_enabled_label'),
            help: app.translator.trans('tryhackx-magnet-link.admin.settings.tooltip_enabled_help'),
        })
        .registerSetting({
            setting: 'tryhackx-magnet-link.tooltip_max_magnets',
            type: 'number',
            label: app.translator.trans('tryhackx-magnet-link.admin.settings.tooltip_max_magnets_label'),
            help: app.translator.trans('tryhackx-magnet-link.admin.settings.tooltip_max_magnets_help'),
            min: 0,
            max: 20,
            default: 3,
        })
        .registerSetting({
            setting: 'tryhackx-magnet-link.rename_enabled',
            type: 'boolean',
            label: app.translator.trans('tryhackx-magnet-link.admin.settings.rename_enabled_label'),
            help: app.translator.trans('tryhackx-magnet-link.admin.settings.rename_enabled_help'),
        })
        .registerSetting({
            setting: 'tryhackx-magnet-link.ban_enabled',
            type: 'boolean',
            label: app.translator.trans('tryhackx-magnet-link.admin.settings.ban_enabled_label'),
            help: app.translator.trans('tryhackx-magnet-link.admin.settings.ban_enabled_help'),
        })
        .registerSetting({
            setting: 'tryhackx-magnet-link.ban_time',
            type: 'number',
            label: app.translator.trans('tryhackx-magnet-link.admin.settings.ban_time_label'),
            help: app.translator.trans('tryhackx-magnet-link.admin.settings.ban_time_help'),
            min: 1,
            max: 1440,
            default: 20,
        })
        .registerSetting({
            setting: 'tryhackx-magnet-link.ban_interval',
            type: 'number',
            label: app.translator.trans('tryhackx-magnet-link.admin.settings.ban_interval_label'),
            help: app.translator.trans('tryhackx-magnet-link.admin.settings.ban_interval_help'),
            min: 1,
            max: 60,
            default: 10,
        })
        .registerSetting({
            setting: 'tryhackx-magnet-link.ban_interval_count',
            type: 'number',
            label: app.translator.trans('tryhackx-magnet-link.admin.settings.ban_interval_count_label'),
            help: app.translator.trans('tryhackx-magnet-link.admin.settings.ban_interval_count_help'),
            min: 5,
            max: 1000,
            default: 100,
        })
        .registerSetting({
            setting: 'tryhackx-magnet-link.self_interval',
            type: 'number',
            label: app.translator.trans('tryhackx-magnet-link.admin.settings.self_interval_label'),
            help: app.translator.trans('tryhackx-magnet-link.admin.settings.self_interval_help'),
            min: 1,
            max: 365,
            default: 1,
        })
        .registerPermission(
            {
                icon: 'fas fa-magnet',
                label: app.translator.trans('tryhackx-magnet-link.permissions.viewMagnetLinks'),
                permission: 'tryhackx-magnet-link.viewMagnetLinks',
            },
            'view',
            95
        );
});
