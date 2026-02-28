import Extend from 'flarum/common/extenders';
import app from 'flarum/admin/app';

export default [
    new Extend.Admin()
        // --- Widoczność ---
        .setting(() => ({
            setting: 'tryhackx-magnet-link.guest_visible',
            type: 'boolean',
            label: app.translator.trans('tryhackx-magnet-link.admin.settings.guest_visible_label', {}, true),
            help: app.translator.trans('tryhackx-magnet-link.admin.settings.guest_visible_help', {}, true),
        }))
        .setting(() => ({
            setting: 'tryhackx-magnet-link.activated_only',
            type: 'boolean',
            label: app.translator.trans('tryhackx-magnet-link.admin.settings.activated_only_label', {}, true),
            help: app.translator.trans('tryhackx-magnet-link.admin.settings.activated_only_help', {}, true),
        }))

        // --- Scraper ---
        .setting(() => ({
            setting: 'tryhackx-magnet-link.scraper_enabled',
            type: 'boolean',
            label: app.translator.trans('tryhackx-magnet-link.admin.settings.scraper_enabled_label', {}, true),
            help: app.translator.trans('tryhackx-magnet-link.admin.settings.scraper_enabled_help', {}, true),
        }))
        .setting(() => ({
            setting: 'tryhackx-magnet-link.http_only',
            type: 'boolean',
            label: app.translator.trans('tryhackx-magnet-link.admin.settings.http_only_label', {}, true),
            help: app.translator.trans('tryhackx-magnet-link.admin.settings.http_only_help', {}, true),
        }))
        .setting(() => ({
            setting: 'tryhackx-magnet-link.check_all',
            type: 'boolean',
            label: app.translator.trans('tryhackx-magnet-link.admin.settings.check_all_label', {}, true),
            help: app.translator.trans('tryhackx-magnet-link.admin.settings.check_all_help', {}, true),
        }))
        .setting(() => ({
            setting: 'tryhackx-magnet-link.display_type',
            type: 'select',
            label: app.translator.trans('tryhackx-magnet-link.admin.settings.display_type_label', {}, true),
            help: app.translator.trans('tryhackx-magnet-link.admin.settings.display_type_help', {}, true),
            options: {
                average: app.translator.trans('tryhackx-magnet-link.admin.settings.display_type_average', {}, true),
                average_max_downloads: app.translator.trans('tryhackx-magnet-link.admin.settings.display_type_average_max', {}, true),
                max_all: app.translator.trans('tryhackx-magnet-link.admin.settings.display_type_max_all', {}, true),
            },
            default: 'average',
        }))
        .setting(() => ({
            setting: 'tryhackx-magnet-link.tracker_timeout',
            type: 'number',
            label: app.translator.trans('tryhackx-magnet-link.admin.settings.tracker_timeout_label', {}, true),
            help: app.translator.trans('tryhackx-magnet-link.admin.settings.tracker_timeout_help', {}, true),
            min: 1,
            max: 30,
            default: 2,
        }))
        .setting(() => ({
            setting: 'tryhackx-magnet-link.max_trackers',
            type: 'number',
            label: app.translator.trans('tryhackx-magnet-link.admin.settings.max_trackers_label', {}, true),
            help: app.translator.trans('tryhackx-magnet-link.admin.settings.max_trackers_help', {}, true),
            min: 0,
            max: 50,
            default: 0,
        }))

        // --- Click tracking ---
        .setting(() => ({
            setting: 'tryhackx-magnet-link.click_tracking',
            type: 'boolean',
            label: app.translator.trans('tryhackx-magnet-link.admin.settings.click_tracking_label', {}, true),
            help: app.translator.trans('tryhackx-magnet-link.admin.settings.click_tracking_help', {}, true),
        }))

        // --- Tooltip listy dyskusji ---
        .setting(() => ({
            setting: 'tryhackx-magnet-link.tooltip_enabled',
            type: 'boolean',
            label: app.translator.trans('tryhackx-magnet-link.admin.settings.tooltip_enabled_label', {}, true),
            help: app.translator.trans('tryhackx-magnet-link.admin.settings.tooltip_enabled_help', {}, true),
        }))
        .setting(() => ({
            setting: 'tryhackx-magnet-link.tooltip_max_magnets',
            type: 'number',
            label: app.translator.trans('tryhackx-magnet-link.admin.settings.tooltip_max_magnets_label', {}, true),
            help: app.translator.trans('tryhackx-magnet-link.admin.settings.tooltip_max_magnets_help', {}, true),
            min: 0,
            max: 20,
            default: 3,
        }))

        // --- Własna nazwa torrenta ---
        .setting(() => ({
            setting: 'tryhackx-magnet-link.rename_enabled',
            type: 'boolean',
            label: app.translator.trans('tryhackx-magnet-link.admin.settings.rename_enabled_label', {}, true),
            help: app.translator.trans('tryhackx-magnet-link.admin.settings.rename_enabled_help', {}, true),
        }))

        // --- Ban system ---
        .setting(() => ({
            setting: 'tryhackx-magnet-link.ban_enabled',
            type: 'boolean',
            label: app.translator.trans('tryhackx-magnet-link.admin.settings.ban_enabled_label', {}, true),
            help: app.translator.trans('tryhackx-magnet-link.admin.settings.ban_enabled_help', {}, true),
        }))
        .setting(() => ({
            setting: 'tryhackx-magnet-link.ban_time',
            type: 'number',
            label: app.translator.trans('tryhackx-magnet-link.admin.settings.ban_time_label', {}, true),
            help: app.translator.trans('tryhackx-magnet-link.admin.settings.ban_time_help', {}, true),
            min: 1,
            max: 1440,
            default: 20,
        }))
        .setting(() => ({
            setting: 'tryhackx-magnet-link.ban_interval',
            type: 'number',
            label: app.translator.trans('tryhackx-magnet-link.admin.settings.ban_interval_label', {}, true),
            help: app.translator.trans('tryhackx-magnet-link.admin.settings.ban_interval_help', {}, true),
            min: 1,
            max: 60,
            default: 10,
        }))
        .setting(() => ({
            setting: 'tryhackx-magnet-link.ban_interval_count',
            type: 'number',
            label: app.translator.trans('tryhackx-magnet-link.admin.settings.ban_interval_count_label', {}, true),
            help: app.translator.trans('tryhackx-magnet-link.admin.settings.ban_interval_count_help', {}, true),
            min: 5,
            max: 1000,
            default: 100,
        }))
        .setting(() => ({
            setting: 'tryhackx-magnet-link.self_interval',
            type: 'number',
            label: app.translator.trans('tryhackx-magnet-link.admin.settings.self_interval_label', {}, true),
            help: app.translator.trans('tryhackx-magnet-link.admin.settings.self_interval_help', {}, true),
            min: 1,
            max: 365,
            default: 1,
        }))

        // --- Permissions ---
        .permission(
            () => ({
                icon: 'fas fa-magnet',
                label: app.translator.trans('tryhackx-magnet-link.permissions.viewMagnetLinks', {}, true),
                permission: 'tryhackx-magnet-link.viewMagnetLinks',
            }),
            'view',
            95
        ),
];
