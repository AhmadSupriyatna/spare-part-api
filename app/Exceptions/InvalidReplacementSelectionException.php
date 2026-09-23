<?php

namespace App\Exceptions;

use Exception;

/**
 * The set of installations picked to close out a breakdown replacement
 * request doesn't satisfy the request's own quantity_used — wrong count,
 * a duplicate, or one that isn't actually an active installation of this
 * part on this equipment.
 */
class InvalidReplacementSelectionException extends Exception {}
