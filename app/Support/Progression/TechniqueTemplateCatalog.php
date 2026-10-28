<?php

namespace App\Support\Progression;

use App\Models\TechniqueItem;
use JsonException;
use RuntimeException;

/** Modelos de catálogo de técnicas e currículo mantidos em resources/data/technique-templates.json (ADR-014). */
class TechniqueTemplateCatalog
{
    /**
     * Campos de estudo que o modelo pode trazer em cada item; ao reaplicar, só preenchem o que está vazio
     * ou o que ainda é material aposentado do modelo (technique-templates-retired.json).
     */
    public const STUDY_FIELDS = ['translation', 'key_points', 'common_error', 'steps', 'video_url'];

    private ?array $templates = null;

    private ?array $retired = null;

    public function all(): array
    {
        if ($this->templates !== null) {
            return $this->templates;
        }

        try {
            $templates = json_decode(file_get_contents(resource_path('data/technique-templates.json')) ?: '', true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('O catálogo de modelos de técnicas está inválido.', previous: $exception);
        }
        if (! is_array($templates)) {
            throw new RuntimeException('O catálogo de modelos de técnicas está inválido.');
        }
        foreach ($templates as $key => $template) {
            $this->validate((string) $key, $template);
        }

        return $this->templates = $templates;
    }

    /** Pasta das fichas do modelo (imagem ou PDF), copiadas para o disco privado ao aplicar. */
    public function sheetPath(string $key, string $sheet): string
    {
        return resource_path('data/technique-sheets/'.$key.'/'.$sheet);
    }

    /** O valor é igual a um texto de estudo que o modelo já trouxe e foi retirado (comparação por SHA-256). */
    public function isRetiredValue(string $key, string $field, ?string $value): bool
    {
        if (blank($value)) {
            return false;
        }
        $hash = hash('sha256', $field."\n".trim(str_replace("\r\n", "\n", $value)));

        return in_array($hash, $this->retired()[$key]['values'] ?? [], true);
    }

    /** A ficha do item veio de uma versão anterior do modelo e foi retirada. */
    public function isRetiredSheet(string $key, ?string $name): bool
    {
        return $name !== null && in_array($name, $this->retired()[$key]['sheets'] ?? [], true);
    }

    private function retired(): array
    {
        if ($this->retired !== null) {
            return $this->retired;
        }
        $path = resource_path('data/technique-templates-retired.json');
        $retired = is_file($path) ? json_decode(file_get_contents($path) ?: '', true) : [];

        return $this->retired = is_array($retired) ? $retired : [];
    }

    public function find(?string $key): ?array
    {
        return $key ? ($this->all()[$key] ?? null) : null;
    }

    private function validate(string $key, mixed $template): void
    {
        if (! is_array($template) || ! isset($template['label'], $template['description'], $template['review_notice'], $template['source_name'], $template['items'], $template['curriculum'])
            || ! is_array($template['items']) || ! is_array($template['curriculum'])) {
            throw new RuntimeException("O modelo de técnicas {$key} está incompleto.");
        }
        $paths = [];
        foreach ($template['items'] as $root) {
            if (! in_array($root['knowledge'] ?? null, TechniqueItem::KNOWLEDGE_TYPES, true)) {
                throw new RuntimeException("O modelo de técnicas {$key} tem grupo raiz sem tipo de conhecimento.");
            }
        }
        $this->collectPaths($key, $template['items'], '', $paths);
        foreach ($template['curriculum'] as $entries) {
            foreach ($entries as $entry) {
                if (! is_array($entry) || ! isset($paths[$entry[0] ?? '']) || ! array_key_exists((int) ($entry[1] ?? 0), AssessmentLevel::requirable())) {
                    throw new RuntimeException("O modelo de técnicas {$key} tem item de currículo inválido.");
                }
            }
        }
        foreach ($template['quiz'] ?? [] as $question) {
            $options = $question['options'] ?? null;
            if (! isset($question['rank'], $question['statement'], $template['curriculum'][$question['rank']]) || ! is_array($options)
                || count($options) < 2 || count($options) > 5 || ! array_key_exists((int) ($question['correct'] ?? -1), $options)
                || (isset($question['item']) && ! isset($paths[$question['item']]))) {
                throw new RuntimeException("O modelo de técnicas {$key} tem pergunta de quiz inválida.");
            }
        }
    }

    private function collectPaths(string $key, array $nodes, string $prefix, array &$paths): void
    {
        $names = [];
        foreach ($nodes as $node) {
            if (! isset($node['name'], $node['kind']) || ! in_array($node['kind'], TechniqueItem::KINDS, true) || isset($names[$node['name']])) {
                throw new RuntimeException("O modelo de técnicas {$key} tem item incompleto ou repetido.");
            }
            $names[$node['name']] = true;
            foreach (self::STUDY_FIELDS as $field) {
                if (isset($node[$field]) && (! is_string($node[$field]) || $node['kind'] !== 'item')) {
                    throw new RuntimeException("O modelo de técnicas {$key} tem material de estudo inválido.");
                }
            }
            if (isset($node['sheet']) && (! is_string($node['sheet']) || $node['kind'] !== 'item' || ! preg_match('/^[a-z0-9-]+\.(jpg|png|webp|pdf)$/', $node['sheet'])
                || ! is_file($this->sheetPath($key, $node['sheet'])))) {
                throw new RuntimeException("O modelo de técnicas {$key} tem ficha ausente: {$node['name']}.");
            }
            $path = $prefix === '' ? $node['name'] : $prefix.' > '.$node['name'];
            $paths[$path] = true;
            $this->collectPaths($key, $node['children'] ?? [], $path, $paths);
        }
    }
}
