<?php
declare(strict_types=1);

final class PluginActadeentregaProfile extends Profile
{
    public static $rightname = 'plugin_actadeentrega';

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        return $item instanceof Profile ? self::createTabEntry(__('Acta de Entrega', 'actadeentrega')) : '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Profile) {
            $profile = new self();
            $profile->showRights((int)$item->getID());
        }
        return true;
    }

    private function showRights(int $profileId): void
    {
        $target = new Profile();
        if (!$target->getFromDB($profileId)) return;
        $canEdit = Session::haveRight('profile', UPDATE);
        echo "<div class='spaced'>";
        if ($canEdit) echo "<form method='post' action='" . htmlspecialchars(Profile::getFormURL(), ENT_QUOTES, 'UTF-8') . "'>";
        $target->displayRightsChoiceMatrix([
            ['itemtype' => PluginActadeentregaActa::class, 'label' => __('Actas de Entrega', 'actadeentrega'), 'field' => self::$rightname],
        ], ['canedit' => $canEdit, 'title' => __('Derechos de Actas de Entrega', 'actadeentrega')]);
        if ($canEdit) {
            echo "<div class='text-center'>" . Html::hidden('id', ['value' => $profileId]) . Html::submit(_sx('button', 'Save'), ['name' => 'update']) . '</div>';
            Html::closeForm();
        }
        echo '</div>';
    }
}
