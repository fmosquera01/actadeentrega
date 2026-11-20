<?php
declare(strict_types=1);

include('../../../inc/includes.php');

$userId = (int)Session::getLoginUserID();
if ($userId < 1) Html::displayNotFoundError();

global $DB;
$rowsByActa = [];
$participantRows = $DB->request([
    'SELECT' => [
        'a.id', 'a.act_number', 'a.act_date', 'a.status', 'a.snapshot',
        'au.signed_at', 'au.signature_method',
    ],
    'FROM' => 'glpi_plugin_actadeentrega_acta_users AS au',
    'INNER JOIN' => [
        'glpi_plugin_actadeentrega_actas AS a' => [
            'ON' => ['au' => 'plugin_actadeentrega_actas_id', 'a' => 'id'],
        ],
    ],
    'WHERE' => [
        'au.users_id' => $userId,
        ['NOT' => ['au.signed_at' => null]],
    ],
    'ORDER' => ['a.id DESC'],
]);
foreach ($participantRows as $row) {
    $rowsByActa[(int)$row['id']] = $row;
}

// Incluir también las actas firmadas como gerente o técnico emisor.
// Se excluyen los eventos de generación de enlace y envío de correo.
if ($DB->tableExists('glpi_plugin_actadeentrega_signatures')) {
    $auditRows = $DB->request([
        'SELECT' => [
            'a.id', 'a.act_number', 'a.act_date', 'a.status', 'a.snapshot',
            's.signed_at', 's.method AS signature_method',
        ],
        'FROM' => 'glpi_plugin_actadeentrega_signatures AS s',
        'INNER JOIN' => [
            'glpi_plugin_actadeentrega_actas AS a' => [
                'ON' => ['s' => 'plugin_actadeentrega_actas_id', 'a' => 'id'],
            ],
        ],
        'WHERE' => [
            's.users_id' => $userId,
            's.method' => ['pin_local', 'firma_dibujada', 'pin_remoto', 'pin_glpi'],
        ],
        'ORDER' => ['s.signed_at DESC'],
    ]);
    foreach ($auditRows as $row) {
        $actaId = (int)$row['id'];
        if (!isset($rowsByActa[$actaId])) $rowsByActa[$actaId] = $row;
    }
}
$rows = array_values($rowsByActa);
usort($rows, static fn($a, $b) => (int)$b['id'] <=> (int)$a['id']);

$isHelpdesk = Session::getCurrentInterface() === 'helpdesk';
if ($isHelpdesk) {
    Html::helpHeader('Mis actas firmadas', 'plugins', 'actadeentrega');
} else {
    Html::header('Mis actas firmadas', $_SERVER['PHP_SELF'], 'plugins', 'PluginActadeentregaMenu');
}

$escape = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$methodLabels = ['pin_local' => 'PIN personal', 'firma_dibujada' => 'Firma manuscrita', 'legacy' => 'Firma anterior'];
echo '<div class="container-fluid"><div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3"><div><h2>Mis actas firmadas</h2><p class="text-muted mb-0">Documentos en los que su firma quedó registrada.</p></div></div>';
echo '<div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>N.º de acta</th><th>Fecha</th><th>Activos</th><th>Mi firma</th><th>Estado</th><th>Documento</th></tr></thead><tbody>';
$count = 0;
foreach ($rows as $row) {
    $count++;
    $snapshot = json_decode((string)$row['snapshot'], true) ?: [];
    $assets = [];
    foreach (($snapshot['assets'] ?? []) as $asset) {
        $label = trim((string)($asset['type'] ?? '') . ' · ' . (string)($asset['serial'] ?? ''));
        if ($label !== '') $assets[] = $label;
    }
    echo '<tr><td><strong>' . $escape($row['act_number']) . '</strong></td>';
    echo '<td>' . $escape(date('d/m/Y', strtotime((string)$row['act_date']))) . '</td>';
    echo '<td>' . $escape(implode(', ', $assets)) . '</td>';
    echo '<td>' . $escape(date('d/m/Y H:i', strtotime((string)$row['signed_at']))) . '<div class="small text-muted">' . $escape($methodLabels[(string)$row['signature_method']] ?? $row['signature_method']) . '</div></td>';
    echo '<td>' . $escape($row['status']) . '</td>';
    echo '<td><a class="btn btn-sm btn-outline-primary" href="/plugins/actadeentrega/front/pdf.php?id=' . (int)$row['id'] . '" target="_blank" rel="noopener"><i class="ti ti-file-type-pdf"></i> Ver PDF</a></td></tr>';
}
if ($count === 0) echo '<tr><td colspan="6" class="text-center text-muted py-4">Todavía no tiene actas con su firma registrada.</td></tr>';
echo '</tbody></table></div></div>';

if ($isHelpdesk) Html::helpFooter(); else Html::footer();
