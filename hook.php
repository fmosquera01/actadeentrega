<?php
declare(strict_types=1);

/**
 * Database lifecycle for Acta de Entrega.
 * Uses GLPI's Migration API; issued acts are preserved on uninstall.
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

    $actaColumns = [
        'status' => "varchar(40) NOT NULL DEFAULT 'Firmada completamente'",
        'issuer_signature_path' => "varchar(500) NOT NULL DEFAULT ''",
        'recipient_signature_path' => "varchar(500) NOT NULL DEFAULT ''",
        'manager_signature_path' => "varchar(500) NOT NULL DEFAULT ''",
        'recipient_signed_at' => 'datetime NULL',
        'manager_signed_at' => 'datetime NULL',
        'manager_signature_method' => "varchar(30) NOT NULL DEFAULT ''",
    ];
    foreach ($actaColumns as $field => $definition) {
        if (!$DB->fieldExists($table, $field)) {
            $migration->addPostQuery("ALTER TABLE `{$table}` ADD `{$field}` {$definition}");
        }
    }

    $publicTokenColumns = [
        'manager_token_hash' => "char(64) NOT NULL DEFAULT ''",
        'manager_token_created_at' => 'datetime NULL',
        'manager_token_expires_at' => 'datetime NULL',
        'manager_token_used_at' => 'datetime NULL',
        'manager_token_revoked_at' => 'datetime NULL',
        'manager_email_sent_at' => 'datetime NULL',
        'manager_email' => "varchar(255) NOT NULL DEFAULT ''",
    ];
    foreach ($publicTokenColumns as $field => $definition) {
        if (!$DB->fieldExists($table, $field)) {
            $migration->addPostQuery("ALTER TABLE `{$table}` ADD `{$field}` {$definition}");
        }
    }

    $signatures = 'glpi_plugin_actadeentrega_signatures';
    if (!$DB->tableExists($signatures)) {
        $migration->addPostQuery("CREATE TABLE IF NOT EXISTS `{$signatures}` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `plugin_actadeentrega_actas_id` int unsigned NOT NULL,
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `role` varchar(30) NOT NULL,
            `method` varchar(30) NOT NULL,
            `detail` varchar(255) NOT NULL DEFAULT '',
            `signed_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `acta_id` (`plugin_actadeentrega_actas_id`),
            KEY `users_id` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
    }

    // Destinatarios institucionales adicionales para notificaciones de actas.
    $mailRecipients = 'glpi_plugin_actadeentrega_mail_recipients';
    if (!$DB->tableExists($mailRecipients)) {
        $migration->addPostQuery("CREATE TABLE IF NOT EXISTS `{$mailRecipients}` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `email` varchar(255) NOT NULL,
            `is_active` tinyint NOT NULL DEFAULT 1,
            `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
    }

    // Firma independiente, método, fecha e integridad para cada responsable.
    $participantColumns = [
        'signature_path' => "varchar(500) NOT NULL DEFAULT ''",
        'signature_sha256' => "char(64) NOT NULL DEFAULT ''",
        'signature_method' => "varchar(30) NOT NULL DEFAULT ''",
        'signed_at' => 'datetime NULL',
    ];
    foreach ($participantColumns as $field => $definition) {
        if (!$DB->fieldExists($participants, $field)) {
            $migration->addPostQuery("ALTER TABLE `{$participants}` ADD `{$field}` {$definition}");
        }
    }
    // Compatibilidad: conservar como firma del titular la firma principal de actas anteriores.
    $migration->addPostQuery("UPDATE `{$participants}` p
        INNER JOIN `glpi_plugin_actadeentrega_actas` a
            ON a.id = p.plugin_actadeentrega_actas_id
        SET p.signature_path = a.recipient_signature_path,
            p.signature_method = 'legacy',
            p.signed_at = COALESCE(a.recipient_signed_at, a.date_creation)
        WHERE p.is_holder = 1
          AND p.signature_path = ''
          AND a.recipient_signature_path <> ''");
    
    $migration->executeMigration();

    $rights = ['plugin_actadeentrega'];
    $profiles = $DB->request(['SELECT' => ['id'], 'FROM' => Profile::getTable()]);
    foreach ($profiles as $profile) {
        $profiles_id = (int)$profile['id'];
        $existingRights = ProfileRight::getProfileRights($profiles_id, $rights);
        foreach ($rights as $rightName) {
            if (!array_key_exists($rightName, $existingRights)) {
                $DB->insert(ProfileRight::getTable(), ['profiles_id' => $profiles_id, 'name' => $rightName]);
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
    return true;
}
