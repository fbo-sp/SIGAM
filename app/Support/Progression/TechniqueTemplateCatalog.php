<?php

namespace App\Support\Progression;

use App\Models\TechniqueItem;
use JsonException;
use RuntimeException;

/** Modelos de catálogo de técnicas e currículo mantidos em resources/data/technique-templates.json (ADR-014). */
class TechniqueTemplateCatalog
{
    private ?array $templates = null;

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
            $path = $prefix === '' ? $node['name'] : $prefix.' > '.$node['name'];
            $paths[$path] = true;
            $this->collectPaths($key, $node['children'] ?? [], $path, $paths);
        }
    }
}
