<?php

declare(strict_types=1);

namespace SafeMigrate\Remap;

final class RemapEngine
{
    /**
     * @param array<string, string> $rules
     */
    public function remapString(string $value, array $rules): string
    {
        if ($value === '' || $rules === []) {
            return $value;
        }

        if (is_serialized($value)) {
            $unserialized = @unserialize($value);

            if ($unserialized !== false || $value === 'b:0;') {
                return serialize($this->remapValue($unserialized, $rules));
            }
        }

        $json = json_decode($value, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            return wp_json_encode($this->remapValue($json, $rules), JSON_UNESCAPED_SLASHES);
        }

        return strtr($value, $rules);
    }

    /**
     * @param array<string, string> $rules
     */
    public function remapValue(mixed $value, array $rules): mixed
    {
        if (is_string($value)) {
            return strtr($value, $rules);
        }

        if (is_array($value)) {
            $mapped = [];

            foreach ($value as $key => $item) {
                $mapped[$this->remapKey($key, $rules)] = $this->remapValue($item, $rules);
            }

            return $mapped;
        }

        if (is_object($value)) {
            foreach ($value as $key => $item) {
                $value->{$key} = $this->remapValue($item, $rules);
            }
        }

        return $value;
    }

    /**
     * @return array<string, string>
     */
    public function rules(string $sourceUrl, string $targetUrl, string $sourcePath, string $targetPath): array
    {
        $rules = [];

        if ($sourceUrl !== '' && $targetUrl !== '' && $sourceUrl !== $targetUrl) {
            $rules[$sourceUrl] = $targetUrl;
            $rules[str_replace(['http://', 'https://'], '', $sourceUrl)] = str_replace(['http://', 'https://'], '', $targetUrl);
        }

        if ($sourcePath !== '' && $targetPath !== '' && $sourcePath !== $targetPath) {
            $rules[wp_normalize_path($sourcePath)] = wp_normalize_path($targetPath);
        }

        return $rules;
    }

    private function remapKey(mixed $key, array $rules): mixed
    {
        return is_string($key) ? strtr($key, $rules) : $key;
    }
}
