<?php

namespace App\Exceptions;

/**
 * Thrown when the tourist's own choices rule out a trip (e.g. no destination in the chosen
 * towns matches their interests). The message is written for the tourist and shown as is,
 * since trying again with the same choices can't succeed.
 */
class ItineraryNotPossibleException extends \Exception {}
