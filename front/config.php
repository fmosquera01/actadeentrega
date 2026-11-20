<?php
declare(strict_types=1);

include('../../../inc/includes.php');
Session::checkRight('config', UPDATE);
Html::header('Configuración - Actas de Entrega', $_SERVER['PHP_SELF'], 'plugins', 'PluginActadeentregaMenu');

global $DB;
require_once __DIR__ . '/../src/FinalMailService.php';
$table = 'glpi_plugin_actadeentrega_mail_recipients';
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'save_final_mail') {
        try {
            $toEmails = trim((string)($_POST['to_emails'] ?? ''));
            $ccEmails = trim((string)($_POST['cc_emails'] ?? ''));
            $toList = PluginActadeentregaFinalMailService::parseEmails($toEmails);
            PluginActadeentregaFinalMailService::parseEmails($ccEmails);
            $enabled = isset($_POST['is_enabled']) ? 1 : 0;
            if ($enabled && !$toList) throw new RuntimeException('Para activar el envío automático debe configurar al menos un destinatario en Para.');
            $subject = trim((string)($_POST['subject_template'] ?? ''));
            $body = trim((string)($_POST['body_template'] ?? ''));
            if ($subject === '' || $body === '') throw new RuntimeException('El asunto y el mensaje no pueden quedar vacíos.');
            $values = [
                'id' => 1, 'to_emails' => $toEmails, 'cc_emails' => $ccEmails,
                'subject_template' => $subject, 'body_template' => $body,
                'is_enabled' => $enabled,
            ];
            $existingConfig = $DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_plugin_actadeentrega_mail_config', 'WHERE' => ['id' => 1], 'LIMIT' => 1])->current();
            if ($existingConfig) $DB->update('glpi_plugin_actadeentrega_mail_config', $values, ['id' => 1]);
            else $DB->insert('glpi_plugin_actadeentrega_mail_config', $values);
            Session::addMessageAfterRedirect('Configuración de envío automático guardada.', true, INFO);
        } catch (Throwable $e) {
            Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
        }
        Html::redirect('/plugins/actadeentrega/front/config.php');
    } elseif ($action === 'add') {
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

$mailSettings = PluginActadeentregaFinalMailService::getSettings();
echo "<div class='container-fluid' style='max-width:1100px'>";
echo "<div class='d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3'><div><h2>Configuración de Actas de Entrega</h2><p class='text-muted mb-0'>Notificaciones de firma y envío automático a Activo Fijo.</p></div><a class='btn btn-outline-secondary' href='/plugins/actadeentrega/front/acta.php'>Volver</a></div>";
echo "<div class='card card-body mb-4'><h3 class='h5'>Envío automático a Activo Fijo</h3><p class='text-muted small'>Se enviará al completarse la firma del gerente y generarse el PDF definitivo. GLPI utilizará el remitente y el servidor SMTP configurados en Administración de notificaciones.</p>";
echo "<form method='post'><input type='hidden' name='_glpi_csrf_token' value='" . $escape(Session::getNewCSRFToken(true)) . "'><input type='hidden' name='action' value='save_final_mail'>";
echo "<div class='form-check form-switch mb-3'><input class='form-check-input' type='checkbox' role='switch' id='ae-mail-enabled' name='is_enabled' value='1' " . ((int)$mailSettings['is_enabled'] === 1 ? 'checked' : '') . "><label class='form-check-label fw-semibold' for='ae-mail-enabled'>Activar envío automático al finalizar el acta</label></div>";
echo "<div class='row g-3'><div class='col-md-6'><label class='form-label' for='ae-mail-to'>Para – Lista(s) de distribución de Activo Fijo</label><textarea class='form-control' id='ae-mail-to' name='to_emails' rows='2' required placeholder='activofijo@songa.com'>" . $escape($mailSettings['to_emails']) . "</textarea><div class='form-text'>Puede ingresar varias direcciones separadas por coma, punto y coma o espacio.</div></div>";
echo "<div class='col-md-6'><label class='form-label' for='ae-mail-cc'>CC – Lista(s) de distribución de técnicos</label><textarea class='form-control' id='ae-mail-cc' name='cc_emails' rows='2' placeholder='tecnicos-it@songa.com'>" . $escape($mailSettings['cc_emails']) . "</textarea><div class='form-text'>Las direcciones se incluirán como copia visible real del correo.</div></div></div>";
echo "<div class='mt-3'><label class='form-label' for='ae-mail-subject'>Asunto</label><input class='form-control' id='ae-mail-subject' name='subject_template' maxlength='500' required value='" . $escape($mailSettings['subject_template']) . "'></div>";
echo "<div class='mt-3'><label class='form-label' for='ae-mail-body'>Mensaje</label><textarea class='form-control' id='ae-mail-body' name='body_template' rows='13' required>" . $escape($mailSettings['body_template']) . "</textarea></div>";
echo "<div class='mt-2'><div class='form-text'>Variables disponibles: {numero_acta}, {fecha_entrega}, {usuario}, {gerente}, {tecnico}, {activos}, {fecha_firma}, {remitente}. La dirección {remitente} se obtiene automáticamente de la configuración de GLPI.</div></div>";
echo "<div class='d-flex justify-content-end mt-3'><button class='btn btn-primary' type='submit'><i class='ti ti-device-floppy'></i> Guardar configuración</button></div></form></div>";
echo "<div class='card card-body mb-4'><h3 class='h5'>Copias de solicitudes de firma al gerente</h3><p class='text-muted small'>Estos destinatarios adicionales se mantienen para el correo de solicitud de firma remota del gerente. No son los destinatarios del envío final a Activo Fijo.</p>";
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
