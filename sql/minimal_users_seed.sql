-- Minimal schema to satisfy demo impersonation
-- Database: taskhive

CREATE DATABASE IF NOT EXISTS `taskhive` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `taskhive`;

-- Users table (minimal columns used by the app)
CREATE TABLE IF NOT EXISTS `users` (
  `user_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `user_type` VARCHAR(20) NOT NULL, -- values: client|freelancer|admin
  `profile_picture` VARCHAR(255) DEFAULT NULL,
  `bio` TEXT DEFAULT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'active',
  `avg_rating` DECIMAL(3,2) DEFAULT NULL,
  `total_reviews` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_login_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uniq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed fixed IDs for demo: 1 (freelancer), 2 (client), 5 (admin)
-- Use INSERT ... ON DUPLICATE KEY to be idempotent
INSERT INTO `users` (`user_id`,`first_name`,`last_name`,`email`,`phone`,`password_hash`,`user_type`,`profile_picture`,`bio`,`status`,`avg_rating`,`total_reviews`,`last_login_at`,`created_at`)
VALUES
  (1,'Demo','Freelancer','demo_freelancer@example.com',NULL,'$2y$10$7fPJpQwQ6g2cN8m0x8H8xO7nqzO3s4F0C9HkOt3Kf0Qyq2eH2V2d6','freelancer',NULL,NULL,'active',NULL,0,NULL,NOW())
ON DUPLICATE KEY UPDATE email=VALUES(email), user_type=VALUES(user_type), status=VALUES(status);

INSERT INTO `users` (`user_id`,`first_name`,`last_name`,`email`,`phone`,`password_hash`,`user_type`,`profile_picture`,`bio`,`status`,`avg_rating`,`total_reviews`,`last_login_at`,`created_at`)
VALUES
  (2,'Demo','Client','demo_client@example.com',NULL,'$2y$10$7fPJpQwQ6g2cN8m0x8H8xO7nqzO3s4F0C9HkOt3Kf0Qyq2eH2V2d6','client',NULL,NULL,'active',NULL,0,NULL,NOW())
ON DUPLICATE KEY UPDATE email=VALUES(email), user_type=VALUES(user_type), status=VALUES(status);

INSERT INTO `users` (`user_id`,`first_name`,`last_name`,`email`,`phone`,`password_hash`,`user_type`,`profile_picture`,`bio`,`status`,`avg_rating`,`total_reviews`,`last_login_at`,`created_at`)
VALUES
  (5,'Demo','Admin','demo_admin@example.com',NULL,'$2y$10$7fPJpQwQ6g2cN8m0x8H8xO7nqzO3s4F0C9HkOt3Kf0Qyq2eH2V2d6','admin',NULL,NULL,'active',NULL,0,NULL,NOW())
ON DUPLICATE KEY UPDATE email=VALUES(email), user_type=VALUES(user_type), status=VALUES(status);

-- Optional: bump auto-increment beyond seeded IDs
ALTER TABLE `users` AUTO_INCREMENT = 100;
