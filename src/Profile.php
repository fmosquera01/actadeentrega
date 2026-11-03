<?php
declare(strict_types=1);

/**
 * Permisos nativos por perfil de GLPI.
 *
 * Sigue el mismo patrón de la pestaña de permisos de Informe Técnico:
 * - pestaña propia dentro de Configuración > Perfiles;
 * - matriz nativa de derechos GLPI;
 * - únicamente administradores de perfiles pueden modificar los permisos;
 * - no se concede acceso automáticamente a ningún perfil.
 */
final class PluginActadeentregaProfile extends Profile
{
    public static $rightname = 'plugin_actadeentrega';

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Profile && (int) $item->getField('id') > 0) {
            return self::createTabEntry(__('Acta de Entrega', 'actadeentrega'));
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Profile) {
            $profile = new self();
            $profile->showFormActaEntrega((int) $item->getID());
        }

        return true;
    }

    private function showFormActaEntrega(int $profiles_id): void
    {
        $targetProfile = new Profile();

        // Solo quien puede administrar perfiles puede modificar esta matriz.
        if (!Session::haveRight('profile', UPDATE)) {
            return;
        }

        if (!$targetProfile->getFromDB($profiles_id)) {
            return;
        }

        echo "<div class='spaced'>";
        echo "<form method='post' action='" . htmlspecialchars(Profile::getFormURL(), ENT_QUOTES, 'UTF-8') . "'>";

        $rights = [
            [
                'itemtype' => PluginActadeentregaActa::class,
                'label'    => __('Actas de Entrega', 'actadeentrega'),
                'field'    => self::$rightname,
            ],
        ];

        $targetProfile->displayRightsChoiceMatrix($rights, [
            'canedit' => true,
            'title'   => __('Derechos de Acta de Entrega', 'actadeentrega'),
        ]);

        echo "<div class='text-center'>";
        echo Html::hidden('id', ['value' => $profiles_id]);
        echo Html::submit(_sx('button', 'Save'), ['name' => 'update']);
        echo '</div>';
        Html::closeForm();
        echo '</div>';
    }
}
