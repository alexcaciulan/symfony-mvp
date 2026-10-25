<?php

declare(strict_types=1);

namespace App\Service\Filing;

use App\Enum\SignatureStatus;

/**
 * A signed upload the platform did not accept. Nothing was stored and the part
 * keeps its previous state. The key describes a technical finding only.
 */
final class SignedUploadRefusedException extends \RuntimeException
{
    public function __construct(
        public readonly string $messageKey,
        public readonly ?SignatureStatus $status = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($messageKey, 0, $previous);
    }
}
