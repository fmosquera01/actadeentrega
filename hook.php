<?php
declare(strict_types=1);

function plugin_actadeentrega_install(): bool
{
    global $DB;
    $charset = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $DB->queryOrDie("CREATE TABLE IF NOT EXISTS glpi_plugin_actadeentrega_acts (
        id int unsigned NOT NULL AUTO_INCREMENT,
        act_number varchar(40) NOT NULL,
        entities_id int unsigned NOT NULL DEFAULT 0,
        recipient_users_id int unsigned NOT NULL,
        issuer_users_id int unsigned NOT NULL,
        act_date datetime NOT NULL,
        snapshot longtext NOT NULL,
        pdf_path varchar(500) NOT NULL,
        pdf_sha256 char(64) NOT NULL,
        date_creation timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY unicity_act_number (act_number),
        KEY recipient (recipient_users_id),
        KEY issuer (issuer_users_id)
    ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}", 'Acta de Entrega: create acts table');
    if (class_exists('ProfileRight')) {
        ProfileRight::addProfileRights(['plugin_actadeentrega']);
    }
    return true;
}

function plugin_actadeentrega_uninstall(): bool
{
    // Los documentos emitidos son registros institucionales: se preservan al desinstalar.
    return true;
}
