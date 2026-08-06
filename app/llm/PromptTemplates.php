<?php

final class PromptTemplates
{
    public static function load(string $baseDir, string $name): string
    {
        $path = rtrim($baseDir, '/\\') . '/' . $name;
        if (!is_file($path)) {
            throw new RuntimeException("Prompt file not found: $path");
        }
        return file_get_contents($path);
    }

    /**
     * Простейшая подстановка {{var}}.
     * Значения экранировать не будем — потому что промпт это текст,
     * но данные товара лучше передавать в JSON отдельно.
     */
    public static function render(string $template, array $vars): string
    {
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_\-]+)\s*\}\}/', function ($m) use ($vars) {
            $k = $m[1];
            return array_key_exists($k, $vars) ? (string)$vars[$k] : $m[0];
        }, $template);
    }
}
