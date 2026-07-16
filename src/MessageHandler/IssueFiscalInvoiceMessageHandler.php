<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Invoice;
use App\Enum\EInvoiceStatus;
use App\Enum\InvoiceStatus;
use App\Enum\NotificationType;
use App\Message\IssueFiscalInvoiceMessage;
use App\Repository\FiscalInvoiceRepository;
use App\Repository\InvoiceRepository;
use App\Service\Billing\EInvoicing\EInvoicingException;
use App\Service\Billing\FiscalInvoiceService;
use App\Service\Notification\NotificationDispatch;
use App\Service\Notification\NotificationDispatcherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Async handler for {@see IssueFiscalInvoiceMessage}. Issues the fiscal invoice
 * for a paid Invoice through the active provider.
 *
 * Failure policy:
 *  - transient provider errors (network/5xx) re-throw → Messenger retries
 *    (3× backoff) then parks in the `failed` transport;
 *  - business errors (4xx/invalid data) and any other Throwable are swallowed
 *    after marking the mirror ERROR, so a poison message does not loop. The
 *    leftover DRAFT/ERROR row is picked up by the reconcile command (P3).
 */
#[AsMessageHandler]
final class IssueFiscalInvoiceMessageHandler
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly FiscalInvoiceRepository $fiscalInvoices,
        private readonly FiscalInvoiceService $fiscalInvoiceService,
        private readonly EntityManagerInterface $em,
        private readonly NotificationDispatcherInterface $notifier,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function __invoke(IssueFiscalInvoiceMessage $message): void
    {
        $invoice = $this->invoices->find($message->invoiceId);
        if (null === $invoice) {
            $this->logger->warning('IssueFiscalInvoice: invoice {id} not found, skipping.', ['id' => $message->invoiceId]);

            return;
        }

        if (InvoiceStatus::PAID !== $invoice->getStatus()) {
            $this->logger->warning('IssueFiscalInvoice: invoice {id} is not paid, skipping.', ['id' => $message->invoiceId]);

            return;
        }

        // Defense in depth: the checkout gate blocks payment without fiscal data,
        // but a future gateway webhook could reach markPaid() directly. Skip and
        // let the reconcile command retry once the client completes their data.
        if (!$invoice->getUser()->hasCompleteFiscalData()) {
            $this->logger->warning('IssueFiscalInvoice: invoice {id} user has incomplete fiscal data, skipping.', ['id' => $message->invoiceId]);

            return;
        }

        try {
            $this->fiscalInvoiceService->issueForPaidInvoice($invoice);
        } catch (EInvoicingException $e) {
            if ($e->retryable) {
                throw $e; // let Messenger retry transient failures
            }
            $this->markError($invoice, $e);

            return;
        } catch (\Throwable $e) {
            $this->markError($invoice, $e);

            return;
        }

        // Notify only on the success path. issueForPaidInvoice already committed the
        // ISSUED state (its own flush), so this runs outside the issuance boundary and
        // never references an un-issued invoice. It is dispatched here, at the retry
        // boundary, rather than inside the service: issueForPaidInvoice is idempotent
        // and returns early on the already-issued path, so a Messenger retry re-enters
        // this success branch and re-dispatches, while the dedup key collapses the
        // duplicate. Placing it in the service would skip that early-return retry and
        // could lose the notification if the first attempt crashed after issuing.
        $this->notifyInvoiceIssued($invoice);
    }

    /** Tells the client their fiscal invoice is available. In-app only (no email template yet). */
    private function notifyInvoiceIssued(Invoice $invoice): void
    {
        $this->notifier->dispatch(new NotificationDispatch(
            user: $invoice->getUser(),
            legalCase: null,
            type: NotificationType::INVOICE_ISSUED,
            title: $this->translator->trans('notification.invoice_issued.title'),
            message: $this->translator->trans('notification.invoice_issued.message'),
            resourceLink: '/invoices',
            variant: 'info',
            emailSubject: null,
            emailTemplate: null,
            dedupKey: sprintf('invoice_issued:%d', $invoice->getId()),
        ));
    }

    private function markError(Invoice $invoice, \Throwable $e): void
    {
        $this->logger->error('IssueFiscalInvoice failed for invoice {id}: {msg}', [
            'id' => $invoice->getId(),
            'msg' => $e->getMessage(),
        ]);

        $fiscal = $this->fiscalInvoices->findOneByInvoice($invoice);
        if (null !== $fiscal && null === $fiscal->getProviderInvoiceId()) {
            $fiscal->setEInvoiceStatus(EInvoiceStatus::ERROR)->setEInvoiceError($e->getMessage());
            $this->em->flush();
        }
    }
}
