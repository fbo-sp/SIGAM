<?php

namespace App\Support\Sports;

use JsonException;
use RuntimeException;

class RankTemplateCatalog
{
    private ?array $templates = null;

    public function all(): array
    {
        if ($this->templates !== null) {
            return $this->templates;
        }

        try {
            $templates = json_decode(
                file_get_contents(resource_path('data/rank-templates.json')) ?: '',
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('O catálogo de templates de graduação está inválido.', previous: $exception);
        }

        if (! is_array($templates)) {
            throw new RuntimeException('O catálogo de templates de graduação está inválido.');
        }

        foreach ($templates as $key => $template) {
            $this->validateTemplate((string) $key, $template);
        }

        return $this->templates = $templates;
    }

    public function keys(): array
    {
        return array_keys($this->all());
    }

    public function find(?string $key): ?array
    {
        if ($key === null || $key === '') {
            return null;
        }

        return $this->all()[$key] ?? null;
    }

    private function validateTemplate(string $key, mixed $template): void
    {
        if (! is_array($template) || ! isset($template['label'], $template['default_name'], $template['description'], $template['summary'], $template['review_notice'], $template['source_name'], $template['source_url'], $template['ranks']) || ! is_array($template['ranks']) || $template['ranks'] === []) {
            throw new RuntimeException("O template de graduação {$key} está incompleto.");
        }

        $names = [];
        $orders = [];

        foreach ($template['ranks'] as $rank) {
            if (! is_array($rank) || ! isset($rank['name'], $rank['display_name'], $rank['sort_order'], $rank['status'])) {
                throw new RuntimeException("O template de graduação {$key} possui uma graduação incompleta.");
            }

            if (isset($names[$rank['name']]) || isset($orders[$rank['sort_order']])) {
                throw new RuntimeException("O template de graduação {$key} possui nome ou ordem duplicada.");
            }

            $names[$rank['name']] = true;
            $orders[$rank['sort_order']] = true;
        }
    }
}
