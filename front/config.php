<?php
declare(strict_types=1);

include('../../../inc/includes.php');
Session::checkRight('config', UPDATE);
Html::header('Configuración - Actas de Entrega', $_SERVER['PHP_SELF'], 'plugins', 'PluginActadeentregaMenu');

global $DB;
$table = 'glpi_plugin_actadeentrega_mail_recipients';
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Session::validateCSRFToken($_POST)) {
        Html::displayErrorAndDie('La solicitud no superó la validación de seguridad.');
    }

    $action = (string)($_POST['action'] ?? '');
    if ($action === 'add') {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Session::addMessageAfterRedirect('Ingrese una dirección de correo válida.', false, ERROR);
        } else {
            $existing = $DB->request([
                'SELECT' => ['id'],
                'FROM' => $table,
                'WHERE' => ['email' => $email],
                'LIMIT' => 1,
            ])->current();
            if ($existing) {
                $DB->update($table, ['is_active' => 1], ['id' => (int)$existing['id']]);
                Session::addMessageAfterRedirect('El destinatario ya existía y fue habilitado.', true, INFO);
            } else {
                $DB->insert($table, ['email' => $email, 'is_active' => 1]);
                Session::addMessageAfterRedirect('Destinatario agregado correctamente.', true, INFO);
            }
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $row = $DB->request(['SELECT' => ['is_active'], 'FROM' => $table, 'WHERE' => ['id' => $id], 'LIMIT' => 1])->current();
        if ($row) {
            $DB->update($table, ['is_active' => (int)!((int)$row['is_active'])], ['id' => $id]);
            Session::addMessageAfterRedirect('Estado del destinatario actualizado.', true, INFO);
        }
    } elseif ($action === 'delete') {
        $DB->delete($table, ['id' => (int)($_POST['id'] ?? 0)]);
        Session::addMessageAfterRedirect('Destinatario eliminado.', true, INFO);
    }
    Html::redirect('/plugins/actadeentrega/front/config.php');
}

echo "<div class='container-fluid' style='max-width:1000px'>";
echo "<div class='d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3'><div><h2>Configuración de Actas de Entrega</h2><p class='text-muted mb-0'>Destinatarios adicionales para las notificaciones de las actas.</p></div><a class='btn btn-outline-secondary' href='/plugins/actadeentrega/front/acta.php'>Volver</a></div>";
echo "<div class='card card-body mb-4'><h3 class='h5'>Agregar destinatario</h3><p class='text-muted small'>Estas direcciones recibirán copia de las notificaciones del proceso, además de los firmantes. No sustituyen los correos del colaborador ni del gerente.</p>";
echo "<form method='post' class='row g-2 align-items-end'><input type='hidden' name='_glpi_csrf_token' value='" . $escape(Session::getNewCSRFToken(true)) . "'><input type='hidden' name='action' value='add'><div class='col-md-8'><label class='form-label' for='ae-email'>Correo electrónico</label><input class='form-control' id='ae-email' name='email' type='email' maxlength='255' required placeholder='nombre@songa.com'></div><div class='col-md-4'><button class='btn btn-primary' type='submit'><i class='ti ti-plus'></i> Agregar correo</button></div></form></div>";

echo "<div class='card card-body'><h3 class='h5'>Destinatarios configurados</h3><div class='table-responsive'><table class='table table-hover align-middle'><thead><tr><th>Correo</th><th>Estado</th><th class='text-end'>Acciones</th></tr></thead><tbody>";
$rows = $DB->request(['SELECT' => ['id', 'email', 'is_active'], 'FROM' => $table, 'ORDER' => ['email ASC']]);
$count = 0;
foreach ($rows as $row) {
    $count++;
    $id = (int)$row['id'];
    $active = (int)$row['is_active'] === 1;
    echo "<tr><td>" . $escape($row['email']) . "</td><td>" . ($active ? "<span class='badge bg-success'>Activo</span>" : "<span class='badge bg-secondary'>Inactivo</span>") . "</td><td class='text-end'><div class='d-inline-flex gap-2'>";
    echo "<form method='post'><input type='hidden' name='_glpi_csrf_token' value='" . $escape(Session::getNewCSRFToken(true)) . "'><input type='hidden' name='action' value='toggle'><input type='hidden' name='id' value='{$id}'><button class='btn btn-sm btn-outline-secondary' type='submit'>" . ($active ? 'Desactivar' : 'Activar') . "</button></form>";
    echo "<form method='post' onsubmit=\"return confirm('¿Eliminar este destinatario?')\"><input type='hidden' name='_glpi_csrf_token' value='" . $escape(Session::getNewCSRFToken(true)) . "'><input type='hidden' name='action' value='delete'><input type='hidden' name='id' value='{$id}'><button class='btn btn-sm btn-outline-danger' type='submit'>Eliminar</button></form>";
    echo "</div></td></tr>";
}
if ($count === 0) echo "<tr><td colspan='3' class='text-muted'>Todavía no hay destinatarios adicionales configurados.</td></tr>";
echo "</tbody></table></div></div></div>";
Html::footer();
