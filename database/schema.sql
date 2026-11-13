-- Gaming Calls Platform - Database Schema
-- MySQL/MariaDB compatible
-- Run this script to create the necessary tables

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Drop tables if they exist (for clean reinstall)
DROP TABLE IF EXISTS `room_participants`;
DROP TABLE IF EXISTS `rooms`;

-- Rooms table
CREATE TABLE `rooms` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `invite_code` VARCHAR(12) NOT NULL,
    `creator_session_id` VARCHAR(64) NOT NULL,
    `max_participants` TINYINT UNSIGNED NOT NULL DEFAULT 10,
    `settings` JSON NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `expires_at` TIMESTAMP NULL DEFAULT NULL,
    `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_invite_code` (`invite_code`),
    INDEX `idx_creator_session` (`creator_session_id`),
    INDEX `idx_active_expires` (`is_active`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Room participants table (for persistent tracking)
CREATE TABLE `room_participants` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `room_id` BIGINT UNSIGNED NOT NULL,
    `session_id` VARCHAR(64) NOT NULL,
    `display_name` VARCHAR(50) NOT NULL,
    `avatar_data` MEDIUMTEXT NULL,
    `joined_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `left_at` TIMESTAMP NULL DEFAULT NULL,
    `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
    `metadata` JSON NULL,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`) ON DELETE CASCADE,
    INDEX `idx_room_active` (`room_id`, `is_active`),
    INDEX `idx_session` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sessions table (cópia das sessões PHP, usada por SessionManager)
CREATE TABLE IF NOT EXISTS `sessions` (
    `id` VARCHAR(128) NOT NULL,
    `data` MEDIUMTEXT NOT NULL,
    `last_activity` INT UNSIGNED NOT NULL,
    `created_at` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_last_activity` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- Insert a test room (optional, for development)
-- INSERT INTO `rooms` (`name`, `invite_code`, `creator_session_id`, `max_participants`) 
-- VALUES ('Test Room', 'TEST123456', 'dev-session-001', 8);