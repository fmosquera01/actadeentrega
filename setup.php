<?php
declare(strict_types=1);

define('PLUGIN_ACTADEENTREGA_VERSION', '0.2.61');

require_once __DIR__ . '/src/ActaService.php';
require_once __DIR__ . '/src/ActaPdf.php';
require_once __DIR__ . '/src/SigningService.php';
require_once __DIR__ . '/src/PublicSigningService.php';
require_once __DIR__ . '/src/FinalMailService.php';
require_once __DIR__ . '/src/NotificationEventActadeentrega.php';
require_once __DIR__ . '/src/NotificationActadeentregaSetting.php';
require_once __DIR__ . '/src/Menu.php';
require_once __DIR__ . '/src/Profile.php';
require_once __DIR__ . '/src/Acta.php';

function plugin_init_actadeentrega(): void
{
    global $PLUGIN_HOOKS, $CFG_GLPI;
    // GLPI 11 reads this key for every registered notification mode.
    // Initialize it when the plugin mode is first registered; the native
    // NotificationSettingConfig form persists subsequent changes in core config.
    if (!array_key_exists('notifications_actadeentrega', $CFG_GLPI)) {
        $CFG_GLPI['notifications_actadeentrega'] = 0;
    }
    $PLUGIN_HOOKS['csrf_compliant']['actadeentrega'] = true;
    Notification_NotificationTemplate::registerMode('actadeentrega', 'Acta de Entrega', 'actadeentrega');
    $PLUGIN_HOOKS['helpdesk_menu_entry']['actadeentrega'] = '/front/my-acts.php';
    $PLUGIN_HOOKS['helpdesk_menu_entry_icon']['actadeentrega'] = 'ti ti-file-certificate';
    \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts(
        'actadeentrega',
        '#^/front/public-sign\\.php$#',
        \Glpi\Http\Firewall::STRATEGY_NO_CHECK
    );
    $PLUGIN_HOOKS['menu_entry']['actadeentrega'] = true;
    $PLUGIN_HOOKS['menu_toadd']['actadeentrega'] = ['plugins' => [PluginActadeentregaMenu::class]];
    Plugin::registerClass(PluginActadeentregaMenu::class);
    Plugin::registerClass(PluginActadeentregaActa::class, ['addtabon' => ['User']]);
    Plugin::registerClass(PluginActadeentregaProfile::class, ['addtabon' => ['Profile']]);
}

function plugin_version_actadeentrega(): array
{
    return [
        'name' => 'Acta de Entrega',
        'version' => PLUGIN_ACTADEENTREGA_VERSION,
        'author' => 'Songa - Infraestructura IT',
        'license' => 'GPL-3.0-or-later',
        'homepage' => 'https://github.com/fmosquera01/actadeentrega',
        'requirements' => ['glpi' => ['min' => '11.0.0', 'max' => '11.99.99'], 'php' => ['min' => '8.2.0']],
    ];
}

function plugin_actadeentrega_check_prerequisites(): bool
{
    return defined('GLPI_VERSION')
        && version_compare(GLPI_VERSION, '11.0.0', '>=')
        && version_compare(GLPI_VERSION, '12.0.0', '<');
}

function plugin_actadeentrega_check_config(): bool { return true; }

function plugin_actadeentrega_update(string $oldVersion): bool
{
    return plugin_actadeentrega_install();
}
