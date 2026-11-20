<?php
declare(strict_types=1);

/**
 * Seguridad del enlace de firma remota del gerente.
 *
 * El token es una credencial temporal de acceso al acta, no una firma ni
 * una prueba de identidad. Para firmar remotamente se exige el PIN personal
 * de Informe Técnico del gerente asignado al acta.
 */
final class PluginActadeentregaPublicSigningService
{
    public const TOKEN_TTL_HOURS = 48;

    public static function createToken(array $acta): string
    {
        global $DB;

        if ((string)($acta['status'] ?? '') !== 'Pendiente de firma del gerente'
            || empty($acta['recipient_signature_path'])) {
            throw new RuntimeException('El colaborador debe firmar antes de solicitar la firma remota del gerente.');
        }

        $snapshot = json_decode((string)($acta['snapshot'] ?? ''), true);
        if (!is_array($snapshot)) {
            throw new RuntimeException('El contenido del acta no es válido.');
        }

        $managerId = (int)($snapshot['user']['manager_users_id'] ?? 0);
        if ($managerId < 1) {
            throw new RuntimeException('El acta no tiene un gerente válido asignado.');
        }

        // La firma remota requiere una identidad personal ya enrolada con PIN.
        $signatureTable = 'glpi_plugin_informeglopi_user_signatures';
        if (!$DB->tableExists($signatureTable)) {
            throw new RuntimeException('No está disponible el registro de firmas personales de Informe Técnico.');
        }
        $signature = $DB->request([
            'SELECT' => ['signature_path', 'signature_sha256', 'pin_hash'],
            'FROM' => $signatureTable,
            'WHERE' => ['users_id' => $managerId],
            'LIMIT' => 1,
        ])->current();

        if (!$signature
            || trim((string)($signature['pin_hash'] ?? '')) === ''
            || trim((string)($signature['signature_path'] ?? '')) === ''
            || trim((string)($signature['signature_sha256'] ?? '')) === '') {
            throw new RuntimeException('El gerente no tiene firma personal y PIN configurados. La firma deberá recogerse presencialmente.');
        }

        $signatureBase = realpath(rtrim(GLPI_VAR_DIR, DIRECTORY_SEPARATOR) . '/_plugins/informeglpi/signatures/users');
        $signaturePath = realpath(rtrim(GLPI_VAR_DIR, DIRECTORY_SEPARATOR) . '/' . ltrim((string)$signature['signature_path'], '/'));
        if (!$signatureBase || !$signaturePath || !str_starts_with($signaturePath, $signatureBase . DIRECTORY_SEPARATOR) || !is_file($signaturePath)) {
            throw new RuntimeException('La firma personal del gerente no está disponible. Debe actualizarla antes de solicitar la firma remota.');
        }
        $signatureHash = hash_file('sha256', $signaturePath);
        if (!$signatureHash || !hash_equals((string)$signature['signature_sha256'], $signatureHash)) {
            throw new RuntimeException('La firma personal del gerente no superó la verificación de integridad.');
        }

        $email = self::managerEmail($managerId);
        if ($email === '') {
            throw new RuntimeException('El gerente no tiene un correo electrónico válido registrado. La firma deberá recogerse presencialmente.');
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hash = hash('sha256', $token);
        $now = date('Y-m-d H:i:s');
        $expires = date('Y-m-d H:i:s', time() + self::TOKEN_TTL_HOURS * 3600);

        $DB->update('glpi_plugin_actadeentrega_actas', [
            'manager_token_hash' => $hash,
            'manager_token_created_at' => $now,
            'manager_token_expires_at' => $expires,
            'manager_token_used_at' => null,
            'manager_token_revoked_at' => null,
            'manager_email_sent_at' => null,
            'manager_email' => $email,
        ], ['id' => (int)$acta['id']]);

        self::audit((int)$acta['id'], $managerId, 'gerente', 'enlace_generado', 'Solicitud de firma remota generada; vigencia de 48 horas.');
        return $token;
    }

    public static function validateToken(string $token): array
    {
        global $DB;

        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            throw new RuntimeException('El enlace de firma no es válido o ha vencido.');
        }

        $hash = hash('sha256', $token);
        $acta = $DB->request([
            'SELECT' => '*',
            'FROM' => 'glpi_plugin_actadeentrega_actas',
            'WHERE' => ['manager_token_hash' => $hash],
            'LIMIT' => 1,
        ])->current();

        if (!$acta
            || !empty($acta['manager_token_used_at'])
            || !empty($acta['manager_token_revoked_at'])
            || empty($acta['manager_token_expires_at'])
            || strtotime((string)$acta['manager_token_expires_at']) < time()
            || (string)$acta['status'] !== 'Pendiente de firma del gerente'
            || empty($acta['recipient_signature_path'])) {
            throw new RuntimeException('El enlace de firma no es válido, ya fue utilizado o ha vencido.');
        }

        $snapshot = json_decode((string)$acta['snapshot'], true);
        if (!is_array($snapshot) || (int)($snapshot['user']['manager_users_id'] ?? 0) < 1) {
            throw new RuntimeException('No se pudo validar el gerente asignado al acta.');
        }

        return $acta;
    }

    public static function markEmailSent(int $actaId): void
    {
        global $DB;
        $DB->update('glpi_plugin_actadeentrega_actas', [
            'manager_email_sent_at' => date('Y-m-d H:i:s'),
        ], ['id' => $actaId]);
    }

    public static function revoke(int $actaId): void
    {
        global $DB;
        $DB->update('glpi_plugin_actadeentrega_actas', [
            'manager_token_revoked_at' => date('Y-m-d H:i:s'),
            'manager_token_hash' => '',
        ], ['id' => $actaId]);
    }

    public static function consume(array $acta): void
    {
        global $DB;
        $id = (int)$acta['id'];
        $hash = (string)($acta['manager_token_hash'] ?? '');
        if ($hash === '') {
            throw new RuntimeException('El enlace de firma ya no está disponible.');
        }

        $DB->update('glpi_plugin_actadeentrega_actas', [
            'manager_token_used_at' => date('Y-m-d H:i:s'),
            'manager_token_hash' => '',
        ], [
            'id' => $id,
            'manager_token_hash' => $hash,
            'manager_token_used_at' => null,
            'manager_token_revoked_at' => null,
        ]);

        if (method_exists($DB, 'affectedRows') && $DB->affectedRows() !== 1) {
            throw new RuntimeException('El enlace ya fue utilizado o invalidado. Recargue el acta.');
        }
    }

    private static function managerEmail(int $userId): string
    {
        global $DB;
        $table = 'glpi_useremails';
        if (!$DB->tableExists($table) || !$DB->fieldExists($table, 'users_id') || !$DB->fieldExists($table, 'email')) {
            return '';
        }

        $where = ['users_id' => $userId];
        if ($DB->fieldExists($table, 'is_default')) {
            $defaultRow = $DB->request([
                'SELECT' => ['email'],
                'FROM' => $table,
                'WHERE' => ['users_id' => $userId, 'is_default' => 1],
                'ORDER' => ['id ASC'],
                'LIMIT' => 1,
            ])->current();
            $defaultEmail = trim((string)($defaultRow['email'] ?? ''));
            if (filter_var($defaultEmail, FILTER_VALIDATE_EMAIL)) {
                return $defaultEmail;
            }
        }

        // Si no existe correo predeterminado válido, utilizar el primer correo válido registrado.
        foreach ($DB->request([
            'SELECT' => ['email'],
            'FROM' => $table,
            'WHERE' => $where,
            'ORDER' => ['id ASC'],
        ]) as $row) {
            $email = trim((string)($row['email'] ?? ''));
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }
        return '';
    }

    private static function audit(int $actaId, int $userId, string $role, string $method, string $detail): void
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
}
