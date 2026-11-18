<?php
declare(strict_types=1);

namespace Kris\Entity;

use Kris\Site\Paths;
use Kris\Store\Stores;

class JsonRepository {
    // Per percorso letto: con l'anteprima dello staging lo stesso archivio puo venire da due file diversi
    private static array $cache = [];

    public function find(string $file, string $name, int $id = 0): array {
        $data = $this->load($file);
        foreach ($data as $index => $item) {
            if ($item['name'] === $name && $item['id'] == $id) {
                return [$item, $index];
            }
        }
        return [null, -1];
    }

    public function findAll(string $file, string $name): array {
        $data = $this->load($file);
        return array_filter($data, fn($item) => $item['name'] === $name);
    }

    /** Scrive sempre nel sito, mai nella sovrapposizione dell'anteprima. */
    public function save(string $file, int $index, array $entity): void {
        $data = $this->load($file);
        $data[$index] = $entity;
        Stores::site()->write(self::doc($file), $data);
        self::$cache[Paths::data($file)] = $data;
    }

    /** Per chiave il file letto: con l'anteprima dello staging lo stesso documento può venire da due file diversi. */
    private function load(string $file): array {
        $path = Paths::data($file);
        if (!isset(self::$cache[$path])) {
            self::$cache[$path] = Stores::site(null, true)->read(self::doc($file))->value;
        }
        return self::$cache[$path];
    }

    /** I contenuti del formato 1 sono il documento data dell'archivio. */
    private static function doc(string $file): string {
        if ($file !== 'k_data') throw new StorageException("Archivio sconosciuto: {$file}");
        return 'data';
    }
}
