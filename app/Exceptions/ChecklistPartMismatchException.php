<?php

namespace App\Exceptions;

use Exception;

/**
 * A submitted checklist row's part_id didn't match the part_id actually
 * stored on that TaskPartCheck row — the client can't be trusted to say
 * which part a checklist entry is for.
 */
class ChecklistPartMismatchException extends Exception
{
    public function __construct(int $checkId)
    {
        parent::__construct(
            "Part pada checklist #{$checkId} tidak sesuai dengan data checklist yang tersimpan — permintaan ditolak."
        );
    }
}
