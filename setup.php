<?php
declare(strict_types=1);

define('PLUGIN_ACTADEENTREGA_VERSION', '0.2.1');

require_once __DIR__ . '/src/ActaService.php';
require_once __DIR__ . '/src/ActaPdf.php';
require_once __DIR__ . '/src/Menu.php';
require_once __DIR__ . '/src/Profile.php';
require_once __DIR__ . '/src/Acta.php';

function plugin_init_actadeentrega(): void
{
    global $PLUGIN_HOOKS;
    $PLUGIN_HOOKS['csrf_compliant']['actadeentrega'] = true;
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

/**
 * Version 0.2.1 improves native GLPI user selectors and preserves the user profile workflow.
 */
function plugin_actadeentrega_update(string $oldVersion): bool
{
    return plugin_actadeentrega_install();
}
