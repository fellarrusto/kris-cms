<?php
declare(strict_types=1);

namespace Kris\Staging;

use RuntimeException;

/** Operazione sullo staging rifiutata o non riuscita. Il messaggio si puo mostrare a chi l'ha chiesta. */
final class StagingException extends RuntimeException
{
    /** @param array<int|string, string> $details per esempio i file in conflitto, o current_hash di un file cambiato */
    public function __construct(string $message, private array $details = [])
    {
        parent::__construct($message);
    }

    /** @return array<int|string, string> */
    public function details(): array
    {
        return $this->details;
    }
}
