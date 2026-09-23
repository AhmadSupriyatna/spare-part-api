<?php

namespace App\Exceptions;

use Exception;

/**
 * An explicitly-picked old_installation_id doesn't actually belong to the
 * equipment/part it's being used for, or is no longer active — thrown by
 * PartLifecycleService::resolveActiveInstallation() so a caller (PM
 * checklist, breakdown approval) can never remove/replace an installation
 * it was never shown to pick from.
 */
class InvalidOldInstallationException extends Exception
{
    public function __construct(int $installationId)
    {
        parent::__construct(
            "Instalasi #{$installationId} tidak valid — bukan milik equipment/part ini, atau sudah tidak aktif."
        );
    }
}
