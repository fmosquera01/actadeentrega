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

// Selector de registros por página mediante GET: evita el POST sin token CSRF
// que GLPI 11 rechaza en las páginas propias de plugins.
$allowedLimits = [10, 20, 50, 100, 200, 500];
if (isset($_GET['glpilist_limit']) && in_array((int)$_GET['glpilist_limit'], $allowedLimits, true)) {
    $_SESSION['glpilist_limit'] = (int)$_GET['glpilist_limit'];
}
$limit = (int)($_SESSION['glpilist_limit'] ?? $CFG_GLPI['list_limit'] ?? 20);
if (!in_array($limit, $allowedLimits, true)) {
    $limit = 20;
}
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

$totalPages = (int)ceil($total / $limit);
$currentPage = $totalPages > 0 ? (int)floor($start / $limit) + 1 : 1;
$baseParams = [];
if ($search !== '') $baseParams['q'] = $search;
$baseParams['glpilist_limit'] = $limit;
$makePageUrl = static function(int $page) use ($target, $limit, $baseParams): string {
    $params = $baseParams;
    $params['start'] = max(0, ($page - 1) * $limit);
    return $target . '?' . http_build_query($params);
};

echo "<div class='d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3'>";
echo "<form method='get' action='" . htmlspecialchars($target, ENT_QUOTES, 'UTF-8') . "' class='d-flex align-items-center gap-2'>";
if ($search !== '') echo "<input type='hidden' name='q' value='" . htmlspecialchars($search, ENT_QUOTES, 'UTF-8') . "'>";
echo "<label for='ae-list-limit' class='form-label mb-0'>Mostrar</label>";
echo "<select id='ae-list-limit' name='glpilist_limit' class='form-select form-select-sm' style='width:auto' onchange='this.form.submit()'>";
foreach ([10, 20, 50, 100, 200, 500] as $option) {
    $selected = $option === $limit ? ' selected' : '';
    echo "<option value='" . $option . "'" . $selected . ">" . $option . "</option>";
}
echo "</select><span>registros</span></form>";
if ($totalPages > 0) {
    $from = $start + 1;
    $to = min($start + $limit, $total);
    echo "<span class='text-muted'>Mostrando " . $from . "–" . $to . " de " . $total . " actas</span>";
}
echo "</div>";

if ($totalPages > 1) {
    $windowStart = max(1, $currentPage - 2);
    $windowEnd = min($totalPages, $currentPage + 2);
    if ($windowEnd - $windowStart < 4) {
        if ($windowStart === 1) $windowEnd = min($totalPages, 5);
        elseif ($windowEnd === $totalPages) $windowStart = max(1, $totalPages - 4);
    }
    echo "<nav aria-label='Paginación del historial' class='mb-3'><ul class='pagination pagination-sm justify-content-center flex-wrap'>";
    if ($currentPage > 1) {
        echo "<li class='page-item'><a class='page-link' href='" . htmlspecialchars($makePageUrl(1), ENT_QUOTES, 'UTF-8') . "' aria-label='Primera página'>&laquo;&laquo;</a></li>";
        echo "<li class='page-item'><a class='page-link' href='" . htmlspecialchars($makePageUrl($currentPage - 1), ENT_QUOTES, 'UTF-8') . "' aria-label='Página anterior'>&laquo;</a></li>";
    }
    if ($windowStart > 1) {
        echo "<li class='page-item'><a class='page-link' href='" . htmlspecialchars($makePageUrl(1), ENT_QUOTES, 'UTF-8') . "'>1</a></li>";
        if ($windowStart > 2) echo "<li class='page-item disabled'><span class='page-link'>…</span></li>";
    }
    for ($page = $windowStart; $page <= $windowEnd; $page++) {
        $active = $page === $currentPage ? ' active' : '';
        $aria = $page === $currentPage ? " aria-current='page'" : '';
        echo "<li class='page-item" . $active . "'><a class='page-link' href='" . htmlspecialchars($makePageUrl($page), ENT_QUOTES, 'UTF-8') . "'" . $aria . ">" . $page . "</a></li>";
    }
    if ($windowEnd < $totalPages) {
        if ($windowEnd < $totalPages - 1) echo "<li class='page-item disabled'><span class='page-link'>…</span></li>";
        echo "<li class='page-item'><a class='page-link' href='" . htmlspecialchars($makePageUrl($totalPages), ENT_QUOTES, 'UTF-8') . "'>" . $totalPages . "</a></li>";
    }
    if ($currentPage < $totalPages) {
        echo "<li class='page-item'><a class='page-link' href='" . htmlspecialchars($makePageUrl($currentPage + 1), ENT_QUOTES, 'UTF-8') . "' aria-label='Página siguiente'>&raquo;</a></li>";
        echo "<li class='page-item'><a class='page-link' href='" . htmlspecialchars($makePageUrl($totalPages), ENT_QUOTES, 'UTF-8') . "' aria-label='Última página'>&raquo;&raquo;</a></li>";
    }
    echo "</ul></nav>";
}

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
