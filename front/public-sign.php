<?php
declare(strict_types=1);

include('../../../inc/includes.php');
require_once __DIR__ . '/../src/ActaService.php';
require_once __DIR__ . '/../src/SigningService.php';
require_once __DIR__ . '/../src/PublicSigningService.php';

$token = (string)($_POST['token'] ?? $_GET['token'] ?? '');
$acta = null;
$snapshot = [];
$error = '';
$success = false;

try {
    $acta = PluginActadeentregaPublicSigningService::validateToken($token);
    $snapshot = json_decode((string)$acta['snapshot'], true) ?: [];
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
header('Pragma: no-cache');
echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Firma de Acta de Entrega</title>';
echo '<link rel="stylesheet" href="/css/palettes/default.css"><link rel="stylesheet" href="/css/styles.css">';
echo '<style>body{background:#f3f5f7;font-family:Arial,sans-serif}.ae-public{max-width:680px;margin:7vh auto;padding:24px}.ae-card{background:#fff;border:1px solid #dce2e8;border-radius:12px;padding:30px;box-shadow:0 8px 30px rgba(20,40,60,.08)}.ae-brand{font-weight:800;color:#174f75;letter-spacing:.04em}.ae-public h1{font-size:24px;margin:18px 0 8px}.ae-muted{color:#64717d;line-height:1.6}.ae-alert{padding:14px;border-radius:7px;background:#fceeed;color:#8a2923}.ae-success{padding:14px;border-radius:7px;background:#eaf6ef;color:#17643a}</style></head><body><main class="ae-public"><section class="ae-card"><div class="ae-brand">SONGA | INFRAESTRUCTURA IT</div><h1>Firma de Acta de Entrega</h1>';
if ($success) {
    echo '<div class="ae-success"><strong>Firma registrada correctamente.</strong><p>El acta ' . $escape($actNumber) . ' quedó firmada y el PDF definitivo fue generado.</p>Ya puede cerrar esta ventana.</div>';
} elseif ($error !== '') {
    echo '<div class="ae-alert"><strong>No fue posible completar la firma.</strong><p>' . nl2br($escape($error)) . '</p>Si necesita un nuevo enlace, solicítelo al equipo de Infraestructura IT.</div>';
} else {
    echo '<p class="ae-muted">Estimado/a ' . $escape($managerName) . ', revise la solicitud y confirme su firma para el acta <strong>' . $escape($actNumber) . '</strong>.</p>';
    echo '<p class="ae-muted">Para autorizar la firma se requiere su PIN personal de Informe Técnico. El PIN no se envía ni se almacena en este formulario.</p>';
    echo '<form method="post" autocomplete="off"><input type="hidden" name="token" value="' . $escape($token) . '"><label for="manager_pin" class="form-label">PIN personal de 6 dígitos</label><input id="manager_pin" name="manager_pin" type="password" class="form-control" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus autocomplete="one-time-code"><button type="submit" class="btn btn-primary mt-3">Confirmar y firmar acta</button></form>';
    echo '<p class="ae-muted mt-3 small">Este enlace es personal, de un solo uso y vence a las 48 horas de su emisión.</p>';
}
echo '</section></main></body></html>';
