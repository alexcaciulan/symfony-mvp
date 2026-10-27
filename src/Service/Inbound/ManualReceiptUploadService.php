<?php

declare(strict_types=1);

namespace App\Service\Inbound;

use App\Entity\FilingSubmission;
use App\Entity\InboundMail;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\FilingDeliveryStatus;
use App\Enum\FilingEvidenceStatus;
use App\Enum\InboundAuthVerdict;
use App\Enum\InboundClassification;
use App\Enum\InboundMatchConfidence;
use App\Enum\InboundMatchMethod;
use App\Service\AuditLogService;
use App\Service\Pdf\ProcessSandbox;
use App\Service\Pdf\UploadedPdfException;
use App\Service\Pdf\UploadedPdfValidator;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * A receipt the lawyer holds (the court's answer as .eml, a PDF or a picture of
 * it), recorded as the lawyer's own evidence: RECEIPT_MANUAL, never the automatic
 * RECEIPT_RECEIVED, and never downgrading one. The checks are technical (real type
 * by content, size, a parse in the sandbox), not a check of authenticity. The court
 * time is the Date header of an .eml (the court's answer), never the upload time;
 * a PDF or a picture gives no time, so the lawyer types the date from the document.
 * No number is read from any file. Outlook .msg files are refused.
 */
final class ManualReceiptUploadService
{
    public const MAX_BYTES = 20_971_520;
    private const MAX_PIXELS = 50_000_000;
    private const SANDBOX_BUDGET_SECONDS = 90.0;
    /** Clock difference tolerated between the court's mail server and ours. */
    private const CLOCK_SKEW = 'PT15M';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UploadedPdfValidator $pdfValidator,
        private readonly ProcessSandbox $sandbox,
        private readonly InboundRawStorage $storage,
        private readonly InboundMailParser $parser,
        private readonly AuditLogService $auditLogService,
        private readonly ClockInterface $clock,
    ) {}

    public function isUploadable(FilingSubmission $submission): bool
    {
        return $submission->getSentAt() !== null
            && !in_array($submission->getDeliveryStatus(), [FilingDeliveryStatus::UNDELIVERED, FilingDeliveryStatus::CANCELLED], true);
    }

    /** @throws ManualReceiptRefusedException */
    public function upload(FilingSubmission $submission, UploadedFile $file, User $user): InboundMail
    {
        if ($submission->getLegalCase()->getUser() !== $user) {
            throw new ManualReceiptRefusedException('filing.send.blocker.not_owner');
        }
        if (!$this->isUploadable($submission)) {
            throw new ManualReceiptRefusedException('filing.receipt.upload.error.not_allowed');
        }
        if (!$file->isValid()) {
            throw new ManualReceiptRefusedException(in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'filing.receipt.upload.error.too_large'
                : 'filing.receipt.upload.error.failed');
        }
        if ((int) $file->getSize() > self::MAX_BYTES) {
            throw new ManualReceiptRefusedException('filing.receipt.upload.error.too_large');
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());
        $headers = null;
        [$bytes, $kind, $extension] = match (true) {
            in_array($mime, ['application/vnd.ms-outlook', 'application/cdfv2', 'application/x-ole-storage', 'application/CDFV2'], true)
                || str_starts_with(strtolower($mime), 'application/cdfv2') => throw new ManualReceiptRefusedException('filing.receipt.upload.error.msg_refused'),
            $mime === 'application/pdf' => [$this->pdfBytes($file), InboundMail::MIME_PDF, 'pdf'],
            in_array($mime, ['image/png', 'image/jpeg'], true) => [$this->imageBytes($file, $mime), InboundMail::MIME_IMAGE, $mime === 'image/png' ? 'png' : 'jpg'],
            in_array($mime, ['message/rfc822', 'text/plain'], true) => [(string) file_get_contents($file->getPathname()), InboundMail::MIME_EML, 'eml'],
            default => throw new ManualReceiptRefusedException('filing.receipt.upload.error.type'),
        };
        if ($kind === InboundMail::MIME_EML) {
            $headers = $this->parser->parse($bytes);
            if ($headers->from === null || $headers->date === null) {
                throw new ManualReceiptRefusedException('filing.receipt.upload.error.eml_invalid');
            }
        }

        $now = $this->clock->now();
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $this->em->lock($submission->getLegalCase(), LockMode::PESSIMISTIC_WRITE);
            $this->em->lock($submission, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($submission);
            if (!$this->isUploadable($submission)) {
                throw new ManualReceiptRefusedException('filing.receipt.upload.error.not_allowed');
            }

            $path = $this->storage->write($bytes, $extension, $now);
            $mail = new InboundMail(InboundMail::SOURCE_MANUAL, bin2hex(random_bytes(16)), $path, hash('sha256', $bytes), strlen($bytes), $now, $now);
            $mail->setRawMime($kind);
            $mail->setOriginalFilename(self::safeName($file->getClientOriginalName()));
            if ($headers !== null) {
                $mail->setHeaders($headers->messageId, $headers->inReplyTo, $headers->references !== [] ? implode(' ', $headers->references) : null, $headers->from, $headers->to !== [] ? implode(', ', $headers->to) : null, $headers->cc, null, $headers->subject);
            }
            $mail->setClassification(InboundClassification::RECEIPT);
            $mail->setAuthVerdict(InboundAuthVerdict::ABSENT);
            $mail->match(null, $submission, InboundMatchMethod::MANUAL, InboundMatchConfidence::CERTAIN);
            $mail->setUploadedBy($user);
            $mail->markProcessed($now, null);
            $this->em->persist($mail);

            $before = $submission->getEvidenceStatus();
            $receiptAt = $headers !== null ? $this->courtTime($headers->date, $submission, $now) : null;
            $submission->markReceipt($receiptAt, $mail, FilingEvidenceStatus::RECEIPT_MANUAL);
            $this->em->flush();
            $this->auditLogService->log(
                action: 'filing_receipt_uploaded',
                entityType: LegalCase::class,
                entityId: (string) $submission->getLegalCase()->getId(),
                newData: [
                    'submissionId' => $submission->getId(),
                    'inboundMailId' => $mail->getId(),
                    'kind' => $kind,
                    'sha256' => $mail->getRawSha256(),
                    'bytes' => $mail->getRawBytes(),
                    'receiptDate' => $receiptAt?->format(\DateTimeInterface::ATOM),
                    'previousEvidence' => $before->value,
                    'evidence' => $submission->getEvidenceStatus()->value,
                ],
                category: AuditLogService::CATEGORY_FILING_SUBMISSION,
                actor: $user,
            );
            $this->em->flush();
            $connection->commit();

            return $mail;
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $e;
        }
    }

    /**
     * The .eml Date header as the court's time, unless it cannot be an answer to
     * this transmission (before it was sent or in the future): then no time at all.
     */
    private function courtTime(?\DateTimeImmutable $date, FilingSubmission $submission, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $sentAt = $submission->getSentAt();
        if ($date === null || $sentAt === null) {
            return null;
        }
        $skew = new \DateInterval(self::CLOCK_SKEW);
        if ($date < $sentAt->sub($skew) || $date > $now->add($skew)) {
            return null;
        }

        // The header keeps the sender's zone; the database keeps the application's zone.
        return $date->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    private function pdfBytes(UploadedFile $file): string
    {
        $this->sandbox->begin(self::SANDBOX_BUDGET_SECONDS);
        try {
            $validated = $this->pdfValidator->validate($file);

            return (string) file_get_contents($validated->path);
        } catch (UploadedPdfException $e) {
            throw new ManualReceiptRefusedException(str_replace('filing.upload.error.', 'filing.receipt.upload.error.', $e->messageKey), $e);
        } finally {
            $this->sandbox->end();
        }
    }

    private function imageBytes(UploadedFile $file, string $mime): string
    {
        $info = @getimagesize($file->getPathname());
        if ($info === false || $info['mime'] !== $mime || $info[0] < 1 || $info[1] < 1) {
            throw new ManualReceiptRefusedException('filing.receipt.upload.error.unreadable');
        }
        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            throw new ManualReceiptRefusedException('filing.receipt.upload.error.too_large');
        }

        return (string) file_get_contents($file->getPathname());
    }

    private static function safeName(string $name): string
    {
        $name = (string) preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', basename($name));

        return mb_substr($name, 0, 120);
    }
}
