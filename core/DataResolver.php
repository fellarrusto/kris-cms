<?php
namespace Core;

class DataResolver {
    private array $data;

    public function __construct(string $path) {
        $json = file_get_contents($path);
        $this->data = json_decode($json, true) ?? [];
    }

    public function all(): array
    {
        return $this->data;
    }

    public function get(string $id, string $lang, ?string $field = null) {
        if (!isset($this->data[$id])) return null;
        $entry = $this->data[$id];
        if ($field === 'src') {
            return $entry['src'] ?? null;
        }
        return $entry[$lang] ?? null;
    }

    public function getComponentData(string $id): array {
        return $this->data[$id] ?? [];
    }
}