<?php
declare(strict_types=1);

final class PluginActadeentregaSigningService
{
    public static function acta(int $id): array
    {
        global $DB;
        $row = $DB->request([
            'SELECT' => '*',
            'FROM' => 'glpi_plugin_actadeentrega_actas',
            'WHERE' => ['id' => $id],
            'LIMIT' => 1,
        ])->current();
        if (!$row) {
            throw new RuntimeException('No se encontró el acta solicitada.');
        }
        return $row;
    }

    public static function storeDrawing(string $data, string $number, string $role): string
    {
        if (!preg_match('#^data:image/png;base64,#', $data)) {
            throw new RuntimeException('La firma dibujada no tiene un formato válido.');
        }
        $binary = base64_decode(substr($data, strpos($data, ',') + 1), true);
        if ($binary === false || strlen($binary) < 100 || strlen($binary) > 2_000_000) {
            throw new RuntimeException('La imagen de firma está vacía o supera el tamaño permitido.');
        }
        if (substr($binary, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            throw new RuntimeException('El archivo recibido no es un PNG válido.');
        }
        $base = rtrim(GLPI_VAR_DIR, DIRECTORY_SEPARATOR) . '/_plugins/actadeentrega/signatures';
        if (!is_dir($base) && !mkdir($base, 0750, true) && !is_dir($base)) {
            throw new RuntimeException('No se pudo crear el almacenamiento privado de firmas.');
        }
        $name = 'sig_' . preg_replace('/[^A-Z0-9_-]/i', '', $number) . '_' . $role . '_' . bin2hex(random_bytes(8)) . '.png';
        $path = $base . '/' . $name;
        if (file_put_contents($path, $binary, LOCK_EX) === false) {
            throw new RuntimeException('No se pudo guardar la firma.');
        }
        chmod($path, 0640);
        return $path;
    }

    public static function audit(int $actaId, int $userId, string $role, string $method, string $detail = ''): void
    {
        global $DB;
        $DB->insert('glpi_plugin_actadeentrega_signatures', [
            'plugin_actadeentrega_actas_id' => $actaId,
            'users_id' => $userId,
            'role' => $role,
            'method' => $method,
            'detail' => $detail,
            'signed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function finalize(array $acta): void
    {
        global $DB;
        $snapshot = json_decode((string)$acta['snapshot'], true);
        if (!is_array($snapshot)) {
            throw new RuntimeException('La información original del acta no es válida.');
        }
        $issuerSignature = (string)$acta['issuer_signature_path'];
        $recipientSignature = (string)$acta['recipient_signature_path'];
        $managerSignature = (string)$acta['manager_signature_path'];
        foreach ([$issuerSignature, $recipientSignature, $managerSignature] as $path) {
            if ($path === '' || !is_file($path)) {
                throw new RuntimeException('Falta una de las firmas obligatorias para finalizar el acta.');
            }
        }
        require_once __DIR__ . '/ActaPdf.php';
        $base = rtrim(GLPI_VAR_DIR, DIRECTORY_SEPARATOR) . '/_plugins/actadeentrega/acts';
        $file = 'acta_final_' . (int)$acta['id'] . '_' . bin2hex(random_bytes(5)) . '.pdf';
        $path = $base . '/' . $file;
        PluginActadeentregaActaPdf::generate($snapshot, $issuerSignature, $path, $recipientSignature, $managerSignature);
        $hash = hash_file('sha256', $path);
        if (!$hash) {
            @unlink($path);
            throw new RuntimeException('No se pudo calcular la integridad del PDF final.');
        }
        $DB->update('glpi_plugin_actadeentrega_actas', [
            'pdf_path' => '_plugins/actadeentrega/acts/' . $file,
            'pdf_sha256' => $hash,
            'status' => 'Firmada completamente',
            'manager_signed_at' => date('Y-m-d H:i:s'),
        ], ['id' => (int)$acta['id']]);
    }
}
