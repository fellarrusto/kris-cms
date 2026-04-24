<?php
declare(strict_types=1);

namespace Kris\Entity;
use Exception;

class Entity
{
    private array $data;
    private int $index;
    private string $file;
    private string $name;
    private ?JsonRepository $repo;
    private bool $modified = false;

    public function __construct(string $filename, string $name, int $id = 0, ?array $preloaded = null)
    {
        $this->file = $filename;
        $this->name = $name;

        if ($preloaded !== null) {
            $this->repo = null;
            $this->data = $preloaded;
            $this->index = -1;
        } else {
            $this->repo = new JsonRepository();
            [$this->data, $this->index] = $this->repo->find($filename, $name, $id);
            if (!$this->data) {
                throw new Exception("Entity {$name} with id {$id} not found");
            }
        }

        // Aggiungi id ai data per renderlo accessibile via getData()
        $this->data['data'][] = [
            'name' => 'id',
            'type' => 'plain',
            'value' => $this->data['id']
        ];
    }

    public static function fromArray(array $itemData, string $name): self
    {
        $payload = [
            'id' => $itemData['id'] ?? 0,
            'name' => $name,
            'data' => $itemData['data'] ?? []
        ];
        return new self('', $name, 0, $payload);
    }

    // Load an entity by walking a nested path from a root entity.
    // $path alternates fieldName/subId: ['features', '0', 'highlights', '2'].
    public static function fromPath(string $filename, string $rootName, int $rootId, array $path): self
    {
        if (empty($path)) {
            return new self($filename, $rootName, $rootId);
        }

        $repo = new JsonRepository();
        [$cur, ] = $repo->find($filename, $rootName, $rootId);
        if (!$cur) {
            throw new Exception("Root entity {$rootName} with id {$rootId} not found");
        }

        $leafName = $rootName;
        for ($i = 0, $n = count($path); $i < $n; $i += 2) {
            $fieldName = $path[$i];
            if (!isset($path[$i + 1])) {
                throw new Exception("Invalid path: field '{$fieldName}' has no id");
            }
            $subId = (int) $path[$i + 1];

            $sub = null;
            foreach ($cur['data'] ?? [] as $item) {
                if ($item['name'] === $fieldName && ($item['type'] ?? null) === 'array') {
                    foreach ($item['value'] as $child) {
                        if ((int) ($child['id'] ?? -1) === $subId) {
                            $sub = $child;
                            break 2;
                        }
                    }
                    break;
                }
            }
            if ($sub === null) {
                throw new Exception("Path not found: {$fieldName}/{$subId}");
            }
            $cur = $sub;
            $leafName = $fieldName;
        }

        return self::fromArray($cur, $leafName);
    }

    public function get(?string $field = null): mixed
    {
        return $field ? ($this->data[$field] ?? null) : $this->data;
    }

    public function getData(string $name, ?string $lang = null): mixed
    {
        foreach ($this->data['data'] as $item) {
            if ($item['name'] === $name) {
                if ($lang && \is_array($item['value']) && ($item['type'] ?? null) !== 'array') {
                    if (!empty($item['value'][$lang])) {
                        return $item['value'][$lang];
                    }
                    foreach ($item['value'] as $v) {
                        if (!empty($v))
                            return $v;
                    }
                    return null;
                }
                return $item['value'];
            }
        }
        return null;
    }

    public function getArray(string $name): ?array
    {
        foreach ($this->data['data'] as $item) {
            if ($item['name'] === $name && ($item['type'] ?? null) === 'array') {
                return \is_array($item['value']) ? $item['value'] : [];
            }
        }
        return null;
    }

    public function set(string $field, mixed $value): void
    {
        $this->data[$field] = $value;
        $this->modified = true;
    }

    public function setData(string $name, mixed $value, ?string $lang = null): void
    {
        foreach ($this->data['data'] as &$item) {
            if ($item['name'] === $name) {
                if ($item['type'] === 'text' && $lang) {
                    $item['value'][$lang] = $value;
                } else {
                    $item['value'] = $value;
                }
                $this->modified = true;
                return;
            }
        }
    }

    public function save(): void
    {
        if (!$this->modified || $this->repo === null)
            return;
        $this->repo->save($this->file, $this->index, $this->data);
        $this->modified = false;
    }

    public function toJson(): string
    {
        return json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
