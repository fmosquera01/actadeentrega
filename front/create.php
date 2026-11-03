<?php
declare(strict_types=1);

include('../../../inc/includes.php');
Session::checkRight('plugin_actadeentrega', CREATE);
require_once __DIR__ . '/../src/ActaService.php';
require_once __DIR__ . '/../src/ActaPdf.php';

global $DB;
try {
    $userId = (int) ($_POST['users_id'] ?? 0);
    $issuerId = (int) Session::getLoginUserID();
    $user = PluginActadeentregaActaService::getUser($userId);
    if (!$user) throw new RuntimeException('No se encontró el colaborador seleccionado.');
    $selectedKeys = array_values(array_unique(array_filter(array_map('strval', (array)($_POST['assets'] ?? [])))));
    $available = PluginActadeentregaActaService::getAssets($userId);
    $selected = array_values(array_filter($available, static fn($a) => in_array($a['_itemtype'] . ':' . (int)$a['id'], $selectedKeys, true)));
    if (count($selected) !== count($selectedKeys)) throw new RuntimeException('La selección contiene activos que ya no están asignados actualmente al colaborador. Actualice la pantalla.');
    $coIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['co_responsible_users'] ?? [])), static fn($id) => $id > 0 && $id !== $userId)));
    $participants = [['users_id' => $userId, 'name' => $user['recipient'], 'is_holder' => 1]];
    foreach ($coIds as $coId) {
        $coUser = PluginActadeentregaActaService::getUser($coId);
        if (!$coUser) throw new RuntimeException('Uno de los colaboradores adicionales no existe o está inactivo.');
        $participants[] = ['users_id' => $coId, 'name' => $coUser['recipient'], 'is_holder' => 0];
    }
    $signature = PluginActadeentregaActaService::authorizeSignature($issuerId, (string)($_POST['signature_pin'] ?? ''));
    $missing = PluginActadeentregaActaService::missing($user, $selected, true);
    if ($missing) throw new RuntimeException("No se puede generar el acta. Complete en GLPI:\n• " . implode("\n• ", $missing));

    $assets = [];
    foreach ($selected as $a) {
        $assets[] = [
            'itemtype' => $a['_itemtype'], 'items_id' => (int)$a['id'],
            'type' => $a['_label'], 'serial' => (string)$a['serial'],
            'description' => PluginActadeentregaActaService::describeAsset($a),
            'asset_number' => (string)($a['otherserial'] ?? ''),
            'comment' => (string)($a['comment'] ?? ''),
        ];
    }
    $now = date('Y-m-d H:i:s');
    $snapshot = [
        'date' => $now,
        'user' => [
            'users_id' => $userId, 'recipient' => $user['recipient'],
            'department' => $user['department'], 'jobtitle' => $user['jobtitle'],
            'supervisor' => $user['supervisor'], 'area' => $user['area'], 'manager' => $user['manager'],
        ],
        'issuer' => ['users_id' => $issuerId, 'name' => getUserName($issuerId), 'signature_sha256' => $signature['sha256']],
        'participants' => $participants,
        'assets' => $assets,
    ];
    $year = date('Y');
    $prefix = 'AE-' . $year . '-';
    $DB->beginTransaction();
    try {
        $last = 0;
        foreach ($DB->request(['SELECT'=>['act_number'],'FROM'=>'glpi_plugin_actadeentrega_actas','WHERE'=>['act_number'=>['LIKE', $prefix.'%']],'ORDER'=>['id DESC'],'LIMIT'=>1]) as $r) {
            $last = (int)substr((string)$r['act_number'], strlen($prefix));
        }
        $number = $prefix . str_pad((string)($last + 1), 5, '0', STR_PAD_LEFT);
        $base = rtrim(GLPI_VAR_DIR, DIRECTORY_SEPARATOR) . '/_plugins/actadeentrega/acts';
        if (!is_dir($base) && !mkdir($base, 0750, true) && !is_dir($base)) throw new RuntimeException('No se pudo crear el almacenamiento privado de actas.');
        $file = 'acta_' . $number . '_' . bin2hex(random_bytes(5)) . '.pdf';
        $path = $base . '/' . $file;
        PluginActadeentregaActaPdf::generate($snapshot, $signature['path'], $path);
        $hash = hash_file('sha256', $path);
        if (!$hash) throw new RuntimeException('No se pudo verificar la integridad del PDF.');
        $DB->insert('glpi_plugin_actadeentrega_actas', [
            'act_number'=>$number, 'entities_id'=>(int)($_SESSION['glpiactive_entity'] ?? 0),
            'recipient_users_id'=>$userId, 'issuer_users_id'=>$issuerId,
            'act_date'=>$now, 'snapshot'=>json_encode($snapshot, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
            'pdf_path'=>'_plugins/actadeentrega/acts/'.$file, 'pdf_sha256'=>$hash,
        ]);
        $actaId = (int)$DB->insertId();
        foreach ($participants as $order => $participant) {
            $DB->insert('glpi_plugin_actadeentrega_acta_users', [
                'plugin_actadeentrega_actas_id' => $actaId,
                'users_id' => (int)$participant['users_id'],
                'is_holder' => (int)$participant['is_holder'],
                'sort_order' => $order,
            ]);
        }
        $DB->commit();
    } catch (Throwable $e) {
        $DB->rollBack();
        if (isset($path) && is_file($path)) @unlink($path);
        throw $e;
    }
    Session::addMessageAfterRedirect('Acta ' . $number . ' generada correctamente. Se descargará el PDF.', true, INFO);
    header('Location: /plugins/actadeentrega/front/pdf.php?id=' . $actaId, true, 303);
    exit;
} catch (Throwable $e) {
    Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
    $redirect = '/plugins/actadeentrega/front/acta.form.php?users_id=' . (int)($_POST['users_id'] ?? 0);
    if ((int)($_POST['from_user'] ?? 0) === 1) {
        $redirect .= '&from_user=1';
    }
    Html::redirect($redirect);
}
