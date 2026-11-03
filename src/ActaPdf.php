<?php
declare(strict_types=1);

final class PluginActadeentregaActaPdf
{
    public static function generate(array $snapshot, string $signaturePath, string $outputPath): void
    {
        if (!class_exists('TCPDF')) throw new RuntimeException('TCPDF no está disponible en GLPI.');
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('GLPI - Acta de Entrega');
        $pdf->SetAuthor('Songa - Infraestructura IT');
        $pdf->SetTitle('Acta de Entrega - ' . $snapshot['user']['recipient']);
        $pdf->SetMargins(17, 15, 17);
        $pdf->SetAutoPageBreak(true, 25);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();
        $e = static fn($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $u = $snapshot['user'];
        $date = new DateTimeImmutable($snapshot['date']);
        $months = ['', 'ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO', 'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE'];
        $dateLabel = $date->format('d') . ' DE ' . $months[(int) $date->format('n')] . ' DEL ' . $date->format('Y');

        $html = '<style>
            body{font-family:helvetica;font-size:9pt;color:#111}
            h2{text-align:center;font-size:14pt;font-weight:bold;margin-bottom:12px}
            .section{font-weight:bold;font-size:10pt;margin-top:8px;margin-bottom:5px}
            table{border-collapse:collapse;width:100%}
            td,th{border:0.5px solid #555;padding:5px;vertical-align:top}
            .data td{border:0;padding:3px 2px}
            .label{font-weight:bold;width:34%}
            th{font-weight:bold;text-align:center;background-color:#eeeeee}
            .clauses{font-size:8.5pt;line-height:1.35}
            .sign{border:0;text-align:center}
        </style>';
        $html .= '<h2>ACTA DE ENTREGA DE EQUIPOS</h2><div class="section">DATOS</div>';
        $html .= '<table class="data">';
        foreach ([
            'Gerente o jefe de Área' => $u['manager'],
            'Responsable(s) del Equipo' => $u['recipient'],
            'Cargo/Función' => $u['jobtitle'],
            'Área' => $u['area'],
            'Fecha' => $dateLabel,
        ] as $label => $value) {
            $html .= '<tr><td class="label"><b>' . $e($label) . ':</b></td><td>' . $e($value) . '</td></tr>';
        }
        $html .= '</table><div class="section">Se entrega el (los) siguiente(s) equipo(s):</div>';
        $html .= '<table><thead><tr><th width="7%">Ítem</th><th width="15%">Tipo</th><th width="19%">Número de Serie</th><th width="43%">Descripción del equipo</th><th width="16%">Activo Fijo</th></tr></thead><tbody>';
        foreach ($snapshot['assets'] as $i => $asset) {
            $html .= '<tr><td align="center">' . ($i + 1) . '</td><td>' . $e($asset['type']) . '</td><td>' . $e($asset['serial']) . '</td><td>' . nl2br($e($asset['description'])) . '</td><td align="center">' . $e($asset['asset_number'] !== '' ? $asset['asset_number'] : 'N/A') . '</td></tr>';
        }
        $html .= '</tbody></table>';
        $html .= '<div class="section">COMPROMISO DE RESPONSABILIDAD</div><div class="clauses">Me comprometo con la compañía a hacerme responsable del cuidado de este equipo, con las siguientes cláusulas:<br>
        1. El responsable directo del equipo al momento de la entrega debe recibir una inducción de cómo se utiliza el equipo y el sistema por parte del Dpto. de Sistemas.<br>
        2. El equipo que se le entrega está en perfectas condiciones. En caso de que el equipo no esté funcionando, entregarlo al Dpto. de Sistemas.<br>
        3. En caso de que el equipo sea asignado por turno de trabajo deberá ser entregado al responsable del equipo del siguiente turno.<br>
        4. En caso de pérdida o daños causados por mal uso el equipo será descontado de su sueldo o liquidación de haberes al responsable directo.<br>
        5. La pérdida o daño por préstamo a algún otro empleado de la empresa no lo exime de su responsabilidad con el equipo.</div>';
        $html .= '<br><br><table><tr><td class="sign" width="50%"><b>FIRMA DEL RESPONSABLE</b><br><br><br>' . $e($u['recipient']) . '</td><td class="sign" width="50%"><b>FIRMA DEL GERENTE O JEFE DE ÁREA</b><br><br><br>' . $e($u['manager']) . '</td></tr></table>';
        $html .= '<br><table><tr><td class="sign"><b>RESPONSABLE DE ENTREGA</b><br><img src="' . $e($signaturePath) . '" height="22mm"><br>' . $e($snapshot['issuer']['name']) . '</td></tr></table>';
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Output($outputPath, 'F');
        if (!is_file($outputPath) || filesize($outputPath) < 800) throw new RuntimeException('No se pudo generar un PDF válido.');
        chmod($outputPath, 0640);
    }
}
