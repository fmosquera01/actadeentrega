<?php
declare(strict_types=1);

final class PluginActadeentregaSigningService
{
    public static function acta(int $id): array
    {
        global $DB;
        $row = $DB->request([
            'SELECT' => '*', 'FROM' => 'glpi_plugin_actadeentrega_actas',
            'WHERE' => ['id' => $id], 'LIMIT' => 1,
        ])->current();
        if (!$row) throw new RuntimeException('No se encontró el acta solicitada.');
        return $row;
    }

    public static function participants(int $actaId): array
    {
        global $DB;
        $rows = [];
        foreach ($DB->request([
            'SELECT' => '*', 'FROM' => 'glpi_plugin_actadeentrega_acta_users',
            'WHERE' => ['plugin_actadeentrega_actas_id' => $actaId],
            'ORDER' => ['sort_order ASC', 'id ASC'],
        ]) as $row) $rows[] = $row;
        return $rows;
    }

    public static function allParticipantsSigned(int $actaId): bool
    {
        $participants = self::participants($actaId);
        if (!$participants) return false;
        foreach ($participants as $participant) {
            if (trim((string)($participant['signature_path'] ?? '')) === ''
                || empty($participant['signed_at'])) return false;
        }
        return true;
    }

    public static function storeDrawing(string $data, string $number, string $role): string
    {
        if (!preg_match('#^data:image/png;base64,#', $data)) {
            throw new RuntimeException('La firma dibujada no tiene un formato válido.');
        }
        $binary = base64_decode(substr($data, strpos($data, ',') + 1), true);
        if ($binary === false || strlen($binary) < 100 || strlen($binary) > 2_000_000
            || substr($binary, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            throw new RuntimeException('La imagen de firma está vacía o no es un PNG válido.');
        }
        $base = rtrim(GLPI_VAR_DIR, DIRECTORY_SEPARATOR) . '/_plugins/actadeentrega/signatures';
        if (!is_dir($base) && !mkdir($base, 0750, true) && !is_dir($base)) {
            throw new RuntimeException('No se pudo crear el almacenamiento privado de firmas.');
        }
        $name = 'sig_' . preg_replace('/[^A-Z0-9_-]/i', '', $number) . '_' . preg_replace('/[^a-z0-9_-]/i', '', $role) . '_' . bin2hex(random_bytes(8)) . '.png';
        $path = $base . '/' . $name;
        if (file_put_contents($path, $binary, LOCK_EX) === false) throw new RuntimeException('No se pudo guardar la firma.');
        chmod($path, 0640);
        return $path;
    }

    public static function audit(int $actaId, int $userId, string $role, string $method, string $detail = ''): void
    {
        global $DB;
        $DB->insert('glpi_plugin_actadeentrega_signatures', [
            'plugin_actadeentrega_actas_id' => $actaId,
            'users_id' => $userId, 'role' => $role, 'method' => $method,
            'detail' => $detail, 'signed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function finalize(array $acta): void
    {
        global $DB;
        $snapshot = json_decode((string)$acta['snapshot'], true);
        if (!is_array($snapshot)) throw new RuntimeException('La información original del acta no es válida.');
        if (!self::allParticipantsSigned((int)$acta['id'])) {
            throw new RuntimeException('Todavía faltan firmas de responsables o corresponsables.');
        }

        $issuer = (string)$acta['issuer_signature_path'];
        $manager = (string)$acta['manager_signature_path'];
        if ($issuer === '' || !is_file($issuer) || $manager === '' || !is_file($manager)) {
            throw new RuntimeException('Falta la firma del técnico o del gerente para finalizar el acta.');
        }

        $signatures = [];
        foreach (self::participants((int)$acta['id']) as $participant) {
            $path = (string)$participant['signature_path'];
            if ($path === '' || !is_file($path)) {
                throw new RuntimeException('No se encontró el archivo de firma de ' . getUserName((int)$participant['users_id']) . '.');
            }
            $hash = hash_file('sha256', $path);
            if (!$hash) throw new RuntimeException('No se pudo verificar la integridad de una firma.');
            if (!empty($participant['signature_sha256'])
                && !hash_equals((string)$participant['signature_sha256'], $hash)) {
                throw new RuntimeException('Una firma de responsable no superó la verificación de integridad.');
            }
            $signatures[] = [
                'users_id' => (int)$participant['users_id'],
                'name' => getUserName((int)$participant['users_id']),
                'path' => $path, 'sha256' => $hash,
                'method' => (string)$participant['signature_method'],
                'signed_at' => (string)$participant['signed_at'],
            ];
        }

        $managerSignedAt = trim((string)($acta['manager_signed_at'] ?? ''));
        if ($managerSignedAt === '') $managerSignedAt = gmdate('Y-m-d H:i:s');

        require_once __DIR__ . '/ActaPdf.php';
        $base = rtrim(GLPI_VAR_DIR, DIRECTORY_SEPARATOR) . '/_plugins/actadeentrega/acts';
        $file = 'acta_final_' . (int)$acta['id'] . '_' . bin2hex(random_bytes(5)) . '.pdf';
        $path = $base . '/' . $file;
        PluginActadeentregaActaPdf::generate($snapshot, $issuer, $path, $signatures, $manager, $managerSignedAt);
        $hash = hash_file('sha256', $path);
        if (!$hash) { @unlink($path); throw new RuntimeException('No se pudo calcular la integridad del PDF final.'); }

        $holderSignature = '';
        foreach ($signatures as $signature) {
            foreach ($snapshot['participants'] ?? [] as $p) {
                if ((int)($p['users_id'] ?? 0) === $signature['users_id'] && (int)($p['is_holder'] ?? 0) === 1) {
                    $holderSignature = $signature['path'];
                    break 2;
                }
            }
        }
        $DB->update('glpi_plugin_actadeentrega_actas', [
            'recipient_signature_path' => $holderSignature,
            'pdf_path' => '_plugins/actadeentrega/acts/' . $file,
            'pdf_sha256' => $hash, 'status' => 'Firmada completamente',
            'manager_signed_at' => date('Y-m-d H:i:s'),
        ], ['id' => (int)$acta['id']]);
    }
}
