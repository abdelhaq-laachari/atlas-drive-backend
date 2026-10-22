<?php

namespace App\Enums;

enum MaintenanceStatus: string
{
    case Scheduled = 'scheduled';
    case InProgress = 'in_progress'; // the car is at the garage (status "maintenance")
    case Done = 'done';
    case Cancelled = 'cancelled';
}
