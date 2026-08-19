<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Address\RomanianAddressFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Renders a party's postal address for display and for the generated documents.
 *
 * The stored `address` is street level: the locality and the county live in
 * their own fields because they drive the competent court and the stamp-duty
 * town hall. A somaţie or a cerere de OP must still carry a complete address,
 * so the three are recomposed here at render time.
 *
 * Takes plain strings rather than a party object because the call sites do not
 * share a shape: the PDFs and the case overview pass Doctrine entities, while
 * the wizard confirmation step passes the step DTOs.
 */
final class AddressExtension extends AbstractExtension
{
    public function __construct(
        private RomanianAddressFormatter $formatter,
    ) {}

    public function getFilters(): array
    {
        return [
            new TwigFilter('mailing_address', $this->mailingAddress(...)),
        ];
    }

    public function mailingAddress(?string $address, ?string $locality = null, ?string $county = null): string
    {
        return $this->formatter->formatMailingLine($address, $locality, $county);
    }
}
