<?php

declare(strict_types=1);

namespace App\Enum;

/** How a received message was tied to one of our court emails. */
enum InboundMatchMethod: string
{
    /** Its envelope recipient carries the secret VERP token of one part. */
    case VERP = 'VERP';
    /** It answers one of our Message-IDs (In-Reply-To or References). */
    case IN_REPLY_TO = 'IN_REPLY_TO';
    /** A delivery report quotes our headers in its body. */
    case EMBEDDED_HEADERS = 'EMBEDDED_HEADERS';
    /** Sender domain and timing only. */
    case HEURISTIC = 'HEURISTIC';
    /** Uploaded by the lawyer on the submission. */
    case MANUAL = 'MANUAL';

    public function label(): string
    {
        return 'enum.inbound_match_method.' . $this->value;
    }
}
