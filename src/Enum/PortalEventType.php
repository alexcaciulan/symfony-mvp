<?php

namespace App\Enum;

enum PortalEventType: string
{
    case HEARING_SCHEDULED = 'hearing_scheduled';
    case HEARING_COMPLETED = 'hearing_completed';
    case RULING_ISSUED = 'ruling_issued';
    case APPEAL_FILED = 'appeal_filed';
    case CASE_INFO_UPDATE = 'case_info_update';

    public function label(): string
    {
        return match ($this) {
            self::HEARING_SCHEDULED => 'Ședință programată',
            self::HEARING_COMPLETED => 'Ședință finalizată',
            self::RULING_ISSUED => 'Hotărâre pronunțată',
            self::APPEAL_FILED => 'Cale de atac declarată',
            self::CASE_INFO_UPDATE => 'Actualizare informații dosar',
        };
    }
}
