<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/ActaEntregaPdfRenderer.php';

final class PluginActadeentregaActaPdf
{
    public static function generate(array $snapshot, string $signaturePath, string $outputPath): void
    {
        if (!class_exists('TCPDF')) {
            throw new RuntimeException('TCPDF no está disponible en GLPI.');
        }
        if (!class_exists('ActaEntregaPdfRenderer')) {
            throw new RuntimeException('No se pudo cargar el renderizador institucional del acta.');
        }

        $user = $snapshot['user'] ?? [];
        $assets = $snapshot['assets'] ?? [];
        $date = new DateTimeImmutable((string)($snapshot['date'] ?? 'now'));
        $months = [
            '', 'ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO',
            'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE',
        ];
        $dateLabel = $date->format('d') . ' DE '
            . $months[(int)$date->format('n')] . ' DEL ' . $date->format('Y');

        $equipment = [];
        foreach ($assets as $asset) {
            $equipment[] = [
                'tipo' => (string)($asset['type'] ?? ''),
                'serie' => (string)($asset['serial'] ?? ''),
                'descripcion' => (string)($asset['description'] ?? ''),
                'activo' => trim((string)($asset['asset_number'] ?? '')) !== ''
                    ? (string)$asset['asset_number']
                    : 'N/A',
            ];
        }

        $logoPath = dirname(__DIR__) . '/logo_songa.jpeg';
        if (!is_file($logoPath)) {
            throw new RuntimeException('No se encontró logo_songa.jpeg en la raíz del plugin.');
        }

        $renderData = [
            'logo' => $logoPath,
            'gerente' => (string)($user['manager'] ?? ''),
            'responsable' => (string)($user['recipient'] ?? ''),
            'cargo' => (string)($user['jobtitle'] ?? ''),
            'area' => (string)($user['area'] ?? ''),
            'fecha' => $dateLabel,
            'equipos' => $equipment,
            'clausulas' => null,
            'firma_responsable' => null,
            'firma_gerente' => null,
            'firma_entrega' => $signaturePath,
            'responsable_entrega' => (string)($snapshot['issuer']['name'] ?? ''),
        ];

        $pdfBinary = ActaEntregaPdfRenderer::render($renderData);
        if (strlen($pdfBinary) < 800) {
            throw new RuntimeException('El renderizador no produjo un PDF válido.');
        }

        $directory = dirname($outputPath);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('No se pudo crear el directorio privado del acta.');
        }
        if (file_put_contents($outputPath, $pdfBinary, LOCK_EX) === false) {
            throw new RuntimeException('No se pudo guardar el PDF del acta.');
        }
        chmod($outputPath, 0640);
    }
}
