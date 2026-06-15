-- SEO Publisher v2 Migration
-- Run this SQL to upgrade from v1 to v2

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 文章模板表
-- ============================================================
CREATE TABLE IF NOT EXISTS `article_templates` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `article_type` VARCHAR(20) DEFAULT 'short',
    `custom_template` TEXT,
    `model` VARCHAR(50) DEFAULT 'deepseek',
    `api_key` VARCHAR(500) DEFAULT '',
    `api_endpoint` VARCHAR(500) DEFAULT '',
    `export_format` VARCHAR(20) DEFAULT 'html',
    `export_content_type` VARCHAR(20) DEFAULT 'html',
    `title_type` VARCHAR(20) DEFAULT 'original',
    `language` VARCHAR(10) DEFAULT 'zh',
    `sensitive_words` TEXT,
    `ad_paragraph_pos` VARCHAR(30) DEFAULT '',
    `ad_paragraph` TEXT,
    `ad_ending_pos` VARCHAR(30) DEFAULT '',
    `ad_ending` TEXT,
    `image_source` VARCHAR(20) DEFAULT 'none',
    `image_urls` TEXT,
    `image_position` VARCHAR(30) DEFAULT '',
    `image_max_count` INT DEFAULT 2,
    `custom_prompt` TEXT,
    `is_default` TINYINT DEFAULT 0,
    `created_at` DATETIME,
    `updated_at` DATETIME,
    KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 站点发布设置表
-- ============================================================
CREATE TABLE IF NOT EXISTS `site_publish_settings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `site_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `publish_time` VARCHAR(50) DEFAULT '',
    `publish_interval` INT DEFAULT 0,
    `daily_max` INT DEFAULT 0,
    `created_at` DATETIME,
    `updated_at` DATETIME,
    KEY `idx_site_id` (`site_id`),
    KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- articles 表新增字段
-- ============================================================
ALTER TABLE `articles` ADD COLUMN `publish_site_id` INT UNSIGNED DEFAULT NULL AFTER `publish_post_id`;
ALTER TABLE `articles` ADD COLUMN `publish_category_id` VARCHAR(50) DEFAULT NULL AFTER `publish_site_id`;
ALTER TABLE `articles` ADD COLUMN `template_id` INT UNSIGNED DEFAULT NULL AFTER `publish_category_id`;

-- ============================================================
-- keyword_tasks 表新增字段（任务级别绑定）
-- ============================================================
ALTER TABLE `keyword_tasks` ADD COLUMN `bound_site_id` INT UNSIGNED DEFAULT NULL AFTER `status`;
ALTER TABLE `keyword_tasks` ADD COLUMN `bound_category_id` VARCHAR(50) DEFAULT NULL AFTER `bound_site_id`;
ALTER TABLE `keyword_tasks` ADD COLUMN `template_id` INT UNSIGNED DEFAULT NULL AFTER `bound_category_id`;

-- ============================================================
-- keyword_results 表新增字段
-- ============================================================
ALTER TABLE `keyword_results` ADD COLUMN `bound_site_id` INT UNSIGNED DEFAULT NULL AFTER `source`;
ALTER TABLE `keyword_results` ADD COLUMN `bound_category_id` VARCHAR(50) DEFAULT NULL AFTER `bound_site_id`;
ALTER TABLE `keyword_results` ADD COLUMN `template_id` INT UNSIGNED DEFAULT NULL AFTER `bound_category_id`;

-- ============================================================
-- 从 global_config 迁移默认模板数据（可选，首次访问时也会自动创建）
-- ============================================================
-- INSERT INTO article_templates (user_id, name, article_type, custom_template, model, api_key, api_endpoint, export_format, export_content_type, title_type, language, sensitive_words, ad_paragraph_pos, ad_paragraph, ad_ending_pos, ad_ending, image_source, image_urls, image_position, image_max_count, custom_prompt, is_default, created_at, updated_at)
-- SELECT user_id, '默认模板', article_type, custom_template, model, api_key, api_endpoint, export_format, export_content_type, title_type, language, sensitive_words, ad_paragraph_pos, ad_paragraph, ad_ending_pos, ad_ending, image_source, image_urls, image_position, image_max_count, custom_prompt, 1, NOW(), NOW()
-- FROM global_config;

SET FOREIGN_KEY_CHECKS = 1;
