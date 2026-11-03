<?php
declare(strict_types=1);

/**
 * Database lifecycle for Acta de Entrega.
 * Uses GLPI's Migration API; direct SQL execution is blocked by GLPI 11.
 * Existing issued acts are preserved when the plugin is uninstalled.
 */
function plugin_actadeentrega_install(): bool
{
    global $DB;

    $migration = new Migration(PLUGIN_ACTADEENTREGA_VERSION);
    $charset = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();

    $table = 'glpi_plugin_actadeentrega_actas';

    if (!$DB->tableExists($table)) {
        $migration->addPostQuery("CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `act_number` varchar(40) NOT NULL,
            `entities_id` int unsigned NOT NULL DEFAULT 0,
            `recipient_users_id` int unsigned NOT NULL,
            `issuer_users_id` int unsigned NOT NULL,
            `act_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `snapshot` longtext NOT NULL,
            `pdf_path` varchar(500) NOT NULL,
            `pdf_sha256` char(64) NOT NULL,
            `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity_act_number` (`act_number`),
            KEY `recipient` (`recipient_users_id`),
            KEY `issuer` (`issuer_users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
    }

    $participants = 'glpi_plugin_actadeentrega_acta_users';
    if (!$DB->tableExists($participants)) {
        $migration->addPostQuery("CREATE TABLE IF NOT EXISTS `{$participants}` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `plugin_actadeentrega_actas_id` int unsigned NOT NULL,
            `users_id` int unsigned NOT NULL,
            `is_holder` tinyint NOT NULL DEFAULT 0,
            `sort_order` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_acta_user` (`plugin_actadeentrega_actas_id`, `users_id`),
            KEY `users_id` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
    }

    $migration->executeMigration();

    // Register the plugin right for every existing profile, without
    // duplicating records when installation/upgrade is run more than once.
    $rights = ['plugin_actadeentrega'];
    $profiles = $DB->request([
        'SELECT' => ['id'],
        'FROM'   => Profile::getTable(),
    ]);

    foreach ($profiles as $profile) {
        $profiles_id = (int) $profile['id'];
        $existingRights = ProfileRight::getProfileRights($profiles_id, $rights);

        foreach ($rights as $rightName) {
            if (!array_key_exists($rightName, $existingRights)) {
                $DB->insert(
                    ProfileRight::getTable(),
                    [
                        'profiles_id' => $profiles_id,
                        'name'        => $rightName,
                    ]
                );
            }
        }
    }

    ProfileRight::cleanAllPossibleRights();

    return true;
}

function plugin_actadeentrega_upgrade($previousVersion): bool
{
    return plugin_actadeentrega_install();
}

function plugin_actadeentrega_uninstall(): bool
{
    // Los documentos emitidos son registros institucionales: se preservan.
    return true;
}
