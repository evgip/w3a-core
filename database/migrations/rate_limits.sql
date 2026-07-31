-- ============================================================================
-- w3a-core: Таблицы rate limiting (опциональные)
-- ============================================================================
-- Установка: php bin/w3a auth:install --with-rate-limits
-- ============================================================================

CREATE TABLE IF NOT EXISTS `rate_limits` (
    `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
    `identifier` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `endpoint_action` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `window_start` timestamp NOT NULL,
    `request_count` int UNSIGNED NOT NULL DEFAULT '1',
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_rate_limit` (`identifier`, `endpoint_action`, `window_start`),
    KEY `idx_cleanup` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;