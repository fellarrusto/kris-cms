<?php
namespace Kris\Entity;
class JsonRepository {
    private static array $cache = [];
    private string $dataPath;
    
    public function __construct() {
        $this->dataPath = __DIR__ . '/../../data/';
    }
    
    public function find($file, $name, $id = 0) {
        $data = $this->load($file);
        foreach ($data as $index => $item) {
            if ($item['name'] === $name && $item['id'] == $id) {
                return [$item, $index];
            }
        }
        return [null, -1];
    }
    
    public function findAll($file, $name) {
        $data = $this->load($file);
        return array_filter($data, fn($item) => $item['name'] === $name);
    }
    
    public function save($file, $index, $entity) {
        $data = $this->load($file);
        $data[$index] = $entity;
        file_put_contents($this->dataPath . $file . '.json', 
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        self::$cache[$file] = $data;
    }
    
    private function load($file) {
        if (!isset(self::$cache[$file])) {
            self::$cache[$file] = json_decode(
                file_get_contents($this->dataPath . $file . '.json'), 
                true
            );
        }
        return self::$cache[$file];
    }
}