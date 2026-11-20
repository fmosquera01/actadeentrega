<?php
declare(strict_types=1);

include('../../../inc/includes.php');
Session::checkRight('plugin_actadeentrega', CREATE);
require_once __DIR__ . '/../src/ActaService.php';
require_once __DIR__ . '/../src/SigningService.php';
require_once __DIR__ . '/../src/ActaPdf.php';

global $DB;
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
try {
    $acta = PluginActadeentregaSigningService::acta($id);
    $snapshot = json_decode((string)$acta['snapshot'], true);
    if (!is_array($snapshot)) throw new RuntimeException('El contenido del acta no es válido.');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (($_POST['sign_as_manager'] ?? '') === '1') {
            if ((string)$acta['status'] !== 'Pendiente de firma del gerente'
                || !PluginActadeentregaSigningService::allParticipantsSigned($id)) {
                throw new RuntimeException('El gerente solo puede firmar cuando todos los responsables hayan firmado.');
            }
            if (trim((string)($acta['manager_signature_path'] ?? '')) !== '') {
                throw new RuntimeException('La firma del gerente ya fue registrada.');
            }
            $managerId = (int)($snapshot['user']['manager_users_id'] ?? 0);
            if ($managerId < 1) throw new RuntimeException('No se pudo identificar al gerente asignado.');

            $method = (string)($_POST['participant_method'] ?? 'draw');
            if ($method === 'pin') {
                $authorized = PluginActadeentregaActaService::authorizeSignature(
                    $managerId, (string)($_POST['participant_pin'] ?? '')
                );
                $managerSignaturePath = $authorized['path'];
                $auditMethod = 'pin_local';
            } elseif ($method === 'draw') {
                $managerSignaturePath = PluginActadeentregaSigningService::storeDrawing(
                    (string)($_POST['signature_data'] ?? ''),
                    (string)$acta['act_number'],
                    'gerente'
                );
                $auditMethod = 'firma_dibujada';
            } else {
                throw new RuntimeException('Método de firma del gerente no válido.');
            }

            $managerSignatureHash = hash_file('sha256', $managerSignaturePath);
            if (!$managerSignatureHash) throw new RuntimeException('No se pudo verificar la integridad de la firma del gerente.');

            $DB->beginTransaction();
            try {
                $DB->update('glpi_plugin_actadeentrega_actas', [
                    'manager_signature_path' => $managerSignaturePath,
                    'manager_signature_method' => $auditMethod,
                ], ['id' => $id, 'manager_signature_path' => '']);
                if (method_exists($DB, 'affectedRows') && $DB->affectedRows() !== 1) {
                    throw new RuntimeException('La firma del gerente ya fue registrada por otra operación. Actualice el acta.');
                }
                PluginActadeentregaSigningService::audit(
                    $id, $managerId, 'gerente', $auditMethod,
                    'Firma presencial del gerente registrada en la tablet del técnico GLPI ' . (int)Session::getLoginUserID()
                );
                $freshActa = PluginActadeentregaSigningService::acta($id);
                PluginActadeentregaSigningService::finalize($freshActa);
                $DB->commit();
            } catch (Throwable $e) {
                $DB->rollBack();
                throw $e;
            }

            Session::addMessageAfterRedirect('Firma del gerente registrada. El acta quedó firmada y el PDF definitivo fue generado.', true, INFO);
            Html::redirect('/plugins/actadeentrega/front/sign.php?id=' . $id);
        }

        if ((string)$acta['status'] !== 'Pendiente de firma del colaborador') {
            throw new RuntimeException('La etapa de firma de responsables ya finalizó o el acta está cerrada.');
        }
        $participantId = (int)($_POST['participant_users_id'] ?? 0);
        $participant = $DB->request([
            'SELECT' => '*', 'FROM' => 'glpi_plugin_actadeentrega_acta_users',
            'WHERE' => ['plugin_actadeentrega_actas_id' => $id, 'users_id' => $participantId],
            'LIMIT' => 1,
        ])->current();
        if (!$participant || $participantId < 1) throw new RuntimeException('El usuario no forma parte de los responsables de esta acta.');
        if (trim((string)($participant['signature_path'] ?? '')) !== '' || !empty($participant['signed_at'])) {
            throw new RuntimeException('Este responsable ya registró su firma. No se permite reemplazarla.');
        }

        $method = (string)($_POST['participant_method'] ?? 'draw');
        if ($method === 'pin') {
            $authorized = PluginActadeentregaActaService::authorizeSignature(
                $participantId, (string)($_POST['participant_pin'] ?? '')
            );
            $signaturePath = $authorized['path'];
            $auditMethod = 'pin_local';
        } elseif ($method === 'draw') {
            $signaturePath = PluginActadeentregaSigningService::storeDrawing(
                (string)($_POST['signature_data'] ?? ''),
                (string)$acta['act_number'],
                'responsable_' . $participantId
            );
            $auditMethod = 'firma_dibujada';
        } else {
            throw new RuntimeException('Método de firma no válido.');
        }
        $signatureHash = hash_file('sha256', $signaturePath);
        if (!$signatureHash) throw new RuntimeException('No se pudo verificar la integridad de la firma.');

        $DB->beginTransaction();
        try {
            $DB->update('glpi_plugin_actadeentrega_acta_users', [
                'signature_path' => $signaturePath,
                'signature_sha256' => $signatureHash,
                'signature_method' => $auditMethod,
                'signed_at' => gmdate('Y-m-d H:i:s'),
            ], ['id' => (int)$participant['id'], 'signature_path' => '']);
            if (method_exists($DB, 'affectedRows') && $DB->affectedRows() !== 1) {
                throw new RuntimeException('La firma ya fue registrada por otra operación. Actualice el acta.');
            }

            if ((int)$participant['is_holder'] === 1) {
                $DB->update('glpi_plugin_actadeentrega_actas', [
                    'recipient_signature_path' => $signaturePath,
                    'recipient_signed_at' => gmdate('Y-m-d H:i:s'),
                ], ['id' => $id]);
            }

            PluginActadeentregaSigningService::audit(
                $id, $participantId, (int)$participant['is_holder'] === 1 ? 'responsable_principal' : 'corresponsable',
                $auditMethod, 'Firma presencial registrada por el técnico GLPI ' . (int)Session::getLoginUserID()
            );

            $allSigned = PluginActadeentregaSigningService::allParticipantsSigned($id);
            $freshParticipants = PluginActadeentregaSigningService::participants($id);
            $signatureRows = [];
            foreach ($freshParticipants as $row) {
                $path = (string)($row['signature_path'] ?? '');
                if ($path === '') continue;
                $signatureRows[] = [
                    'users_id' => (int)$row['users_id'],
                    'name' => getUserName((int)$row['users_id']),
                    'path' => $path,
                    'signed_at' => (string)$row['signed_at'],
                    'method' => (string)$row['signature_method'],
                ];
            }
            $previewBase = rtrim(GLPI_VAR_DIR, DIRECTORY_SEPARATOR) . '/_plugins/actadeentrega/acts';
            if (!is_dir($previewBase) && !mkdir($previewBase, 0750, true) && !is_dir($previewBase)) {
                throw new RuntimeException('No se pudo preparar el almacenamiento del PDF preliminar.');
            }
            $previewFile = 'acta_preview_' . $id . '_' . bin2hex(random_bytes(5)) . '.pdf';
            $previewPath = $previewBase . '/' . $previewFile;
            PluginActadeentregaActaPdf::generate(
                $snapshot, (string)$acta['issuer_signature_path'], $previewPath, $signatureRows
            );
            $previewHash = hash_file('sha256', $previewPath);
            if (!$previewHash) { @unlink($previewPath); throw new RuntimeException('No se pudo verificar el PDF actualizado.'); }
            $DB->update('glpi_plugin_actadeentrega_actas', [
                'pdf_path' => '_plugins/actadeentrega/acts/' . $previewFile,
                'pdf_sha256' => $previewHash,
                'status' => $allSigned ? 'Pendiente de firma del gerente' : 'Pendiente de firma del colaborador',
            ], ['id' => $id]);
            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            if (isset($previewPath) && is_file($previewPath)) @unlink($previewPath);
            throw $e;
        }

        Session::addMessageAfterRedirect(
            $allSigned
                ? 'Todas las firmas de responsables fueron registradas. Ahora puede solicitar la firma del gerente por correo.'
                : 'Firma registrada correctamente. Quedan responsables pendientes de firmar.',
            true, INFO
        );
        Html::redirect('/plugins/actadeentrega/front/sign.php?id=' . $id);
    }
} catch (Throwable $e) {
    Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
    Html::redirect('/plugins/actadeentrega/front/sign.php?id=' . $id);
}

$esc = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$user = $snapshot['user'] ?? [];
$participants = PluginActadeentregaSigningService::participants($id);
if (!$participants) {
    // Compatibilidad de lectura para registros antiguos sin tabla de participantes.
    $participants = [[
        'users_id' => (int)$acta['recipient_users_id'], 'is_holder' => 1,
        'signature_path' => (string)($acta['recipient_signature_path'] ?? ''),
        'signed_at' => (string)($acta['recipient_signed_at'] ?? ''),
        'signature_method' => 'legacy',
    ]];
}
$managerId = (int)($user['manager_users_id'] ?? 0);
$managerName = (string)($user['manager'] ?? '');
$managerHasPin = false;
if ($managerId > 0 && $DB->tableExists('glpi_plugin_informeglopi_user_signatures')) {
    $managerRow = $DB->request([
        'SELECT' => ['signature_path','signature_sha256','pin_hash'],
        'FROM' => 'glpi_plugin_informeglopi_user_signatures',
        'WHERE' => ['users_id' => $managerId], 'LIMIT' => 1,
    ])->current();
    $managerHasPin = $managerRow
        && trim((string)$managerRow['signature_path']) !== ''
        && trim((string)$managerRow['signature_sha256']) !== ''
        && trim((string)$managerRow['pin_hash']) !== '';
}
$managerEmail = '';
if ($managerId > 0 && $DB->tableExists('glpi_useremails')) {
    foreach ($DB->request([
        'SELECT' => ['email'], 'FROM' => 'glpi_useremails',
        'WHERE' => ['users_id' => $managerId], 'ORDER' => ['id ASC'],
    ]) as $emailRow) {
        $candidate = trim((string)$emailRow['email']);
        if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) { $managerEmail = $candidate; break; }
    }
}
$status = (string)$acta['status'];
$statusHtml = $esc($status);
$managerNameHtml = $esc($managerName);
$actNumberHtml = $esc($acta['act_number']);
$allSigned = PluginActadeentregaSigningService::allParticipantsSigned($id);
$isFinal = $status === 'Firmada completamente';
$methodLabels = ['pin_local' => 'PIN personal', 'firma_dibujada' => 'Firma manuscrita', 'legacy' => 'Firma anterior'];
Html::header('Acta de Entrega', $_SERVER['PHP_SELF'], 'plugins', 'PluginActadeentregaMenu');
echo <<<HTML
<style>
.ae-page{max-width:1120px;margin:18px auto 42px;color:#20252b}.ae-toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px}
.ae-paper{background:#fff;border:1px solid #d9dee5;border-radius:8px;box-shadow:0 3px 14px rgba(20,35,50,.07);padding:clamp(18px,3vw,38px)}
.ae-dochead{display:flex;align-items:center;justify-content:space-between;gap:20px;border-bottom:2px solid #263746;padding-bottom:18px;margin-bottom:22px}.ae-dochead img{width:180px;max-width:38%;height:auto;object-fit:contain}
.ae-dochead h1{font-size:clamp(18px,2.4vw,25px);font-weight:750;text-align:right;margin:0;color:#1f2933}.ae-number{font-size:13px;color:#65717d;margin-top:7px;text-align:right}
.ae-section-title{font-size:14px;font-weight:800;letter-spacing:.04em;border-bottom:1px solid #9da8b3;padding:0 0 8px;margin:22px 0 12px;color:#263746}
.ae-data{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 28px}.ae-field{padding:9px 0;border-bottom:1px solid #edf0f3;min-width:0}.ae-field label{display:block;font-size:11px;text-transform:uppercase;color:#687582;font-weight:700;margin-bottom:4px}.ae-field div{font-size:14px;font-weight:600;overflow-wrap:anywhere}
.ae-table{width:100%;border-collapse:collapse;font-size:13px}.ae-table th{background:#edf1f4;color:#273746}.ae-table th,.ae-table td{border:1px solid #cbd2d9;padding:10px 9px;vertical-align:top}
.ae-signatures{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-top:16px}.ae-signbox{border:1px solid #d7dde3;border-radius:8px;padding:16px;min-height:130px;display:flex;flex-direction:column}
.ae-signbox h3{font-size:13px;font-weight:800;margin:0 0 8px;color:#293946}.ae-signline{border-top:1px solid #7c8791;margin-top:auto;padding-top:8px;font-size:12px;font-weight:700}
.ae-signed{color:#187448;font-size:12px;font-weight:750}.ae-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:12px}.ae-iconbtn{width:42px;height:42px;display:inline-flex;align-items:center;justify-content:center;border:1px solid #b8c3cd;border-radius:8px;background:#fff;color:#263746;font-size:18px}
.ae-help{font-size:11px;color:#6b7782;margin-top:7px}.ae-status{display:inline-flex;border-radius:30px;padding:7px 12px;background:#eef2f5;color:#344452;font-size:12px;font-weight:750}
.ae-modal-canvas{width:100%;height:190px;border:1px dashed #82909c;border-radius:8px;touch-action:none;background:#fff}
.ae-terms{margin:0 0 18px;padding:14px 16px;border:1px solid #d6dee6;border-left:4px solid #315b78;border-radius:8px;background:#f7f9fb;color:#263746;font-size:13px;line-height:1.5}.ae-terms strong{display:block;margin-bottom:8px}.ae-terms ol{margin:0;padding-left:21px}.ae-terms li{margin:0 0 7px}.ae-terms li:last-child{margin-bottom:0}
@media(max-width:700px){.ae-data,.ae-signatures{grid-template-columns:1fr}.ae-dochead{align-items:flex-start}.ae-dochead img{width:125px}.ae-paper{padding:16px}}
@media print{#page,.ae-toolbar,.ae-actions,.ae-help,.ae-modal{display:none!important}.ae-page{max-width:none;margin:0}.ae-paper{border:0;box-shadow:none;padding:0}.ae-signbox{break-inside:avoid}}
</style>
<div class="ae-page"><div class="ae-toolbar"><div><h2 class="mb-0">Acta de Entrega</h2><div class="text-muted">Registro individual de firmas</div></div>
<div class="d-flex align-items-center gap-2 flex-wrap"><span class="ae-status">{$statusHtml}</span><a class="btn btn-outline-secondary btn-sm" href="/plugins/actadeentrega/front/pdf.php?id={$id}" target="_blank" rel="noopener">Ver PDF</a></div></div>
<article class="ae-paper"><header class="ae-dochead"><img src="../logo_songa.jpeg" alt="Songa"><div><h1>ACTA DE ENTREGA DE EQUIPOS</h1><div class="ae-number">N.º {$actNumberHtml}</div></div></header>
<h2 class="ae-section-title">DATOS</h2><section class="ae-data">
<div class="ae-field"><label>Gerente o jefe de área</label><div>{$managerNameHtml}</div></div><div class="ae-field"><label>Responsables del equipo</label><div>
HTML;
foreach (($snapshot['participants'] ?? []) as $p) echo '<div>' . $esc($p['name'] ?? '') . '</div>';
echo '</div></div><div class="ae-field"><label>Cargo / Función</label><div>' . $esc($user['jobtitle'] ?? '') . '</div></div><div class="ae-field"><label>Área</label><div>' . $esc($user['area'] ?? '') . '</div></div><div class="ae-field"><label>Departamento</label><div>' . $esc($user['department'] ?? '') . '</div></div><div class="ae-field"><label>Fecha de emisión</label><div>' . $esc($acta['act_date']) . '</div></div></section>';

echo '<h2 class="ae-section-title">EQUIPOS ENTREGADOS</h2><div class="table-responsive"><table class="ae-table"><thead><tr><th>Ítem</th><th>Tipo</th><th>Serie</th><th>Descripción</th><th>Activo fijo</th></tr></thead><tbody>';
foreach (($snapshot['assets'] ?? []) as $i => $asset) {
    echo '<tr><td>' . ($i + 1) . '</td><td>' . $esc($asset['type'] ?? '') . '</td><td>' . $esc($asset['serial'] ?? '') . '</td><td>' . $esc($asset['description'] ?? '') . '</td><td>' . $esc($asset['asset_number'] ?? 'N/A') . '</td></tr>';
}
echo '</tbody></table></div>';
echo '<section class="ae-terms"><strong>Me comprometo con la compañía a hacerme responsable del cuidado de este equipo, con las siguientes cláusulas:</strong><ol><li>El responsable directo del equipo al momento de la entrega debe recibir una inducción de cómo se utiliza el equipo y el sistema por parte del Dpto. de Sistemas.</li><li>El equipo que se le entrega está en perfectas condiciones. En caso de que el equipo no esté funcionando, entregarlo al Dpto. de Sistemas.</li><li>En caso de que el equipo sea asignado por turno de trabajo deberá ser entregado al responsable del equipo del siguiente turno.</li><li>En caso de pérdida o daños causados por mal uso el equipo será descontado de su sueldo o liquidación de haberes al responsable directo.</li><li>La pérdida o daño por préstamo a algún otro empleado de la empresa no lo exime de su responsabilidad con el equipo.</li></ol></section>';
echo '<h2 class="ae-section-title">ACEPTACIÓN Y FIRMAS</h2><section class="ae-signatures">';
foreach ($participants as $participant) {
    $pid = (int)$participant['users_id'];
    $name = getUserName($pid);
    $signed = trim((string)($participant['signature_path'] ?? '')) !== '' && !empty($participant['signed_at']);
    $hasPin = false;
    if ($DB->tableExists('glpi_plugin_informeglopi_user_signatures')) {
        $pinRow = $DB->request(['SELECT'=>['signature_path','signature_sha256','pin_hash'],'FROM'=>'glpi_plugin_informeglopi_user_signatures','WHERE'=>['users_id'=>$pid],'LIMIT'=>1])->current();
        $hasPin = $pinRow && trim((string)$pinRow['signature_path']) !== '' && trim((string)$pinRow['signature_sha256']) !== '' && trim((string)$pinRow['pin_hash']) !== '';
    }
    $nameJson = htmlspecialchars((string)json_encode($name, JSON_HEX_APOS|JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
    echo '<div class="ae-signbox"><h3>' . 'RESPONSABLE' . '</h3>';
    if ($signed) {
        echo '<div class="ae-signed"><i class="fas fa-check-circle"></i> Firma registrada</div><div class="ae-help">Fecha: ' . $esc($participant['signed_at']) . ' · Método: ' . $esc($methodLabels[(string)$participant['signature_method']] ?? $participant['signature_method']) . '</div>';
    } else {
        echo '<p class="small text-muted">Debe firmar personalmente en la tablet del técnico.</p>';
        if ($status === 'Pendiente de firma del colaborador') {
            echo '<div class="ae-actions"><button class="ae-iconbtn" type="button" title="Firma manuscrita" onclick="openSignatureModal(' . $pid . ',' . $nameJson . ',\'draw\')"><i class="fas fa-pen-nib"></i></button>';
            if ($hasPin) echo '<button class="ae-iconbtn" type="button" title="Firmar con PIN" onclick="openSignatureModal(' . $pid . ',' . $nameJson . ',\'pin\')"><i class="fas fa-key"></i></button>';
            echo '</div><div class="ae-help">' . ($hasPin ? 'Puede firmar con PIN personal o manuscritamente.' : 'No tiene PIN configurado; firme manuscritamente.') . '</div>';
        }
    }
    echo '<div class="ae-signline">' . $esc($name) . '</div></div>';
}
echo '<div class="ae-signbox"><h3>GERENTE O JEFE DE ÁREA</h3>';
$managerSigned = trim((string)($acta['manager_signature_path'] ?? '')) !== '';
if ($managerSigned) {
    echo '<div class="ae-signed"><i class="fas fa-check-circle"></i> Firma registrada</div><div class="ae-help">Fecha: ' . $esc($acta['manager_signed_at'] ?? '') . ' · Método: ' . $esc($methodLabels[(string)($acta['manager_signature_method'] ?? '')] ?? ($acta['manager_signature_method'] ?? '')) . '</div>';
} else {
    echo '<p class="small text-muted">' . ($status === 'Pendiente de firma del gerente' ? 'El gerente puede firmar presencialmente en la tablet del técnico o recibir un enlace personal por correo.' : 'La firma del gerente estará disponible cuando todos los responsables hayan firmado.') . '</p>';
}
echo '<div class="ae-signline">' . $esc($managerName) . '</div>';
if (!$isFinal && !$managerSigned && $status === 'Pendiente de firma del gerente' && $allSigned) {
    echo '<div class="ae-actions">';
    echo '<button class="ae-iconbtn" type="button" title="Firma manuscrita del gerente" onclick="openManagerSignatureModal(\'draw\')"><i class="fas fa-pen-nib"></i></button>';
    if ($managerHasPin) {
        echo '<button class="ae-iconbtn" type="button" title="Firmar con PIN personal del gerente" onclick="openManagerSignatureModal(\'pin\')"><i class="fas fa-key"></i></button>';
    }
    if ($managerHasPin && $managerEmail !== '') {
        echo '<form method="post" action="/plugins/actadeentrega/front/send-manager.php"><input type="hidden" name="_glpi_csrf_token" value="' . $esc(Session::getNewCSRFToken(true)) . '"><input type="hidden" name="id" value="' . $id . '"><button class="ae-iconbtn" type="submit" title="Enviar enlace de firma al gerente por correo"><i class="fas fa-envelope"></i></button></form>';
    }
    echo '</div><div class="ae-help">Firma manuscrita disponible. ' . ($managerHasPin ? 'También puede usar su PIN personal.' : 'No tiene PIN personal habilitado; la opción PIN y la firma remota no están disponibles.') . ($managerHasPin && $managerEmail !== '' ? ' También puede enviar el enlace seguro por correo.' : ($managerHasPin && $managerEmail === '' ? ' No tiene correo válido registrado para la firma remota.' : '')) . '</div>';
}
echo '</div></section><section class="ae-signatures"><div class="ae-signbox"><h3>ENTREGADO POR</h3><div class="ae-signed">Firma del técnico registrada</div><div class="ae-signline">' . $esc($snapshot['issuer']['name'] ?? '') . '</div></div><div class="ae-signbox"><h3>ESTADO</h3><p>' . ($isFinal ? 'Acta firmada y finalizada.' : ($allSigned ? 'Todos los responsables firmaron; pendiente gerente.' : 'Pendiente de firmas de responsables.')) . '</p><div class="ae-signline">' . $esc($acta['act_number']) . '</div></div></section></article></div>';

if (!$isFinal && in_array($status, ['Pendiente de firma del colaborador', 'Pendiente de firma del gerente'], true)) {
    echo '<div class="modal fade ae-modal" id="aeSignatureModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="aeModalTitle">Firma del responsable</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><form method="post" id="aeSignatureForm"><input type="hidden" name="_glpi_csrf_token" value="' . $esc(Session::getNewCSRFToken(true)) . '"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="participant_users_id" id="aeParticipantId"><input type="hidden" name="sign_as_manager" id="aeSignAsManager" value="0"><input type="hidden" name="participant_method" id="aeMethod"><input type="hidden" name="signature_data" id="aeSignatureData"><div id="aeDrawArea"><canvas id="aeCanvas" class="ae-modal-canvas" width="1000" height="320"></canvas><div class="text-end mt-2"><button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearAeCanvas()">Limpiar</button></div></div><div id="aePinArea" style="display:none"><label class="form-label" for="aePin">PIN personal de 6 dígitos</label><input id="aePin" class="form-control" style="max-width:240px" type="password" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="off"></div><div class="d-flex justify-content-end gap-2 mt-3"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Aceptar y firmar</button></div></form></div></div></div></div>';
}
echo <<<JS
<script>
(function(){
 let modal=null, mode='draw', drawing=false, hasInk=false;
 const canvas=document.getElementById('aeCanvas'),ctx=canvas?canvas.getContext('2d'):null;
 if(ctx){ctx.lineWidth=3;ctx.lineCap='round';ctx.lineJoin='round';ctx.strokeStyle='#111';}
 window.openSignatureModal=function(userId,name,nextMode){
   mode=nextMode;
   document.getElementById('aeSignAsManager').value='0';
   document.getElementById('aeModalTitle').textContent=(mode==='pin'?'Autorización con PIN · ':'Firma manuscrita · ')+name;
   document.getElementById('aeParticipantId').value=userId;
   document.getElementById('aeMethod').value=mode;
   document.getElementById('aeDrawArea').style.display=mode==='draw'?'block':'none';
   document.getElementById('aePinArea').style.display=mode==='pin'?'block':'none';
   document.getElementById('aePin').required=mode==='pin';document.getElementById('aePin').value='';
   clearAeCanvas();modal=bootstrap.Modal.getOrCreateInstance(document.getElementById('aeSignatureModal'));modal.show();
 };
 window.openManagerSignatureModal=function(nextMode){
   mode=nextMode;
   document.getElementById('aeSignAsManager').value='1';
   document.getElementById('aeParticipantId').value='';
   document.getElementById('aeMethod').value=mode;
   document.getElementById('aeModalTitle').textContent=(mode==='pin'?'Autorización con PIN · ':'Firma manuscrita · ')+"Gerente";
   document.getElementById('aeDrawArea').style.display=mode==='draw'?'block':'none';
   document.getElementById('aePinArea').style.display=mode==='pin'?'block':'none';
   document.getElementById('aePin').required=mode==='pin';document.getElementById('aePin').value='';
   clearAeCanvas();modal=bootstrap.Modal.getOrCreateInstance(document.getElementById('aeSignatureModal'));modal.show();
 };
 window.clearAeCanvas=function(){if(ctx){ctx.clearRect(0,0,canvas.width,canvas.height);hasInk=false;}};
 if(canvas){const point=e=>{const r=canvas.getBoundingClientRect();return{x:(e.clientX-r.left)*canvas.width/r.width,y:(e.clientY-r.top)*canvas.height/r.height};};
 canvas.addEventListener('pointerdown',e=>{e.preventDefault();drawing=true;canvas.setPointerCapture(e.pointerId);const p=point(e);ctx.beginPath();ctx.moveTo(p.x,p.y);});
 canvas.addEventListener('pointermove',e=>{if(!drawing)return;e.preventDefault();const p=point(e);ctx.lineTo(p.x,p.y);ctx.stroke();hasInk=true;});
 const stop=()=>drawing=false;canvas.addEventListener('pointerup',stop);canvas.addEventListener('pointercancel',stop);}
 const form=document.getElementById('aeSignatureForm');
 if(form)form.addEventListener('submit',e=>{
   if(mode==='pin'){const pin=document.getElementById('aePin');pin.name='participant_pin';if(!/^[0-9]{6}$/.test(pin.value)){e.preventDefault();alert('Ingrese el PIN personal de 6 dígitos.');}}
   else{if(!hasInk){e.preventDefault();alert('Debe dibujar la firma antes de continuar.');return;}document.getElementById('aeSignatureData').value=canvas.toDataURL('image/png');}
 });
})();
</script>
JS;
Html::footer();
