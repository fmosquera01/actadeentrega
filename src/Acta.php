<?php
declare(strict_types=1);

final class PluginActadeentregaActa extends CommonDBTM
{
    public static $rightname = 'plugin_actadeentrega';

    public static function getTypeName($nb = 0): string
    {
        return _n('Acta de Entrega', 'Actas de Entrega', $nb, 'actadeentrega');
    }

    private static function canViewUserActas(int $userId): bool
    {
        return $userId > 0 && (
            Session::haveRight(self::$rightname, READ)
            || $userId === (int)Session::getLoginUserID()
        );
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof User && self::canViewUserActas((int)$item->getID())) {
            return self::createTabEntry(__('Actas de Entrega', 'actadeentrega'));
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof User) return false;
        $userId = (int)$item->getID();
        if (!self::canViewUserActas($userId)) return false;

        global $DB;
        $isOwnProfile = $userId === (int)Session::getLoginUserID();
        echo "<div class='d-flex justify-content-between align-items-center mb-3'><h3>Mis actas de entrega</h3>";
        if (!$isOwnProfile) echo "<h3>Actas de entrega del colaborador</h3>";
        if (Session::haveRight(self::$rightname, CREATE)) {
            echo "<a class='btn btn-primary' href='/front/user.form.php?id=" . $userId . "&forcetab=PluginActadeentregaActa$1&acta_action=new'>Nueva acta</a>";
        }
        echo "</div>";

        if (($_GET['acta_action'] ?? '') === 'new' && Session::haveRight(self::$rightname, CREATE)) {
            $_GET['users_id'] = $userId;
            $_GET['from_user'] = 1;
            $_GET['embedded'] = 1;
            require __DIR__ . '/../front/acta.form.php';
            return true;
        }

        $ids = [];
        if ($DB->tableExists('glpi_plugin_actadeentrega_acta_users')) {
            foreach ($DB->request([
                'SELECT' => ['plugin_actadeentrega_actas_id'],
                'FROM' => 'glpi_plugin_actadeentrega_acta_users',
                'WHERE' => ['users_id' => $userId],
            ]) as $r) {
                $ids[] = (int)$r['plugin_actadeentrega_actas_id'];
            }
        }
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM' => 'glpi_plugin_actadeentrega_actas',
            'WHERE' => ['recipient_users_id' => $userId],
        ]) as $r) {
            $ids[] = (int)$r['id'];
        }
        $ids = array_values(array_unique($ids));
        if (!$ids) {
            echo "<div class='alert alert-info'>Todavía no tienes actas de entrega asociadas a tu usuario.</div>";
            return true;
        }

        echo "<div class='table-responsive'><table class='table table-sm table-hover'><thead><tr><th>Número</th><th>Fecha</th><th>Activos</th><th>Estado</th><th>Documento</th></tr></thead><tbody>";
        foreach ($DB->request([
            'SELECT' => ['id', 'act_number', 'act_date', 'snapshot', 'status'],
            'FROM' => 'glpi_plugin_actadeentrega_actas',
            'WHERE' => ['id' => $ids],
            'ORDER' => ['id DESC'],
        ]) as $row) {
            $snapshot = json_decode((string)$row['snapshot'], true) ?: [];
            $assets = array_map(
                static fn($asset) => trim((string)($asset['type'] ?? '') . ' ' . (string)($asset['serial'] ?? '')),
                $snapshot['assets'] ?? []
            );
            $status = (string)($row['status'] ?? 'Pendiente');
            echo "<tr><td>" . htmlspecialchars((string)$row['act_number'], ENT_QUOTES, 'UTF-8') . "</td>";
            echo "<td>" . htmlspecialchars((string)$row['act_date'], ENT_QUOTES, 'UTF-8') . "</td>";
            echo "<td>" . htmlspecialchars(implode(', ', array_filter($assets)), ENT_QUOTES, 'UTF-8') . "</td>";
            echo "<td>" . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . "</td>";
            echo "<td><a target='_blank' rel='noopener' href='/plugins/actadeentrega/front/pdf.php?id=" . (int)$row['id'] . "'>Ver PDF</a></td></tr>";
        }
        echo "</tbody></table></div>";
        return true;
    }
}
