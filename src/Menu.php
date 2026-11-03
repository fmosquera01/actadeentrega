<?php
declare(strict_types=1);

final class PluginActadeentregaMenu extends CommonGLPI
{
    public static function getMenuName(): string
    {
        return __('Actas de Entrega', 'actadeentrega');
    }

    public static function getMenuContent(): array|false
    {
        if (!Session::haveRight('plugin_actadeentrega', READ)) return false;
        return [
            'title' => self::getMenuName(),
            'page' => '/plugins/actadeentrega/front/acta.php',
            'icon' => 'ti ti-file-certificate',
            'options' => [],
        ];
    }
}
