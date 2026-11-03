<?php
declare(strict_types=1);

include('../../../inc/includes.php');
Session::checkRight('plugin_actadeentrega', READ);
Html::header('Historial de Actas', $_SERVER['PHP_SELF'], 'management', 'PluginActadeentregaMenu');
global $DB;
echo "<div class='container-fluid'><h2>Historial de actas de entrega</h2><table class='table table-sm table-hover'><thead><tr><th>Número</th><th>Fecha</th><th>Colaborador</th><th>Emitido por</th><th>PDF</th></tr></thead><tbody>";
foreach ($DB->request(['SELECT'=>['id','act_number','act_date','recipient_users_id','issuer_users_id'],'FROM'=>'glpi_plugin_actadeentrega_acts','ORDER'=>['id DESC']]) as $row) {
    echo '<tr><td>' . htmlspecialchars($row['act_number'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars($row['act_date'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars(getUserName((int)$row['recipient_users_id']), ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars(getUserName((int)$row['issuer_users_id']), ENT_QUOTES, 'UTF-8') . '</td><td><a target="_blank" href="/plugins/actadeentrega/front/pdf.php?id=' . (int)$row['id'] . '">Ver PDF</a></td></tr>';
}
echo "</tbody></table></div>";
Html::footer();
