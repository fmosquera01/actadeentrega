<?php
/**
 * Renderizador del PDF "ACTA DE ENTREGA DE EQUIPOS" (formato Songa).
 * Reproduce el Word: logo, recuadro DATOS, tabla de equipos, cláusulas con
 * viñetas, y los bloques de firma del responsable, del gerente/jefe de área
 * y del responsable de entrega.
 *
 * Uso:
 *   $pdf = ActaEntregaPdfRenderer::render($data);   // string binario del PDF
 *
 * Requiere TCPDF (el que incluye GLPI) y el archivo logo_songa.jpeg.
 */
class ActaEntregaPdfRenderer
{
    private const PAGE_H = 842.0;   // A4
    private const BOTTOM = 40.0;

    /** @var TCPDF */
    private $pdf;

    private const CLAUSULAS = [
        'El responsable directo del equipo al momento de la entrega debe recibir una inducción de cómo se utiliza el equipo y el sistema por parte del Dpto. de Sistemas.',
        'El equipo que se le entrega está en perfectas condiciones. En caso de que el equipo no esté funcionado entregarlo al Dpto. de Sistemas.',
        'En caso de que el equipo sea asignado por turno de trabajo deberá ser entregado al responsable del equipo del siguiente turno.',
        'En caso de pérdida o daños causados por mal uso el equipo será descontado de su sueldo o liquidación de haberes al responsable directo.',
        'La pérdida o daño por préstamo a algún otro empleado de la empresa no lo exime de su responsabilidad con el equipo.',
    ];

    public static function render(array $d): string
    {
        return (new self())->build($d);
    }

    private function build(array $d): string
    {
        $p = new TCPDF('P', 'pt', 'A4', true, 'UTF-8', false);
        $p->setPrintHeader(false);
        $p->setPrintFooter(false);
        $p->SetMargins(40, 30, 40);
        $p->SetAutoPageBreak(false);
        $p->SetTitle('Acta de entrega de equipos');
        $p->SetCreator('GLPI - ActaEntrega');
        $p->AddPage();
        $p->setCellPaddings(4, 0, 4, 0);
        $this->pdf = $p;

        $y = $this->header($d);
        $y = $this->datos($d, $y);
        $y = $this->equipos($d, $y + 8);
        $y = $this->clausulas($d, $y + 12);
        $y = $this->firmaBloque('FIRMA DEL RESPONSABLE', $d['responsable'] ?? '', $d['firma_responsable'] ?? null, $y + 10);
        $y = $this->firmaBloque('FIRMA DEL GERENTE O JEFE DE AREA', $d['gerente'] ?? '', $d['firma_gerente'] ?? null, $y + 12);
        $this->entrega($d, $y + 12);

        return $p->Output('', 'S');
    }

    /* ------------------------------------------------------- logo + título */
    private function header(array $d): float
    {
        $p = $this->pdf;
        $logo = $d['logo'] ?? (__DIR__ . '/logo_songa.jpeg');
        if (is_file($logo)) {
            $p->Image($logo, 45, 18, 110, 0, '', '', '', true, 300);
        }
        $p->SetTextColor(0, 0, 0);
        $p->SetFont('helvetica', 'B', 17);
        $p->SetXY(170, 86);
        $p->Cell(380, 22, 'ACTA DE ENTREGA DE EQUIPOS', 0, 0, 'L');
        return 118;
    }

    /* ------------------------------------------------------------- DATOS */
    private function datos(array $d, float $y): float
    {
        $p = $this->pdf;
        $x = 73.0;
        $w = 468.0;
        $labelW = 224.0;
        $valueW = $w - $labelW;

        $rows = [
            ['Gerente o jefe de Área:', $d['gerente'] ?? ''],
            ['Responsable(s) del Equipo:', $d['responsable'] ?? ''],
            ['Cargo/Función:', $d['cargo'] ?? ''],
            ['Área:', $d['area'] ?? ''],
            ['Fecha:', $d['fecha'] ?? ''],
        ];

        $p->SetFont('helvetica', 'B', 10.5);
        $heights = [];
        $total = 20.0;                                   // fila "DATOS"
        foreach ($rows as $i => $r) {
            $h = max(15.0, $p->getStringHeight($labelW - 8, $r[0]), $p->getStringHeight($valueW - 8, (string)$r[1])) + 2;
            $heights[$i] = $h;
            $total += $h;
        }

        $p->SetDrawColor(0, 0, 0);
        $p->SetLineWidth(0.7);
        $p->Rect($x, $y, $w, $total + 4);

        $p->SetXY($x, $y);
        $p->Cell($w, 20, 'DATOS', 0, 0, 'L');
        $p->SetLineWidth(1.2);
        $p->Line($x, $y + 20, $x + $w, $y + 20);

        $yy = $y + 22;
        foreach ($rows as $i => $r) {
            $h = $heights[$i];
            $p->MultiCell($labelW, $h, $r[0], 0, 'L', false, 0, $x, $yy, true, 0, false, true, $h, 'T');
            $p->MultiCell($valueW, $h, (string)$r[1], 0, 'L', false, 0, $x + $labelW, $yy, true, 0, false, true, $h, 'T');
            $yy += $h;
        }
        return $y + $total + 4;
    }

    /* ----------------------------------------------------- tabla de equipos */
    private function equipos(array $d, float $y): float
    {
        $p = $this->pdf;
        $p->SetTextColor(0, 0, 0);
        $p->SetFont('helvetica', '', 10.5);
        $p->SetXY(85, $y);
        $p->Cell(400, 15, 'Se entrega el (los) siguiente(s) equipo(s):', 0, 0, 'L');
        $y += 18;

        // Anchos proporcionales a la tabla del Word (1135/1559/1916/5029/1277)
        $x0 = 30.0;
        $total = 535.0;
        $w = [55.6, 76.4, 93.9, 246.5, 62.6];

        $p->SetDrawColor(0, 0, 0);
        $p->SetLineWidth(0.5);
        $p->SetFont('helvetica', 'B', 8);
        $heads = ['Ítem', 'Tipo', 'Número de Serie', 'Descripción del equipo', 'Activo Fijo'];
        $x = $x0;
        foreach ($heads as $i => $h) {
            $p->SetXY($x, $y);
            $p->Cell($w[$i], 13, $h, 1, 0, 'C');
            $x += $w[$i];
        }
        $y += 13;

        $n = 1;
        foreach (($d['equipos'] ?? []) as $e) {
            $codigo = trim((string)($e['codigo'] ?? ''));
            $texto  = (string)($e['descripcion'] ?? '');
            $html   = ($codigo !== '' ? '<b>' . $this->e($codigo) . ':</b> ' : '') . $this->e($texto);

            $p->SetFont('helvetica', '', 8);
            $plain = ($codigo !== '' ? $codigo . ': ' : '') . $texto;
            $h = max(13.0, $p->getStringHeight($w[3] - 8, $plain) + 3);

            $p->SetTextColor(64, 64, 64);
            $cells = [
                [$w[0], (string)$n, 'C', false],
                [$w[1], (string)($e['tipo'] ?? ''), 'L', false],
                [$w[2], (string)($e['serie'] ?? ''), 'L', false],
                [$w[3], $html, 'L', true],
                [$w[4], (string)($e['activo'] ?? 'N/A'), 'C', false],
            ];
            $x = $x0;
            foreach ($cells as $c) {
                $p->Rect($x, $y, $c[0], $h);
                $p->MultiCell($c[0], $h, $c[1], 0, $c[2], false, 0, $x, $y + 1.5, true, 0, $c[3], true, $h, 'T');
                $x += $c[0];
            }
            $y += $h;
            $n++;
        }
        $p->SetTextColor(0, 0, 0);
        return $y;
    }

    /* -------------------------------------------------------- cláusulas */
    private function clausulas(array $d, float $y): float
    {
        $p = $this->pdf;
        $p->SetTextColor(89, 89, 89);
        $p->SetFont('times', 'B', 9.5);
        $p->SetXY(85, $y);
        $p->MultiCell(440, 12, 'Me comprometo con la compañía hacerme responsable del cuidado de este equipo, con las siguientes cláusulas:', 0, 'L', false, 1, 85, $y, true);
        $y = $p->GetY() + 3;

        $p->SetTextColor(0, 0, 0);
        $p->SetFont('helvetica', '', 10);
        $textX = 124.0;
        $textW = 385.0;
        foreach (($d['clausulas'] ?? self::CLAUSULAS) as $c) {
            $h = $p->getStringHeight($textW, $c);
            // viñeta cuadrada
            $p->SetFillColor(0, 0, 0);
            $p->Rect(106, $y + 4.2, 3.6, 3.6, 'F');
            $p->MultiCell($textW, $h, $c, 0, 'J', false, 1, $textX, $y, true, 0, false, true, $h, 'T');
            $y += $h + 1.5;
        }
        return $y;
    }

    /* -------------------------------------------------- bloques de firma */
    private function firmaBloque(string $titulo, string $nombre, ?string $firma, float $y): float
    {
        $p = $this->pdf;
        $p->SetDrawColor(0, 0, 0);
        $p->SetLineWidth(0.6);
        $p->SetTextColor(0, 0, 0);

        // Título en recuadro
        $p->SetFont('helvetica', 'B', 10.5);
        $p->SetXY(81, $y);
        $p->Cell(433, 17, $titulo, 1, 0, 'L');
        $y += 17 + 10;

        // Nombre | espacio de firma
        $x = 120.0;
        $nameW = 239.0;
        $sigW = 163.0;
        $h = 42.0;
        $p->Rect($x, $y, $nameW, $h);
        $p->Rect($x + $nameW, $y, $sigW, $h);
        $p->SetFont('helvetica', 'B', 8.5);
        $p->MultiCell($nameW, $h, $nombre, 0, 'C', false, 0, $x, $y, true, 0, false, true, $h, 'M');
        if ($firma && is_file($firma)) {
            $p->Image($firma, $x + $nameW + 4, $y + 2, $sigW - 8, $h - 4, '', '', '', true, 300, '', false, false, 0, 'CM', false, false);
        }
        return $y + $h;
    }

    private function entrega(array $d, float $y): void
    {
        $p = $this->pdf;
        $x = 107.0;
        $w = 150.0;
        $p->SetDrawColor(0, 0, 0);
        $p->SetLineWidth(0.6);

        // Casilla superior (firma / sello)
        $p->Rect($x, $y, $w, 26);
        if (!empty($d['firma_entrega']) && is_file($d['firma_entrega'])) {
            $p->Image($d['firma_entrega'], $x + 4, $y + 2, $w - 8, 22, '', '', '', true, 300, '', false, false, 0, 'CM', false, false);
        }
        // Casilla inferior (cargo + nombre)
        $p->Rect($x, $y + 26, $w, 27);
        $p->SetTextColor(0, 0, 0);
        $p->SetFont('helvetica', 'B', 7.5);
        $p->SetXY($x, $y + 28);
        $p->Cell($w, 11, 'RESPONSABLE DE ENTREGA', 0, 0, 'C');
        $p->SetXY($x, $y + 39);
        $p->Cell($w, 11, (string)($d['responsable_entrega'] ?? ''), 0, 0, 'C');
    }

    private function e($s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

/* Ejemplo de datos:
$data = [
    'gerente'     => 'CLAUDIA SUSETTY DELGADO DIAZ',
    'responsable' => 'RODOLFO ALEJANDRO VALLEJO MENDOZA',
    'cargo'       => 'AUDITORIA OPERATIVA',
    'area'        => 'AUDITORIA',
    'fecha'       => '18 DE JUNIO DEL 2026',
    'equipos'     => [
        ['tipo' => 'LAPTOP',   'serie' => 'PF2QN1KA', 'codigo' => 'SNGC052',
         'descripcion' => 'Laptop Lenovo E14 Gen 2 - 11th Gen Intel(R) Core(TM) i7-1165G7 @ 2.80GHz - DDR4 3200 SODIMM 16 GB - KINGSTON SNV2S1000G NVME 1 TB',
         'activo' => '10930'],
        ['tipo' => 'CARGADOR', 'serie' => '8SSA10R16922C2TJ', 'descripcion' => 'Cargador Lenovo tipo – C Original', 'activo' => 'N/A'],
    ],
    'responsable_entrega' => 'ALFONSO PESANTES P.',
    'firma_responsable'   => null,   // rutas opcionales a imágenes de firma
    'firma_gerente'       => null,
    'firma_entrega'       => null,
    'logo'                => __DIR__ . '/logo_songa.jpeg',
];
*/
