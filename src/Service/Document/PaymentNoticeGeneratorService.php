<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Entity\LegalCase;
use App\Enum\DocumentType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Translation\LocaleSwitcher;
use Twig\Environment;

/**
 * Generează somația de plată (CPC art. 1015 alin. 1).
 *
 * Termenul legal de plată este 15 zile de la primirea somației, citat verbatim
 * în template (NU 30 zile; cele 30 din Legea 72/2013 art. 3 alin. 1 sunt termen
 * supletiv contractual între profesioniști, distinct de somația CPC).
 *
 * Calculul accesoriilor (dobândă legală vs. penalitate contractuală) și
 * defalcarea pe perioade sunt asamblate de {@see SummonsContextBuilder} și
 * fuzionate în contextul template-ului.
 */
final class PaymentNoticeGeneratorService extends AbstractPdfGenerator
{
    public function __construct(
        Environment $twig,
        EntityManagerInterface $em,
        Security $security,
        string $uploadsDir,
        private readonly SummonsContextBuilder $contextBuilder,
        private readonly LocaleSwitcher $localeSwitcher,
    ) {
        parent::__construct($twig, $em, $security, $uploadsDir);
    }

    protected function templatePath(): string
    {
        return 'pdf/payment_notice.html.twig';
    }

    /**
     * A Romanian legal act, whatever language the lawyer uses the platform in.
     */
    public function renderHtml(LegalCase $case): string
    {
        return $this->localeSwitcher->runWithLocale('ro', fn (): string => parent::renderHtml($case));
    }

    protected function templateContext(LegalCase $case): array
    {
        return array_merge(parent::templateContext($case), $this->contextBuilder->build($case));
    }

    protected function documentType(): DocumentType
    {
        return DocumentType::SOMATIE;
    }

    protected function originalFilenameFor(LegalCase $case): string
    {
        return 'Somatie_' . $case->getCaseNumber() . '.pdf';
    }

    protected function storedFilenameStem(LegalCase $case): string
    {
        return 'somatie_' . $case->getId();
    }
}
