<?php
declare(strict_types=1);

include('../../../inc/includes.php');
Session::checkRight('plugin_actadeentrega', READ);
Html::header('Actas de Entrega', $_SERVER['PHP_SELF'], 'management', 'PluginActadeentregaMenu');

global $DB;
echo "<div class='container-fluid'><h2>Actas de Entrega</h2>";
echo "<p><a class='btn btn-primary' href='/plugins/actadeentrega/front/acta.form.php'>Nueva acta de entrega</a> ";
echo "<a class='btn btn-outline-secondary' href='/plugins/actadeentrega/front/history.php'>Historial de actas</a></p>";
echo "<div class='alert alert-info'>Seleccione un colaborador, marque únicamente los activos que se entregarán y genere el documento. Los datos se validan contra GLPI.</div></div>";
Html::footer();
