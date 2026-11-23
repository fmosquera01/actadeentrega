<?php
declare(strict_types=1);

include('../../../inc/includes.php');
require_once __DIR__ . '/../src/Navigation.php';

Session::checkRight('plugin_actadeentrega', READ);
Html::header('Historial de Actas', $_SERVER['PHP_SELF'], 'plugins', 'PluginActadeentregaMenu');
PluginActadeentregaNavigation::backButton('/plugins/actadeentrega/front/acta.php');

global $DB, $CFG_GLPI;

$table = 'glpi_plugin_actadeentrega_actas';
$target = rtrim((string)($CFG_GLPI['root_doc'] ?? ''), '/') . '/plugins/actadeentrega/front/history.php';

// Utilizar el mismo selector de cantidad de registros que los listados nativos de GLPI.
if (isset($_POST['glpilist_limit']) && is_numeric($_POST['glpilist_limit'])) {
    $requestedLimit = (int)$_POST['glpilist_limit'];
    $maximumLimit = (int)($CFG_GLPI['list_limit_max'] ?? 1000);
    if ($requestedLimit > 0 && $requestedLimit <= $maximumLimit) {
        $_SESSION['glpilist_limit'] = $requestedLimit;
    }
}

$limit = (int)($_SESSION['glpilist_limit'] ?? $CFG_GLPI['list_limit'] ?? 20);
if ($limit < 1) {
    $limit = 20;
}
$search = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
$start = max(0, (int)($_GET['start'] ?? 0));

$where = [];
if ($search !== '') {
    // Buscar en los datos principales y en el snapshot para incluir responsables y activos.
    $like = '%' . addcslashes($search, "\\\\%_") . '%';
    $where = [
        'OR' => [
            'act_number' => ['LIKE', $like],
            'act_date'   => ['LIKE', $like],
            'status'     => ['LIKE', $like],
            'snapshot'   => ['LIKE', $like],
        ],
    ];
}

$countCriteria = [
    'COUNT' => 'total',
    'FROM'  => $table,
];
if ($where !== []) {
    $countCriteria['WHERE'] = $where;
}
$countIterator = $DB->request($countCriteria);
$countRow = $countIterator->current();
$total = (int)($countRow['total'] ?? 0);

if ($total > 0 && $start >= $total) {
    $start = (int)(floor(($total - 1) / $limit) * $limit);
}

$listCriteria = [
    'SELECT' => ['id', 'act_number', 'act_date', 'recipient_users_id', 'issuer_users_id', 'snapshot', 'status'],
    'FROM'   => $table,
    'ORDER'  => ['id DESC'],
    'LIMIT'  => $limit,
    'START'  => $start,
];
if ($where !== []) {
    $listCriteria['WHERE'] = $where;
}
$rows = $DB->request($listCriteria);

$searchValue = htmlspecialchars($search, ENT_QUOTES, 'UTF-8');
$searchAction = htmlspecialchars($target, ENT_QUOTES, 'UTF-8');
echo "<div class='container-fluid'>";
echo "<h2>Historial de actas de entrega</h2>";
echo "<form method='get' action='" . $searchAction . "' class='mb-3'>";
echo "<div class='input-group' style='max-width: 650px'>";
echo "<input type='search' name='q' class='form-control' value='" . $searchValue . "' placeholder='Buscar por número, fecha, responsable, activo o estado'>";
echo "<button type='submit' class='btn btn-primary'><i class='ti ti-search'></i> Buscar</button>";
if ($search !== '') {
    echo "<a class='btn btn-outline-secondary' href='" . $searchAction . "'>Limpiar</a>";
}
echo "</div></form>";

$pagerParameters = $search !== '' ? 'q=' . rawurlencode($search) : '';
Html::printPager($start, $total, $target, $pagerParameters);

echo "<div class='table-responsive'><table class='table table-sm table-hover'>";
echo "<thead><tr><th>Número</th><th>Fecha</th><th>Colaboradores responsables</th><th>Activos incluidos</th><th>Emitido por</th><th>Estado</th><th>Acción</th></tr></thead><tbody>";

if ($total === 0) {
    $message = $search !== '' ? 'No se encontraron actas que coincidan con la búsqueda.' : 'No existen actas registradas.';
    echo "<tr><td colspan='7' class='text-center'>" . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "</td></tr>";
} else {
    foreach ($rows as $row) {
        $snapshot = json_decode((string)$row['snapshot'], true) ?: [];
        $names = array_map(static fn($p) => (string)($p['name'] ?? ''), $snapshot['participants'] ?? []);
        if (!$names) {
            $names = [getUserName((int)$row['recipient_users_id'])];
        }
        $assets = array_map(static function($a) {
            $label = trim((string)($a['type'] ?? '') . ' ' . (string)($a['serial'] ?? ''));
            return $label !== '' ? $label : (string)($a['description'] ?? 'Activo');
        }, $snapshot['assets'] ?? []);

        $id = (int)$row['id'];
        $number = htmlspecialchars((string)$row['act_number'], ENT_QUOTES, 'UTF-8');
        $date = htmlspecialchars((string)$row['act_date'], ENT_QUOTES, 'UTF-8');
        $participants = htmlspecialchars(implode(', ', array_filter($names)), ENT_QUOTES, 'UTF-8');
        $assetList = htmlspecialchars(implode('; ', $assets), ENT_QUOTES, 'UTF-8');
        $issuer = htmlspecialchars(getUserName((int)$row['issuer_users_id']), ENT_QUOTES, 'UTF-8');
        $status = (string)($row['status'] ?? 'Firmada completamente');
        $statusHtml = htmlspecialchars($status, ENT_QUOTES, 'UTF-8');
        $actions = '';
        if ($status !== 'Firmada completamente' && $status !== 'Anulada') {
            $actions .= '<a href="/plugins/actadeentrega/front/sign.php?id=' . $id . '">Continuar firmas</a> · ';
        }
        $actions .= '<a target="_blank" href="/plugins/actadeentrega/front/pdf.php?id=' . $id . '">Ver PDF</a>';

        echo '<tr><td>' . $number . '</td><td>' . $date . '</td><td>' . $participants . '</td><td>' . $assetList . '</td><td>' . $issuer . '</td><td>' . $statusHtml . '</td><td>' . $actions . '</td></tr>';
    }
}

echo "</tbody></table></div>";
Html::printPager($start, $total, $target, $pagerParameters);
echo "</div>";
Html::footer();
