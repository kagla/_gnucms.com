<?php

declare(strict_types=1);

namespace GnuCms\Modules\YoungCart;

use GnuCms\Cms\HtmlSanitizer;
use GnuCms\Error\DomainError;

final class Settings
{
    public const TYPES = ['hit', 'new', 'recommend', 'discount', 'popular'];
    public const TYPE_LABELS = ['hit' => '히트상품', 'new' => '최신상품', 'recommend' => '추천상품', 'discount' => '할인상품', 'popular' => '인기상품'];
    public const TYPE_COLUMNS = ['hit' => 'is_hit', 'new' => 'is_new', 'recommend' => 'is_recommended', 'discount' => 'is_discount', 'popular' => 'is_popular'];

    private ?array $cache = null;

    public function __construct(private Store $store, private HtmlSanitizer $sanitizer) {}

    public static function defaults(): array
    {
        $block = ['columns' => 4, 'rows' => 1, 'image_width' => 200, 'image_height' => 0];
        $main = [];
        foreach (self::TYPES as $type) $main[$type] = ['use' => $type !== 'popular'] + $block;
        return [
            'main' => $main,
            'category' => ['columns' => 3, 'rows' => 5, 'image_width' => 200, 'image_height' => 0],
            'type' => ['columns' => 4, 'rows' => 5, 'image_width' => 200, 'image_height' => 0],
            'search' => ['columns' => 4, 'rows' => 5, 'image_width' => 200, 'image_height' => 0],
            'related' => ['use' => true, 'columns' => 4, 'image_width' => 100, 'image_height' => 0],
            'detail' => ['image_width' => 400, 'image_height' => 0],
            'show_tax' => false,
            'shipping' => ['content' => ''],
            'exchange' => ['content' => ''],
        ];
    }

    public function all(): array
    {
        if ($this->cache !== null) return $this->cache;
        $row = $this->store->selectOne('SELECT payload FROM ' . $this->store->table('yc_settings') . " WHERE id = 'settings'");
        $saved = $row === null ? [] : json_decode((string) $row['payload'], true, 8, JSON_THROW_ON_ERROR);
        return $this->cache = array_replace_recursive(self::defaults(), is_array($saved) ? $saved : []);
    }

    public function block(string $name): array
    {
        $all = $this->all();
        [$section, $type] = array_pad(explode('.', $name, 2), 2, null);
        return $type === null ? $all[$section] : $all[$section][$type];
    }

    private function flatArray(array $settings): array
    {
        $flat = [];
        foreach (self::TYPES as $type) {
            foreach ($settings['main'][$type] as $key => $value) {
                $flat['main_' . $type . '_' . $key] = $key === 'use' ? ($value ? '1' : '0') : (string) $value;
            }
        }
        foreach (['category', 'type', 'search'] as $section) {
            foreach ($settings[$section] as $key => $value) {
                $flat[$section . '_' . $key] = $key === 'use' ? ($value ? '1' : '0') : (string) $value;
            }
        }
        foreach ($settings['related'] as $key => $value) {
            $flat['related_' . $key] = $key === 'use' ? ($value ? '1' : '0') : (string) $value;
        }
        foreach ($settings['detail'] as $key => $value) {
            $flat['detail_' . $key] = (string) $value;
        }
        $flat['show_tax'] = $settings['show_tax'] ? '1' : '0';
        $flat['shipping_content'] = $settings['shipping']['content'];
        $flat['exchange_content'] = $settings['exchange']['content'];
        return $flat;
    }

    public function save(array $input): array
    {
        $current = $this->all();
        $flatInput = $input + $this->flatArray($current);

        $errors = [];
        $int = static function (string $key, int $min, int $max) use ($flatInput, &$errors): int {
            $value = $flatInput[$key] ?? null;
            if (!is_string($value) && !is_int($value) || !preg_match('/^(0|[1-9][0-9]{0,6})$/D', (string) $value) || (int) $value < $min || (int) $value > $max) {
                $errors[$key] = $min . '~' . $max . ' 사이의 정수를 입력해 주세요.';
                return $min;
            }
            return (int) $value;
        };
        $bool = static fn (string $key): bool => ($flatInput[$key] ?? '') === '1';
        $settings = ['main' => []];
        foreach (self::TYPES as $type) {
            $settings['main'][$type] = ['use' => $bool('main_' . $type . '_use'), 'columns' => $int('main_' . $type . '_columns', 1, 12),
                'rows' => $int('main_' . $type . '_rows', 1, 50), 'image_width' => $int('main_' . $type . '_image_width', 0, 2000),
                'image_height' => $int('main_' . $type . '_image_height', 0, 2000)];
        }
        foreach (['category', 'type', 'search'] as $section) {
            $settings[$section] = ['columns' => $int($section . '_columns', 1, 12), 'rows' => $int($section . '_rows', 1, 50),
                'image_width' => $int($section . '_image_width', 0, 2000), 'image_height' => $int($section . '_image_height', 0, 2000)];
        }
        $settings['related'] = ['use' => $bool('related_use'), 'columns' => $int('related_columns', 1, 12),
            'image_width' => $int('related_image_width', 0, 2000), 'image_height' => $int('related_image_height', 0, 2000)];
        $settings['detail'] = ['image_width' => $int('detail_image_width', 0, 2000), 'image_height' => $int('detail_image_height', 0, 2000)];
        $settings['show_tax'] = $bool('show_tax');
        foreach (['shipping', 'exchange'] as $key) {
            $content = $input[$key . '_content'] ?? '';
            if (!is_string($content) || strlen($content) > 60000) { $errors[$key . '_content'] = '내용이 너무 깁니다.'; $content = ''; }
            $settings[$key] = ['content' => $this->sanitizer->clean($content)];
        }
        if ($errors !== []) throw DomainError::validation($errors);
        $payload = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->store->transaction(function () use ($payload): void {
            if ($this->store->selectOne('SELECT id FROM ' . $this->store->table('yc_settings') . " WHERE id = 'settings'") === null) {
                $this->store->insert('yc_settings', ['id' => 'settings', 'payload' => $payload]);
            } else {
                $this->store->db->update('yc_settings', ['payload' => $payload], 'id = :id', ['id' => 'settings']);
            }
        });
        $this->cache = null;
        return $this->all();
    }
}
