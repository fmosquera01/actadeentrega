<?php
declare(strict_types=1);

include('../../../inc/includes.php');
global $DB;

$id = (int)($_GET['id'] ?? 0);
$row = $DB->request([
    'SELECT' => ['id', 'recipient_users_id', 'issuer_users_id', 'snapshot', 'issuer_signature_path', 'manager_signature_path', 'manager_signed_at', 'issuer_signature_path', 'status', 'pdf_path', 'pdf_sha256'],
    'FROM' => 'glpi_plugin_actadeentrega_actas',
    'WHERE' => ['id' => $id],
    'LIMIT' => 1,
])->current();

if (!$row) Html::displayNotFoundError();

$canReadAll = Session::haveRight('plugin_actadeentrega', READ);
$userId = (int)Session::getLoginUserID();
$isParticipant = (int)$row['recipient_users_id'] === $userId;
$snapshot = json_decode((string)$row['snapshot'], true) ?: [];
$snapshotParticipantNames = [];
foreach (($snapshot['participants'] ?? []) as $snapshotParticipant) {
    $snapshotParticipantId = (int)($snapshotParticipant['users_id'] ?? 0);
    $snapshotParticipantName = trim((string)($snapshotParticipant['name'] ?? ''));
    if ($snapshotParticipantId > 0 && $snapshotParticipantName !== '') {
        $snapshotParticipantNames[$snapshotParticipantId] = $snapshotParticipantName;
    }
}
$isIssuer = (int)$row['issuer_users_id'] === $userId && trim((string)$row['issuer_signature_path']) !== '';
$isManager = (int)($snapshot['user']['manager_users_id'] ?? 0) === $userId
    && trim((string)$row['manager_signature_path']) !== ''
    && !empty($row['manager_signed_at']);

if (!$isParticipant && !$isIssuer && !$isManager && !$canReadAll && $DB->tableExists('glpi_plugin_actadeentrega_acta_users')) {
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

if (!$canReadAll && !$isParticipant && !$isIssuer && !$isManager) {
    Html::displayNotFoundError();
}

// Los PDF preliminares se reconstruyen al abrirlos para aplicar siempre
// el diseño vigente y reflejar las firmas disponibles. Los PDF finales se conservan.
if ((string)$row['status'] !== 'Firmada completamente'
    && trim((string)$row['issuer_signature_path']) !== ''
    && is_file((string)$row['issuer_signature_path'])) {
    try {
        require_once __DIR__ . '/../src/ActaPdf.php';
        $participants = [];
        if ($DB->tableExists('glpi_plugin_actadeentrega_acta_users')) {
            foreach ($DB->request([
                'SELECT' => ['users_id', 'signature_path', 'signed_at', 'signature_method'],
                'FROM' => 'glpi_plugin_actadeentrega_acta_users',
                'WHERE' => ['plugin_actadeentrega_actas_id' => $id],
                'ORDER' => ['sort_order ASC', 'id ASC'],
            ]) as $participantRow) {
                $participants[] = [
                    'users_id' => (int)$participantRow['users_id'],
                    'name' => $snapshotParticipantNames[(int)$participantRow['users_id']] ?? getUserName((int)$participantRow['users_id']),
                    'path' => (string)($participantRow['signature_path'] ?? ''),
                    'signed_at' => (string)($participantRow['signed_at'] ?? ''),
                    'method' => (string)($participantRow['signature_method'] ?? ''),
                ];
            }
        }
        $directory = rtrim(GLPI_VAR_DIR, DIRECTORY_SEPARATOR) . '/_plugins/actadeentrega/acts';
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('No se pudo preparar el PDF preliminar.');
        }
        $filename = 'acta_preview_' . $id . '_' . bin2hex(random_bytes(5)) . '.pdf';
        $newPath = $directory . '/' . $filename;
        PluginActadeentregaActaPdf::generate(
            $snapshot,
            (string)$row['issuer_signature_path'],
            $newPath,
            $participants,
            trim((string)$row['manager_signature_path']) !== '' ? (string)$row['manager_signature_path'] : null
        );
        $newHash = hash_file('sha256', $newPath);
        if (!$newHash) {
            @unlink($newPath);
            throw new RuntimeException('No se pudo verificar el PDF preliminar.');
        }
        $DB->update('glpi_plugin_actadeentrega_actas', [
            'pdf_path' => '_plugins/actadeentrega/acts/' . $filename,
            'pdf_sha256' => $newHash,
        ], ['id' => $id]);
        $row['pdf_path'] = '_plugins/actadeentrega/acts/' . $filename;
        $row['pdf_sha256'] = $newHash;
    } catch (Throwable $e) {
        Html::displayErrorAndDie('No se pudo actualizar el PDF del acta: ' . $e->getMessage());
    }
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
