<?php
declare(strict_types=1);

use Symfony\Component\Mime\Address;

final class PluginActadeentregaNotificationEventActadeentrega
{
    public static function canCron(): bool
    {
        return true;
    }

    public static function send(array $data): int
    {
        global $CFG_GLPI;
        $processed = 0;
        foreach ($data as $row) {
            $queue = new QueuedNotification();
            $queue->getFromResultSet($row);
            $actaId = (int)($queue->fields['items_id'] ?? 0);
            try {
                $metadata = importArrayFromDB((string)($queue->fields['documents'] ?? ''));
                if (!is_array($metadata)) throw new RuntimeException('Los metadatos del correo en cola no son válidos.');
                $to = is_array($metadata['to'] ?? null) ? $metadata['to'] : [(string)$queue->fields['recipient']];
                $cc = is_array($metadata['cc'] ?? null) ? $metadata['cc'] : [];
                $attachment = (string)($metadata['attachment_path'] ?? '');
                $attachmentHash = (string)($metadata['attachment_sha256'] ?? '');
                $base = realpath(rtrim(GLPI_VAR_DIR, DIRECTORY_SEPARATOR) . '/_plugins/actadeentrega/acts');
                $path = realpath($attachment);
                if (!$base || !$path || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
                    throw new RuntimeException('El PDF adjunto ya no está disponible en el almacenamiento privado.');
                }
                $actualHash = hash_file('sha256', $path);
                if (!$actualHash || $attachmentHash === '' || !hash_equals($attachmentHash, $actualHash)) {
                    throw new RuntimeException('El PDF adjunto no superó la verificación de integridad.');
                }

                $mailer = new GLPIMailer();
                $mail = $mailer->getEmail();
                $mail->from(new Address((string)$queue->fields['sender'], (string)$queue->fields['sendername']));
                $hasTo = false;
                foreach (array_values(array_unique($to)) as $address) {
                    if (!filter_var($address, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('La cola contiene un destinatario Para no válido.');
                    if (!$hasTo) {
                        $mail->to(new Address($address));
                        $hasTo = true;
                    } else {
                        $mail->addTo(new Address($address));
                    }
                }
                if (empty($hasTo)) throw new RuntimeException('No hay destinatarios Para válidos.');
                foreach (array_values(array_unique($cc)) as $address) {
                    if (!filter_var($address, FILTER_VALIDATE_EMAIL) || in_array($address, $to, true)) continue;
                    $mail->addCc(new Address($address));
                }
                $mail->subject((string)$queue->fields['name']);
                $mail->text((string)$queue->fields['body_text']);
                if (!empty($queue->fields['body_html'])) $mail->html((string)$queue->fields['body_html']);
                $mail->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated');
                $mail->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'OOF, DR, NDR, RN, NRN');
                $mail->attachFromPath($path, 'Acta_' . basename($path));

                if (!$mailer->send()) {
                    throw new RuntimeException((string)$mailer->getError() ?: 'GLPI no pudo enviar el correo.');
                }

                $queue->update([
                    'id' => (int)$queue->fields['id'],
                    'sent_time' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
                ]);
                $queue->delete(['id' => (int)$queue->fields['id']]);
                PluginActadeentregaFinalMailService::markSendResult($actaId, 'enviado');
                Toolbox::logInFile('mail', 'Acta de Entrega: correo final enviado. Acta ID ' . $actaId . ', cola ' . (int)$queue->fields['id'] . "\n");
                $processed++;
            } catch (Throwable $e) {
                $error = $e->getMessage();
                $maxRetries = max(0, (int)($CFG_GLPI['smtp_max_retries'] ?? 5));
                $sentTry = (int)($queue->fields['sent_try'] ?? 0);
                if ($sentTry >= $maxRetries) {
                    $queue->delete(['id' => (int)$queue->fields['id']]);
                    PluginActadeentregaFinalMailService::markSendResult($actaId, 'fallido', $error);
                } else {
                    $retryMinutes = max(0, (int)($CFG_GLPI['smtp_retry_time'] ?? 0));
                    $update = [
                        'id' => (int)$queue->fields['id'],
                        'sent_try' => $sentTry + 1,
                    ];
                    if ($retryMinutes > 0) $update['send_time'] = date('Y-m-d H:i:s', time() + $retryMinutes * 60);
                    $queue->update($update);
                    PluginActadeentregaFinalMailService::markSendResult($actaId, 'en_cola', $error);
                }
                Toolbox::logInFile('mail-error', 'Acta de Entrega: error de envío. Acta ID ' . $actaId . '. ' . $error . "\n");
            }
        }
        return $processed;
    }
}
