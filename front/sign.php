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
    $status = (string)$acta['status'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // GLPI 11 valida el token CSRF mediante su CheckCsrfListener antes de ejecutar esta página.
        // No volver a consumirlo aquí: la doble validación provoca CSRF check failed.
        if ($status === 'Firmada completamente' || $status === 'Anulada') {
            throw new RuntimeException('El acta ya está finalizada o anulada y no admite cambios.');
        }
        $role = (string)($_POST['role'] ?? '');
        if ($role === 'recipient') {
            if ($status !== 'Pendiente de firma del colaborador') {
                throw new RuntimeException('La firma del colaborador ya fue registrada o el acta no está en esa etapa.');
            }
            $sig = PluginActadeentregaSigningService::storeDrawing((string)($_POST['signature_data'] ?? ''), (string)$acta['act_number'], 'colaborador');
            // Actualizar el PDF de vista previa para que el gerente vea también la firma del colaborador.
            $previewBase = rtrim(GLPI_VAR_DIR, DIRECTORY_SEPARATOR) . '/_plugins/actadeentrega/acts';
            $previewFile = 'acta_preview_' . $id . '_' . bin2hex(random_bytes(5)) . '.pdf';
            $previewPath = $previewBase . '/' . $previewFile;
            try {
                PluginActadeentregaActaPdf::generate($snapshot, (string)$acta['issuer_signature_path'], $previewPath, $sig);
                $previewHash = hash_file('sha256', $previewPath);
                if (!$previewHash) {
                    throw new RuntimeException('No se pudo verificar la vista previa del acta.');
                }
            } catch (Throwable $previewError) {
                if (is_file($previewPath)) @unlink($previewPath);
                if (is_file($sig)) @unlink($sig);
                throw $previewError;
            }
            $DB->update('glpi_plugin_actadeentrega_actas', [
                'recipient_signature_path' => $sig,
                'recipient_signed_at' => date('Y-m-d H:i:s'),
                'status' => 'Pendiente de firma del gerente',
                'pdf_path' => '_plugins/actadeentrega/acts/' . $previewFile,
                'pdf_sha256' => $previewHash,
            ], ['id' => $id]);
            PluginActadeentregaSigningService::audit($id, (int)$acta['recipient_users_id'], 'colaborador', 'firma_dibujada', 'Firma manuscrita recogida por técnico GLPI ' . (int)Session::getLoginUserID());
            Session::addMessageAfterRedirect('Firma del colaborador registrada. Ahora corresponde la firma del gerente.', true, INFO);
        } elseif ($role === 'manager') {
            if ($status !== 'Pendiente de firma del gerente' || empty($acta['recipient_signature_path'])) {
                throw new RuntimeException('El colaborador debe firmar primero.');
            }
            $managerId = (int)($snapshot['user']['manager_users_id'] ?? 0);
            if ($managerId < 1) throw new RuntimeException('El gerente asignado al departamento no tiene un usuario GLPI válido.');
            $method = (string)($_POST['manager_method'] ?? 'draw');
            if ($method === 'pin') {
                $authorized = PluginActadeentregaActaService::authorizeSignature($managerId, (string)($_POST['manager_pin'] ?? ''));
                $managerSignature = $authorized['path'];
                $auditMethod = 'pin_local';
            } elseif ($method === 'draw') {
                $managerSignature = PluginActadeentregaSigningService::storeDrawing((string)($_POST['signature_data'] ?? ''), (string)$acta['act_number'], 'gerente');
                $auditMethod = 'firma_dibujada';
            } else {
                throw new RuntimeException('Método de firma del gerente no válido.');
            }
            $DB->update('glpi_plugin_actadeentrega_actas', [
                'manager_signature_path' => $managerSignature,
                'manager_signature_method' => $auditMethod,
            ], ['id' => $id]);
            PluginActadeentregaSigningService::audit($id, $managerId, 'gerente', $auditMethod, 'Firma recogida por técnico GLPI ' . (int)Session::getLoginUserID());
            $fresh = PluginActadeentregaSigningService::acta($id);
            PluginActadeentregaSigningService::finalize($fresh);
            Session::addMessageAfterRedirect('Acta firmada por el colaborador y el gerente. PDF final generado y protegido.', true, INFO);
            Html::redirect('/plugins/actadeentrega/front/pdf.php?id=' . $id);
        } else {
            throw new RuntimeException('Etapa de firma no reconocida.');
        }
        Html::redirect('/plugins/actadeentrega/front/sign.php?id=' . $id);
    }
} catch (Throwable $e) {
    Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
    Html::redirect('/plugins/actadeentrega/front/history.php');
}

Html::header('Firmas del Acta de Entrega', $_SERVER['PHP_SELF'], 'plugins', 'PluginActadeentregaMenu');
$recipientName = (string)($snapshot['user']['recipient'] ?? '');
$managerName = (string)($snapshot['user']['manager'] ?? '');
$recipientSigned = !empty($acta['recipient_signature_path']);
$managerSigned = !empty($acta['manager_signature_path']);
$managerHasReusableSignature = false;
$managerId = (int)($snapshot['user']['manager_users_id'] ?? 0);
if ($managerId > 0 && $DB->tableExists('glpi_plugin_informeglopi_user_signatures')) {
    $managerHasReusableSignature = (int)$DB->request([
        'COUNT' => 'c',
        'FROM' => 'glpi_plugin_informeglopi_user_signatures',
        'WHERE' => ['users_id' => $managerId, 'pin_hash' => ['!=', '']],
    ])->current()['c'] > 0;
}
echo "<div class='container-fluid'><h2>Acta " . htmlspecialchars((string)$acta['act_number'], ENT_QUOTES, 'UTF-8') . "</h2>";
echo "<p><strong>Estado:</strong> " . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . "</p>";
echo "<p><strong>Colaborador:</strong> " . htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8') . "<br><strong>Gerente:</strong> " . htmlspecialchars($managerName, ENT_QUOTES, 'UTF-8') . "</p>";
echo "<div class='row g-3 align-items-start'>";
echo "<div class='col-12 col-xl-7'><div class='card'><div class='card-header d-flex justify-content-between align-items-center'><strong>Documento del acta</strong><a class='btn btn-sm btn-outline-secondary' target='_blank' href='/plugins/actadeentrega/front/pdf.php?id=" . $id . "'>Abrir PDF</a></div>";
echo "<iframe title='Vista previa del acta' src='/plugins/actadeentrega/front/pdf.php?id=" . $id . "' style='display:block;width:100%;height:78vh;min-height:560px;border:0;background:#f2f2f2' loading='eager'></iframe></div></div>";
echo "<div class='col-12 col-xl-5'>";
if ($status === 'Pendiente de firma del colaborador') {
    echo "<div class='alert alert-info'>El colaborador debe revisar y firmar primero. La firma se recoge en el dispositivo del técnico.</div>";
    echo "<form method='post' id='recipientForm' class='card card-body' style='max-width:760px'>";
    echo "<input type='hidden' name='_glpi_csrf_token' value='" . Session::getNewCSRFToken(true) . "'><input type='hidden' name='id' value='" . $id . "'><input type='hidden' name='role' value='recipient'><input type='hidden' name='signature_data' id='recipientSignature'>";
    echo "<p>Declaro haber recibido los equipos detallados en esta acta y asumo su custodia mientras estén asignados a mi responsabilidad.</p>";
    echo "<label class='form-label fw-semibold'>Firma del colaborador: " . htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8') . "</label><canvas id='recipientCanvas' width='900' height='260' style='width:100%;height:190px;border:1px solid #777;border-radius:6px;touch-action:none;background:#fff'></canvas>";
    echo "<button type='button' class='btn btn-outline-secondary mt-2' onclick='clearSignature(&quot;recipientCanvas&quot;)'>Limpiar firma</button><button class='btn btn-primary mt-3' type='submit'>Guardar firma del colaborador</button></form>";
} elseif ($status === 'Pendiente de firma del gerente') {
    echo "<div class='alert alert-warning'>El colaborador ya firmó. Ahora el gerente debe aceptar la responsabilidad. El técnico debe poner el dispositivo a disposición del gerente.</div>";
    echo "<div class='card card-body' style='max-width:760px'><p>He revisado esta acta y acepto la responsabilidad de custodia de los activos correspondientes, conforme a las condiciones establecidas.</p>";
    echo "<div class='mb-3'><label class='form-label fw-semibold'>Firma del gerente: " . htmlspecialchars($managerName, ENT_QUOTES, 'UTF-8') . "</label>";
    echo "<div class='btn-group mb-2' role='group'><button type='button' class='btn btn-outline-primary' onclick='showManagerMode(&quot;draw&quot;)'>Dibujar firma</button>";
    if ($managerHasReusableSignature) echo "<button type='button' class='btn btn-outline-primary' onclick='showManagerMode(&quot;pin&quot;)'>Usar firma registrada con PIN</button>";
    echo "</div></div>";
    echo "<form method='post' id='managerDrawForm'><input type='hidden' name='_glpi_csrf_token' value='" . Session::getNewCSRFToken(true) . "'><input type='hidden' name='id' value='" . $id . "'><input type='hidden' name='role' value='manager'><input type='hidden' name='manager_method' value='draw'><input type='hidden' name='signature_data' id='managerSignature'>";
    echo "<div id='managerDraw'><canvas id='managerCanvas' width='900' height='260' style='width:100%;height:190px;border:1px solid #777;border-radius:6px;touch-action:none;background:#fff'></canvas><button type='button' class='btn btn-outline-secondary mt-2' onclick='clearSignature(&quot;managerCanvas&quot;)'>Limpiar firma</button><button class='btn btn-primary mt-3' type='submit'>Guardar firma del gerente</button></div></form>";
    if ($managerHasReusableSignature) {
        echo "<form method='post' id='managerPinForm' style='display:none'><input type='hidden' name='_glpi_csrf_token' value='" . Session::getNewCSRFToken(true) . "'><input type='hidden' name='id' value='" . $id . "'><input type='hidden' name='role' value='manager'><input type='hidden' name='manager_method' value='pin'><label class='form-label'>El gerente debe ingresar personalmente su PIN de 6 dígitos</label><input class='form-control' style='max-width:220px' type='password' inputmode='numeric' pattern='[0-9]{6}' maxlength='6' name='manager_pin' autocomplete='off' required><button class='btn btn-primary mt-3' type='submit'>Autorizar y firmar</button></form>";
    }
    echo "</div>";
} else {
    echo "<div class='alert alert-success'>El acta está finalizada. El documento firmado se muestra en el visor de la izquierda.</div>";
}
echo "</div></div>";
echo "<script>
function setupCanvas(id, hiddenId, formId) {
 const canvas=document.getElementById(id); if(!canvas)return;
 const ctx=canvas.getContext('2d'); ctx.lineWidth=3; ctx.lineCap='round'; ctx.strokeStyle='#111';
 let drawing=false, ink=false;
 const pos=e=>{const r=canvas.getBoundingClientRect();const p=e.touches?e.touches[0]:e;return {x:(p.clientX-r.left)*canvas.width/r.width,y:(p.clientY-r.top)*canvas.height/r.height};};
 const start=e=>{e.preventDefault();drawing=true;const p=pos(e);ctx.beginPath();ctx.moveTo(p.x,p.y);};
 const move=e=>{if(!drawing)return;e.preventDefault();const p=pos(e);ctx.lineTo(p.x,p.y);ctx.stroke();ink=true;};
 const end=()=>{drawing=false;};
 canvas.addEventListener('pointerdown',start);canvas.addEventListener('pointermove',move);window.addEventListener('pointerup',end);
 canvas.dataset.hidden=hiddenId;canvas.dataset.form=formId;canvas.dataset.ink='0';
 canvas.addEventListener('pointermove',()=>{if(ink)canvas.dataset.ink='1';});
}
function clearSignature(id){const c=document.getElementById(id);if(!c)return;c.getContext('2d').clearRect(0,0,c.width,c.height);c.dataset.ink='0';}
function bindForm(formId,canvasId,hiddenId){const f=document.getElementById(formId);if(!f)return;f.addEventListener('submit',e=>{const c=document.getElementById(canvasId);if(!c||c.dataset.ink!=='1'){e.preventDefault();alert('Debe dibujar la firma antes de continuar.');return;}document.getElementById(hiddenId).value=c.toDataURL('image/png');});}
function showManagerMode(mode){document.getElementById('managerDraw').style.display=mode==='draw'?'block':'none';const p=document.getElementById('managerPinForm');if(p)p.style.display=mode==='pin'?'block':'none';}
setupCanvas('recipientCanvas','recipientSignature','recipientForm');bindForm('recipientForm','recipientCanvas','recipientSignature');
setupCanvas('managerCanvas','managerSignature','managerDrawForm');bindForm('managerDrawForm','managerCanvas','managerSignature');
</script></div>";
Html::footer();
