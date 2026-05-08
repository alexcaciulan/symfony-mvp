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
        return 'enum.portal_event_type.' . $this->value;
    }
}
