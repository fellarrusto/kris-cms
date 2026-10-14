<?php
declare(strict_types=1);

namespace Kris\Entity;

use Kris\Site\Paths;

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

    /** Scrive sempre nel file del sito, mai nella sovrapposizione dell'anteprima. */
    public function save(string $file, int $index, array $entity): void {
        $data = $this->load($file);
        $data[$index] = $entity;
        $path = KRIS_ROOT . '/data/' . $file . '.json';
        (new JsonStore($path))->write($data);
        self::$cache[$path] = $data;
    }

    private function load(string $file): array {
        $path = Paths::data($file);
        if (!isset(self::$cache[$path])) {
            self::$cache[$path] = (new JsonStore($path))->read();
        }
        return self::$cache[$path];
    }
}
