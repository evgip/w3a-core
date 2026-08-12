<?php

declare(strict_types=1);

namespace W3a\Core\Support;

/**
 * Легковесный HTML-санитайзер на базе DOMDocument.
 * 
 * Альтернатива HTML Purifier для случаев, когда не нужна полная спецификация HTML5.
 * Работает по принципу white-list: разрешены только явно указанные теги и атрибуты.
 * 
 * Особенности:
 * - Глобальные атрибуты (глобально для всех разрешённых тегов)
 * - Префиксные атрибуты (data-*, aria-* — wildcard)
 * - Специфичные атрибуты по тегам
 * - Несколько готовых пресетов (article, editorjs, comment, minimal, text_only)
 * 
 * Использование:
 *   $clean = HtmlSanitizer::sanitize($dirty);
 *   $clean = HtmlSanitizer::sanitize($dirty, 'editorjs');
 *   $clean = HtmlSanitizer::preset('comment')->clean($dirty);
 *   $clean = (new HtmlSanitizer(['p', 'strong']))->clean($dirty);
 */
class HtmlSanitizer
{
    /**
     * Пресеты разрешенных тегов для разных типов контента.
     */
    private const PRESETS = [
        // Базовый набор для статей
        'article' => [
            'p', 'br', 'strong', 'em', 'b', 'i', 'u', 's', 'mark', 'sub', 'sup',
            'a', 'code', 'pre', 'blockquote', 'ul', 'ol', 'li', 'footer',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'figure', 'img', 'figcaption',
        ],
        // Расширенный набор для Editor.js контента (статьи)
        // Включает picture/source для адаптивных изображений,
        // div/button/svg для интерактивных элементов (lightbox и др.)
        'editorjs' => [
            // Текстовые блоки
            'p', 'br', 'strong', 'em', 'b', 'i', 'u', 's', 'del', 'mark', 'sub', 'sup',
            'a', 'code', 'pre', 'blockquote', 'ul', 'ol', 'li', 'footer',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            // Изображения и адаптивные источники
            'figure', 'figcaption', 'img', 'picture', 'source',
            // Интерактивные элементы (lightbox и др.)
            'div', 'button', 'span',
            // SVG и его примитивы
            'svg', 'circle', 'line', 'path', 'rect', 'g', 'polyline', 'polygon',
        ],
        // Ограниченный набор для комментариев (Markdown)
        'comment' => [
            'p', 'br', 'strong', 'em', 'b', 'i', 'u', 's', 'mark',
            'a', 'code', 'pre', 'blockquote', 'ul', 'ol', 'li',
        ],
        // Минимальный набор для профилей/подписей
        'minimal' => [
            'strong', 'em', 'b', 'i', 'u', 's', 'a', 'code', 'br',
        ],
        // Только текст (никакого HTML)
        'text_only' => [],
    ];

    /**
     * Глобальные атрибуты (разрешены для ВСЕХ тегов из белого списка).
     * 
     * Типичные глобальные атрибуты:
     * - class: для стилизации
     * 
     * Для data-* и aria-* используйте self::PREFIXED_ATTRS.
     */
    private const GLOBAL_ATTRS = [
        'class',
    ];

    /**
     * Префиксные атрибуты (wildcard-сопоставление).
     * 
     * Любое имя атрибута, начинающееся с указанного префикса,
     * считается разрешённым для всех тегов.
     * 
     * Пример:
     * - 'data-' разрешает data-block-index, data-user-id, data-anything
     * - 'aria-' разрешает aria-label, aria-hidden, aria-expanded
     */
    private const PREFIXED_ATTRS = [
        'data-',
        'aria-',
    ];

    /**
     * Специфичные атрибуты по тегам.
     * 
     * Дополняют глобальные и префиксные атрибуты.
     * Используются когда атрибут специфичен для конкретного тега.
     */
    private const TAG_SPECIFIC_ATTRS = [
        'a'      => ['href', 'title', 'target', 'rel'],
        'img'    => ['src', 'alt', 'width', 'height', 'loading', 'decoding', 'srcset', 'sizes'],
        'source' => ['srcset', 'type', 'media', 'sizes'],
        'button' => ['type'],
        'svg'    => ['viewBox', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 
                     'stroke-linejoin', 'width', 'height', 'xmlns'],
        'circle' => ['cx', 'cy', 'r', 'fill', 'stroke', 'stroke-width'],
        'line'   => ['x1', 'y1', 'x2', 'y2', 'stroke', 'stroke-width'],
        'path'   => ['d', 'fill', 'stroke', 'stroke-width'],
        'rect'   => ['x', 'y', 'width', 'height', 'fill', 'stroke', 'stroke-width'],
        'g'      => ['fill', 'stroke', 'stroke-width'],
    ];

    /**
     * Запрещенные протоколы в URL-атрибутах.
     */
    private const FORBIDDEN_PROTOCOLS = ['javascript', 'data', 'vbscript', 'file'];

    private array $allowedTags;
    private array $globalAttrs;
    private array $prefixedAttrs;
    private array $tagSpecificAttrs;

    public function __construct(
        ?array $allowedTags = null,
        ?array $globalAttrs = null,
        ?array $prefixedAttrs = null,
        ?array $tagSpecificAttrs = null
    ) {
        $this->allowedTags = $allowedTags ?? self::PRESETS['article'];
        $this->globalAttrs = $globalAttrs ?? self::GLOBAL_ATTRS;
        $this->prefixedAttrs = $prefixedAttrs ?? self::PREFIXED_ATTRS;
        $this->tagSpecificAttrs = $tagSpecificAttrs ?? self::TAG_SPECIFIC_ATTRS;
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
	 * Проверяет, разрешён ли атрибут для данного тега.
	 * 
	 * Проверка идёт в три этапа:
	 * 1. Глобальные атрибуты (class и подобные)
	 * 2. Префиксные атрибуты (data-*, aria-*)
	 * 3. Специфичные атрибуты тега (href, src, viewBox и т.д.)
	 * 
	 * ВАЖНО: сравнение идёт без учёта регистра, т.к. DOM нормализует
	 * имена атрибутов в нижний регистр, а SVG-атрибуты в HTML пишутся
	 * в camelCase (viewBox, preserveAspectRatio).
	 */
	private function isAttributeAllowed(string $attrName, string $tagName): bool
	{
		// Нормализуем к нижнему регистру для сравнения
		$attrNameLower = strtolower($attrName);

		// 1. Глобальные атрибуты
		foreach ($this->globalAttrs as $allowed) {
			if (strtolower($allowed) === $attrNameLower) {
				return true;
			}
		}

		// 2. Префиксные атрибуты (wildcard)
		foreach ($this->prefixedAttrs as $prefix) {
			if (str_starts_with($attrNameLower, strtolower($prefix))) {
				return true;
			}
		}

		// 3. Специфичные атрибуты тега (без учёта регистра)
		$allowedForTag = $this->tagSpecificAttrs[$tagName] ?? [];
		foreach ($allowedForTag as $allowed) {
			if (strtolower($allowed) === $attrNameLower) {
				return true;
			}
		}

		return false;
	}

    /**
     * Очистка атрибутов элемента.
     */
    private function cleanAttributes(\DOMElement $element, string $tagName): void
    {
        $attrsToRemove = [];

        foreach ($element->attributes as $attr) {
            $attrName = strtolower($attr->name);

            // Удаляем все on*-события (XSS-защита)
            if (str_starts_with($attrName, 'on')) {
                $attrsToRemove[] = $attr->name;
                continue;
            }

            // Удаляем style (может содержать expression() в старых IE)
            if ($attrName === 'style') {
                $attrsToRemove[] = $attr->name;
                continue;
            }

            // Проверяем по whitelist
            if (!$this->isAttributeAllowed($attrName, $tagName)) {
                $attrsToRemove[] = $attr->name;
                continue;
            }

            // Проверка URL-атрибутов
            if ($attrName === 'href' || $attrName === 'src' || $attrName === 'srcset') {
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
     * 
     * Для srcset (список URL через запятую) проверяем каждый URL отдельно.
     */
    private function isForbiddenUrl(string $url): bool
    {
        // srcset содержит несколько URL — проверяем каждый
        if (str_contains($url, ',')) {
            $parts = explode(',', $url);
            foreach ($parts as $part) {
                // В srcset каждый элемент: "url 800w" — берём только URL
                $urlPart = trim(explode(' ', trim($part))[0] ?? '');
                if ($urlPart !== '' && $this->checkSingleUrl($urlPart)) {
                    return true;
                }
            }
            return false;
        }

        return $this->checkSingleUrl($url);
    }

    /**
     * Проверка одиночного URL на запрещённые протоколы.
     */
    private function checkSingleUrl(string $url): bool
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