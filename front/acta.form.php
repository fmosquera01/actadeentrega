<?php
declare(strict_types=1);

include('../../../inc/includes.php');
Session::checkRight('plugin_actadeentrega', CREATE);
require_once __DIR__ . '/../src/ActaService.php';

$embeddedInUser = ((int)($_GET['embedded'] ?? 0) === 1);
if (!$embeddedInUser) {
    Html::header('Nueva acta de entrega', $_SERVER['PHP_SELF'], 'plugins', 'PluginActadeentregaMenu');
}

global $DB;
$userId = (int) ($_GET['users_id'] ?? 0);
$fromUser = ((int)($_GET['from_user'] ?? 0) === 1 && $userId > 0);
$user = $userId > 0 ? PluginActadeentregaActaService::getUser($userId) : [];
$assets = $userId > 0 ? PluginActadeentregaActaService::getAssets($userId) : [];
$signatureExists = false;
if ($DB->tableExists('glpi_plugin_informeglopi_user_signatures')) {
    $signatureExists = (int)$DB->request([
        'COUNT' => 'c',
        'FROM' => 'glpi_plugin_informeglopi_user_signatures',
        'WHERE' => ['users_id' => Session::getLoginUserID()],
    ])->current()['c'] > 0;
}

echo "<div class='container-fluid'>";
echo "<div class='d-flex align-items-center justify-content-between mb-3'><h2 class='mb-0'>Nueva acta de entrega</h2>";
if ($fromUser) {
    echo "<a class='btn btn-outline-secondary' href='/front/user.form.php?id=" . $userId . "&forcetab=PluginActadeentregaActa$1'>Volver al colaborador</a>";
}
echo "</div>";

echo "<form method='get' class='card card-body mb-3'>";
if ($fromUser) {
    echo "<input type='hidden' name='from_user' value='1'>";
}
if ($embeddedInUser) {
    echo "<input type='hidden' name='embedded' value='1'><input type='hidden' name='acta_action' value='new'>";
}
echo "<label class='form-label fw-semibold'>Colaborador titular</label>";
User::dropdown([
    'name' => 'users_id',
    'value' => $userId,
    'right' => 'all',
    'entity' => -1,
    'entity_sons' => true,
    'on_change' => 'this.form.submit()',
    'display' => true,
    'width' => '100%',
]);
echo "</form>";

if ($user) {
    echo "<h3>Datos recuperados de GLPI</h3><div class='table-responsive'><table class='table table-sm table-bordered' style='max-width:900px'>";
    foreach (['recipient'=>'Responsable','department'=>'Departamento','jobtitle'=>'Cargo','supervisor'=>'Jefe / Supervisor','area'=>'Área / Ubicación','manager'=>'Gerente (ubicación o supervisor)'] as $key=>$label) {
        $value = trim((string)($user[$key] ?? ''));
        echo '<tr><th style="width:280px">' . $label . '</th><td>' . ($value !== '' ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : '<span class="text-danger fw-bold">FALTA COMPLETAR EN GLPI</span>') . '</td></tr>';
    }
    echo "</table></div>";
    echo "<h3>Activos asignados actualmente</h3>";
    echo "<form method='post' action='/plugins/actadeentrega/front/create.php'>";
    echo "<input type='hidden' name='_glpi_csrf_token' value='" . Session::getNewCSRFToken(true) . "'>";
    echo "<input type='hidden' name='users_id' value='" . $userId . "'>";
    echo "<input type='hidden' name='from_user' value='" . ($fromUser ? '1' : '0') . "'>";

    if (!$fromUser) {
        echo "<div class='card card-body mb-3' style='max-width:900px'><label class='form-label fw-semibold'>Otros colaboradores responsables</label>";
        echo "<p class='text-muted small mb-2'>Escriba el nombre y seleccione uno o varios colaboradores. Puede agregar varios en el mismo campo, igual que en los participantes de un ticket.</p>";
        User::dropdown([
            'name' => 'co_responsible_users',
            'multiple' => true,
            'value' => [],
            'right' => 'all',
            'display' => true,
            'width' => '100%',
        ]);
        echo "<small class='form-text text-muted'>Los corresponsables se registran únicamente en esta acta. No se modifica la asignación de los activos en GLPI.</small></div>";
    } else {
        echo "<div class='alert alert-info py-2'>Esta acta se generará únicamente a nombre del colaborador cuya ficha está abierta.</div>";
    }

    echo "<div class='table-responsive'><table class='table table-sm table-hover table-bordered'><thead><tr><th>Incluir</th><th>Tipo</th><th>Serie</th><th>Descripción del equipo</th><th>Activo fijo</th></tr></thead><tbody>";
    foreach ($assets as $a) {
        $desc = PluginActadeentregaActaService::describeAsset($a);
        echo '<tr><td><input class="form-check-input" type="checkbox" name="assets[]" value="' . htmlspecialchars($a['_itemtype'] . ':' . (int)$a['id'], ENT_QUOTES, 'UTF-8') . '"></td><td>' . htmlspecialchars($a['_label'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string)$a['serial'], ENT_QUOTES, 'UTF-8') . '</td><td>' . nl2br(htmlspecialchars($desc, ENT_QUOTES, 'UTF-8')) . '</td><td>' . htmlspecialchars((string)($a['otherserial'] ?: 'N/A'), ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }
    if (!$assets) echo "<tr><td colspan='5'>No se encontraron activos con asignación actual a este colaborador.</td></tr>";
    echo "</tbody></table></div>";
    echo "<div class='mt-3' style='max-width:320px'><label class='form-label'>PIN personal para autorizar la firma del técnico</label><input type='password' class='form-control' name='signature_pin' inputmode='numeric' pattern='[0-9]{6}' maxlength='6' required></div>";
    echo "<button class='btn btn-primary mt-3' type='submit'>Validar y generar acta PDF</button></form>";
    if (!$signatureExists) echo "<div class='alert alert-warning mt-3'>No tiene firma personal registrada. Regístrela desde su perfil de GLPI en la pestaña Firma personal del plugin Informe Técnico.</div>";
}
echo "</div>";
if (!$embeddedInUser) {
    Html::footer();
}
