<?php
declare(strict_types=1);

final class PluginActadeentregaActa extends CommonDBTM
{
    public static $rightname = 'plugin_actadeentrega';

    public static function getTypeName($nb = 0): string
    {
        return _n('Acta de Entrega', 'Actas de Entrega', $nb, 'actadeentrega');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof User && (int)$item->getID() > 0 && Session::haveRight(self::$rightname, READ)) {
            return self::createTabEntry(__('Actas de Entrega', 'actadeentrega'));
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof User || !Session::haveRight(self::$rightname, READ)) return false;
        global $DB;
        $userId = (int)$item->getID();
        echo "<div class='d-flex justify-content-between align-items-center mb-3'><h3>Actas de entrega del colaborador</h3>";
        if (Session::haveRight(self::$rightname, CREATE)) {
            echo "<a class='btn btn-primary' href='/plugins/actadeentrega/front/acta.form.php?users_id=" . $userId . "'>Nueva acta</a>";
        }
        echo "</div>";
        $ids = [];
        if ($DB->tableExists('glpi_plugin_actadeentrega_acta_users')) {
            foreach ($DB->request(['SELECT' => ['plugin_actadeentrega_actas_id'], 'FROM' => 'glpi_plugin_actadeentrega_acta_users', 'WHERE' => ['users_id' => $userId]]) as $r) {
                $ids[] = (int)$r['plugin_actadeentrega_actas_id'];
            }
        }
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_plugin_actadeentrega_actas', 'WHERE' => ['recipient_users_id' => $userId]]) as $r) {
            $ids[] = (int)$r['id'];
        }
        $ids = array_values(array_unique($ids));
        if (!$ids) {
            echo "<div class='alert alert-info'>Este colaborador todavía no tiene actas de entrega.</div>";
            return true;
        }
        echo "<div class='table-responsive'><table class='table table-sm table-hover'><thead><tr><th>Número</th><th>Fecha</th><th>Activos</th><th>PDF</th></tr></thead><tbody>";
        foreach ($DB->request(['SELECT' => ['id','act_number','act_date','snapshot'], 'FROM' => 'glpi_plugin_actadeentrega_actas', 'WHERE' => ['id' => $ids], 'ORDER' => ['id DESC']]) as $row) {
            $snapshot = json_decode((string)$row['snapshot'], true) ?: [];
            $assets = array_map(static fn($asset) => trim((string)($asset['type'] ?? '') . ' ' . (string)($asset['serial'] ?? '')), $snapshot['assets'] ?? []);
            echo "<tr><td>" . htmlspecialchars((string)$row['act_number'], ENT_QUOTES, 'UTF-8') . "</td><td>" . htmlspecialchars((string)$row['act_date'], ENT_QUOTES, 'UTF-8') . "</td><td>" . htmlspecialchars(implode(', ', array_filter($assets)), ENT_QUOTES, 'UTF-8') . "</td><td><a target='_blank' href='/plugins/actadeentrega/front/pdf.php?id=" . (int)$row['id'] . "'>Ver PDF</a></td></tr>";
        }
        echo "</tbody></table></div>";
        return true;
    }
}
