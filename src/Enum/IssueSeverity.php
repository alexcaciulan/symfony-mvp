<?php

namespace App\Enum;

enum IssueSeverity: string
{
    case ERROR = 'ERROR';
    case WARNING = 'WARNING';

    public function label(): string
    {
        return 'enum.issue_severity.' . $this->value;
    }
}
