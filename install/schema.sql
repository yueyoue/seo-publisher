-- SEO Publisher 数据库结构
-- 支持 MySQL 5.7+ / 8.0+

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 用户表
CREATE TABLE IF NOT EXISTS `users` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` varchar(50) NOT NULL,
    `email` varchar(100) NOT NULL,
    `password` varchar(255) NOT NULL,
    `role` enum('admin','user') NOT NULL DEFAULT 'user',
    `status` tinyint(1) NOT NULL DEFAULT 1,
    `last_login` datetime DEFAULT NULL,
    `created_at` datetime NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_username` (`username`),
    UNIQUE KEY `uk_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 站点表
CREATE TABLE IF NOT EXISTS `sites` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` int(11) UNSIGNED NOT NULL,
    `name` varchar(100) NOT NULL,
    `site_type` enum('wordpress','empirecms') NOT NULL DEFAULT 'wordpress',
    `bind_type` enum('account','cookie') NOT NULL DEFAULT 'account',
    `domain` varchar(255) NOT NULL,
    `username` varchar(100) NOT NULL,
    `password` varchar(255) NOT NULL,
    `status` tinyint(1) NOT NULL DEFAULT 1,
    `last_publish` datetime DEFAULT NULL,
    `next_publish` datetime DEFAULT NULL,
    `publish_interval` int(11) NOT NULL DEFAULT 3600 COMMENT '发布间隔(秒)',
    `created_at` datetime NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 站点栏目表
CREATE TABLE IF NOT EXISTS `site_categories` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `site_id` int(11) UNSIGNED NOT NULL,
    `category_id` varchar(50) NOT NULL,
    `category_name` varchar(100) NOT NULL,
    `synced_at` datetime NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_site_id` (`site_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 文章表
CREATE TABLE IF NOT EXISTS `articles` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` int(11) UNSIGNED NOT NULL,
    `site_id` int(11) UNSIGNED DEFAULT NULL,
    `category_id` varchar(50) DEFAULT NULL,
    `keyword` varchar(255) NOT NULL,
    `title` varchar(500) NOT NULL,
    `content` text NOT NULL,
    `content_type` enum('html','text') NOT NULL DEFAULT 'html',
    `status` enum('pending','generating','generated','scheduled','publishing','published','failed') NOT NULL DEFAULT 'pending',
    `error_message` text DEFAULT NULL,
    `word_count` int(11) NOT NULL DEFAULT 0,
    `publish_site` varchar(255) DEFAULT NULL,
    `publish_post_id` varchar(50) DEFAULT NULL,
    `publish_site_id` int(11) UNSIGNED DEFAULT NULL,
    `publish_category_id` varchar(50) DEFAULT NULL,
    `template_id` int(11) UNSIGNED DEFAULT NULL,
    `created_at` datetime NOT NULL,
    `published_at` datetime DEFAULT NULL,
    `publish_at` datetime DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 关键词挖掘任务表
CREATE TABLE IF NOT EXISTS `keyword_tasks` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` int(11) UNSIGNED NOT NULL,
    `keyword` varchar(255) NOT NULL,
    `must_contain` varchar(500) DEFAULT NULL,
    `keyword_count` int(11) NOT NULL DEFAULT 50,
    `source_types` varchar(100) NOT NULL DEFAULT 'suggest,related,also_search',
    `found_count` int(11) NOT NULL DEFAULT 0,
    `status` enum('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    `bound_site_id` int(11) UNSIGNED DEFAULT NULL,
    `bound_category_id` varchar(50) DEFAULT NULL,
    `template_id` int(11) UNSIGNED DEFAULT NULL,
    `completed_at` datetime DEFAULT NULL,
    `created_at` datetime NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 关键词结果表
CREATE TABLE IF NOT EXISTS `keyword_results` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `task_id` int(11) UNSIGNED NOT NULL,
    `user_id` int(11) UNSIGNED NOT NULL,
    `keyword` varchar(255) NOT NULL,
    `source` varchar(50) NOT NULL DEFAULT 'baidu',
    `bound_site_id` int(11) UNSIGNED DEFAULT NULL,
    `bound_category_id` varchar(50) DEFAULT NULL,
    `template_id` int(11) UNSIGNED DEFAULT NULL,
    `created_at` datetime NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_task_id` (`task_id`),
    KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 全局配置表
CREATE TABLE IF NOT EXISTS `global_config` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` int(11) UNSIGNED NOT NULL,
    `article_type` enum('short','long','custom') NOT NULL DEFAULT 'short',
    `custom_template` text DEFAULT NULL,
    `model` varchar(50) NOT NULL DEFAULT 'deepseek',
    `api_key` varchar(255) DEFAULT NULL,
    `api_endpoint` varchar(255) DEFAULT NULL COMMENT '自定义API端点',
    `export_format` enum('html','txt','excel') NOT NULL DEFAULT 'html',
    `export_content_type` enum('html','text') NOT NULL DEFAULT 'html',
    `title_type` enum('original','generate','double') NOT NULL DEFAULT 'original',
    `language` enum('zh','en') NOT NULL DEFAULT 'zh',
    `sensitive_words` text DEFAULT NULL,
    `ad_paragraph_pos` enum('before_first','after_first') DEFAULT NULL,
    `ad_paragraph` text DEFAULT NULL,
    `ad_ending_pos` enum('before_last','after_last') DEFAULT NULL,
    `ad_ending` text DEFAULT NULL,
    `image_source` enum('none','web','ai','custom') NOT NULL DEFAULT 'none',
    `image_urls` text DEFAULT NULL,
    `image_position` varchar(50) DEFAULT NULL,
    `image_max_count` int(11) NOT NULL DEFAULT 2,
    `site_id` int(11) UNSIGNED DEFAULT NULL,
    `category_id` varchar(50) DEFAULT NULL,
    `custom_prompt` text DEFAULT NULL,
    `updated_at` datetime NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 套餐表
CREATE TABLE IF NOT EXISTS `packages` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` varchar(50) NOT NULL,
    `price` decimal(10,2) NOT NULL DEFAULT 0,
    `article_limit` int(11) NOT NULL DEFAULT 0,
    `keyword_limit` int(11) NOT NULL DEFAULT 0,
    `description` text DEFAULT NULL,
    `status` tinyint(1) NOT NULL DEFAULT 1,
    `created_at` datetime NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 用户套餐表
CREATE TABLE IF NOT EXISTS `user_packages` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` int(11) UNSIGNED NOT NULL,
    `package_id` int(11) UNSIGNED NOT NULL,
    `articles_used` int(11) NOT NULL DEFAULT 0,
    `keywords_used` int(11) NOT NULL DEFAULT 0,
    `status` enum('active','expired','cancelled') NOT NULL DEFAULT 'active',
    `expire_time` datetime NOT NULL,
    `created_at` datetime NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 订单表
CREATE TABLE IF NOT EXISTS `orders` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_no` varchar(64) NOT NULL,
    `user_id` int(11) UNSIGNED NOT NULL,
    `package_id` int(11) UNSIGNED NOT NULL,
    `amount` decimal(10,2) NOT NULL,
    `status` enum('pending','paid','cancelled','refunded') NOT NULL DEFAULT 'pending',
    `payment_method` varchar(50) DEFAULT NULL,
    `paid_at` datetime DEFAULT NULL,
    `created_at` datetime NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_order_no` (`order_no`),
    KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 发布日志表
CREATE TABLE IF NOT EXISTS `publish_logs` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `site_id` int(11) UNSIGNED NOT NULL,
    `article_id` int(11) UNSIGNED DEFAULT NULL,
    `user_id` int(11) UNSIGNED NOT NULL,
    `action` varchar(50) NOT NULL,
    `status` enum('success','failed') NOT NULL,
    `message` text DEFAULT NULL,
    `created_at` datetime NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_site_id` (`site_id`),
    KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 系统日志表
CREATE TABLE IF NOT EXISTS `logs` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` int(11) UNSIGNED DEFAULT NULL,
    `module` varchar(50) NOT NULL,
    `action` varchar(50) NOT NULL,
    `content` text DEFAULT NULL,
    `ip` varchar(50) DEFAULT NULL,
    `created_at` datetime NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 系统设置表
CREATE TABLE IF NOT EXISTS `settings` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `setting_key` varchar(50) NOT NULL,
    `setting_value` text DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AI模型管理表
CREATE TABLE IF NOT EXISTS `ai_models` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `model_key` varchar(50) NOT NULL COMMENT '模型标识',
    `model_name` varchar(100) NOT NULL COMMENT '模型显示名称',
    `api_endpoint` varchar(500) NOT NULL COMMENT 'API端点地址',
    `model_id` varchar(100) NOT NULL COMMENT '模型ID（如gpt-4o-mini）',
    `is_builtin` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否内置模型',
    `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态 1=启用 0=禁用',
    `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
    `created_at` datetime NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_model_key` (`model_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 默认AI模型数据
INSERT INTO `ai_models` (`model_key`, `model_name`, `api_endpoint`, `model_id`, `is_builtin`, `status`, `sort_order`, `created_at`) VALUES
('deepseek', 'DeepSeek', 'https://api.deepseek.com/v1/chat/completions', 'deepseek-chat', 1, 1, 1, NOW()),
('gpt', 'GPT (OpenAI)', 'https://api.openai.com/v1/chat/completions', 'gpt-4o-mini', 1, 1, 2, NOW()),
('mimo', 'MiMo', 'https://api.xiaomimimo.com/v1/chat/completions', 'mimo-v2-flash', 1, 1, 3, NOW()),
('qwen', '通义千问', 'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions', 'qwen-turbo', 1, 1, 4, NOW()),
('glm', 'ChatGLM (智谱)', 'https://open.bigmodel.cn/api/paas/v4/chat/completions', 'glm-4-flash', 1, 1, 5, NOW()),
('kimi', 'Kimi (月之暗面)', 'https://api.moonshot.cn/v1/chat/completions', 'moonshot-v1-8k', 1, 1, 6, NOW()),
('doubao', '豆包 (字节)', 'https://ark.cn-beijing.volces.com/api/v3/chat/completions', 'doubao-lite-4k', 1, 1, 7, NOW()),
('yi', '零一万物', 'https://api.lingyiwanwu.com/v1/chat/completions', 'yi-lightning', 1, 1, 8, NOW()),
('baichuan', '百川智能', 'https://api.baichuan-ai.com/v1/chat/completions', 'Baichuan4-Turbo', 1, 1, 9, NOW()),
('hunyuan', '腾讯混元', 'https://api.hunyuan.cloud.tencent.com/v1/chat/completions', 'hunyuan-turbo', 1, 1, 10, NOW());

-- 文章模板表
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

-- 站点发布设置表
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

SET FOREIGN_KEY_CHECKS = 1;
