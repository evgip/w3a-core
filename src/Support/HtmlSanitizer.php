<?php

declare(strict_types=1);

namespace W3a\Core\Support;

/**
 * Легковесный HTML-санитайзер на базе DOMDocument.
 * 
 * Альтернатива HTML Purifier для случаев, когда не нужна полная спецификация HTML5.
 * Работает по принципу white-list: разрешены только явно указанные теги и атрибуты.
 * 
 * Использование:
 *   $clean = HtmlSanitizer::sanitize($dirty);
 *   $clean = HtmlSanitizer::preset('comment')->sanitize($dirty);
 *   $clean = (new HtmlSanitizer(['p', 'strong']))->sanitize($dirty);
 */
class HtmlSanitizer
{
    /**
     * Пресеты разрешенных тегов для разных типов контента.
     */
    private const PRESETS = [
        // Полный набор для статей (Editor.js)
        'article' => [
            'p', 'br', 'strong', 'em', 'b', 'i', 'u', 's', 'mark', 'sub', 'sup',
            'a', 'code', 'pre', 'blockquote', 'ul', 'ol', 'li',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'figure', 'img', 'figcaption',
        ],
        // Ограниченный набор для комментариев
        'comment' => [
            'p', 'br', 'strong', 'em', 'b', 'i', 'u', 's', 'mark',
            'a', 'code', 'blockquote', 'ul', 'ol', 'li',
        ],
        // Минимальный набор для профилей/подписей
        'minimal' => [
            'strong', 'em', 'b', 'i', 'u', 's', 'a', 'code', 'br',
        ],
        // Только текст (никакого HTML)
        'text_only' => [],
    ];

    /**
     * Разрешенные атрибуты по тегам (глобально для всех пресетов).
     */
    private const ALLOWED_ATTRS = [
        'a'    => ['href', 'title', 'target', 'rel'],
        'img'  => ['src', 'alt', 'width', 'height'],
        'code' => ['class'],
        'pre'  => ['class'],
    ];

    /**
     * Запрещенные протоколы в URL-атрибутах.
     */
    private const FORBIDDEN_PROTOCOLS = ['javascript', 'data', 'vbscript', 'file'];

    private array $allowedTags;
    private array $allowedAttrs;

    public function __construct(?array $allowedTags = null, ?array $allowedAttrs = null)
    {
        $this->allowedTags = $allowedTags ?? self::PRESETS['article'];
        $this->allowedAttrs = $allowedAttrs ?? self::ALLOWED_ATTRS;
    }

    /**
     * Создать санитайзер с предустановленным пресетом.
     */
    public static function preset(string $name): self
    {
        if (!isset(self::PRESETS[$name])) {
            throw new \InvalidArgumentException("Unknown sanitizer preset: {$name}");
        }
        return new self(self::PRESETS[$name]);
    }

    /**
     * Статический шорткат для быстрого использования.
     */
    public static function sanitize(string $html, string $preset = 'article'): string
    {
        return self::preset($preset)->clean($html);
    }

    /**
     * Основная очистка HTML.
     */
    public function clean(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        // Пресет "только текст" — просто strip_tags
        if (empty($this->allowedTags)) {
            return strip_tags($html);
        }

        libxml_use_internal_errors(true);

        $dom = new \DOMDocument('1.0', 'UTF-8');
        // Оборачиваем в мета-тег для корректной обработки UTF-8
        $wrapped = '<?xml encoding="UTF-8"><div>' . $html . '</div>';
        $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        libxml_clear_errors();

        $root = $dom->getElementsByTagName('div')->item(0);
        if ($root) {
            $this->cleanNode($dom, $root);
        }

        $result = '';
        if ($root) {
            foreach ($root->childNodes as $child) {
                $result .= $dom->saveHTML($child);
            }
        }

        return trim($result);
    }

    /**
     * Рекурсивная очистка DOM-узла.
     */
    private function cleanNode(\DOMDocument $dom, \DOMNode $node): void
    {
        $children = iterator_to_array($node->childNodes);

        foreach (array_reverse($children) as $child) {
            if ($child instanceof \DOMElement) {
                $tagName = strtolower($child->tagName);

                // Тег не в белом списке — заменяем его содержимым
                if (!in_array($tagName, $this->allowedTags, true)) {
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }

                $this->cleanAttributes($child, $tagName);
                $this->cleanNode($dom, $child);

            } elseif ($child instanceof \DOMComment) {
                // Удаляем комментарии (в них могут прятать XSS)
                $node->removeChild($child);
            } elseif ($child instanceof \DOMProcessingInstruction) {
                // Удаляем PI (<?...
                $node->removeChild($child);
            }
        }
    }

    /**
     * Очистка атрибутов элемента.
     */
    private function cleanAttributes(\DOMElement $element, string $tagName): void
    {
        $allowedForTag = $this->allowedAttrs[$tagName] ?? [];
        $attrsToRemove = [];

        foreach ($element->attributes as $attr) {
            $attrName = strtolower($attr->name);

            // Удаляем все on*-события
            if (str_starts_with($attrName, 'on')) {
                $attrsToRemove[] = $attr->name;
                continue;
            }

            // Удаляем style (может содержать expression() в старых IE)
            if ($attrName === 'style') {
                $attrsToRemove[] = $attr->name;
                continue;
            }

            // Удаляем атрибуты не из белого списка
            if (!in_array($attrName, $allowedForTag, true)) {
                $attrsToRemove[] = $attr->name;
                continue;
            }

            // Проверка URL-атрибутов
            if ($attrName === 'href' || $attrName === 'src') {
                $value = trim($attr->value);
                // Удаляем пробелы и нулевые байты (обфускация XSS)
                $value = preg_replace('/[\x00-\x1F\x7F]+/', '', $value);
                
                if ($this->isForbiddenUrl($value)) {
                    $attrsToRemove[] = $attr->name;
                    continue;
                }
            }
        }

        foreach ($attrsToRemove as $attrName) {
            $element->removeAttribute($attrName);
        }

        // Безопасность ссылок: rel="noopener noreferrer" + target="_blank"
        if ($tagName === 'a' && $element->hasAttribute('href')) {
            $element->setAttribute('rel', 'noopener noreferrer');
            if (!$element->hasAttribute('target')) {
                $element->setAttribute('target', '_blank');
            }
        }
    }

    /**
     * Проверка URL на запрещенные протоколы.
     */
    private function isForbiddenUrl(string $url): bool
    {
        $url = strtolower(trim($url));
        
        foreach (self::FORBIDDEN_PROTOCOLS as $protocol) {
            // Проверяем с учетом возможных пробелов/табов внутри протокола
            $pattern = '/^\s*' . preg_quote($protocol, '/') . '\s*:/i';
            if (preg_match($pattern, preg_replace('/[\s\x00]+/', '', $url))) {
                return true;
            }
        }
        
        return false;
    }
}