<?php
declare(strict_types=1);

include('../../../inc/includes.php');
Session::checkRight('plugin_actadeentrega', READ);
require_once __DIR__ . '/../src/ActaService.php';
Html::header('Nueva acta de entrega', $_SERVER['PHP_SELF'], 'management', 'PluginActadeentregaMenu');

global $DB;
$userId = (int) ($_GET['users_id'] ?? 0);
$user = $userId > 0 ? PluginActadeentregaActaService::getUser($userId) : [];
$assets = $userId > 0 ? PluginActadeentregaActaService::getAssets($userId) : [];
$errors = [];
$signatureExists = false;
if ($DB->tableExists('glpi_plugin_informeglopi_user_signatures')) {
    $signatureExists = $DB->request(['COUNT' => 'c', 'FROM' => 'glpi_plugin_informeglopi_user_signatures', 'WHERE' => ['users_id' => Session::getLoginUserID()]])->current()['c'] > 0;
}
echo "<div class='container-fluid'><h2>Nueva acta de entrega</h2>";
echo "<form method='get' class='mb-3'><label class='form-label'>Colaborador</label><select class='form-select' style='max-width:520px' name='users_id' onchange='this.form.submit()'><option value=''>Seleccione...</option>";
foreach ($DB->request(['SELECT' => ['id','name','firstname','realname'],'FROM'=>'glpi_users','WHERE'=>['is_deleted'=>0,'is_active'=>1],'ORDER'=>['realname ASC','firstname ASC']]) as $row) {
    $name = trim(($row['firstname'] ?? '') . ' ' . ($row['realname'] ?? ''));
    if ($name === '') $name = $row['name'];
    echo '<option value="' . (int)$row['id'] . '" ' . ((int)$row['id'] === $userId ? 'selected' : '') . '>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</option>';
}
echo "</select></form>";
if ($user) {
    echo "<h3>Datos recuperados de GLPI</h3><table class='table table-sm table-bordered' style='max-width:900px'>";
    foreach (['recipient'=>'Responsable','department'=>'Departamento','jobtitle'=>'Cargo','supervisor'=>'Jefe / Supervisor','area'=>'Área / Ubicación','manager'=>'Gerente (ubicación o supervisor)'] as $key=>$label) {
        $value = trim((string)($user[$key] ?? ''));
        echo '<tr><th style="width:280px">' . $label . '</th><td>' . ($value !== '' ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : '<span class="text-danger fw-bold">FALTA COMPLETAR EN GLPI</span>') . '</td></tr>';
    }
    echo "</table><h3>Activos asignados actualmente</h3>";
    echo "<form method='post' action='/plugins/actadeentrega/front/create.php'><input type='hidden' name='_glpi_csrf_token' value='" . Session::getNewCSRFToken(true) . "'><input type='hidden' name='users_id' value='" . $userId . "'>";
    echo "<div class='table-responsive'><table class='table table-sm table-hover table-bordered'><thead><tr><th>Incluir</th><th>Tipo</th><th>Serie</th><th>Descripción del equipo</th><th>Activo fijo</th></tr></thead><tbody>";
    foreach ($assets as $a) {
        $desc = PluginActadeentregaActaService::describeAsset($a);
        echo '<tr><td><input class="form-check-input" type="checkbox" name="assets[]" value="' . (int)$a['id'] . '"></td><td>' . htmlspecialchars($a['_label'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string)$a['serial'], ENT_QUOTES, 'UTF-8') . '</td><td>' . nl2br(htmlspecialchars($desc, ENT_QUOTES, 'UTF-8')) . '</td><td>' . htmlspecialchars((string)($a['otherserial'] ?: 'N/A'), ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }
    if (!$assets) echo "<tr><td colspan='5'>No se encontraron activos con asignación actual a este colaborador.</td></tr>";
    echo "</tbody></table></div><div class='mt-3' style='max-width:320px'><label class='form-label'>PIN personal para autorizar la firma del técnico</label><input type='password' class='form-control' name='signature_pin' inputmode='numeric' pattern='[0-9]{6}' maxlength='6' required></div>";
    echo "<button class='btn btn-primary mt-3' type='submit'>Validar y generar acta PDF</button></form>";
    if (!$signatureExists) echo "<div class='alert alert-warning mt-3'>No tiene firma personal registrada. Regístrela desde su perfil de GLPI en la pestaña Firma personal del plugin Informe Técnico.</div>";
}
echo "</div>";
Html::footer();
