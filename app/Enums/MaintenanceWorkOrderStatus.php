<?php

namespace App\Enums;

/**
 * The PM planning layer's execution package — deliberately separate from
 * TaskStatus (an individual Task's own pending/in_progress/completed/
 * cancelled) and from the legacy, unrelated WorkOrder model (a pre-Task-
 * Library recurring recipe, unused by any current UI — see
 * MaintenanceWorkOrder's own docblock for why this isn't the same table).
 */
enum MaintenanceWorkOrderStatus: string
{
    /** Just grouped — still being assembled/reviewed by the planner. */
    case Planning = 'planning';

    /** Explicitly marked ready by the planner — nothing has started yet. */
    case Ready = 'ready';

    /** At least one contained Task has started — derived automatically, never set directly. */
    case InProgress = 'in_progress';

    /** Every non-cancelled contained Task is completed — derived automatically, never set directly. */
    case Completed = 'completed';
}
