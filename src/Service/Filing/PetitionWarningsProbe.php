<?php

declare(strict_types=1);

namespace App\Service\Filing;

use App\Service\Pdf\PdfTextProbe;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Facts about the generated petition the lawyer should see before signing.
 * Warnings, not blockers: the petition is frozen and cannot be regenerated here.
 */
final class PetitionWarningsProbe
{
    public function __construct(
        private readonly PdfTextProbe $textProbe,
        private readonly TranslatorInterface $translator,
    ) {}

    /**
     * @return list<string>
     */
    public function warnings(string $petitionPath, bool $proofAnnexed): array
    {
        $text = PdfTextProbe::normalize($this->textProbe->text($petitionPath));
        $warnings = [];

        $interestLabel = $this->translator->trans('pdf.payment_order.amount_interest', [], null, 'ro');
        $asOf = explode('%date%', $this->translator->trans('pdf.payment_order.amount_interest_as_of', [], null, 'ro'))[0];
        if (str_contains($text, $interestLabel) && !str_contains($text, trim($asOf))) {
            $warnings[] = 'petition_without_interest_date';
        }

        // The wording after the amount, e.g. "RON, achitată conform dovezii anexate."
        $paidParts = explode('%amount%', $this->translator->trans('pdf.payment_order.costs_stamp_duty_paid', [], null, 'ro'));
        $annexedWording = trim(end($paidParts), ' .');
        if ($annexedWording !== '' && $proofAnnexed !== str_contains($text, $annexedWording)) {
            $warnings[] = 'petition_stamp_wording_mismatch';
        }

        return $warnings;
    }
}
