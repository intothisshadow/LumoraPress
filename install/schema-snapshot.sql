-- Lumora Press schema snapshot. Generated; do not edit by hand.
-- Regenerate with "PHP Test Suite/generate-schema-snapshot.sh" whenever a migration is added.
-- Through: 0090_add_pinned_media_folders_to_users.sql
-- >>>
CREATE TABLE `{prefix}bluesky_embeds` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `handle` varchar(191) NOT NULL,
  `rkey` varchar(191) NOT NULL,
  `at_uri` varchar(500) DEFAULT NULL,
  `cid` varchar(255) DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_handle_rkey` (`handle`,`rkey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(191) NOT NULL,
  `slug` varchar(191) NOT NULL,
  `description` text DEFAULT NULL,
  `parent_id` int(10) unsigned DEFAULT NULL,
  `menu_order` int(11) NOT NULL DEFAULT 0,
  `image_id` int(10) unsigned DEFAULT NULL,
  `archive_display_mode` varchar(20) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `trashed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_slug` (`slug`),
  KEY `idx_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
INSERT INTO `{prefix}categories` (`id`, `name`, `slug`, `description`, `parent_id`, `menu_order`, `image_id`, `archive_display_mode`, `created_at`, `updated_at`, `trashed_at`) VALUES 
('1', 'Uncategorized', 'uncategorized', '', NULL, '0', NULL, NULL, NOW(), NOW(), NULL)
-- >>>
CREATE TABLE `{prefix}comments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` int(10) unsigned DEFAULT NULL,
  `page_id` int(10) unsigned DEFAULT NULL,
  `parent_id` int(10) unsigned DEFAULT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `guest_name` varchar(191) NOT NULL,
  `guest_email` varchar(191) NOT NULL,
  `guest_url` varchar(255) DEFAULT NULL,
  `content` text NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `edited_at` datetime DEFAULT NULL,
  `followup_notified_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_post_id` (`post_id`),
  KEY `idx_parent_id` (`parent_id`),
  KEY `idx_status` (`status`),
  KEY `idx_ip_created` (`ip_address`,`created_at`),
  KEY `idx_page_id` (`page_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}comment_meta` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `comment_id` int(10) unsigned NOT NULL,
  `meta_key` varchar(191) NOT NULL,
  `meta_value` longtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_comment_key` (`comment_id`,`meta_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}comment_reactions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `comment_id` int(10) unsigned NOT NULL,
  `reaction` varchar(20) NOT NULL,
  `voter_key` varchar(66) NOT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_comment_voter` (`comment_id`,`voter_key`),
  KEY `idx_comment_reaction` (`comment_id`,`reaction`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}comment_reports` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `comment_id` int(10) unsigned NOT NULL,
  `reason` varchar(20) NOT NULL,
  `reporter_key` varchar(66) NOT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_comment_reporter` (`comment_id`,`reporter_key`),
  KEY `idx_comment_id` (`comment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}comment_subscriptions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` int(10) unsigned DEFAULT NULL,
  `page_id` int(10) unsigned DEFAULT NULL,
  `email` varchar(191) NOT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `token` char(64) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `source_comment_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_token` (`token`),
  UNIQUE KEY `uniq_post_email` (`post_id`,`email`),
  UNIQUE KEY `uniq_page_email` (`page_id`,`email`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_source_comment` (`source_comment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}contact_forms` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `recipient_email` varchar(255) NOT NULL,
  `fields` longtext NOT NULL,
  `success_message` varchar(255) NOT NULL DEFAULT 'Thanks for your message!',
  `redirect_url` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}contact_form_submissions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `form_id` int(10) unsigned NOT NULL,
  `data` longtext NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `page_url` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `is_spam` tinyint(1) NOT NULL DEFAULT 0,
  `is_archived` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_form_id` (`form_id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}content_import_records` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `batch_id` varchar(64) NOT NULL,
  `source` varchar(40) NOT NULL,
  `content_type` varchar(20) NOT NULL,
  `content_id` int(10) unsigned NOT NULL,
  `external_id` varchar(191) DEFAULT NULL,
  `snapshot_value` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_batch_id` (`batch_id`),
  KEY `idx_content_type_id` (`content_type`,`content_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}downloads` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `description_format` varchar(10) NOT NULL DEFAULT 'plain',
  `folder_id` int(10) unsigned DEFAULT NULL,
  `category_id` int(10) unsigned DEFAULT NULL,
  `type` enum('file','url') NOT NULL,
  `media_id` int(10) unsigned DEFAULT NULL,
  `media_owned` tinyint(1) NOT NULL DEFAULT 1,
  `thumbnail_media_id` int(10) unsigned DEFAULT NULL,
  `redirect_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `trashed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_folder_id` (`folder_id`),
  KEY `idx_media_id` (`media_id`),
  KEY `idx_redirect_id` (`redirect_id`),
  KEY `idx_category_id` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}download_categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(191) NOT NULL,
  `parent_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `trashed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}download_github_images` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `download_id` int(10) unsigned NOT NULL,
  `repo_path` varchar(255) NOT NULL,
  `blob_sha` char(40) NOT NULL,
  `media_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_download_id` (`download_id`),
  KEY `idx_media_id` (`media_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}download_github_sources` (
  `download_id` int(10) unsigned NOT NULL,
  `owner` varchar(100) NOT NULL,
  `repo` varchar(100) NOT NULL,
  `branch` varchar(255) NOT NULL,
  `title_hash` char(64) NOT NULL,
  `description_hash` char(64) NOT NULL,
  `readme_hash` char(64) NOT NULL,
  `synced_at` datetime NOT NULL,
  PRIMARY KEY (`download_id`),
  KEY `idx_repo` (`owner`,`repo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}enumeration_attempts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `requested_slug` varchar(191) NOT NULL,
  `reason` varchar(30) NOT NULL,
  `attempted_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ip_address` (`ip_address`),
  KEY `idx_attempted_at` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}link_directory_categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(191) NOT NULL,
  `parent_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `trashed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}link_directory_links` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `url` varchar(2048) NOT NULL,
  `description` text DEFAULT NULL,
  `description_format` varchar(20) NOT NULL DEFAULT 'plain',
  `category_id` int(10) unsigned DEFAULT NULL,
  `thumbnail_media_id` int(10) unsigned DEFAULT NULL,
  `button_media_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `trashed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_category_id` (`category_id`),
  KEY `idx_thumbnail_media_id` (`thumbnail_media_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}login_attempts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `username` varchar(60) DEFAULT NULL,
  `attempted_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ip_address` (`ip_address`),
  KEY `idx_attempted_at` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}media` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `file_size` int(10) unsigned NOT NULL,
  `width` int(10) unsigned DEFAULT NULL,
  `height` int(10) unsigned DEFAULT NULL,
  `uploaded_by` int(10) unsigned NOT NULL,
  `folder_id` int(10) unsigned DEFAULT NULL,
  `alt_text` varchar(255) DEFAULT NULL,
  `caption` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `file_hash` varchar(64) DEFAULT NULL,
  `poster_media_id` int(10) unsigned DEFAULT NULL,
  `caption_track_media_id` int(10) unsigned DEFAULT NULL,
  `uploaded_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_uploaded_by` (`uploaded_by`),
  KEY `idx_folder` (`folder_id`),
  KEY `idx_poster_media` (`poster_media_id`),
  KEY `idx_caption_track_media` (`caption_track_media_id`),
  KEY `idx_file_path` (`file_path`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}media_folders` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(191) NOT NULL,
  `parent_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}media_stats` (
  `media_id` int(10) unsigned NOT NULL,
  `downloads` int(10) unsigned NOT NULL DEFAULT 0,
  `last_downloaded_at` datetime DEFAULT NULL,
  PRIMARY KEY (`media_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}media_thumbnails` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `media_id` int(10) unsigned NOT NULL,
  `size_name` varchar(32) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `width` int(10) unsigned NOT NULL,
  `height` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_media_size` (`media_id`,`size_name`),
  KEY `idx_media` (`media_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}notifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `type` varchar(50) NOT NULL,
  `message` varchar(500) NOT NULL,
  `url` varchar(2048) NOT NULL DEFAULT '',
  `badge` varchar(50) DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_read` (`user_id`,`read_at`),
  KEY `idx_user_created` (`user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}options` (
  `option_name` varchar(191) NOT NULL,
  `option_value` longtext DEFAULT NULL,
  PRIMARY KEY (`option_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
INSERT INTO `{prefix}options` (`option_name`, `option_value`) VALUES 
('default_category_id', '1')
-- >>>
CREATE TABLE `{prefix}pages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(191) NOT NULL,
  `meta_title` varchar(255) DEFAULT NULL,
  `slug` varchar(191) NOT NULL,
  `content` longtext NOT NULL,
  `content_format` varchar(10) NOT NULL DEFAULT 'plain',
  `excerpt` text DEFAULT NULL,
  `meta_description` text DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'draft',
  `visibility` varchar(20) NOT NULL DEFAULT 'public',
  `author_id` int(10) unsigned NOT NULL,
  `parent_id` int(10) unsigned DEFAULT NULL,
  `menu_order` int(11) NOT NULL DEFAULT 0,
  `featured_image_id` int(10) unsigned DEFAULT NULL,
  `featured_image_crop` varchar(255) DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `trashed_at` datetime DEFAULT NULL,
  `comment_status` varchar(10) NOT NULL DEFAULT 'open',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_slug` (`slug`),
  KEY `idx_status_published_at` (`status`,`published_at`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_author` (`author_id`),
  FULLTEXT KEY `idx_fulltext_title_content` (`title`,`content`),
  FULLTEXT KEY `idx_fulltext_title` (`title`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}password_resets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `selector` varchar(24) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_selector` (`selector`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}password_reset_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `email` varchar(191) DEFAULT NULL,
  `attempted_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ip_address` (`ip_address`),
  KEY `idx_attempted_at` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}pending_registrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(60) NOT NULL,
  `email` varchar(191) NOT NULL,
  `display_name` varchar(191) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `selector` varchar(24) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'unconfirmed',
  `ip_address` varchar(45) NOT NULL,
  `created_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_selector` (`selector`),
  KEY `idx_email` (`email`),
  KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}posts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(191) NOT NULL,
  `meta_title` varchar(255) DEFAULT NULL,
  `slug` varchar(191) NOT NULL,
  `content` longtext NOT NULL,
  `content_format` varchar(10) NOT NULL DEFAULT 'plain',
  `excerpt` text DEFAULT NULL,
  `meta_description` text DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'draft',
  `visibility` varchar(20) NOT NULL DEFAULT 'public',
  `is_sticky` tinyint(1) NOT NULL DEFAULT 0,
  `author_id` int(10) unsigned NOT NULL,
  `featured_image_id` int(10) unsigned DEFAULT NULL,
  `featured_image_crop` varchar(255) DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  `unpublish_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `trashed_at` datetime DEFAULT NULL,
  `comment_status` varchar(10) NOT NULL DEFAULT 'open',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_slug` (`slug`),
  KEY `idx_status_published_at` (`status`,`published_at`),
  KEY `idx_author` (`author_id`),
  FULLTEXT KEY `idx_fulltext_title_content` (`title`,`content`),
  FULLTEXT KEY `idx_fulltext_title` (`title`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}post_categories` (
  `post_id` int(10) unsigned NOT NULL,
  `category_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`post_id`,`category_id`),
  KEY `idx_category` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}post_meta` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` int(10) unsigned NOT NULL,
  `meta_key` varchar(191) NOT NULL,
  `meta_value` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_post_id` (`post_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}post_tags` (
  `post_id` int(10) unsigned NOT NULL,
  `tag_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`post_id`,`tag_id`),
  KEY `idx_tag` (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}post_views` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` int(10) unsigned NOT NULL,
  `view_date` date NOT NULL,
  `views` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_post_date` (`post_id`,`view_date`),
  KEY `idx_view_date` (`view_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}redirects` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `source_path` varchar(191) NOT NULL,
  `target_url` varchar(500) NOT NULL,
  `status_code` smallint(5) unsigned NOT NULL DEFAULT 301,
  `folder_id` int(10) unsigned DEFAULT NULL,
  `hit_count` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_source_path` (`source_path`),
  KEY `idx_folder_id` (`folder_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}registration_attempts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `email` varchar(191) DEFAULT NULL,
  `attempted_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ip_attempted` (`ip_address`,`attempted_at`),
  KEY `idx_attempted_at` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}remember_tokens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `selector` varchar(24) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_selector` (`selector`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}revisions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `content_type` varchar(10) NOT NULL,
  `content_id` int(10) unsigned NOT NULL,
  `title` varchar(191) NOT NULL,
  `content` longtext NOT NULL,
  `excerpt` text DEFAULT NULL,
  `content_format` varchar(10) NOT NULL DEFAULT 'plain',
  `author_id` int(10) unsigned NOT NULL,
  `is_autosave` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_content` (`content_type`,`content_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}search_queries` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `query` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `searches` int(10) unsigned NOT NULL DEFAULT 0,
  `last_result_count` int(10) unsigned NOT NULL DEFAULT 0,
  `first_searched_at` datetime NOT NULL,
  `last_searched_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_query` (`query`),
  KEY `idx_last_searched` (`last_searched_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}search_terms` (
  `term` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `weight` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`term`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}sessions` (
  `id` varchar(128) NOT NULL,
  `data` mediumtext NOT NULL,
  `last_activity` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_last_activity` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}spam_events` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `source` varchar(20) NOT NULL,
  `comment_id` int(10) unsigned DEFAULT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `author_name` varchar(255) NOT NULL DEFAULT '',
  `author_email` varchar(255) NOT NULL DEFAULT '',
  `ip_address` varchar(45) NOT NULL,
  `spam_score` smallint(5) unsigned NOT NULL DEFAULT 0,
  `triggered_rules` varchar(255) NOT NULL DEFAULT '',
  `action_taken` varchar(20) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_ip_address` (`ip_address`),
  KEY `idx_comment_id` (`comment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}tags` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(191) NOT NULL,
  `slug` varchar(191) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `trashed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}update_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `from_version` varchar(32) NOT NULL,
  `to_version` varchar(32) NOT NULL,
  `source` varchar(20) NOT NULL DEFAULT 'manual',
  `status` varchar(20) NOT NULL,
  `message` text DEFAULT NULL,
  `backup_files_path` varchar(255) DEFAULT NULL,
  `backup_database_path` varchar(255) DEFAULT NULL,
  `performed_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(60) NOT NULL,
  `email` varchar(191) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `display_name` varchar(191) NOT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'subscriber',
  `created_at` datetime NOT NULL,
  `preferred_editor` varchar(20) DEFAULT NULL,
  `trashed_at` datetime DEFAULT NULL,
  `avatar_media_id` int(10) unsigned DEFAULT NULL,
  `last_active_at` datetime DEFAULT NULL,
  `editor_layout_preferences` longtext DEFAULT NULL,
  `theme_preference` varchar(10) DEFAULT NULL,
  `list_view_preferences` longtext DEFAULT NULL,
  `folder_tree_state` longtext DEFAULT NULL,
  `recent_emoji` longtext DEFAULT NULL,
  `recent_searches` longtext DEFAULT NULL,
  `registration_source` varchar(10) NOT NULL DEFAULT 'admin',
  `notification_preferences` text DEFAULT NULL,
  `sidebar_mode` varchar(10) DEFAULT NULL,
  `pinned_media_folders` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_username` (`username`),
  UNIQUE KEY `uniq_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}view_stats_browsers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `view_date` date NOT NULL,
  `browser` varchar(30) NOT NULL,
  `views` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_date_browser` (`view_date`,`browser`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}view_stats_countries` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `view_date` date NOT NULL,
  `country_code` char(2) NOT NULL,
  `views` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_date_country` (`view_date`,`country_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}view_stats_devices` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `view_date` date NOT NULL,
  `device_type` varchar(30) NOT NULL,
  `views` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_date_device` (`view_date`,`device_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- >>>
CREATE TABLE `{prefix}view_stats_referrers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `view_date` date NOT NULL,
  `referrer_domain` varchar(191) NOT NULL,
  `views` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_date_domain` (`view_date`,`referrer_domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
