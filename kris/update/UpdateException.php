<?php
declare(strict_types=1);

namespace Kris\Update;

use RuntimeException;

/**
 * Errore dell'aggiornamento. Il messaggio e pensato per chi usa l'editor:
 * spiega cosa e successo e, se serve, cosa fare, senza dettagli del server.
 */
final class UpdateException extends RuntimeException
{
}
