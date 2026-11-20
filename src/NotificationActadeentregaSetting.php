<?php
declare(strict_types=1);

/**
 * Minimal GLPI 11 settings adapter for the plugin notification mode.
 * SMTP credentials remain managed by GLPI's native Email notification settings.
 */
final class PluginActadeentregaNotificationActadeentregaSetting extends NotificationSetting
{
    public static function getTypeName($nb = 0)
    {
        return 'Correo automático de Actas de Entrega';
    }

    public function getEnableLabel()
    {
        return 'Habilitar notificaciones de Actas de Entrega';
    }

    public static function getMode()
    {
        return 'actadeentrega';
    }

    /**
     * Open the plugin's own mail configuration instead of GLPI's generic
     * CommonDBTM form endpoint, which is not implemented for this adapter.
     */
    public static function getFormURL($full = true)
    {
        return Plugin::getWebDir('actadeentrega', $full) . '/front/config.php';
    }

    protected function showFormConfig()
    {
        echo '<div class="alert alert-info">';
        echo 'Este canal utiliza el servidor y la cuenta de correo configurados en GLPI, en la sección de notificaciones por correo. ';
        echo 'Los destinatarios, el asunto y el mensaje del envío final se administran desde la configuración del plugin Acta de Entrega.';
        echo '</div>';
    }

    public static function getIcon()
    {
        return 'ti ti-file-certificate';
    }
}
