<?php
declare(strict_types=1);

/**
 * Presentación común de los datos del acta antes de solicitar una firma.
 * No incluye rutas, hashes, PIN ni tokens.
 */
final class PluginActadeentregaActaPresentation
{
    private static function e($value): string
    {
        return htmlspecialchars(trim((string)$value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function date(string $value): string
    {
        if ($value === '') return '—';
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('America/Guayaquil'))
                ->format('d/m/Y H:i');
        } catch (Throwable $e) {
            return self::e($value);
        }
    }

    public static function renderDetails(array $acta, array $snapshot, array $participants): string
    {
        $user = $snapshot['user'] ?? [];
        $issuer = $snapshot['issuer'] ?? [];
        $assets = $snapshot['assets'] ?? [];
        $date = self::date((string)($acta['act_date'] ?? $snapshot['date'] ?? ''));
        $html = '<section class="ae-details"><h2>Detalle del acta y de la entrega</h2>';
        $html .= '<div class="ae-summary">';
        $rows = [
            ['Número de acta', $acta['act_number'] ?? ''],
            ['Fecha de entrega', $date],
            ['Gerente / jefe de área', $user['manager'] ?? ''],
            ['Responsable del equipo', $user['recipient'] ?? ''],
            ['Departamento', $user['department'] ?? ''],
            ['Cargo / función', $user['jobtitle'] ?? ''],
            ['Área', $user['area'] ?? ''],
            ['Técnico que realiza la entrega', $issuer['name'] ?? ''],
        ];
        foreach ($rows as [$label, $value]) {
            $html .= '<div class="ae-field"><span>' . self::e($label) . '</span><strong>' . (trim((string)$value) !== '' ? self::e($value) : '—') . '</strong></div>';
        }
        $html .= '</div><h3>Activos que se entregan</h3>';
        if (!$assets) {
            $html .= '<p class="ae-empty">El acta no contiene activos registrados. No firme y contacte a Sistemas.</p>';
        } else {
            $html .= '<div class="ae-table-wrap"><table class="ae-assets"><thead><tr><th>#</th><th>Tipo</th><th>Serie</th><th>Descripción</th><th>Activo fijo</th></tr></thead><tbody>';
            foreach ($assets as $index => $asset) {
                $html .= '<tr><td>' . ($index + 1) . '</td><td>' . self::e($asset['type'] ?? '') . '</td><td>' . self::e($asset['serial'] ?? '') . '</td><td>' . self::e($asset['description'] ?? '') . '</td><td>' . self::e(($asset['asset_number'] ?? '') !== '' ? $asset['asset_number'] : 'N/A') . '</td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        $html .= '<h3>Responsables y estado de sus firmas</h3>';
        if (!$participants) {
            $html .= '<p class="ae-empty">No se encontraron responsables asociados al acta.</p>';
        } else {
            $html .= '<div class="ae-table-wrap"><table class="ae-signers"><thead><tr><th>Responsable</th><th>Estado</th><th>Fecha de firma</th></tr></thead><tbody>';
            foreach ($participants as $participant) {
                $signed = trim((string)($participant['signature_path'] ?? '')) !== '' && !empty($participant['signed_at']);
                $html .= '<tr><td>' . self::e($participant['name'] ?? '') . '</td><td>' . ($signed ? '<span class="ae-signed">Firmado</span>' : '<span class="ae-pending">Pendiente</span>') . '</td><td>' . ($signed ? self::date((string)$participant['signed_at']) : '—') . '</td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        $html .= '<p class="ae-note">Revise los activos, sus números de serie, el responsable que recibe y las firmas registradas antes de autorizar el acta.</p></section>';
        return $html;
    }

    public static function emailDetails(array $acta, array $snapshot, array $participants): string
    {
        $user = $snapshot['user'] ?? [];
        $issuer = $snapshot['issuer'] ?? [];
        $assets = $snapshot['assets'] ?? [];
        $html = '<h3 style="color:#174f75;margin:22px 0 8px">Detalle de la entrega</h3><table cellpadding="6" cellspacing="0" width="100%" style="border-collapse:collapse;border:1px solid #dce2e8">';
        $rows = [
            ['Acta', $acta['act_number'] ?? ''],
            ['Fecha de entrega', self::date((string)($acta['act_date'] ?? $snapshot['date'] ?? ''))],
            ['Gerente / jefe de área', $user['manager'] ?? ''],
            ['Responsable del equipo', $user['recipient'] ?? ''],
            ['Departamento', $user['department'] ?? ''],
            ['Cargo / función', $user['jobtitle'] ?? ''],
            ['Área', $user['area'] ?? ''],
            ['Técnico que entrega', $issuer['name'] ?? ''],
        ];
        foreach ($rows as [$label, $value]) {
            $html .= '<tr><th align="left" style="background:#f3f6f8;border:1px solid #dce2e8">' . self::e($label) . '</th><td style="border:1px solid #dce2e8">' . (trim((string)$value) !== '' ? self::e($value) : '—') . '</td></tr>';
        }
        $html .= '</table><h3 style="color:#174f75;margin:22px 0 8px">Activos que se entregan</h3><table cellpadding="6" cellspacing="0" width="100%" style="border-collapse:collapse;border:1px solid #dce2e8"><tr style="background:#f3f6f8"><th>Tipo</th><th>Serie</th><th>Descripción</th><th>Activo fijo</th></tr>';
        foreach ($assets as $asset) {
            $html .= '<tr><td style="border:1px solid #dce2e8">' . self::e($asset['type'] ?? '') . '</td><td style="border:1px solid #dce2e8">' . self::e($asset['serial'] ?? '') . '</td><td style="border:1px solid #dce2e8">' . self::e($asset['description'] ?? '') . '</td><td style="border:1px solid #dce2e8">' . self::e(($asset['asset_number'] ?? '') !== '' ? $asset['asset_number'] : 'N/A') . '</td></tr>';
        }
        if (!$assets) $html .= '<tr><td colspan="4" style="border:1px solid #dce2e8">No hay activos registrados en el snapshot.</td></tr>';
        $html .= '</table><h3 style="color:#174f75;margin:22px 0 8px">Responsables y firmas registradas</h3><table cellpadding="6" cellspacing="0" width="100%" style="border-collapse:collapse;border:1px solid #dce2e8"><tr style="background:#f3f6f8"><th align="left">Responsable</th><th>Estado</th><th>Fecha de firma</th></tr>';
        foreach ($participants as $participant) {
            $signed = trim((string)($participant['signature_path'] ?? '')) !== '' && !empty($participant['signed_at']);
            $html .= '<tr><td style="border:1px solid #dce2e8">' . self::e($participant['name'] ?? '') . '</td><td style="border:1px solid #dce2e8">' . ($signed ? 'Firmado' : 'Pendiente') . '</td><td style="border:1px solid #dce2e8">' . ($signed ? self::date((string)$participant['signed_at']) : '—') . '</td></tr>';
        }
        $html .= '</table><p style="margin-top:16px;color:#52616d">Revise el detalle completo del acta en el enlace seguro antes de autorizar la firma.</p>';
        return $html;
    }
}
