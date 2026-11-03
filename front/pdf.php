<?php
declare(strict_types=1);

include('../../../inc/includes.php');
Session::checkRight('plugin_actadeentrega', READ);
global $DB;
$id = (int)($_GET['id'] ?? 0);
$row = $DB->request(['SELECT'=>['pdf_path','pdf_sha256'],'FROM'=>'glpi_plugin_actadeentrega_actas','WHERE'=>['id'=>$id],'LIMIT'=>1])->current();
if (!$row) Html::displayNotFoundError();
$base = realpath(rtrim(GLPI_VAR_DIR, DIRECTORY_SEPARATOR) . '/_plugins/actadeentrega/acts');
$path = realpath(rtrim(GLPI_VAR_DIR, DIRECTORY_SEPARATOR) . '/' . ltrim((string)$row['pdf_path'], '/'));
if (!$base || !$path || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) Html::displayNotFoundError();
$hash = hash_file('sha256', $path);
if (!$hash || !hash_equals((string)$row['pdf_sha256'], $hash)) Html::displayErrorAndDie('El PDF no superó la verificación de integridad.');
header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="acta-entrega-' . $id . '.pdf"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
exit;
