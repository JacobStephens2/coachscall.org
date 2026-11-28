<?php

require_once __DIR__ . '/atomic_write.php';

final class EditablePageText
{
    private const FIELD_LIMIT = 2000;

    private $path;
    private $groups;
    private $fieldsByKey = [];
    private $overrides = [];

    public function __construct(string $path = __DIR__ . '/data/content.json')
    {
        $this->path = $path;
        $this->groups = require __DIR__ . '/content_fields.php';
        foreach ($this->groups as $group) {
            foreach ($group['fields'] as $key => $field) {
                $this->fieldsByKey[$key] = $field;
            }
        }

        if (is_file($this->path) && is_readable($this->path)) {
            $decoded = json_decode((string) @file_get_contents($this->path), true);
            if (is_array($decoded)) {
                $this->overrides = $decoded;
            }
        }
    }

    /** Project the form with any values submitted in a save request. */
    public function editorFields($submitted = null): array
    {
        $groups = [];
        foreach ($this->groups as $group) {
            $fields = [];
            foreach ($group['fields'] as $key => $field) {
                $fields[] = [
                    'key' => $key,
                    'label' => $field['label'],
                    'help' => $field['help'] ?? null,
                    'type' => $field['type'],
                    'limit' => self::FIELD_LIMIT,
                    'value' => $this->submittedValue($submitted, $key) ?? $this->value($key),
                ];
            }
            $groups[] = ['label' => $group['label'], 'fields' => $fields];
        }
        return $groups;
    }

    /** Replace the complete overrides document and refresh this instance on success. */
    public function save($submitted): bool
    {
        $overrides = [];
        foreach ($this->fieldsByKey as $key => $field) {
            $submittedValue = $this->submittedValue($submitted, $key);
            if ($submittedValue === null) {
                continue;
            }
            if (mb_strlen($submittedValue, 'UTF-8') > self::FIELD_LIMIT) {
                $submittedValue = mb_substr($submittedValue, 0, self::FIELD_LIMIT, 'UTF-8');
            }
            $value = trim($submittedValue);
            if ($value !== '' && $value !== $field['default']) {
                $overrides[$key] = $value;
            }
        }
        $json = json_encode($overrides, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || !atomic_write($this->path, $json)) {
            return false;
        }
        $this->overrides = $overrides;
        return true;
    }

    /** Escape for HTML element text and quoted attributes. */
    public function escapedText(string $key): string
    {
        return htmlspecialchars($this->value($key), ENT_QUOTES, 'UTF-8');
    }

    public function lineBreakHtml(string $key): string
    {
        return nl2br($this->escapedText($key), false);
    }

    public function bioHtml(string $key): string
    {
        $parts = explode(' | ', $this->escapedText($key));
        foreach ($parts as $index => $part) {
            if ($index < 2) {
                $parts[$index] = "<b>$part</b>";
            }
        }
        return implode(' | ', $parts);
    }

    private function value(string $key): string
    {
        if (!isset($this->fieldsByKey[$key])) {
            return '';
        }
        $saved = $this->overrides[$key] ?? null;
        return is_string($saved) && $saved !== '' ? $saved : $this->fieldsByKey[$key]['default'];
    }

    private function submittedValue($submitted, string $key): ?string
    {
        $value = is_array($submitted) ? ($submitted[$key] ?? null) : null;
        return is_string($value) ? str_replace("\r\n", "\n", $value) : null;
    }
}
