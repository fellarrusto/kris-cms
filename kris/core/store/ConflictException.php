<?php
declare(strict_types=1);

namespace Kris\Store;

/** Il documento è cambiato dopo la lettura: la scrittura è stata rifiutata per non perdere il lavoro di altri. */
final class ConflictException extends StoreException
{
    public function __construct(public readonly string $doc, public readonly string $currentRev)
    {
        parent::__construct("«{$doc}» è stato modificato nel frattempo: ricarica e riprova.");
    }
}
