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

    /** @var string[] Archivos PNG temporales recortados para el PDF. */
    private array $temporaryImages = [];

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
        $y = $this->firmaResponsables((array)($d['firmas_responsables'] ?? []), $y + 10);
        $y = $this->firmaBloque('FIRMA DEL GERENTE O JEFE DE AREA', $d['gerente'] ?? '', $d['firma_gerente'] ?? null, $y + 12, (string)($d['firma_gerente_signed_at'] ?? ''));
        $this->entrega($d, $y + 12);

        $binary = $p->Output('', 'S');
        foreach ($this->temporaryImages as $temporaryImage) {
            if (is_file($temporaryImage)) {
                @unlink($temporaryImage);
            }
        }
        return $binary;
    }

    /* ------------------------------------------------------- logo + título */
    private function header(array $d): float
    {
        $p = $this->pdf;
        $logo = $d['logo'] ?? (__DIR__ . '/logo_songa.jpeg');
        if (is_file($logo)) {
            $p->Image($logo, 22, 13, 143, 0, '', '', '', true, 300);
        }
        $p->SetTextColor(0, 0, 0);
        $p->SetFont('helvetica', 'B', 16);
        $p->SetXY(0, 111);
        $p->Cell(595, 22, 'ACTA DE ENTREGA DE EQUIPOS', 0, 0, 'C');
        return 133;
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
            $codigo = $this->clean($e['codigo'] ?? '');
            $texto  = $this->clean($e['descripcion'] ?? '');
            $tipo   = $this->clean($e['tipo'] ?? '');
            $serie  = $this->clean($e['serie'] ?? '');
            $activo = $this->clean($e['activo'] ?? '') ?: 'N/A';

            $plain = ($codigo !== '' ? $codigo . ': ' : '') . $texto;
            $html  = ($codigo !== '' ? '<b>' . $this->e($codigo) . ':</b> ' : '') . $this->e($texto);

            // Altura = líneas realmente necesarias (de la celda más larga) + margen mínimo
            $p->SetFont('helvetica', '', 8);
            $lh = $p->getFontSize() * $p->getCellHeightRatio();
            $lines = max(
                1,
                $p->getNumLines($plain, $w[3] - 8),
                $p->getNumLines($tipo,  $w[1] - 8),
                $p->getNumLines($serie, $w[2] - 8)
            );
            $h = max(14.0, $lines * $lh + 4);

            $p->SetTextColor(64, 64, 64);
            $cells = [
                [$w[0], (string)$n, 'C', false],
                [$w[1], $tipo, 'L', false],
                [$w[2], $serie, 'L', false],
                [$w[3], $html, 'L', true],
                [$w[4], $activo, 'C', false],
            ];
            $x = $x0;
            foreach ($cells as $c) {
                $p->Rect($x, $y, $c[0], $h);
                $p->MultiCell($c[0], $h - 3, $c[1], 0, $c[2], false, 0, $x, $y + 2, true, 0, $c[3], true, $h - 3, 'T');
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
        $p->SetTextColor(0, 0, 0);
        $p->SetFont('helvetica', 'B', 9.5);
        $p->SetXY(85, $y);
        $p->MultiCell(440, 12, 'Me comprometo con la compañía hacerme responsable del cuidado de este equipo, con las siguientes cláusulas:', 0, 'L', false, 1, 85, $y, true);
        $y = $p->GetY() + 3;

        $p->SetTextColor(0, 0, 0);
        $p->SetFont('helvetica', '', 9.5);
        $textX = 124.0;
        $textW = 385.0;
        foreach (($d['clausulas'] ?? self::CLAUSULAS) as $c) {
            $h = $p->getStringHeight($textW, $c);
            // viñeta cuadrada
            $p->SetFillColor(0, 0, 0);
            $p->Rect(107, $y + 3.6, 3.4, 3.4, 'F');
            $p->MultiCell($textW, $h, $c, 0, 'L', false, 1, $textX, $y, true, 0, false, true, $h, 'T');
            $y += $h + 1.5;
        }
        return $y;
    }

    /* Una sola celda para todos los responsables: nombres a la izquierda y firmas a la derecha. */
    private function firmaResponsables(array $participants, float $y): float
    {
        $p = $this->pdf;
        if (!$participants) {
            $participants = [['name' => 'Responsable', 'path' => null, 'signed_at' => '']];
        }

        $x = 81.0;
        $w = 433.0;
        $nameW = 245.0;
        $signatureW = $w - $nameW;
        $headingH = 17.0;
        $topGap = 3.0;
        $nameLineH = 18.0;
        $signatureRowH = 38.0;
        $signatureRows = (int)ceil(count($participants) / 3);
        $contentH = max(count($participants) * $nameLineH, $signatureRows * $signatureRowH);
        $cellH = max(38.0, $contentH + 10.0);
        $bottom = 790.0;

        $drawHeading = static function (float $top) use ($p, $x, $w): float {
            $p->SetFont('helvetica', 'B', 10.5);
            $p->SetTextColor(0, 0, 0);
            $p->SetDrawColor(0, 0, 0);
            $p->SetLineWidth(0.6);
            $p->SetXY($x, $top);
            $p->Cell($w, 17, 'FIRMA RESPONSABLE', 1, 0, 'L');
            return $top + 20.0;
        };

        $y = $drawHeading($y);
        if ($y + $cellH > $bottom) {
            $p->AddPage();
            $y = $drawHeading(42.0);
        }

        // Un único contorno exterior; solo se separan las zonas de nombres y firmas.
        $p->SetDrawColor(0, 0, 0);
        $p->SetLineWidth(0.6);
        $p->Rect($x, $y, $w, $cellH);
        $p->Line($x + $nameW, $y, $x + $nameW, $y + $cellH);

        $signatureSlotW = $signatureW / 3.0;
        foreach ($participants as $index => $participant) {
            $nameY = $y + 5.0 + ($index * $nameLineH);
            $name = trim((string)($participant['name'] ?? ''));
            $p->SetFont('helvetica', 'B', 8.5);
            $p->SetTextColor(0, 0, 0);
            $p->SetXY($x + 6.0, $nameY);
            $p->Cell($nameW - 12.0, 11.0, $name, 0, 0, 'L');

            $signedAt = trim((string)($participant['signed_at'] ?? ''));
            if ($signedAt !== '') {
                $timestamp = self::formatEcuadorDateTime($signedAt);
                if ($timestamp !== '') {
                    $p->SetFont('helvetica', '', 6.5);
                    $p->SetTextColor(75, 75, 75);
                    $p->SetXY($x + 6.0, $nameY + 11.0);
                    $p->Cell($nameW - 12.0, 7.0, $timestamp, 0, 0, 'L');
                }
            }

            $path = trim((string)($participant['path'] ?? ''));
            if ($path === '' || !is_file($path)) {
                continue;
            }

            $slotColumn = $index % 3;
            $slotRow = intdiv($index, 3);
            $slotX = $x + $nameW + ($slotColumn * $signatureSlotW);
            $slotY = $y + 5.0 + ($slotRow * $signatureRowH);
            $maxW = $signatureSlotW - 8.0;
            $maxH = $signatureRowH - 10.0;

            $this->drawTrimmedSignature($path, $slotX + 4.0, $slotY + 5.0, $maxW, $maxH);
        }

        return $y + $cellH;
    }

    /** Dibuja solo el trazo recortado, manteniendo proporciones y sin ampliar el lienzo vacío. */
    private function drawTrimmedSignature(string $path, float $x, float $y, float $maxW, float $maxH): void
    {
        if ($path === '' || !is_file($path)) {
            return;
        }
        [$imagePath, $sourceW, $sourceH] = $this->trimSignatureImage($path);
        if ($sourceW < 1 || $sourceH < 1) {
            return;
        }
        $scale = min($maxW / $sourceW, $maxH / $sourceH);
        $imageW = $sourceW * $scale;
        $imageH = $sourceH * $scale;
        $this->pdf->Image(
            $imagePath,
            $x + (($maxW - $imageW) / 2.0),
            $y + (($maxH - $imageH) / 2.0),
            $imageW,
            $imageH,
            '',
            '',
            '',
            false,
            300
        );
    }

    /**
     * Recorta el lienzo transparente/blanco alrededor de la tinta de una firma.
     * Si GD no está disponible o el PNG no puede procesarse, usa la imagen original.
     *
     * @return array{0:string,1:int,2:int}
     */
    private function trimSignatureImage(string $path): array
    {
        $size = @getimagesize($path);
        $width = (int)($size[0] ?? 0);
        $height = (int)($size[1] ?? 0);
        if ($width < 1 || $height < 1) {
            return [$path, 1, 1];
        }

        if (!function_exists('imagecreatefrompng') || !function_exists('imagecrop')) {
            return [$path, $width, $height];
        }

        $source = @imagecreatefrompng($path);
        if (!$source) {
            return [$path, $width, $height];
        }

        $minX = $width;
        $minY = $height;
        $maxX = -1;
        $maxY = -1;
        for ($py = 0; $py < $height; $py++) {
            for ($px = 0; $px < $width; $px++) {
                $rgba = imagecolorat($source, $px, $py);
                $alpha = ($rgba & 0x7F000000) >> 24;
                $red = ($rgba >> 16) & 0xFF;
                $green = ($rgba >> 8) & 0xFF;
                $blue = $rgba & 0xFF;
                $hasInk = $alpha < 120 && ($red < 250 || $green < 250 || $blue < 250);
                if ($hasInk) {
                    $minX = min($minX, $px);
                    $minY = min($minY, $py);
                    $maxX = max($maxX, $px);
                    $maxY = max($maxY, $py);
                }
            }
        }

        if ($maxX < $minX || $maxY < $minY) {
            imagedestroy($source);
            return [$path, $width, $height];
        }

        // Un margen mínimo evita cortar antialiasing, pero elimina el lienzo sobrante.
        $pad = 2;
        $minX = max(0, $minX - $pad);
        $minY = max(0, $minY - $pad);
        $maxX = min($width - 1, $maxX + $pad);
        $maxY = min($height - 1, $maxY + $pad);
        $cropW = $maxX - $minX + 1;
        $cropH = $maxY - $minY + 1;
        $cropped = @imagecrop($source, ['x' => $minX, 'y' => $minY, 'width' => $cropW, 'height' => $cropH]);
        imagedestroy($source);
        if (!$cropped) {
            return [$path, $width, $height];
        }

        imagesavealpha($cropped, true);
        $temporary = tempnam(sys_get_temp_dir(), 'ae-sign-');
        if ($temporary === false) {
            imagedestroy($cropped);
            return [$path, $width, $height];
        }
        $pngPath = $temporary . '.png';
        @unlink($temporary);
        $saved = @imagepng($cropped, $pngPath);
        imagedestroy($cropped);
        if (!$saved) {
            return [$path, $width, $height];
        }

        $this->temporaryImages[] = $pngPath;
        return [$pngPath, $cropW, $cropH];
    }

    /* -------------------------------------------------- bloques de firma */
    private function firmaBloque(string $titulo, string $nombre, ?string $firma, float $y, string $signedAt = ''): float
    {
        $p = $this->pdf;
        if ($y + 70.0 > 790.0) {
            $p->AddPage();
            $y = 42.0;
        }
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
        $p->SetTextColor(0, 0, 0);
        $p->SetXY($x + 3, $y + 7);
        $p->Cell($nameW - 6, 12, $nombre, 0, 0, 'C');
        $localSignedAt = self::formatEcuadorDateTime($signedAt);
        if ($localSignedAt !== '') {
            $p->SetFont('helvetica', '', 6.5);
            $p->SetTextColor(75, 75, 75);
            $p->SetXY($x + 3, $y + 21);
            $p->Cell($nameW - 6, 9, $localSignedAt, 0, 0, 'C');
        }
        if ($firma && is_file($firma)) {
            $this->drawTrimmedSignature($firma, $x + $nameW + 4, $y + 2, $sigW - 8, $h - 4);
        }
        return $y + $h;
    }

    private function entrega(array $d, float $y): void
    {
        $p = $this->pdf;
        if ($y + 60.0 > 790.0) {
            $p->AddPage();
            $y = 42.0;
        }
        $x = 107.0;
        $w = 150.0;
        $p->SetDrawColor(0, 0, 0);
        $p->SetLineWidth(0.6);

        // Casilla superior (firma / sello)
        $p->Rect($x, $y, $w, 26);
        if (!empty($d['firma_entrega']) && is_file($d['firma_entrega'])) {
            $this->drawTrimmedSignature((string)$d['firma_entrega'], $x + 4, $y + 2, $w - 8, 22);
        }
        // Casilla inferior (cargo + nombre)
        $p->Rect($x, $y + 26, $w, 34);
        $p->SetTextColor(0, 0, 0);
        $p->SetFont('helvetica', 'B', 7.5);
        $p->SetXY($x, $y + 28);
        $p->Cell($w, 11, 'RESPONSABLE DE ENTREGA', 0, 0, 'C');
        $p->SetFont('helvetica', 'B', 8.5);
        $p->SetTextColor(0, 0, 0);
        $p->SetXY($x, $y + 39);
        $p->Cell($w, 11, (string)($d['responsable_entrega'] ?? ''), 0, 0, 'C');
        $issuerSignedAt = self::formatEcuadorDateTime((string)($d['firma_entrega_signed_at'] ?? ''));
        if ($issuerSignedAt !== '') {
            $p->SetFont('helvetica', '', 6.2);
            $p->SetXY($x, $y + 49);
            $p->Cell($w, 8, $issuerSignedAt, 0, 0, 'C');
        }
    }

    /** Los timestamps se almacenan en UTC y se presentan en la zona horaria oficial de Ecuador continental. */
    private static function formatEcuadorDateTime(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('America/Guayaquil'))
                ->format('d/m/Y H:i');
        } catch (Throwable $e) {
            return '';
        }
    }

    /** Quita etiquetas, convierte saltos/espacios raros en un solo espacio y recorta. */
    private function clean($s): string
    {
        $s = html_entity_decode(strip_tags((string)$s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('/[\s\x{00A0}]+/u', ' ', $s);
        return trim((string)$s);
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
