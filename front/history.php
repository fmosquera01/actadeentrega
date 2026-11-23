<?php
declare(strict_types=1);

include('../../../inc/includes.php');
require_once __DIR__ . '/../src/Navigation.php';
Session::checkRight('plugin_actadeentrega', READ);
Html::header('Historial de Actas', $_SERVER['PHP_SELF'], 'plugins', 'PluginActadeentregaMenu');
PluginActadeentregaNavigation::backButton('/plugins/actadeentrega/front/acta.php');
global $DB;
echo "<div class='container-fluid'><h2>Historial de actas de entrega</h2><table class='table table-sm table-hover'><thead><tr><th>Número</th><th>Fecha</th><th>Colaboradores responsables</th><th>Activos incluidos</th><th>Emitido por</th><th>Estado</th><th>Acción</th></tr></thead><tbody>";
foreach ($DB->request(['SELECT'=>['id','act_number','act_date','recipient_users_id','issuer_users_id','snapshot','status'],'FROM'=>'glpi_plugin_actadeentrega_actas','ORDER'=>['id DESC']]) as $row) {
    $snapshot = json_decode((string)$row['snapshot'], true) ?: [];
    $names = array_map(static fn($p) => (string)($p['name'] ?? ''), $snapshot['participants'] ?? []);
    if (!$names) $names = [getUserName((int)$row['recipient_users_id'])];
    $assets = array_map(static function($a) {
        $label = trim((string)($a['type'] ?? '') . ' ' . (string)($a['serial'] ?? ''));
        return $label !== '' ? $label : (string)($a['description'] ?? 'Activo');
    }, $snapshot['assets'] ?? []);
    echo '<tr><td>' . htmlspecialchars($row['act_number'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars($row['act_date'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars(implode(', ', array_filter($names)), ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars(implode('; ', $assets), ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars(getUserName((int)$row['issuer_users_id']), ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string)($row['status'] ?? 'Firmada completamente'), ENT_QUOTES, 'UTF-8') . '</td><td>' . ((string)($row['status'] ?? '') !== 'Firmada completamente' && (string)($row['status'] ?? '') !== 'Anulada' ? '<a href="/plugins/actadeentrega/front/sign.php?id=' . (int)$row['id'] . '">Continuar firmas</a> · ' : '') . '<a target="_blank" href="/plugins/actadeentrega/front/pdf.php?id=' . (int)$row['id'] . '">Ver PDF</a></td></tr>';
}
echo "</tbody></table></div>";
Html::footer();
