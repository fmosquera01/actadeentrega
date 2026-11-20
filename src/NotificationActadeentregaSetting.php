<?php
declare(strict_types=1);

/**
 * GLPI 11 notification settings adapter for the Acta de Entrega mail mode.
 * Reuses GLPI's native mailing settings, including its configured SMTP/O365 transport.
 */
final class PluginActadeentregaNotificationActadeentregaSetting extends NotificationMailingSetting
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
}
