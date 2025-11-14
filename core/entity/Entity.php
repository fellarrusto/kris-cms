<?php
// core/entity/Entity.php

class Entity {
    private $file;
    private $name;
    private $id;
    private $data;
    private $modified = false;

    public function __construct($filename, $name, $id = 0) {
        $this->file = __DIR__ . "/../../data/{$filename}.json";
        $this->name = $name;
        $this->id = $id;
        $this->load();
    }

    private function load() {
        $json = json_decode(file_get_contents($this->file), true);
        foreach ($json as $item) {
            if ($item['name'] === $this->name && $item['id'] == $this->id) {
                $this->data = $item;
                return;
            }
        }
        throw new Exception("Entity {$this->name} with id {$this->id} not found");
    }

    public function get($field = null) {
        return $field ? ($this->data[$field] ?? null) : $this->data;
    }

    public function getData($name, $lang = null) {
        foreach ($this->data['data'] as $item) {
            if ($item['name'] === $name) {
                if ($lang && is_array($item['value'])) {
                    if ($item['value'][$lang] == ""){
                        return $item['value']['en'] ?? null;
                    }
                    else{
                        return $item['value'][$lang] ?? null;
                    }
                }
                return $item['value'];
            }
        }
        return null;
    }

    public function set($field, $value) {
        $this->data[$field] = $value;
        $this->modified = true;
    }

    public function setData($name, $value, $lang = null) {
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

    public function save() {
        if (!$this->modified) return;
        
        $json = json_decode(file_get_contents($this->file), true);
        foreach ($json as &$item) {
            if ($item['name'] === $this->name && $item['id'] == $this->id) {
                $item = $this->data;
                break;
            }
        }
        file_put_contents($this->file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->modified = false;
    }

    public function toJson() {
        return json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}