<?php

define('PLUGIN_MAILAPROVE_VERSION', '1.0.4');
define('PLUGIN_MAILAPROVE_MIN_GLPI', '11.0.0');
define('PLUGIN_MAILAPROVE_MAX_GLPI', '11.99.99');

use Glpi\Plugin\Hooks;
use GlpiPlugin\Mailaprove\NotificationHandler;

function plugin_version_mailaprove(): array
{
    return [
        'name'           => __('Approval By Mail', 'mailaprove'),
        'version'        => PLUGIN_MAILAPROVE_VERSION,
        'author'         => 'Community',
        'license'        => 'GPLv3+',
        'homepage'       => 'https://github.com/community/mailaprove',
        'minGlpiVersion' => PLUGIN_MAILAPROVE_MIN_GLPI,
        'maxGlpiVersion' => PLUGIN_MAILAPROVE_MAX_GLPI,
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_MAILAPROVE_MIN_GLPI,
                'max' => PLUGIN_MAILAPROVE_MAX_GLPI,
            ],
        ],
    ];
}

function plugin_init_mailaprove(): void
{
    global $PLUGIN_HOOKS;

    // CSRF compliance: the `Hooks::CSRF_COMPLIANT` flag was deprecated in
    // GLPI 11.0.0 — the HTTP middleware now validates and consumes
    // `_glpi_csrf_token` automatically on POST requests. We just keep the
    // hidden token input inside our forms; do NOT call Session::checkCSRF
    // manually or the second call will fail.

    // Register notification data hook - injects custom tags
    $PLUGIN_HOOKS[Hooks::ITEM_GET_DATA]['mailaprove'] = [
        'NotificationTargetTicket' => [NotificationHandler::class, 'handleNotificationData'],
    ];

    // Public mail links must be reachable without an authenticated GLPI session.
    // In GLPI 11, stateless plugin paths bypass session, firewall and CSRF checks.
    if (
        class_exists(\Glpi\Http\SessionManager::class)
        && method_exists(\Glpi\Http\SessionManager::class, 'registerPluginStatelessPath')
    ) {
        \Glpi\Http\SessionManager::registerPluginStatelessPath(
            'mailaprove',
            '#^/front/(approve|reject|solution_approve|solution_reject|satisfaction)\.php$#'
        );
    }

    // Explicit firewall strategies for legacy plugin scripts (GLPI 11+).
    // Without these the request router can fall through to the inventory
    // agent handler, producing an XML parsing error when an HTML form is
    // submitted.
    if (
        class_exists(\Glpi\Http\Firewall::class)
        && method_exists(\Glpi\Http\Firewall::class, 'addPluginStrategyForLegacyScripts')
    ) {
        // Public mail action endpoints — explicitly anonymous.
        \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts(
            'mailaprove',
            '#^/front/(approve|reject|solution_approve|solution_reject|satisfaction)\.php$#',
            \Glpi\Http\Firewall::STRATEGY_NO_CHECK
        );

        // Admin endpoints — authenticated central interface only.
        \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts(
            'mailaprove',
            '#^/front/(config\.form|audit)\.php$#',
            \Glpi\Http\Firewall::STRATEGY_CENTRAL_ACCESS
        );

        \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts(
            'mailaprove',
            '#^/ajax/template\.preview\.php$#',
            \Glpi\Http\Firewall::STRATEGY_CENTRAL_ACCESS
        );
    }

    // Admin menu for configuration
    if (Plugin::isPluginActive('mailaprove')) {
        $PLUGIN_HOOKS['config_page']['mailaprove'] = 'front/config.form.php';

        if (Session::haveRight('config', UPDATE)) {
            $PLUGIN_HOOKS['menu_toadd']['mailaprove'] = [
                'config' => 'GlpiPlugin\\Mailaprove\\Config',
            ];
        }
    }
}

function plugin_mailaprove_check_prerequisites(): bool
{
    if (!method_exists('Plugin', 'checkGlpiVersion')) {
        $version = preg_replace('/^((\d+\.?)+).*$/', '$1', GLPI_VERSION);
        if (version_compare($version, PLUGIN_MAILAPROVE_MIN_GLPI, '<')) {
            echo 'This plugin requires GLPI >= ' . PLUGIN_MAILAPROVE_MIN_GLPI;
            return false;
        }
    }
    return true;
}

function plugin_mailaprove_check_config($verbose = false): bool
{
    return true;
}
