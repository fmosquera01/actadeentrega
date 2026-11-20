<?php
declare(strict_types=1);

include('../../../inc/includes.php');
require_once __DIR__ . '/../src/ActaService.php';
require_once __DIR__ . '/../src/SigningService.php';
require_once __DIR__ . '/../src/PublicSigningService.php';
require_once __DIR__ . '/../src/FinalMailService.php';
require_once __DIR__ . '/../src/ActaPresentation.php';

$token = (string)($_POST['token'] ?? $_GET['token'] ?? '');
$acta = null;
$snapshot = [];
$participants = [];
$error = '';
$success = false;
$mailQueued = false;

try {
    $acta = PluginActadeentregaPublicSigningService::validateToken($token);
    $snapshot = json_decode((string)$acta['snapshot'], true) ?: [];
    $participants = PluginActadeentregaSigningService::participants((int)$acta['id']);
    $managerId = (int)($snapshot['user']['manager_users_id'] ?? 0);
    if ($managerId < 1) {
        throw new RuntimeException('No se pudo identificar al gerente asignado.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $pin = (string)($_POST['manager_pin'] ?? '');
        $authorized = PluginActadeentregaActaService::authorizeSignature($managerId, $pin);

        global $DB;
        $DB->beginTransaction();
        try {
            PluginActadeentregaPublicSigningService::consume($acta);
            $DB->update('glpi_plugin_actadeentrega_actas', [
                'manager_signature_path' => $authorized['path'],
                'manager_signature_method' => 'pin_remoto',
            ], ['id' => (int)$acta['id']]);
            PluginActadeentregaSigningService::audit(
                (int)$acta['id'],
                $managerId,
                'gerente',
                'pin_remoto',
                'Firma remota autorizada mediante enlace temporal y PIN personal.'
            );
            $fresh = PluginActadeentregaSigningService::acta((int)$acta['id']);
            PluginActadeentregaSigningService::finalize($fresh);
            $DB->commit();
            try {
                $mailQueued = PluginActadeentregaFinalMailService::enqueue((int)$acta['id']);
            } catch (Throwable $mailError) {
                PluginActadeentregaFinalMailService::markSendResult((int)$acta['id'], 'fallido', $mailError->getMessage());
                Toolbox::logInFile('mail-error', 'Acta de Entrega: no se pudo encolar el correo final del acta ' . (int)$acta['id'] . ': ' . $mailError->getMessage() . "\\n");
            }
            $success = true;
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$managerName = (string)($snapshot['user']['manager'] ?? 'Gerente');
$actNumber = (string)($acta['act_number'] ?? '');
$baseUrl = rtrim((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header('Pragma: no-cache');
echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Firma de Acta de Entrega</title>';
echo '<link rel="stylesheet" href="/css/palettes/default.css"><link rel="stylesheet" href="/css/styles.css">';
echo '<style>body{background:#f3f5f7;font-family:Arial,sans-serif}.ae-public{max-width:680px;margin:7vh auto;padding:24px}.ae-card{background:#fff;border:1px solid #dce2e8;border-radius:12px;padding:30px;box-shadow:0 8px 30px rgba(20,40,60,.08)}.ae-brand{font-weight:800;color:#174f75;letter-spacing:.04em}.ae-public h1{font-size:24px;margin:18px 0 8px}.ae-muted{color:#64717d;line-height:1.6}.ae-alert{padding:14px;border-radius:7px;background:#fceeed;color:#8a2923}.ae-success{padding:14px;border-radius:7px;background:#eaf6ef;color:#17643a}.ae-details{margin:22px 0;padding:18px;border:1px solid #dce2e8;border-radius:9px;background:#fff}.ae-details h2{font-size:19px;color:#174f75;margin:0 0 14px}.ae-details h3{font-size:15px;color:#263746;margin:18px 0 9px}.ae-summary{display:grid;grid-template-columns:1fr 1fr;gap:8px}.ae-field{padding:9px;background:#f5f7f9;border-radius:6px;min-width:0}.ae-field span{display:block;font-size:11px;color:#64717d;margin-bottom:3px}.ae-field strong{display:block;font-size:13px;overflow-wrap:anywhere}.ae-table-wrap{overflow-x:auto}.ae-details table{width:100%;border-collapse:collapse;font-size:12px}.ae-details th,.ae-details td{padding:8px;border:1px solid #dce2e8;text-align:left;vertical-align:top}.ae-details th{background:#f1f4f6}.ae-signed{color:#17643a;font-weight:700}.ae-pending{color:#9a5b00;font-weight:700}.ae-note{margin:14px 0 0;padding:10px;background:#eef5fa;border-radius:6px;font-size:12px;color:#315b78}.ae-empty{color:#8a2923;font-weight:600}.ae-terms{margin:0 0 18px;padding:14px 16px;border:1px solid #d6dee6;border-left:4px solid #315b78;border-radius:8px;background:#f7f9fb;color:#263746;font-size:13px;line-height:1.5}.ae-terms strong{display:block;margin-bottom:8px}.ae-terms ol{margin:0;padding-left:21px}.ae-terms li{margin:0 0 7px}.ae-terms li:last-child{margin-bottom:0}</style></head><body><main class="ae-public"><section class="ae-card"><div class="ae-brand">SONGA | INFRAESTRUCTURA IT</div><h1>Firma de Acta de Entrega</h1>';
if ($success) {
    echo '<div class="ae-success"><strong>Firma registrada correctamente.</strong><p>El acta ' . $escape($actNumber) . ' quedó firmada y el PDF definitivo fue generado. ' . ($mailQueued ? 'El correo a Activo Fijo quedó registrado en la cola de GLPI.' : 'El envío automático a Activo Fijo no quedó en cola; contacte a Sistemas para revisar la configuración.') . '</p>Ya puede cerrar esta ventana.</div>';
} elseif ($error !== '') {
    echo '<div class="ae-alert"><strong>No fue posible completar la firma.</strong><p>' . nl2br($escape($error)) . '</p>Si necesita un nuevo enlace, solicítelo al equipo de Infraestructura IT.</div>';
} else {
    echo '<p class="ae-muted">Estimado/a ' . $escape($managerName) . ', revise la solicitud y confirme su firma para el acta <strong>' . $escape($actNumber) . '</strong>.</p>';
    echo '<p class="ae-muted">Para autorizar la firma se requiere su PIN personal de Informe Técnico. El PIN no se envía ni se almacena en este formulario.</p>';
    echo PluginActadeentregaActaPresentation::renderDetails($acta, $snapshot, $participants);
    echo '<div class="ae-terms"><strong>Me comprometo con la compañía a hacerme responsable del cuidado de este equipo, con las siguientes cláusulas:</strong><ol><li>El responsable directo del equipo al momento de la entrega debe recibir una inducción de cómo se utiliza el equipo y el sistema por parte del Dpto. de Sistemas.</li><li>El equipo que se le entrega está en perfectas condiciones. En caso de que el equipo no esté funcionando, entregarlo al Dpto. de Sistemas.</li><li>En caso de que el equipo sea asignado por turno de trabajo deberá ser entregado al responsable del equipo del siguiente turno.</li><li>En caso de pérdida o daños causados por mal uso el equipo será descontado de su sueldo o liquidación de haberes al responsable directo.</li><li>La pérdida o daño por préstamo a algún otro empleado de la empresa no lo exime de su responsabilidad con el equipo.</li></ol></div>' ;
    echo '<form method="post" autocomplete="off"><input type="hidden" name="_glpi_csrf_token" value="' . $escape(Session::getNewCSRFToken(true)) . '"><input type="hidden" name="token" value="' . $escape($token) . '"><label for="manager_pin" class="form-label">PIN personal de 6 dígitos</label><input id="manager_pin" name="manager_pin" type="password" class="form-control" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus autocomplete="one-time-code"><button type="submit" class="btn btn-primary mt-3">Confirmar y firmar acta</button></form>';
    echo '<p class="ae-muted mt-3 small">Este enlace es personal, de un solo uso y vence a las 48 horas de su emisión.</p>';
}
echo '</section></main></body></html>';
