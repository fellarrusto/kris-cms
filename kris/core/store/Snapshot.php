<?php
declare(strict_types=1);

namespace Kris\Store;

/** Un documento letto dall'archivio, con la revisione da passare a write() per non sovrascrivere il lavoro di altri. */
final class Snapshot
{
    public function __construct(public readonly array $value, public readonly string $rev)
    {
    }
}
