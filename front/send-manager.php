<?php
declare(strict_types=1);

include('../../../inc/includes.php');
Session::checkRight('plugin_actadeentrega', CREATE);
require_once __DIR__ . '/../src/SigningService.php';
require_once __DIR__ . '/../src/PublicSigningService.php';

global $DB, $CFG_GLPI;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Html::displayNotFoundError();
}
$id = (int)($_POST['id'] ?? 0);
$token = '';

try {
    $acta = PluginActadeentregaSigningService::acta($id);
    $token = PluginActadeentregaPublicSigningService::createToken($acta);
    $emailAddress = trim((string)($acta['manager_email'] ?? ''));
    if (!filter_var($emailAddress, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('No se pudo validar el correo del gerente.');
    }

    $baseUrl = rtrim((string)($CFG_GLPI['url_base'] ?? ''), '/');
    if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || strtolower((string)parse_url($baseUrl, PHP_URL_SCHEME)) !== 'https') {
        throw new RuntimeException('La URL base de GLPI debe estar configurada con HTTPS para enviar enlaces de firma.');
    }

    $signatureUrl = $baseUrl . '/plugins/actadeentrega/front/public-sign.php?token=' . rawurlencode($token);
    $snapshot = json_decode((string)$acta['snapshot'], true) ?: [];
    $managerName = htmlspecialchars((string)($snapshot['user']['manager'] ?? 'Gerente'), ENT_QUOTES, 'UTF-8');
    $actNumber = htmlspecialchars((string)$acta['act_number'], ENT_QUOTES, 'UTF-8');
    $safeUrl = htmlspecialchars($signatureUrl, ENT_QUOTES, 'UTF-8');

    $sender = Config::getEmailSender();
    if (empty($sender['email']) || !filter_var($sender['email'], FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('GLPI no tiene configurado un correo remitente válido en Configuración > Notificaciones.');
    }

    $mailer = new GLPIMailer();
    $mail = $mailer->getEmail();
    $mail->from(new Symfony\Component\Mime\Address($sender['email'], (string)($sender['name'] ?? 'Songa - GLPI')));
    $mail->to(new Symfony\Component\Mime\Address($emailAddress, (string)($snapshot['user']['manager'] ?? '')));
    $mail->subject('Firma pendiente - Acta de Entrega ' . (string)$acta['act_number']);

    $cc = [];
    if ($DB->tableExists('glpi_plugin_actadeentrega_mail_recipients')) {
        foreach ($DB->request([
            'SELECT' => ['email'],
            'FROM' => 'glpi_plugin_actadeentrega_mail_recipients',
            'WHERE' => ['is_active' => 1],
        ]) as $recipient) {
            $extra = strtolower(trim((string)$recipient['email']));
            if ($extra !== '' && $extra !== strtolower($emailAddress) && filter_var($extra, FILTER_VALIDATE_EMAIL)) {
                $cc[$extra] = true;
            }
        }
    }
    foreach (array_keys($cc) as $extra) {
        $mail->addCc(new Symfony\Component\Mime\Address($extra));
    }

    $mail->html(
        '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#263746">'
        . '<h2>Acta de Entrega pendiente de su firma</h2>'
        . '<p>Estimado/a ' . $managerName . ':</p>'
        . '<p>El acta <strong>' . $actNumber . '</strong> ya fue firmada por el colaborador y requiere su revisión y firma.</p>'
        . '<p><a href="' . $safeUrl . '" style="display:inline-block;padding:12px 20px;background:#174f75;color:#fff;text-decoration:none;border-radius:5px">Revisar y firmar acta</a></p>'
        . '<p>El enlace es personal, tiene una vigencia de 48 horas y solicitará su PIN de firma personal. No reenvíe este mensaje.</p>'
        . '<p>Si no reconoce esta solicitud, puede ignorar este correo.</p>'
        . '<p>Atentamente,<br>Songa - Infraestructura IT</p></body></html>'
    );
    $mail->text(
        "Estimado/a " . ($snapshot['user']['manager'] ?? 'Gerente') . ":\n\n"
        . "El acta " . $acta['act_number'] . " requiere su revisión y firma.\n"
        . "Acceda al enlace seguro: " . $signatureUrl . "\n\n"
        . "El enlace es personal, tiene una vigencia de 48 horas y solicitará su PIN de firma personal.\n"
    );

    if (!$mailer->send()) {
        throw new RuntimeException('GLPI no pudo enviar el correo: ' . (string)$mailer->getError());
    }

    PluginActadeentregaPublicSigningService::markEmailSent($id);
    PluginActadeentregaSigningService::audit($id, (int)($snapshot['user']['manager_users_id'] ?? 0), 'gerente', 'correo_firma', 'Enlace de firma enviado al correo del gerente y destinatarios adicionales configurados.');
    Session::addMessageAfterRedirect('Correo de firma enviado correctamente al gerente.', true, INFO);
} catch (Throwable $e) {
    if ($token !== '') {
        PluginActadeentregaPublicSigningService::revoke($id);
    }
    Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
}

Html::redirect('/plugins/actadeentrega/front/sign.php?id=' . $id);
