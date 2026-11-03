<?php
declare(strict_types=1);

final class PluginActadeentregaActa extends CommonDBTM
{
    public static $rightname = 'plugin_actadeentrega';

    public static function getTypeName($nb = 0): string
    {
        return _n('Acta de Entrega', 'Actas de Entrega', $nb, 'actadeentrega');
    }
}
