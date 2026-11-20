<?php
declare(strict_types=1);

include('../../../inc/includes.php');
Session::checkRight('plugin_actadeentrega', CREATE);
require_once __DIR__ . '/../src/SigningService.php';
require_once __DIR__ . '/../src/PublicSigningService.php';

$id = (int)($_POST['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Html::displayErrorAndDie('Solicitud no válida.');
}

try {
    global $CFG_GLPI;
    $acta = PluginActadeentregaSigningService::acta($id);
    $token = PluginActadeentregaPublicSigningService::createToken($acta);

    $baseUrl = rtrim((string)($CFG_GLPI['url_base'] ?? ''), '/');
    if (!filter_var($baseUrl, FILTER_VALIDATE_URL)) {
        throw new RuntimeException('Configure la URL base de GLPI para generar el enlace.');
    }
    $scheme = strtolower((string)parse_url($baseUrl, PHP_URL_SCHEME));
    $host = strtolower((string)parse_url($baseUrl, PHP_URL_HOST));
    $isLocalHost = in_array($host, ['localhost', '127.0.0.1', '::1'], true)
        || str_ends_with($host, '.test')
        || str_ends_with($host, '.local');
    if ($scheme !== 'https' && !($scheme === 'http' && $isLocalHost)) {
        throw new RuntimeException('Para generar un enlace HTTP de prueba, la URL base debe ser localhost o un dominio local. En otros entornos se requiere HTTPS.');
    }

    $url = $baseUrl . '/plugins/actadeentrega/front/public-sign.php?token=' . rawurlencode($token);
    $escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    Html::header('Enlace de prueba - Acta de Entrega', $_SERVER['PHP_SELF'], 'plugins', 'PluginActadeentregaMenu');
    echo "<div class='container-fluid' style='max-width:900px'><h2>Enlace de firma generado</h2>";
    echo "<div class='alert alert-warning'>Este enlace es una credencial temporal de firma. Compártalo únicamente con el gerente asignado. Tiene una vigencia de 48 horas y se invalida al utilizarse o al generar otro enlace.</div>";
    echo "<div class='card card-body'><label class='form-label' for='ae-test-url'>Enlace para abrir en el navegador</label>";
    echo "<div class='input-group'><input id='ae-test-url' class='form-control' type='text' readonly value='" . $escape($url) . "'><button class='btn btn-outline-secondary' type='button' onclick=\"navigator.clipboard.writeText(document.getElementById('ae-test-url').value)\">Copiar</button></div>";
    echo "<p class='mt-3'><a class='btn btn-primary' target='_blank' rel='noopener noreferrer' href='" . $escape($url) . "'>Abrir página de firma</a> <a class='btn btn-outline-secondary' href='/plugins/actadeentrega/front/sign.php?id=" . $id . "'>Volver al acta</a></p>";
    echo "</div><p class='text-muted mt-3'>No se ha enviado ningún correo. La prueba de la página no requiere conexión SMTP.</p></div>";
    Html::footer();
} catch (Throwable $e) {
    Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
    Html::redirect('/plugins/actadeentrega/front/sign.php?id=' . $id);
}
