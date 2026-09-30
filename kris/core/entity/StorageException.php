<?php
declare(strict_types=1);

namespace Kris\Entity;

use RuntimeException;

/**
 * Errore di lettura o scrittura dell'archivio JSON.
 *
 * Esiste per una ragione precisa: un archivio illeggibile NON deve mai
 * degradare in "nessun contenuto". Chi cattura questa eccezione deve
 * fermarsi e dirlo, non proseguire con dati vuoti e riscrivere il file.
 */
class StorageException extends RuntimeException
{
}
