<?php
declare(strict_types=1);

namespace Kris\Store;

use Kris\Entity\StorageException;

/** Archivio illeggibile o non scrivibile. Estende StorageException: chi la gestiva già continua a farlo. */
class StoreException extends StorageException
{
}
