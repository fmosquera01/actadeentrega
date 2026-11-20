<?php
declare(strict_types=1);

include('../../../inc/includes.php');
Session::checkRight('plugin_actadeentrega', CREATE);
require_once __DIR__ . '/../src/SigningService.php';
require_once __DIR__ . '/../src/FinalMailService.php';

$id = (int)($_POST['id'] ?? 0);
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $id < 1) {
        throw new RuntimeException('Solicitud de reintento no válida.');
    }
    $queued = PluginActadeentregaFinalMailService::enqueue($id, true);
    Session::addMessageAfterRedirect(
        $queued ? 'El correo a Activo Fijo fue agregado nuevamente a la cola de GLPI.' : 'No se agregó un nuevo correo. Revise la configuración de envío automático.',
        true,
        INFO
    );
} catch (Throwable $e) {
    PluginActadeentregaFinalMailService::markSendResult($id, 'fallido', $e->getMessage());
    Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
}
Html::redirect('/plugins/actadeentrega/front/sign.php?id=' . $id);
