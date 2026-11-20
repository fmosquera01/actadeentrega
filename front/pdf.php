<?php
declare(strict_types=1);

include('../../../inc/includes.php');
global $DB;

$id = (int)($_GET['id'] ?? 0);
$row = $DB->request([
    'SELECT' => ['id', 'recipient_users_id', 'pdf_path', 'pdf_sha256'],
    'FROM' => 'glpi_plugin_actadeentrega_actas',
    'WHERE' => ['id' => $id],
    'LIMIT' => 1,
])->current();

if (!$row) Html::displayNotFoundError();

$canReadAll = Session::haveRight('plugin_actadeentrega', READ);
$userId = (int)Session::getLoginUserID();
$isParticipant = (int)$row['recipient_users_id'] === $userId;

if (!$isParticipant && !$canReadAll && $DB->tableExists('glpi_plugin_actadeentrega_acta_users')) {
    $participant = $DB->request([
        'SELECT' => ['id'],
        'FROM' => 'glpi_plugin_actadeentrega_acta_users',
        'WHERE' => [
            'plugin_actadeentrega_actas_id' => $id,
            'users_id' => $userId,
        ],
        'LIMIT' => 1,
    ])->current();
    $isParticipant = (bool)$participant;
}

if (!$canReadAll && !$isParticipant) {
    Html::displayNotFoundError();
}

// En la interfaz Self-Service solo se consulta un acta después de que
// la firma individual del usuario haya quedado registrada.
if (!$canReadAll && Session::getCurrentInterface() === 'helpdesk' && $isParticipant) {
    $signed = false;
    if ($DB->tableExists('glpi_plugin_actadeentrega_acta_users')) {
        $signedRow = $DB->request([
            'SELECT' => ['signed_at'],
            'FROM' => 'glpi_plugin_actadeentrega_acta_users',
            'WHERE' => ['plugin_actadeentrega_actas_id' => $id, 'users_id' => $userId],
            'LIMIT' => 1,
        ])->current();
        $signed = $signedRow && !empty($signedRow['signed_at']);
    }
    if (!$signed && (int)$row['recipient_users_id'] === $userId) {
        $legacy = $DB->request([
            'SELECT' => ['recipient_signed_at'],
            'FROM' => 'glpi_plugin_actadeentrega_actas',
            'WHERE' => ['id' => $id],
            'LIMIT' => 1,
        ])->current();
        $signed = $legacy && !empty($legacy['recipient_signed_at']);
    }
    if (!$signed) Html::displayNotFoundError();
}

$base = realpath(rtrim(GLPI_VAR_DIR, DIRECTORY_SEPARATOR) . '/_plugins/actadeentrega/acts');
$path = realpath(rtrim(GLPI_VAR_DIR, DIRECTORY_SEPARATOR) . '/' . ltrim((string)$row['pdf_path'], '/'));
if (!$base || !$path || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
    Html::displayNotFoundError();
}
$hash = hash_file('sha256', $path);
if (!$hash || !hash_equals((string)$row['pdf_sha256'], $hash)) {
    Html::displayErrorAndDie('El PDF no superó la verificación de integridad.');
}

header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . (!empty($_GET['download']) ? 'attachment' : 'inline') . '; filename="acta-entrega-' . $id . '.pdf"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
exit;
