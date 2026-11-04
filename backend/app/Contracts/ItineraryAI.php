<?php

namespace App\Contracts;

use App\Exceptions\AIServiceUnavailableException;

/**
 * The AI that picks each day's destinations for ItineraryService. Implementations send
 * the prompt to their provider and return the reply text (the itinerary JSON); the
 * service parses and validates it the same way whichever provider answered.
 */
interface ItineraryAI
{
    /**
     * @throws AIServiceUnavailableException when the provider is busy or out of quota
     */
    public function generate(string $prompt): string;
}
