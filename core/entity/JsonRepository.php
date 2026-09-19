<?php
declare(strict_types=1);

namespace Kris\Entity;

class JsonRepository {
    private static array $cache = [];
    private string $dataPath;

    public function __construct() {
        $this->dataPath = __DIR__ . '/../../data/';
    }

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

    public function save(string $file, int $index, array $entity): void {
        $data = $this->load($file);
        $data[$index] = $entity;
        $this->store($file)->write($data);
        self::$cache[$file] = $data;
    }

    private function load(string $file): array {
        if (!isset(self::$cache[$file])) {
            self::$cache[$file] = $this->store($file)->read();
        }
        return self::$cache[$file];
    }

    private function store(string $file): JsonStore {
        return new JsonStore($this->dataPath . $file . '.json');
    }
}
