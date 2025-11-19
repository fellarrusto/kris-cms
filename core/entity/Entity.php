<?php
require_once __DIR__ . '/JsonRepository.php';

class Entity
{
    private array $data;
    private int $index;
    private string $file;
    private string $name;
    private JsonRepository $repo;
    private bool $modified = false;

    public function __construct($filename, $name, $id = 0)
    {
        $this->repo = new JsonRepository();
        $this->file = $filename;
        $this->name = $name;

        [$this->data, $this->index] = $this->repo->find($filename, $name, $id);

        if (!$this->data) {
            throw new Exception("Entity {$name} with id {$id} not found");
        }
    }

    public function get($field = null)
    {
        return $field ? ($this->data[$field] ?? null) : $this->data;
    }

    public function getData($name, $lang = null)
    {
        foreach ($this->data['data'] as $item) {
            if ($item['name'] === $name) {
                if ($lang && is_array($item['value'])) {
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

    public function set($field, $value)
    {
        $this->data[$field] = $value;
        $this->modified = true;
    }

    public function setData($name, $value, $lang = null)
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

    public function save()
    {
        if (!$this->modified)
            return;
        $this->repo->save($this->file, $this->index, $this->data);
        $this->modified = false;
    }

    public function toJson()
    {
        return json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}