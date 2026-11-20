<?php
declare(strict_types=1);

final class PluginActadeentregaFinalMailService
{
    public const DEFAULT_SUBJECT = 'Acta de Entrega N.º {numero_acta} – {usuario}';
    public const DEFAULT_BODY = "Estimados,\n\nSe remite el Acta de Entrega N.º {numero_acta}, correspondiente a la entrega de activos realizada el {fecha_entrega}.\n\nINFORMACIÓN DE LA ENTREGA\nResponsable: {usuario}\nGerente: {gerente}\nTécnico de Sistemas: {tecnico}\nActivos entregados: {activos}\nFecha de finalización de firmas: {fecha_firma}\n\nEl documento adjunto contiene las firmas correspondientes. Se remite para su registro y control por parte del departamento de Activo Fijo.\n\nSaludos cordiales,\nSistemas – Songa\n\nAVISO AUTOMÁTICO: Este correo ha sido generado automáticamente por GLPI. Por favor, no responda directamente a este mensaje. Si necesita responder, elimine de los destinatarios la dirección automática de GLPI ({remitente}) y dirija su respuesta al contacto correspondiente.";

    public static function getSettings(): array
    {
        global $DB;
        $defaults = [
            'to_emails' => '',
            'cc_emails' => '',
            'subject_template' => self::DEFAULT_SUBJECT,
            'body_template' => self::DEFAULT_BODY,
            'is_enabled' => 0,
        ];
        if (!$DB->tableExists('glpi_plugin_actadeentrega_mail_config')) {
            return $defaults;
        }
        $row = $DB->request([
            'SELECT' => '*',
            'FROM' => 'glpi_plugin_actadeentrega_mail_config',
            'WHERE' => ['id' => 1],
            'LIMIT' => 1,
        ])->current();
        return $row ? array_merge($defaults, $row) : $defaults;
    }

    public static function parseEmails(string $value): array
    {
        $parts = preg_split('/[;,\s]+/', trim($value)) ?: [];
        $emails = [];
        foreach ($parts as $part) {
            $email = strtolower(trim($part));
            if ($email === '') continue;
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Dirección de correo no válida: ' . $email);
            }
            $emails[$email] = $email;
        }
        return array_values($emails);
    }

    public static function enqueue(int $actaId, bool $force = false): bool
    {
        global $DB;
        $acta = PluginActadeentregaSigningService::acta($actaId);
        if ((string)$acta['status'] !== 'Firmada completamente') {
            throw new RuntimeException('El correo final solo puede generarse para un acta completamente firmada.');
        }

        $currentStatus = (string)($acta['final_mail_status'] ?? 'no_enviado');
        if (!$force && in_array($currentStatus, ['en_cola', 'enviado'], true)) {
            return false;
        }
        if ($force && !in_array($currentStatus, ['fallido', 'deshabilitado', 'no_enviado'], true)) {
            throw new RuntimeException('El envío no se puede reintentar mientras está en cola o ya fue enviado.');
        }

        $settings = self::getSettings();
        if ((int)$settings['is_enabled'] !== 1) {
            $DB->update('glpi_plugin_actadeentrega_actas', [
                'final_mail_status' => 'deshabilitado',
                'final_mail_error' => 'El envío automático a Activo Fijo está deshabilitado en la configuración del plugin.',
            ], ['id' => $actaId]);
            return false;
        }

        $to = self::parseEmails((string)$settings['to_emails']);
        $cc = self::parseEmails((string)$settings['cc_emails']);
        if (!$to) throw new RuntimeException('Configure al menos una dirección en Para para el envío a Activo Fijo.');
        $sender = Config::getEmailSender();
        $senderEmail = trim((string)($sender['email'] ?? ''));
        $senderName = trim((string)($sender['name'] ?? 'GLPI - Songa'));
        if (!filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('GLPI no tiene configurado un correo remitente válido en Configuración > Notificaciones.');
        }

        $relativePdf = ltrim((string)$acta['pdf_path'], '/\\');
        $pdfBase = realpath(rtrim(GLPI_VAR_DIR, DIRECTORY_SEPARATOR) . '/_plugins/actadeentrega/acts');
        $pdfPath = realpath(rtrim(GLPI_VAR_DIR, DIRECTORY_SEPARATOR) . '/' . $relativePdf);
        if (!$pdfBase || !$pdfPath || !str_starts_with($pdfPath, $pdfBase . DIRECTORY_SEPARATOR) || !is_file($pdfPath)) {
            throw new RuntimeException('No se encontró el PDF definitivo en el almacenamiento privado del plugin.');
        }
        $pdfHash = hash_file('sha256', $pdfPath);
        if (!$pdfHash || !hash_equals((string)$acta['pdf_sha256'], $pdfHash)) {
            throw new RuntimeException('El PDF definitivo no superó la verificación de integridad.');
        }

        $snapshot = json_decode((string)$acta['snapshot'], true);
        if (!is_array($snapshot)) throw new RuntimeException('La información del acta no es válida.');
        $user = $snapshot['user'] ?? [];
        $issuer = $snapshot['issuer'] ?? [];
        $participants = $snapshot['participants'] ?? [];
        $assets = $snapshot['assets'] ?? [];
        $assetLines = [];
        foreach ($assets as $asset) {
            $line = trim((string)($asset['type'] ?? '') . ' | Serie: ' . (string)($asset['serial'] ?? '') . ' | Activo fijo: ' . (string)($asset['asset_number'] ?? 'N/A'));
            if (trim((string)($asset['description'] ?? '')) !== '') $line .= ' | ' . trim((string)$asset['description']);
            $assetLines[] = $line;
        }

        $date = static function (string $value): string {
            if ($value === '') return '';
            try {
                return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
                    ->setTimezone(new DateTimeZone('America/Guayaquil'))
                    ->format('d/m/Y H:i');
            } catch (Throwable $e) {
                return $value;
            }
        };
        $managerSignedAt = (string)($acta['manager_signed_at'] ?? '');
        $variables = [
            '{numero_acta}' => (string)$acta['act_number'],
            '{fecha_entrega}' => $date((string)($acta['act_date'] ?? $snapshot['date'] ?? '')),
            '{usuario}' => (string)($user['recipient'] ?? ''),
            '{gerente}' => (string)($user['manager'] ?? ''),
            '{tecnico}' => (string)($issuer['name'] ?? ''),
            '{activos}' => implode("\n", $assetLines),
            '{fecha_firma}' => $date($managerSignedAt),
            '{remitente}' => $senderEmail,
        ];
        $subject = strtr((string)$settings['subject_template'], $variables);
        $subject = trim(preg_replace('/[\r\n]+/', ' ', $subject) ?? '');
        $body = strtr((string)$settings['body_template'], $variables);
        if ($subject === '' || trim($body) === '') {
            throw new RuntimeException('El asunto y el mensaje del correo no pueden estar vacíos.');
        }

        $metadata = [
            'to' => $to,
            'cc' => array_values(array_diff($cc, $to)),
            'attachment_path' => $pdfPath,
            'attachment_sha256' => $pdfHash,
        ];
        $queue = new QueuedNotification();
        $firstRecipient = $to[0];
        $queueId = 0;

        $DB->beginTransaction();
        try {
            $fresh = self::actaForUpdate($actaId);
            $freshStatus = (string)($fresh['final_mail_status'] ?? 'no_enviado');
            if (!$force && in_array($freshStatus, ['en_cola', 'enviado'], true)) {
                $DB->commit();
                return false;
            }
            $DB->update('glpi_plugin_actadeentrega_actas', [
                'final_mail_status' => 'en_cola',
                'final_mail_error' => '',
                'final_mail_queued_at' => date('Y-m-d H:i:s'),
                'final_mail_sent_at' => null,
            ], ['id' => $actaId]);
            $added = $queue->add([
                'itemtype' => PluginActadeentregaActa::class,
                'items_id' => $actaId,
                'entities_id' => (int)$acta['entities_id'],
                'notificationtemplates_id' => 0,
                'event' => 'acta_finalizada',
                'sender' => $senderEmail,
                'sendername' => $senderName,
                'recipient' => $firstRecipient,
                'recipientname' => '',
                'name' => $subject,
                'body_text' => $body,
                'body_html' => '<!doctype html><html lang="es"><body style="font-family:Arial,sans-serif;color:#263746;line-height:1.55"><div>' . nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')) . '</div></body></html>',
                'headers' => [
                    'Auto-Submitted' => 'auto-generated',
                    'X-Auto-Response-Suppress' => 'OOF, DR, NDR, RN, NRN',
                ],
                'documents' => $metadata,
                'mode' => 'actadeentrega',
                'attach_documents' => 0,
            ]);
            if (!$added) throw new RuntimeException('GLPI no pudo registrar el correo en la cola de notificaciones.');
            $queueId = (int)$queue->getID();
            $DB->update('glpi_plugin_actadeentrega_actas', [
                'final_mail_queue_id' => $queueId,
            ], ['id' => $actaId]);
            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }

        PluginActadeentregaSigningService::audit(
            $actaId,
            (int)Session::getLoginUserID(false),
            'sistema',
            'correo_activo_fijo_en_cola',
            'Correo final agregado a la cola GLPI. ID de cola: ' . $queueId
        );
        return true;
    }

    public static function markSendResult(int $actaId, string $status, string $error = ''): void
    {
        global $DB;
        if ($actaId < 1 || !$DB->tableExists('glpi_plugin_actadeentrega_actas')) return;
        $DB->update('glpi_plugin_actadeentrega_actas', [
            'final_mail_status' => $status,
            'final_mail_sent_at' => $status === 'enviado' ? date('Y-m-d H:i:s') : null,
            'final_mail_error' => mb_substr($error, 0, 4000),
        ], ['id' => $actaId]);
    }

    private static function actaForUpdate(int $id): array
    {
        global $DB;
        $row = $DB->request([
            'SELECT' => ['id', 'final_mail_status'],
            'FROM' => 'glpi_plugin_actadeentrega_actas',
            'WHERE' => ['id' => $id],
            'LIMIT' => 1,
        ])->current();
        if (!$row) throw new RuntimeException('No se encontró el acta para registrar el envío.');
        return $row;
    }
}
