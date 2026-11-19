<?php
declare(strict_types=1);

include('../../../inc/includes.php');
Session::checkRight('plugin_actadeentrega', CREATE);
require_once __DIR__ . '/../src/ActaService.php';
require_once __DIR__ . '/../src/SigningService.php';
require_once __DIR__ . '/../src/ActaPdf.php';

global $DB;
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$acta = [];
$snapshot = [];
$status = '';

try {
    $acta = PluginActadeentregaSigningService::acta($id);
    $snapshot = json_decode((string)$acta['snapshot'], true);
    if (!is_array($snapshot)) {
        throw new RuntimeException('El contenido del acta no es válido.');
    }
    $status = (string)$acta['status'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // GLPI 11 valida el token CSRF mediante CheckCsrfListener.
        if (in_array($status, ['Firmada completamente', 'Anulada'], true)) {
            throw new RuntimeException('El acta ya está finalizada o anulada y no admite cambios.');
        }

        $role = (string)($_POST['role'] ?? '');
        if ($role === 'recipient') {
            if ($status !== 'Pendiente de firma del colaborador') {
                throw new RuntimeException('La firma del colaborador ya fue registrada o el acta no está en esa etapa.');
            }

            $recipientId = (int)($acta['recipient_users_id'] ?? 0);
            if ($recipientId < 1) {
                throw new RuntimeException('El colaborador receptor no tiene un usuario GLPI válido.');
            }

            $method = (string)($_POST['recipient_method'] ?? 'draw');
            if ($method === 'pin') {
                $authorized = PluginActadeentregaActaService::authorizeSignature(
                    $recipientId,
                    (string)($_POST['recipient_pin'] ?? '')
                );
                $sig = $authorized['path'];
                $auditMethod = 'pin_local';
            } elseif ($method === 'draw') {
                $sig = PluginActadeentregaSigningService::storeDrawing(
                    (string)($_POST['signature_data'] ?? ''),
                    (string)$acta['act_number'],
                    'colaborador'
                );
                $auditMethod = 'firma_dibujada';
            } else {
                throw new RuntimeException('Método de firma del colaborador no válido.');
            }

            $previewBase = rtrim(GLPI_VAR_DIR, DIRECTORY_SEPARATOR) . '/_plugins/actadeentrega/acts';
            if (!is_dir($previewBase) && !mkdir($previewBase, 0750, true) && !is_dir($previewBase)) {
                throw new RuntimeException('No se pudo preparar el almacenamiento del PDF preliminar.');
            }
            $previewFile = 'acta_preview_' . $id . '_' . bin2hex(random_bytes(5)) . '.pdf';
            $previewPath = $previewBase . '/' . $previewFile;

            try {
                PluginActadeentregaActaPdf::generate(
                    $snapshot,
                    (string)$acta['issuer_signature_path'],
                    $previewPath,
                    $sig
                );
                $previewHash = hash_file('sha256', $previewPath);
                if (!$previewHash) {
                    throw new RuntimeException('No se pudo verificar el documento actualizado.');
                }
            } catch (Throwable $previewError) {
                if (is_file($previewPath)) @unlink($previewPath);
                // No borrar $sig: con PIN pertenece al repositorio central de Informe Técnico.
                throw $previewError;
            }

            $DB->update('glpi_plugin_actadeentrega_actas', [
                'recipient_signature_path' => $sig,
                'recipient_signed_at' => date('Y-m-d H:i:s'),
                'status' => 'Pendiente de firma del gerente',
                'pdf_path' => '_plugins/actadeentrega/acts/' . $previewFile,
                'pdf_sha256' => $previewHash,
            ], ['id' => $id]);

            PluginActadeentregaSigningService::audit(
                $id,
                $recipientId,
                'colaborador',
                $auditMethod,
                'Firma autorizada por el colaborador; registro mediante técnico GLPI ' . (int)Session::getLoginUserID()
            );
            Session::addMessageAfterRedirect('Firma del colaborador registrada. Ahora corresponde la firma del gerente.', true, INFO);
        } elseif ($role === 'manager') {
            if ($status !== 'Pendiente de firma del gerente' || empty($acta['recipient_signature_path'])) {
                throw new RuntimeException('El colaborador debe firmar primero.');
            }

            $managerId = (int)($snapshot['user']['manager_users_id'] ?? 0);
            if ($managerId < 1) {
                throw new RuntimeException('El gerente asignado al departamento no tiene un usuario GLPI válido.');
            }

            $method = (string)($_POST['manager_method'] ?? 'draw');
            if ($method === 'pin') {
                $authorized = PluginActadeentregaActaService::authorizeSignature(
                    $managerId,
                    (string)($_POST['manager_pin'] ?? '')
                );
                $managerSignature = $authorized['path'];
                $auditMethod = 'pin_local';
            } elseif ($method === 'draw') {
                $managerSignature = PluginActadeentregaSigningService::storeDrawing(
                    (string)($_POST['signature_data'] ?? ''),
                    (string)$acta['act_number'],
                    'gerente'
                );
                $auditMethod = 'firma_dibujada';
            } else {
                throw new RuntimeException('Método de firma del gerente no válido.');
            }

            $DB->update('glpi_plugin_actadeentrega_actas', [
                'manager_signature_path' => $managerSignature,
                'manager_signature_method' => $auditMethod,
            ], ['id' => $id]);

            PluginActadeentregaSigningService::audit(
                $id,
                $managerId,
                'gerente',
                $auditMethod,
                'Firma recogida por técnico GLPI ' . (int)Session::getLoginUserID()
            );

            $fresh = PluginActadeentregaSigningService::acta($id);
            PluginActadeentregaSigningService::finalize($fresh);
            Session::addMessageAfterRedirect('Acta firmada por el colaborador y el gerente. PDF final generado y protegido.', true, INFO);
            Html::redirect('/plugins/actadeentrega/front/sign.php?id=' . $id);
        } else {
            throw new RuntimeException('Etapa de firma no reconocida.');
        }

        Html::redirect('/plugins/actadeentrega/front/sign.php?id=' . $id);
    }
} catch (Throwable $e) {
    Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
    Html::redirect('/plugins/actadeentrega/front/history.php');
}

$esc = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$user = $snapshot['user'] ?? [];
$recipientName = (string)($user['recipient'] ?? '');
$managerName = (string)($user['manager'] ?? '');
$issuerName = (string)($snapshot['issuer']['name'] ?? '');
$participants = $snapshot['participants'] ?? [['name' => $recipientName]];
$assets = $snapshot['assets'] ?? [];
$recipientSigned = !empty($acta['recipient_signature_path']);
$managerSigned = !empty($acta['manager_signature_path']);
$managerHasReusableSignature = false;
$managerId = (int)($user['manager_users_id'] ?? 0);

if ($managerId > 0 && $DB->tableExists('glpi_plugin_informeglopi_user_signatures')) {
    $signatureRow = $DB->request([
        'SELECT' => ['signature_path', 'signature_sha256', 'pin_hash'],
        'FROM' => 'glpi_plugin_informeglopi_user_signatures',
        'WHERE' => ['users_id' => $managerId],
        'LIMIT' => 1,
    ])->current();

    if ($signatureRow
        && trim((string)($signatureRow['signature_path'] ?? '')) !== ''
        && trim((string)($signatureRow['signature_sha256'] ?? '')) !== ''
        && trim((string)($signatureRow['pin_hash'] ?? '')) !== '') {
        $managerHasReusableSignature = true;
    }
}

$date = new DateTimeImmutable((string)($snapshot['date'] ?? $acta['act_date'] ?? 'now'));
$logoUrl = '/plugins/actadeentrega/logo_songa.jpeg';
$statusHtml = $esc($status);
$actNumberHtml = $esc($acta['act_number']);
$isFinal = $status === 'Firmada completamente';

Html::header('Acta de Entrega', $_SERVER['PHP_SELF'], 'plugins', 'PluginActadeentregaMenu');
echo <<<HTML
<style>
.ae-page{max-width:1120px;margin:18px auto 42px;color:#20252b}
.ae-toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px}
.ae-paper{background:#fff;border:1px solid #d9dee5;border-radius:8px;box-shadow:0 3px 14px rgba(20,35,50,.07);padding:clamp(18px,3vw,38px)}
.ae-dochead{display:flex;align-items:center;justify-content:space-between;gap:20px;border-bottom:2px solid #263746;padding-bottom:18px;margin-bottom:22px}
.ae-dochead img{width:180px;max-width:38%;height:auto;object-fit:contain}
.ae-dochead h1{font-size:clamp(18px,2.4vw,25px);font-weight:750;text-align:right;margin:0;color:#1f2933}
.ae-number{font-size:13px;color:#65717d;margin-top:7px;text-align:right}
.ae-section-title{font-size:14px;font-weight:800;letter-spacing:.04em;border-bottom:1px solid #9da8b3;padding:0 0 8px;margin:22px 0 12px;color:#263746}
.ae-data{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 28px}
.ae-field{padding:9px 0;border-bottom:1px solid #edf0f3;min-width:0}
.ae-field label{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#687582;font-weight:700;margin-bottom:4px}
.ae-field div{font-size:14px;font-weight:600;overflow-wrap:anywhere}
.ae-table-wrap{overflow-x:auto}
.ae-table{width:100%;border-collapse:collapse;font-size:13px}
.ae-table th{background:#edf1f4;color:#273746;font-weight:750;text-align:left}
.ae-table th,.ae-table td{border:1px solid #cbd2d9;padding:10px 9px;vertical-align:top}
.ae-table td:first-child,.ae-table th:first-child{text-align:center;width:42px}
.ae-clauses{padding-left:22px;margin:0}
.ae-clauses li{padding:5px 0;font-size:13px;line-height:1.5}
.ae-signatures{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-top:18px}
.ae-signbox{border:1px solid #d7dde3;border-radius:8px;padding:16px;min-height:170px;display:flex;flex-direction:column}
.ae-signbox h3{font-size:13px;font-weight:800;margin:0 0 8px;color:#293946}
.ae-signbox p{font-size:12px;color:#56616c;line-height:1.45;flex:1}
.ae-signline{border-top:1px solid #7c8791;margin-top:22px;padding-top:8px;font-size:12px;font-weight:700}
.ae-signed{display:inline-flex;align-items:center;gap:6px;color:#187448;font-size:12px;font-weight:750}
.ae-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:12px}
.ae-iconbtn{width:42px;height:42px;display:inline-flex;align-items:center;justify-content:center;border:1px solid #b8c3cd;border-radius:8px;background:#fff;color:#263746;font-size:18px}
.ae-iconbtn:hover{background:#eef3f6;border-color:#647989}
.ae-iconbtn:disabled{opacity:.45;cursor:not-allowed}
.ae-help{font-size:11px;color:#6b7782;margin-top:7px}
.ae-status{display:inline-flex;align-items:center;border-radius:30px;padding:7px 12px;background:#eef2f5;color:#344452;font-size:12px;font-weight:750}
.ae-modal-canvas{width:100%;height:190px;border:1px dashed #82909c;border-radius:8px;touch-action:none;background:#fff}
@media(max-width:700px){.ae-data,.ae-signatures{grid-template-columns:1fr}.ae-dochead{align-items:flex-start}.ae-dochead img{width:125px}.ae-dochead h1{font-size:17px}.ae-paper{padding:16px}.ae-table{min-width:680px}}
@media print{#page,.ae-toolbar,.ae-actions,.ae-help,.ae-modal,.ae-no-print{display:none!important}.ae-page{max-width:none;margin:0}.ae-paper{border:0;box-shadow:none;padding:0}.ae-signbox{break-inside:avoid}}
</style>
<div class="ae-page">
  <div class="ae-toolbar">
    <div><h2 style="margin:0">Acta de Entrega</h2><div class="text-muted">Revisión y registro de firmas</div></div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <span class="ae-status">{$statusHtml}</span>
      <a class="btn btn-outline-secondary btn-sm" href="/plugins/actadeentrega/front/pdf.php?id={$id}" target="_blank" rel="noopener"><i class="fas fa-file-pdf"></i> PDF</a>
      <button class="btn btn-outline-secondary btn-sm" type="button" onclick="window.print()"><i class="fas fa-print"></i> Imprimir</button>
    </div>
  </div>
  <article class="ae-paper">
    <header class="ae-dochead">
      <img src="{$logoUrl}" alt="Songa">
      <div><h1>ACTA DE ENTREGA DE EQUIPOS</h1><div class="ae-number">N.º {$actNumberHtml}</div></div>
    </header>
    <h2 class="ae-section-title">DATOS</h2>
    <section class="ae-data">
      <div class="ae-field"><label>Gerente o jefe de área</label><div>{$esc($managerName)}</div></div>
      <div class="ae-field"><label>Responsable(s) del equipo</label><div>
HTML;

foreach ($participants as $participant) {
    echo '<div>' . $esc($participant['name'] ?? '') . '</div>';
}
echo '</div></div>';
echo '<div class="ae-field"><label>Cargo / Función</label><div>' . $esc($user['jobtitle'] ?? '') . '</div></div>';
echo '<div class="ae-field"><label>Área</label><div>' . $esc($user['area'] ?? '') . '</div></div>';
echo '<div class="ae-field"><label>Departamento</label><div>' . $esc($user['department'] ?? '') . '</div></div>';
echo '<div class="ae-field"><label>Fecha de emisión</label><div>' . $esc($date->format('d/m/Y H:i')) . '</div></div>';
echo '</section>';

echo '<h2 class="ae-section-title">EQUIPOS ENTREGADOS</h2><div class="ae-table-wrap"><table class="ae-table"><thead><tr><th>Ítem</th><th>Tipo</th><th>Número de serie</th><th>Descripción del equipo</th><th>Activo fijo</th></tr></thead><tbody>';
foreach ($assets as $index => $asset) {
    echo '<tr><td>' . ($index + 1) . '</td><td>' . $esc($asset['type'] ?? '') . '</td><td>' . $esc($asset['serial'] ?? '') . '</td><td>' . $esc($asset['description'] ?? '') . '</td><td>' . $esc(($asset['asset_number'] ?? '') !== '' ? $asset['asset_number'] : 'N/A') . '</td></tr>';
}
echo '</tbody></table></div>';

echo '<h2 class="ae-section-title">CONDICIONES Y RESPONSABILIDADES</h2><ol class="ae-clauses">';
$clauses = [
    'El responsable directo del equipo al momento de la entrega debe recibir una inducción de cómo se utiliza el equipo y el sistema por parte del Dpto. de Sistemas.',
    'El equipo que se le entrega está en perfectas condiciones. En caso de que el equipo no esté funcionando debe entregarlo al Dpto. de Sistemas.',
    'En caso de que el equipo sea asignado por turno de trabajo deberá ser entregado al responsable del equipo del siguiente turno.',
    'En caso de pérdida o daños causados por mal uso el equipo será descontado de su sueldo o liquidación de haberes al responsable directo.',
    'La pérdida o daño por préstamo a algún otro empleado de la empresa no lo exime de su responsabilidad con el equipo.',
];
foreach ($clauses as $clause) {
    echo '<li>' . $esc($clause) . '</li>';
}
echo '</ol>';

echo '<h2 class="ae-section-title">ACEPTACIÓN Y FIRMAS</h2><section class="ae-signatures">';
echo '<div class="ae-signbox"><h3>RESPONSABLE DEL EQUIPO</h3>';
if ($recipientSigned) {
    echo '<div class="ae-signed"><i class="fas fa-check-circle"></i> Firma registrada</div><div class="ae-help">Fecha: ' . $esc($acta['recipient_signed_at'] ?? '') . '</div>';
} else {
    echo '<p>Declaro haber recibido los equipos detallados en esta acta y asumo su custodia mientras estén asignados a mi responsabilidad.</p>';
}
echo '<div class="ae-signline">' . $esc($recipientName) . '</div>';
if (!$recipientSigned && $status === 'Pendiente de firma del colaborador') {
    echo '<div class="ae-actions"><button class="ae-iconbtn" type="button" title="Firmar manuscritamente" aria-label="Firmar manuscritamente" onclick="openSignatureModal(\'recipient\')"><i class="fas fa-pen-nib"></i></button></div><div class="ae-help">Firma manuscrita en este dispositivo.</div>';
}
echo '</div>';

echo '<div class="ae-signbox"><h3>GERENTE O JEFE DE ÁREA</h3>';
if ($managerSigned) {
    echo '<div class="ae-signed"><i class="fas fa-check-circle"></i> Firma registrada</div><div class="ae-help">Fecha: ' . $esc($acta['manager_signed_at'] ?? '') . '</div>';
} else {
    echo '<p>He revisado esta acta y acepto la responsabilidad de custodia de los activos correspondientes, conforme a las condiciones establecidas.</p>';
}
echo '<div class="ae-signline">' . $esc($managerName) . '</div>';
if (!$managerSigned && $status === 'Pendiente de firma del gerente' && $recipientSigned) {
    echo '<div class="ae-actions"><button class="ae-iconbtn" type="button" title="Firmar manuscritamente" aria-label="Firmar manuscritamente" onclick="openSignatureModal(\'manager-draw\')"><i class="fas fa-pen-nib"></i></button>';
    if ($managerHasReusableSignature) {
        echo '<button class="ae-iconbtn" type="button" title="Firmar con PIN personal" aria-label="Firmar con PIN personal" onclick="openSignatureModal(\'manager-pin\')"><i class="fas fa-key"></i></button>';
    }
    echo '</div><div class="ae-help">El gerente debe realizar personalmente la firma o ingresar su PIN.</div>';
} elseif (!$managerSigned && $status === 'Pendiente de firma del colaborador') {
    echo '<div class="ae-help">Disponible después de la firma del colaborador.</div>';
}
echo '</div></section>';

echo '<section class="ae-signatures" style="margin-top:16px"><div class="ae-signbox"><h3>ENTREGADO POR</h3><div class="ae-signed"><i class="fas fa-check-circle"></i> Firma del técnico registrada</div><div class="ae-signline">' . $esc($issuerName) . '</div></div><div class="ae-signbox"><h3>ESTADO DEL DOCUMENTO</h3><p>' . ($isFinal ? 'El acta cuenta con las firmas requeridas y se encuentra finalizada.' : 'El acta permanece pendiente hasta completar las firmas requeridas.') . '</p><div class="ae-signline">' . $esc($acta['act_number']) . '</div></div></section>';
echo '</article></div>';

if (!$isFinal && ($status === 'Pendiente de firma del colaborador' || $status === 'Pendiente de firma del gerente')) {
    echo '<div class="modal fade ae-modal" id="aeSignatureModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">';
    echo '<div class="modal-header"><h5 class="modal-title" id="aeModalTitle">Registrar firma</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>';
    echo '<div class="modal-body"><p id="aeDeclaration" class="small text-muted"></p>';
    echo '<form method="post" id="aeSignatureForm"><input type="hidden" name="_glpi_csrf_token" value="' . $esc(Session::getNewCSRFToken(true)) . '"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="role" id="aeRole"><input type="hidden" name="manager_method" id="aeMethod"><input type="hidden" name="signature_data" id="aeSignatureData">';
    echo '<div id="aeDrawArea" style="display:none"><canvas id="aeCanvas" class="ae-modal-canvas" width="1000" height="320"></canvas><div class="d-flex justify-content-end mt-2"><button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearAeCanvas()">Limpiar firma</button></div></div>';
    echo '<div id="aePinArea" style="display:none"><label for="aePin" class="form-label">PIN personal de 6 dígitos</label><input id="aePin" class="form-control" style="max-width:240px" type="password" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="off"></div>';
    echo '<div class="d-flex justify-content-end gap-2 mt-3"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Aceptar y firmar</button></div></form></div></div></div></div>';
}

echo <<<JS
<script>
(function(){
 let modal=null, mode='', drawing=false, hasInk=false;
 const canvas=document.getElementById('aeCanvas');
 const ctx=canvas?canvas.getContext('2d'):null;
 if(ctx){ctx.lineWidth=3;ctx.lineCap='round';ctx.lineJoin='round';ctx.strokeStyle='#111';}
 window.openSignatureModal=function(nextMode){
   mode=nextMode;
   const isRecipient=mode==='recipient';
   const isPin=mode==='manager-pin';
   document.getElementById('aeModalTitle').textContent=isRecipient?'Firma del colaborador':(isPin?'Autorización de firma del gerente':'Firma manuscrita del gerente');
   document.getElementById('aeDeclaration').textContent=isRecipient
     ?'Declaro haber recibido los equipos detallados en esta acta y asumo su custodia mientras estén asignados a mi responsabilidad.'
     :'He revisado esta acta y acepto la responsabilidad de custodia de los activos correspondientes, conforme a las condiciones establecidas.';
   document.getElementById('aeRole').value=isRecipient?'recipient':'manager';
   document.getElementById('aeMethod').value=isPin?'pin':'draw';
   document.getElementById('aeDrawArea').style.display=isPin?'none':'block';
   document.getElementById('aePinArea').style.display=isPin?'block':'none';
   document.getElementById('aePin').required=isPin;
   document.getElementById('aePin').value='';
   clearAeCanvas();
   modal=bootstrap.Modal.getOrCreateInstance(document.getElementById('aeSignatureModal'));
   modal.show();
 };
 window.clearAeCanvas=function(){
   if(!ctx)return;
   ctx.clearRect(0,0,canvas.width,canvas.height);hasInk=false;
 };
 if(canvas){
   const point=e=>{const r=canvas.getBoundingClientRect();return{x:(e.clientX-r.left)*canvas.width/r.width,y:(e.clientY-r.top)*canvas.height/r.height};};
   canvas.addEventListener('pointerdown',e=>{e.preventDefault();drawing=true;canvas.setPointerCapture(e.pointerId);const p=point(e);ctx.beginPath();ctx.moveTo(p.x,p.y);});
   canvas.addEventListener('pointermove',e=>{if(!drawing)return;e.preventDefault();const p=point(e);ctx.lineTo(p.x,p.y);ctx.stroke();hasInk=true;});
   const stop=()=>{drawing=false;};canvas.addEventListener('pointerup',stop);canvas.addEventListener('pointercancel',stop);
 }
 const form=document.getElementById('aeSignatureForm');
 if(form)form.addEventListener('submit',e=>{
   if(mode==='manager-pin'){
     document.getElementById('aePin').name='manager_pin';
     if(!/^[0-9]{6}$/.test(document.getElementById('aePin').value)){e.preventDefault();alert('Ingrese el PIN personal de 6 dígitos.');return;}
   }else{
     document.getElementById('aePin').removeAttribute('name');
     if(!hasInk){e.preventDefault();alert('Debe dibujar la firma antes de continuar.');return;}
     document.getElementById('aeSignatureData').value=canvas.toDataURL('image/png');
   }
 });
})();
</script>
JS;

Html::footer();
