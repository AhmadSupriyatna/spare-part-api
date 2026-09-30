<?php

namespace App\Enums;

/** The FP3 paper form's final "Status: OPEN / CLOSED / CLOSED with note" line — set once, at completion, separate from Fp3Status's plain work lifecycle. */
enum Fp3Disposition: string
{
    case Open = 'open';
    case Closed = 'closed';
    case ClosedWithNote = 'closed_with_note';
}
