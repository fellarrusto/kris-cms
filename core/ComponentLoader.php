<?php
namespace Core;

class ComponentLoader {
    private string $path;

    public function __construct(string $componentsPath) {
        $this->path = rtrim($componentsPath, '/');
    }

    public function load(string $template): string {
        $file = "{$this->path}/{$template}.html";
        return file_exists($file)
            ? file_get_contents($file)
            : '';
    }
}