-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 05, 2025 at 01:03 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `taskhive`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin_action_logs`
--

CREATE TABLE `admin_action_logs` (
  `log_id` bigint(20) UNSIGNED NOT NULL,
  `admin_id` bigint(20) UNSIGNED NOT NULL,
  `action` varchar(60) NOT NULL,
  `target_type` enum('user','service','review','booking','dispute','payment','notification','category','report') NOT NULL,
  `target_id` bigint(20) UNSIGNED NOT NULL,
  `changes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`changes`)),
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admin_action_logs`
--

INSERT INTO `admin_action_logs` (`log_id`, `admin_id`, `action`, `target_type`, `target_id`, `changes`, `ip`, `user_agent`, `created_at`) VALUES
(1, 5, 'service_approve', 'service', 10, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36 Edg/140.0.0.0', '2025-10-01 08:25:02'),
(2, 5, 'service_reject', 'service', 11, '{\"reason\":\"aba naman\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36 Edg/140.0.0.0', '2025-10-01 08:25:45'),
(3, 5, 'service_reject', 'service', 11, '{\"reason\":\"\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36 Edg/140.0.0.0', '2025-10-01 08:25:48'),
(4, 5, 'service_reject', 'service', 11, '{\"reason\":\"\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36 Edg/140.0.0.0', '2025-10-01 08:25:54'),
(5, 5, 'service_reject', 'service', 11, '{\"reason\":\"\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36 Edg/140.0.0.0', '2025-10-01 08:25:55'),
(6, 5, 'service_reject', 'service', 11, '{\"reason\":\"\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36 Edg/140.0.0.0', '2025-10-01 08:25:56'),
(7, 5, 'service_reject', 'service', 11, '{\"reason\":\"\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36 Edg/140.0.0.0', '2025-10-01 08:25:56'),
(8, 5, 'service_reject', 'service', 11, '{\"reason\":\"\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36 Edg/140.0.0.0', '2025-10-01 08:25:56'),
(9, 5, 'service_reject', 'service', 11, '{\"reason\":\"\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36 Edg/140.0.0.0', '2025-10-01 08:25:56'),
(10, 5, 'service_reject', 'service', 11, '{\"reason\":\"\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36 Edg/140.0.0.0', '2025-10-01 08:26:01'),
(11, 5, 'service_approve', 'service', 11, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36 Edg/140.0.0.0', '2025-10-01 08:26:04');

-- --------------------------------------------------------

--
-- Table structure for table `bookings`
--

CREATE TABLE `bookings` (
  `booking_id` bigint(20) UNSIGNED NOT NULL,
  `service_id` bigint(20) UNSIGNED NOT NULL,
  `client_id` bigint(20) UNSIGNED NOT NULL,
  `freelancer_id` bigint(20) UNSIGNED NOT NULL,
  `title_snapshot` varchar(160) DEFAULT NULL,
  `description_snapshot` text DEFAULT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `subtotal` decimal(10,2) GENERATED ALWAYS AS (`unit_price` * `quantity`) STORED,
  `platform_fee` decimal(10,2) DEFAULT 0.00,
  `total_amount` decimal(10,2) NOT NULL,
  `currency` char(3) DEFAULT 'PHP',
  `scheduled_start` datetime DEFAULT NULL,
  `scheduled_end` datetime DEFAULT NULL,
  `status` enum('pending','accepted','rejected','in_progress','delivered','completed','cancelled','disputed','refunded') DEFAULT 'pending',
  `payment_status` enum('unpaid','escrowed','released','refunded','partial') DEFAULT 'unpaid',
  `payment_method` enum('advance','downpayment','postpaid') DEFAULT NULL,
  `payment_terms_status` enum('none','proposed','accepted','rejected') DEFAULT 'none',
  `downpayment_percent` decimal(5,2) DEFAULT 50.00,
  `paid_upfront_amount` decimal(10,2) DEFAULT 0.00,
  `total_paid_amount` decimal(10,2) DEFAULT 0.00,
  `escrow_status` enum('none','holding','released','partial','refunded') DEFAULT 'none',
  `client_notes` text DEFAULT NULL,
  `freelancer_notes` text DEFAULT NULL,
  `accepted_at` datetime DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancellation_reason` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `balance_due` decimal(10,2) GENERATED ALWAYS AS (greatest(`total_amount` - `total_paid_amount`,0)) STORED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bookings`
--

INSERT INTO `bookings` (`booking_id`, `service_id`, `client_id`, `freelancer_id`, `title_snapshot`, `description_snapshot`, `unit_price`, `quantity`, `platform_fee`, `total_amount`, `currency`, `scheduled_start`, `scheduled_end`, `status`, `payment_status`, `payment_method`, `payment_terms_status`, `downpayment_percent`, `paid_upfront_amount`, `total_paid_amount`, `escrow_status`, `client_notes`, `freelancer_notes`, `accepted_at`, `delivered_at`, `completed_at`, `cancelled_at`, `cancellation_reason`, `created_at`, `updated_at`) VALUES
(1, 2, 2, 1, 'Web Development', 'make u ur own wibsite', 1000.00, 1, 100.00, 1100.00, 'PHP', '2025-09-25 08:13:00', '2025-09-25 20:13:00', 'completed', 'unpaid', NULL, 'none', 50.00, 0.00, 0.00, 'none', NULL, NULL, '2025-09-25 08:14:16', '2025-09-25 08:46:10', '2025-09-25 08:46:16', NULL, NULL, '2025-09-25 08:14:00', '2025-09-25 08:46:16'),
(10, 3, 2, 1, 'VALO BOOSTING SERVICE', 'gold to bronze real quick', 50.00, 1, 5.00, 55.00, 'PHP', '2025-09-25 08:59:00', '2025-09-25 20:59:00', 'completed', 'unpaid', NULL, 'none', 50.00, 0.00, 0.00, 'none', NULL, NULL, '2025-09-25 09:00:07', '2025-09-25 09:00:13', '2025-09-25 09:00:16', NULL, NULL, '2025-09-25 08:59:39', '2025-09-25 09:00:16'),
(11, 1, 2, 1, 'Babysitting', 'babysitting sbabies', 500.00, 1, 50.00, 550.00, 'PHP', '2025-09-30 09:00:00', '2025-09-30 22:00:00', 'cancelled', 'unpaid', NULL, 'none', 50.00, 0.00, 0.00, 'none', NULL, NULL, NULL, NULL, NULL, '2025-09-25 09:00:50', NULL, '2025-09-25 09:00:47', '2025-09-25 09:00:50'),
(12, 2, 2, 1, 'Web Development', 'make u ur own wibsite', 1000.00, 1, 100.00, 1100.00, 'PHP', '2025-09-25 09:35:00', '2025-09-26 22:36:00', 'completed', 'unpaid', NULL, 'none', 50.00, 0.00, 0.00, 'none', NULL, NULL, '2025-09-25 21:38:05', '2025-09-25 22:43:05', '2025-09-25 23:10:03', NULL, NULL, '2025-09-25 21:36:10', '2025-09-25 23:10:03'),
(13, 1, 2, 1, 'Babysitting', 'babysitting sbabies', 500.00, 1, 50.00, 550.00, 'PHP', '2025-09-27 09:36:00', '2025-09-28 21:36:00', 'rejected', 'unpaid', NULL, 'none', 50.00, 0.00, 0.00, 'none', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-09-25 21:36:38', '2025-09-25 21:38:01'),
(14, 5, 2, 3, 'UI/UX Design', 'ako magdedesign ng ui niyo', 2500.00, 1, 0.00, 2500.00, 'PHP', '2025-09-30 23:06:00', '2025-10-01 11:06:00', 'completed', 'released', 'advance', 'accepted', 50.00, 2500.00, 2500.00, 'released', NULL, NULL, '2025-09-26 03:35:38', '2025-09-26 04:06:42', '2025-09-26 04:06:46', NULL, NULL, '2025-09-25 23:06:38', '2025-09-26 04:06:46'),
(15, 6, 2, 3, 'Valo Deranking Service', 'dia to bronze easy game ggs', 100.00, 1, 0.00, 100.00, 'PHP', '2025-09-25 11:29:00', '2025-09-26 23:29:00', 'completed', 'released', 'advance', 'accepted', 50.00, 100.00, 100.00, 'released', NULL, NULL, '2025-09-26 03:35:35', '2025-09-26 04:07:12', '2025-09-26 04:07:20', NULL, NULL, '2025-09-25 23:29:48', '2025-09-26 04:07:20'),
(16, 7, 2, 3, 'Deliver', 'call me para makapagdeliver', 60.00, 1, 0.00, 60.00, 'PHP', '2025-09-26 03:54:00', '2025-09-26 15:54:00', 'completed', 'unpaid', NULL, 'none', 50.00, 0.00, 0.00, 'none', NULL, NULL, '2025-09-26 03:54:47', '2025-09-26 04:08:35', '2025-09-26 04:08:49', NULL, NULL, '2025-09-26 03:54:29', '2025-09-26 04:08:49'),
(17, 3, 4, 1, 'VALO BOOSTING SERVICE', 'gold to bronze real quick', 50.00, 1, 0.00, 50.00, 'PHP', '2025-09-27 05:36:00', '2025-09-27 17:36:00', 'rejected', 'unpaid', NULL, 'none', 50.00, 0.00, 0.00, 'none', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-09-27 05:36:58', '2025-09-27 05:39:20'),
(18, 2, 4, 1, 'Web Development', 'make u ur own wibsite', 1000.00, 1, 0.00, 1000.00, 'PHP', '2025-09-27 05:37:00', '2025-10-03 17:37:00', 'completed', 'released', 'advance', 'accepted', 50.00, 1000.00, 1000.00, 'released', NULL, NULL, '2025-09-27 05:39:18', '2025-09-27 05:41:25', '2025-09-27 07:50:10', NULL, NULL, '2025-09-27 05:37:13', '2025-09-27 07:50:10'),
(19, 3, 4, 1, 'VALO BOOSTING SERVICE', 'gold to bronze real quick', 50.00, 1, 0.00, 50.00, 'PHP', '2025-09-27 07:50:00', '2025-09-27 19:50:00', 'rejected', 'escrowed', 'downpayment', 'accepted', 50.00, 25.00, 50.00, 'holding', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-09-27 07:50:26', '2025-10-01 07:39:32'),
(20, 8, 2, 3, 'Web Development', 'kaya ko webdev', 1500.00, 3, 0.00, 4500.00, 'PHP', '2025-10-01 03:33:00', '2025-10-03 03:33:00', 'pending', 'unpaid', NULL, 'none', 50.00, 0.00, 0.00, 'none', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-10-01 03:33:52', '2025-10-01 03:33:52'),
(21, 3, 2, 1, 'VALO BOOSTING SERVICE', 'gold to bronze real quick', 50.00, 3, 0.00, 150.00, 'PHP', '2025-10-03 03:37:00', '2025-10-06 03:37:00', 'completed', 'released', 'downpayment', 'accepted', 50.00, 75.00, 150.00, 'released', NULL, NULL, '2025-10-01 03:58:25', '2025-10-01 03:58:30', '2025-10-01 05:39:06', NULL, NULL, '2025-10-01 03:37:19', '2025-10-01 05:39:06');

-- --------------------------------------------------------

--
-- Table structure for table `commissions`
--

CREATE TABLE `commissions` (
  `commission_id` bigint(20) UNSIGNED NOT NULL,
  `booking_id` bigint(20) UNSIGNED NOT NULL,
  `percentage` decimal(5,2) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `commissions`
--

INSERT INTO `commissions` (`commission_id`, `booking_id`, `percentage`, `amount`, `created_at`) VALUES
(1, 14, 7.00, 175.00, '2025-09-26 04:06:46'),
(2, 15, 7.00, 7.00, '2025-09-26 04:07:20'),
(3, 18, 7.00, 70.00, '2025-09-27 07:50:10'),
(4, 21, 7.00, 10.50, '2025-10-01 05:39:06');

-- --------------------------------------------------------

--
-- Table structure for table `conversations`
--

CREATE TABLE `conversations` (
  `conversation_id` bigint(20) UNSIGNED NOT NULL,
  `conversation_type` enum('general','booking') NOT NULL DEFAULT 'general',
  `booking_id` bigint(20) UNSIGNED DEFAULT NULL,
  `client_id` bigint(20) UNSIGNED NOT NULL,
  `freelancer_id` bigint(20) UNSIGNED NOT NULL,
  `last_message_id` bigint(20) UNSIGNED DEFAULT NULL,
  `last_message_at` datetime DEFAULT NULL,
  `status` enum('open','closed','archived') DEFAULT 'open',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `conversations`
--

INSERT INTO `conversations` (`conversation_id`, `conversation_type`, `booking_id`, `client_id`, `freelancer_id`, `last_message_id`, `last_message_at`, `status`, `created_at`, `updated_at`) VALUES
(1, 'general', NULL, 2, 1, 112, '2025-10-01 05:39:06', 'open', '2025-09-25 07:42:03', '2025-10-01 05:39:06'),
(2, 'booking', 1, 2, 1, 14, '2025-09-25 08:46:16', 'open', '2025-09-25 08:14:00', '2025-09-25 08:46:16'),
(11, 'booking', 10, 2, 1, 19, '2025-09-25 09:00:16', 'open', '2025-09-25 08:59:39', '2025-09-25 09:00:16'),
(12, 'booking', 11, 2, 1, 21, '2025-09-25 09:00:50', 'open', '2025-09-25 09:00:47', '2025-09-25 09:00:50'),
(13, 'booking', 12, 2, 1, 80, '2025-10-01 03:32:18', 'open', '2025-09-25 21:36:10', '2025-10-01 03:32:18'),
(14, 'booking', 13, 2, 1, 24, '2025-09-25 21:38:01', 'open', '2025-09-25 21:36:38', '2025-09-25 21:38:01'),
(15, 'general', NULL, 1, 3, NULL, NULL, 'open', '2025-09-25 22:18:38', '2025-09-25 22:18:38'),
(16, 'booking', 14, 2, 3, 49, '2025-09-26 04:06:46', 'open', '2025-09-25 23:06:38', '2025-09-26 04:06:46'),
(17, 'booking', 15, 2, 3, 53, '2025-09-26 04:07:20', 'open', '2025-09-25 23:29:48', '2025-09-26 04:07:20'),
(18, 'booking', 16, 2, 3, 57, '2025-09-27 03:48:15', 'open', '2025-09-26 03:54:29', '2025-09-27 03:48:15'),
(19, 'general', NULL, 2, 3, 81, '2025-10-01 03:33:52', 'open', '2025-09-26 03:59:25', '2025-10-01 03:33:52'),
(20, 'general', NULL, 4, 1, 113, '2025-10-01 07:39:32', 'open', '2025-09-27 05:36:58', '2025-10-01 07:39:32');

-- --------------------------------------------------------

--
-- Table structure for table `disputes`
--

CREATE TABLE `disputes` (
  `dispute_id` bigint(20) UNSIGNED NOT NULL,
  `booking_id` bigint(20) UNSIGNED NOT NULL,
  `raised_by_id` bigint(20) UNSIGNED NOT NULL,
  `against_id` bigint(20) UNSIGNED NOT NULL,
  `reason_code` varchar(60) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `status` enum('open','under_review','resolved','rejected') DEFAULT 'open',
  `resolution` text DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dispute_events`
--

CREATE TABLE `dispute_events` (
  `event_id` bigint(20) UNSIGNED NOT NULL,
  `dispute_id` bigint(20) UNSIGNED NOT NULL,
  `actor_id` bigint(20) UNSIGNED DEFAULT NULL,
  `action` varchar(80) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `freelancer_payment_methods`
--

CREATE TABLE `freelancer_payment_methods` (
  `method_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `method_type` enum('gcash','paymaya') NOT NULL,
  `display_label` varchar(100) NOT NULL,
  `account_name` varchar(120) DEFAULT NULL,
  `account_number` varchar(120) DEFAULT NULL,
  `bank_name` varchar(120) DEFAULT NULL,
  `qr_code_url` varchar(255) DEFAULT NULL,
  `extra_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`extra_json`)),
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `freelancer_payment_methods`
--

INSERT INTO `freelancer_payment_methods` (`method_id`, `user_id`, `method_type`, `display_label`, `account_name`, `account_number`, `bank_name`, `qr_code_url`, `extra_json`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 3, 'gcash', 'Gcash Payment', 'Cxyris Tan', '096030708009', '', 'uploads/1758861146_qr_992e8a.png', NULL, 1, '2025-09-25 23:32:26', '2025-09-25 23:32:39'),
(3, 1, 'gcash', 'Gcash Payment', 'Cxyris Tan', '09603070809', NULL, 'uploads/1758969632_qr_9239fa.png', NULL, 1, '2025-09-27 05:40:32', '2025-09-27 05:40:32');

-- --------------------------------------------------------

--
-- Table structure for table `freelancer_profiles`
--

CREATE TABLE `freelancer_profiles` (
  `freelancer_profile_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `skills` text DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `hourly_rate` decimal(10,2) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `freelancer_profiles`
--

INSERT INTO `freelancer_profiles` (`freelancer_profile_id`, `user_id`, `skills`, `address`, `hourly_rate`, `created_at`, `updated_at`) VALUES
(1, 1, '', NULL, NULL, '2025-09-25 07:09:04', '2025-09-25 07:09:04'),
(2, 3, 'Tutoring, Deliver, UI/UX Designer', 'Malvar', 10000.00, '2025-09-25 21:58:30', '2025-09-25 21:58:30');

-- --------------------------------------------------------

--
-- Table structure for table `messages`
--

CREATE TABLE `messages` (
  `message_id` bigint(20) UNSIGNED NOT NULL,
  `conversation_id` bigint(20) UNSIGNED NOT NULL,
  `sender_id` bigint(20) UNSIGNED NOT NULL,
  `booking_id` bigint(20) UNSIGNED DEFAULT NULL,
  `body` text DEFAULT NULL,
  `message_type` enum('text','system','file') DEFAULT 'text',
  `is_flagged` tinyint(1) NOT NULL DEFAULT 0,
  `flagged_reason` varchar(255) DEFAULT NULL,
  `flagged_at` datetime DEFAULT NULL,
  `attachments` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`attachments`)),
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `messages`
--

INSERT INTO `messages` (`message_id`, `conversation_id`, `sender_id`, `booking_id`, `body`, `message_type`, `is_flagged`, `flagged_reason`, `flagged_at`, `attachments`, `read_at`, `created_at`) VALUES
(1, 1, 2, NULL, 'hello', 'text', 0, NULL, NULL, NULL, '2025-09-25 07:59:32', '2025-09-25 07:42:07'),
(2, 1, 1, NULL, 'hi', 'text', 0, NULL, NULL, NULL, '2025-09-25 08:00:10', '2025-09-25 07:59:34'),
(3, 1, 2, NULL, 'how are you', 'text', 0, NULL, NULL, NULL, '2025-09-25 08:01:40', '2025-09-25 08:00:12'),
(4, 1, 2, NULL, 'hello po', 'text', 0, NULL, NULL, NULL, '2025-09-25 08:01:40', '2025-09-25 08:01:22'),
(5, 1, 1, NULL, 'paorder nga po', 'text', 0, NULL, NULL, NULL, '2025-09-25 08:03:48', '2025-09-25 08:01:52'),
(6, 2, 2, 1, 'New booking #1 created for service \'Web Development\' (Qty: 1). Awaiting freelancer confirmation.', 'system', 0, NULL, NULL, NULL, '2025-09-25 08:14:27', '2025-09-25 08:14:00'),
(7, 2, 1, 1, 'Freelancer has ACCEPTED booking #1.', 'system', 0, NULL, NULL, NULL, '2025-09-25 08:14:53', '2025-09-25 08:14:16'),
(8, 2, 1, NULL, 'hi!', 'text', 0, NULL, NULL, NULL, '2025-09-25 08:14:53', '2025-09-25 08:14:36'),
(9, 1, 2, NULL, 'hello po ako po si cxyris tan', 'text', 0, NULL, NULL, NULL, '2025-09-25 08:27:29', '2025-09-25 08:26:45'),
(10, 1, 1, NULL, 'hello ako rin si cxyristan', 'text', 0, NULL, NULL, NULL, '2025-09-25 08:29:35', '2025-09-25 08:27:34'),
(11, 2, 2, NULL, 'hello', 'text', 0, NULL, NULL, NULL, '2025-09-25 08:46:20', '2025-09-25 08:30:43'),
(12, 2, 1, 1, 'Freelancer performed action \'start\'. Booking now \'in_progress\'.', 'system', 0, NULL, NULL, NULL, '2025-09-25 08:47:26', '2025-09-25 08:46:07'),
(13, 2, 1, 1, 'Freelancer performed action \'deliver\'. Booking now \'delivered\'.', 'system', 0, NULL, NULL, NULL, '2025-09-25 08:47:26', '2025-09-25 08:46:11'),
(14, 2, 1, 1, 'Freelancer performed action \'complete\'. Booking now \'completed\'.', 'system', 0, NULL, NULL, NULL, '2025-09-25 08:47:26', '2025-09-25 08:46:16'),
(15, 11, 2, 10, 'New booking #10 created for service \'VALO BOOSTING SERVICE\' (Qty: 1). Awaiting freelancer confirmation.', 'system', 0, NULL, NULL, NULL, '2025-09-25 08:59:58', '2025-09-25 08:59:39'),
(16, 11, 1, 10, 'Freelancer performed action \'accept\'. Booking now \'accepted\'.', 'system', 0, NULL, NULL, NULL, '2025-09-25 09:00:31', '2025-09-25 09:00:07'),
(17, 11, 1, 10, 'Freelancer performed action \'start\'. Booking now \'in_progress\'.', 'system', 0, NULL, NULL, NULL, '2025-09-25 09:00:31', '2025-09-25 09:00:11'),
(18, 11, 1, 10, 'Freelancer performed action \'deliver\'. Booking now \'delivered\'.', 'system', 0, NULL, NULL, NULL, '2025-09-25 09:00:31', '2025-09-25 09:00:13'),
(19, 11, 1, 10, 'Freelancer performed action \'complete\'. Booking now \'completed\'.', 'system', 0, NULL, NULL, NULL, '2025-09-25 09:00:31', '2025-09-25 09:00:16'),
(20, 12, 2, 11, 'New booking #11 created for service \'Babysitting\' (Qty: 1). Awaiting freelancer confirmation.', 'system', 0, NULL, NULL, NULL, '2025-09-25 09:01:30', '2025-09-25 09:00:47'),
(21, 12, 2, 11, 'Client performed action \'cancel\'. Booking now \'cancelled\'.', 'system', 0, NULL, NULL, NULL, '2025-09-25 09:01:30', '2025-09-25 09:00:50'),
(22, 13, 2, 12, 'New booking #12 created for service \'Web Development\' (Qty: 1). Awaiting freelancer confirmation.', 'system', 0, NULL, NULL, NULL, '2025-09-25 21:37:44', '2025-09-25 21:36:10'),
(23, 14, 2, 13, 'New booking #13 created for service \'Babysitting\' (Qty: 1). Awaiting freelancer confirmation.', 'system', 0, NULL, NULL, NULL, '2025-09-25 21:37:49', '2025-09-25 21:36:38'),
(24, 14, 1, 13, 'Freelancer performed action \'reject\'. Booking now \'rejected\'.', 'system', 0, NULL, NULL, NULL, '2025-09-25 21:54:48', '2025-09-25 21:38:01'),
(25, 13, 1, 12, 'Freelancer performed action \'accept\'. Booking now \'accepted\'.', 'system', 0, NULL, NULL, NULL, '2025-09-25 21:54:25', '2025-09-25 21:38:05'),
(26, 13, 1, 12, 'Freelancer performed action \'start\'. Booking now \'in_progress\'.', 'system', 0, NULL, NULL, NULL, '2025-09-25 21:54:25', '2025-09-25 21:40:35'),
(27, 13, 1, 12, 'Freelancer performed action \'deliver\'. Booking now \'delivered\'.', 'system', 0, NULL, NULL, NULL, '2025-09-25 23:10:16', '2025-09-25 22:43:05'),
(28, 16, 2, 14, 'New booking #14 created for service \'UI/UX Design\' (Qty: 1). Awaiting freelancer confirmation.', 'system', 0, NULL, NULL, NULL, '2025-09-25 23:14:43', '2025-09-25 23:06:38'),
(29, 13, 2, 12, 'Client performed action \'approve_delivery\'. Booking now \'completed\'.', 'system', 0, NULL, NULL, NULL, '2025-09-25 23:10:38', '2025-09-25 23:10:03'),
(30, 16, 3, 14, 'Freelancer proposed payment method: advance', 'system', 0, NULL, NULL, NULL, '2025-09-25 23:15:16', '2025-09-25 23:14:48'),
(31, 16, 2, 14, 'Client accepted payment terms (advance).', 'system', 0, NULL, NULL, NULL, NULL, '2025-09-25 23:15:21'),
(32, 17, 2, 15, 'New booking #15 created for service \'Valo Deranking Service\' (Qty: 1). Awaiting freelancer confirmation.', 'system', 0, NULL, NULL, NULL, '2025-09-25 23:30:18', '2025-09-25 23:29:48'),
(33, 17, 3, 15, 'Freelancer proposed payment method: advance', 'system', 0, NULL, NULL, NULL, '2025-09-25 23:30:40', '2025-09-25 23:30:21'),
(34, 17, 2, 15, 'Client accepted payment terms (advance).', 'system', 0, NULL, NULL, NULL, NULL, '2025-09-25 23:33:47'),
(35, 17, 2, 15, 'Client paid ₱100.00 (phase=full_advance, method=gcash).', 'system', 0, NULL, NULL, NULL, NULL, '2025-09-26 03:33:53'),
(36, 17, 3, 15, 'ok', 'text', 0, NULL, NULL, NULL, '2025-09-27 03:48:29', '2025-09-26 03:35:18'),
(37, 17, 3, 15, 'Freelancer performed action \'accept\'. Booking now \'accepted\'.', 'system', 0, NULL, NULL, NULL, '2025-09-27 03:48:29', '2025-09-26 03:35:35'),
(38, 16, 3, 14, 'Freelancer performed action \'accept\'. Booking now \'accepted\'.', 'system', 0, NULL, NULL, NULL, '2025-09-27 03:48:31', '2025-09-26 03:35:38'),
(39, 16, 3, 14, 'hello', 'text', 0, NULL, NULL, NULL, '2025-09-27 03:48:31', '2025-09-26 03:40:54'),
(40, 16, 2, 14, 'Client paid ₱2,500.00 (phase=full_advance, method=gcash).', 'system', 0, NULL, NULL, NULL, NULL, '2025-09-26 03:41:21'),
(41, 16, 2, 14, 'hi', 'text', 0, NULL, NULL, NULL, NULL, '2025-09-26 03:41:30'),
(42, 18, 2, 16, 'New booking #16 created for service \'Deliver\' (Qty: 1). Awaiting freelancer confirmation.', 'system', 0, NULL, NULL, NULL, NULL, '2025-09-26 03:54:29'),
(43, 18, 3, 16, 'Freelancer performed action \'accept\'. Booking now \'accepted\'.', 'system', 0, NULL, NULL, NULL, '2025-09-27 03:48:11', '2025-09-26 03:54:47'),
(44, 19, 2, NULL, 'hello', 'text', 0, NULL, NULL, NULL, NULL, '2025-09-26 03:59:32'),
(45, 19, 3, NULL, 'hi', 'text', 0, NULL, NULL, NULL, '2025-09-27 03:48:32', '2025-09-26 03:59:38'),
(46, 16, 3, 14, 'Freelancer performed action \'start\'. Booking now \'in_progress\'.', 'system', 0, NULL, NULL, NULL, '2025-09-27 03:48:31', '2025-09-26 04:06:38'),
(47, 16, 3, 14, 'Freelancer performed action \'deliver\'. Booking now \'delivered\'.', 'system', 0, NULL, NULL, NULL, '2025-09-27 03:48:31', '2025-09-26 04:06:42'),
(48, 16, 3, 14, 'Freelancer performed action \'complete\'. Booking now \'completed\'.', 'system', 0, NULL, NULL, NULL, '2025-09-27 03:48:31', '2025-09-26 04:06:46'),
(49, 16, 3, 14, 'Funds released to freelancer. Commission ₱175.00 retained.', 'system', 0, NULL, NULL, NULL, '2025-09-27 03:48:31', '2025-09-26 04:06:46'),
(50, 17, 3, 15, 'Freelancer performed action \'start\'. Booking now \'in_progress\'.', 'system', 0, NULL, NULL, NULL, '2025-09-27 03:48:29', '2025-09-26 04:07:03'),
(51, 17, 3, 15, 'Freelancer performed action \'deliver\'. Booking now \'delivered\'.', 'system', 0, NULL, NULL, NULL, '2025-09-27 03:48:29', '2025-09-26 04:07:12'),
(52, 17, 3, 15, 'Freelancer performed action \'complete\'. Booking now \'completed\'.', 'system', 0, NULL, NULL, NULL, '2025-09-27 03:48:29', '2025-09-26 04:07:20'),
(53, 17, 3, 15, 'Funds released to freelancer. Commission ₱7.00 retained.', 'system', 0, NULL, NULL, NULL, '2025-09-27 03:48:29', '2025-09-26 04:07:20'),
(54, 18, 3, 16, 'Freelancer performed action \'start\'. Booking now \'in_progress\'.', 'system', 0, NULL, NULL, NULL, '2025-09-27 03:48:11', '2025-09-26 04:08:15'),
(55, 18, 3, 16, 'Freelancer performed action \'deliver\'. Booking now \'delivered\'.', 'system', 0, NULL, NULL, NULL, '2025-09-27 03:48:11', '2025-09-26 04:08:35'),
(56, 18, 3, 16, 'Freelancer performed action \'complete\'. Booking now \'completed\'.', 'system', 0, NULL, NULL, NULL, '2025-09-27 03:48:11', '2025-09-26 04:08:49'),
(57, 18, 2, 16, 'hello', 'text', 0, NULL, NULL, NULL, NULL, '2025-09-27 03:48:15'),
(58, 13, 1, 12, 'hi', 'text', 0, NULL, NULL, NULL, '2025-10-01 03:32:05', '2025-09-27 04:03:00'),
(59, 20, 4, 17, 'New booking #17 created for service \'VALO BOOSTING SERVICE\' (Qty: 1). Awaiting freelancer confirmation.', 'system', 0, NULL, NULL, NULL, '2025-09-27 05:39:10', '2025-09-27 05:36:58'),
(60, 20, 4, 18, 'New booking #18 created for service \'Web Development\' (Qty: 1). Awaiting freelancer confirmation.', 'system', 0, NULL, NULL, NULL, '2025-09-27 05:39:10', '2025-09-27 05:37:13'),
(61, 20, 4, 18, 'hi', 'text', 0, NULL, NULL, NULL, '2025-09-27 05:39:10', '2025-09-27 05:38:57'),
(62, 20, 1, 18, 'hi', 'text', 0, NULL, NULL, NULL, '2025-09-27 05:40:52', '2025-09-27 05:39:12'),
(63, 20, 1, 18, 'Freelancer performed action \'accept\'. Booking now \'accepted\'.', 'system', 0, NULL, NULL, NULL, '2025-09-27 05:40:52', '2025-09-27 05:39:18'),
(64, 20, 1, 17, 'Freelancer performed action \'reject\'. Booking now \'rejected\'.', 'system', 0, NULL, NULL, NULL, '2025-09-27 05:40:52', '2025-09-27 05:39:20'),
(65, 20, 1, 18, 'Freelancer performed action \'start\'. Booking now \'in_progress\'.', 'system', 0, NULL, NULL, NULL, '2025-09-27 05:40:52', '2025-09-27 05:39:53'),
(66, 20, 1, 18, 'Freelancer performed action \'deliver\'. Booking now \'delivered\'.', 'system', 0, NULL, NULL, NULL, '2025-09-27 05:41:52', '2025-09-27 05:41:25'),
(67, 20, 1, 18, 'Freelancer proposed payment method: advance', 'system', 0, NULL, NULL, NULL, '2025-09-27 07:35:13', '2025-09-27 07:34:51'),
(68, 20, 4, 18, 'Client accepted payment terms (advance).', 'system', 0, NULL, NULL, NULL, '2025-09-27 07:50:39', '2025-09-27 07:35:22'),
(69, 20, 4, 18, 'Client paid ₱1,000.00 (phase=full_advance, method=gcash).', 'system', 0, NULL, NULL, NULL, '2025-09-27 07:50:39', '2025-09-27 07:35:29'),
(70, 20, 4, 18, '', 'file', 0, NULL, NULL, '[{\"type\":\"image\",\"url\":\"uploads\\/chat\\/1758976808_cc6c1123.png\",\"name\":\"qrcode dummy.png\",\"size\":34777,\"w\":3000,\"h\":3000}]', '2025-09-27 07:50:39', '2025-09-27 07:40:08'),
(71, 20, 4, 18, '', 'file', 0, NULL, NULL, '[{\"type\":\"image\",\"url\":\"uploads\\/chat\\/1758977333_94f8e0ef.jpg\",\"name\":\"PompomPurin.jpg\",\"size\":44485,\"w\":640,\"h\":640},{\"type\":\"image\",\"url\":\"uploads\\/chat\\/1758977333_2973bdce.jpg\",\"name\":\"pompompurin sticker.jpg\",\"size\":15885,\"w\":736,\"h\":736},{\"type\":\"image\",\"url\":\"uploads\\/chat\\/1758977333_338a0300.jpg\",\"name\":\"Pompompurin - Pompompurin Png Clipart (#5227532) - PinClipart.jpg\",\"size\":11923,\"w\":320,\"h\":328}]', '2025-09-27 07:50:39', '2025-09-27 07:48:53'),
(72, 20, 4, 18, 'Client performed action \'approve_delivery\'. Booking now \'completed\'.', 'system', 0, NULL, NULL, NULL, '2025-09-27 07:50:39', '2025-09-27 07:50:10'),
(73, 20, 4, 18, 'Funds released to freelancer. Commission ₱70.00 retained.', 'system', 0, NULL, NULL, NULL, '2025-09-27 07:50:39', '2025-09-27 07:50:10'),
(74, 20, 4, 19, 'New booking #19 created for service \'VALO BOOSTING SERVICE\' (Qty: 1). Awaiting freelancer confirmation.', 'system', 0, NULL, NULL, NULL, '2025-09-27 07:50:39', '2025-09-27 07:50:26'),
(75, 20, 1, 19, 'Freelancer proposed payment method: downpayment', 'system', 0, NULL, NULL, NULL, '2025-09-27 07:50:59', '2025-09-27 07:50:46'),
(76, 20, 4, 19, 'Client accepted payment terms (downpayment).', 'system', 0, NULL, NULL, NULL, '2025-10-01 03:37:28', '2025-09-27 07:51:01'),
(77, 20, 4, 19, 'Client paid ₱25.00 (phase=downpayment, method=gcash).', 'system', 0, NULL, NULL, NULL, '2025-10-01 03:37:28', '2025-09-27 07:51:07'),
(78, 20, 4, 19, 'Client paid ₱25.00 (phase=balance, method=gcash).', 'system', 0, NULL, NULL, NULL, '2025-10-01 03:37:28', '2025-09-27 07:51:16'),
(79, 20, 4, 19, '', 'file', 0, NULL, NULL, '[{\"type\":\"image\",\"url\":\"uploads\\/chat\\/1758977969_55fe905d.jpg\",\"name\":\"PompomPurin.jpg\",\"size\":44485,\"w\":640,\"h\":640}]', '2025-10-01 03:37:28', '2025-09-27 07:59:29'),
(80, 13, 2, 12, '', 'file', 0, NULL, NULL, '[{\"type\":\"image\",\"url\":\"uploads\\/chat\\/1759307538_cf7d3808.jpg\",\"name\":\"Vietnam Culture & Landmarks Vector Image on VectorStock.jpg\",\"size\":69095,\"w\":735,\"h\":794}]', '2025-10-01 03:37:33', '2025-10-01 03:32:18'),
(81, 19, 2, 20, 'New booking #20 created for service \'Web Development\' (Qty: 3). Awaiting freelancer confirmation.', 'system', 0, NULL, NULL, NULL, NULL, '2025-10-01 03:33:52'),
(82, 1, 2, 21, 'New booking #21 created for service \'VALO BOOSTING SERVICE\' (Qty: 3). Awaiting freelancer confirmation.', 'system', 0, NULL, NULL, NULL, '2025-10-01 03:37:35', '2025-10-01 03:37:19'),
(83, 1, 1, 21, 'hi', 'text', 0, NULL, NULL, NULL, '2025-10-01 03:37:53', '2025-10-01 03:37:50'),
(84, 1, 1, 21, 'Freelancer proposed payment method: advance', 'system', 0, NULL, NULL, NULL, '2025-10-01 03:38:10', '2025-10-01 03:38:06'),
(85, 1, 2, 21, 'Client rejected proposed payment terms.', 'system', 0, NULL, NULL, NULL, '2025-10-01 03:38:19', '2025-10-01 03:38:16'),
(86, 1, 1, 21, 'Freelancer proposed payment method: downpayment', 'system', 0, NULL, NULL, NULL, '2025-10-01 03:38:27', '2025-10-01 03:38:23'),
(87, 1, 2, 21, 'Client accepted payment terms (downpayment).', 'system', 0, NULL, NULL, NULL, '2025-10-01 03:38:43', '2025-10-01 03:38:30'),
(88, 1, 2, 21, 'Client paid ₱75.00 (phase=downpayment, method=gcash).', 'system', 0, NULL, NULL, NULL, '2025-10-01 03:38:43', '2025-10-01 03:38:37'),
(89, 1, 1, 21, 'hi', 'text', 0, NULL, NULL, NULL, '2025-10-01 03:44:25', '2025-10-01 03:44:19'),
(90, 1, 2, 21, 'hello', 'text', 0, NULL, NULL, NULL, '2025-10-01 03:46:27', '2025-10-01 03:44:25'),
(91, 1, 1, 21, 'bye', 'text', 0, NULL, NULL, NULL, '2025-10-01 03:47:46', '2025-10-01 03:47:38'),
(92, 1, 2, 21, 'bye', 'text', 0, NULL, NULL, NULL, '2025-10-01 03:47:58', '2025-10-01 03:47:52'),
(93, 1, 1, 21, 'ok', 'text', 0, NULL, NULL, NULL, '2025-10-01 03:54:02', '2025-10-01 03:53:44'),
(94, 1, 1, 21, 'Freelancer performed action \'accept\'. Booking now \'accepted\'.', 'system', 0, NULL, NULL, NULL, '2025-10-01 03:58:35', '2025-10-01 03:58:25'),
(95, 1, 1, 21, 'Freelancer performed action \'start\'. Booking now \'in_progress\'.', 'system', 0, NULL, NULL, NULL, '2025-10-01 03:58:35', '2025-10-01 03:58:28'),
(96, 1, 1, 21, 'Freelancer performed action \'deliver\'. Booking now \'delivered\'.', 'system', 0, NULL, NULL, NULL, '2025-10-01 03:58:35', '2025-10-01 03:58:30'),
(97, 1, 2, 21, 'Client paid ₱75.00 (phase=balance, method=gcash).', 'system', 0, NULL, NULL, NULL, '2025-10-01 04:53:53', '2025-10-01 04:46:55'),
(98, 1, 2, 21, 'wala kang bitaw', 'text', 0, NULL, NULL, NULL, '2025-10-01 04:53:53', '2025-10-01 04:53:50'),
(99, 1, 1, 21, 'meron sah', 'text', 0, NULL, NULL, NULL, '2025-10-01 04:54:06', '2025-10-01 04:54:04'),
(100, 1, 1, 21, 'e ikaw wala kang bitaw, ayaw mo nga magbayad', 'text', 0, NULL, NULL, NULL, '2025-10-01 04:55:20', '2025-10-01 04:55:09'),
(101, 1, 2, 21, 'eme ka meron naman, gusto mo doblehin ko pa bayad ko', 'text', 0, NULL, NULL, NULL, '2025-10-01 04:55:37', '2025-10-01 04:55:33'),
(102, 1, 1, 21, 'okie sumubok ka sah', 'text', 0, NULL, NULL, NULL, '2025-10-01 05:03:49', '2025-10-01 05:03:45'),
(103, 1, 2, 21, 'sige wait mo lang,,,', 'text', 0, NULL, NULL, NULL, '2025-10-01 05:04:01', '2025-10-01 05:03:59'),
(104, 1, 1, 21, 'HELLOOOOOOOO', 'text', 0, NULL, NULL, NULL, '2025-10-01 05:13:25', '2025-10-01 05:13:22'),
(105, 1, 2, 21, 'HIIIIIIIII', 'text', 0, NULL, NULL, NULL, '2025-10-01 05:13:31', '2025-10-01 05:13:28'),
(106, 1, 1, 21, 'ano benta mo te', 'text', 0, NULL, NULL, NULL, '2025-10-01 05:19:49', '2025-10-01 05:19:41'),
(107, 1, 2, 21, 'saging at kalabasa', 'text', 0, NULL, NULL, NULL, '2025-10-01 05:20:56', '2025-10-01 05:20:54'),
(108, 1, 2, 21, 'ikaw ba', 'text', 0, NULL, NULL, NULL, '2025-10-01 05:21:11', '2025-10-01 05:21:02'),
(109, 1, 1, 21, 'dragon fruit', 'text', 0, NULL, NULL, NULL, '2025-10-01 05:23:19', '2025-10-01 05:23:14'),
(110, 1, 2, 21, 'elibap 10,000 sheckles', 'text', 0, NULL, NULL, NULL, '2025-10-01 05:38:47', '2025-10-01 05:33:53'),
(111, 1, 1, 21, 'Freelancer performed action \'complete\'. Booking now \'completed\'.', 'system', 0, NULL, NULL, NULL, '2025-10-01 05:39:06', '2025-10-01 05:39:06'),
(112, 1, 1, 21, 'Funds released to freelancer. Commission ₱10.50 retained.', 'system', 0, NULL, NULL, NULL, '2025-10-01 05:39:06', '2025-10-01 05:39:06'),
(113, 20, 1, 19, 'Freelancer performed action \'reject\'. Booking now \'rejected\'.', 'system', 0, NULL, NULL, NULL, NULL, '2025-10-01 07:39:32');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `notification_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `type` varchar(80) NOT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data`)),
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`notification_id`, `user_id`, `type`, `data`, `read_at`, `created_at`) VALUES
(1, 1, 'booking_created', '{\"booking_id\":1,\"service_id\":2,\"client_id\":2,\"qty\":1}', NULL, '2025-09-25 08:14:00'),
(2, 2, 'booking_status_changed', '{\"booking_id\":1,\"status\":\"accepted\"}', NULL, '2025-09-25 08:14:16'),
(3, 2, 'booking_status_changed', '{\"booking_id\":1,\"status\":\"in_progress\"}', NULL, '2025-09-25 08:46:07'),
(4, 2, 'booking_status_changed', '{\"booking_id\":1,\"status\":\"delivered\"}', NULL, '2025-09-25 08:46:10'),
(5, 2, 'booking_status_changed', '{\"booking_id\":1,\"status\":\"completed\"}', NULL, '2025-09-25 08:46:16'),
(6, 1, 'booking_created', '{\"booking_id\":10,\"service_id\":3,\"client_id\":2,\"qty\":1}', NULL, '2025-09-25 08:59:39'),
(7, 2, 'booking_status_changed', '{\"booking_id\":10,\"status\":\"accepted\"}', NULL, '2025-09-25 09:00:07'),
(8, 2, 'booking_status_changed', '{\"booking_id\":10,\"status\":\"in_progress\"}', NULL, '2025-09-25 09:00:11'),
(9, 2, 'booking_status_changed', '{\"booking_id\":10,\"status\":\"delivered\"}', NULL, '2025-09-25 09:00:13'),
(10, 2, 'booking_status_changed', '{\"booking_id\":10,\"status\":\"completed\"}', NULL, '2025-09-25 09:00:16'),
(11, 1, 'booking_created', '{\"booking_id\":11,\"service_id\":1,\"client_id\":2,\"qty\":1}', NULL, '2025-09-25 09:00:47'),
(12, 1, 'booking_status_changed', '{\"booking_id\":11,\"status\":\"cancelled\"}', NULL, '2025-09-25 09:00:50'),
(13, 1, 'booking_created', '{\"booking_id\":12,\"service_id\":2,\"client_id\":2,\"qty\":1}', NULL, '2025-09-25 21:36:10'),
(14, 1, 'booking_created', '{\"booking_id\":13,\"service_id\":1,\"client_id\":2,\"qty\":1}', NULL, '2025-09-25 21:36:38'),
(15, 2, 'booking_status_changed', '{\"booking_id\":13,\"status\":\"rejected\"}', NULL, '2025-09-25 21:38:01'),
(16, 2, 'booking_status_changed', '{\"booking_id\":12,\"status\":\"accepted\"}', NULL, '2025-09-25 21:38:05'),
(17, 2, 'booking_status_changed', '{\"booking_id\":12,\"status\":\"in_progress\"}', NULL, '2025-09-25 21:40:35'),
(18, 2, 'booking_status_changed', '{\"booking_id\":12,\"status\":\"delivered\"}', NULL, '2025-09-25 22:43:05'),
(19, 3, 'booking_created', '{\"booking_id\":14,\"service_id\":5,\"client_id\":2,\"qty\":1}', NULL, '2025-09-25 23:06:38'),
(20, 1, 'booking_status_changed', '{\"booking_id\":12,\"status\":\"completed\"}', NULL, '2025-09-25 23:10:03'),
(21, 3, 'booking_created', '{\"booking_id\":15,\"service_id\":6,\"client_id\":2,\"qty\":1}', NULL, '2025-09-25 23:29:48'),
(22, 2, 'booking_status_changed', '{\"booking_id\":15,\"status\":\"accepted\"}', NULL, '2025-09-26 03:35:35'),
(23, 2, 'booking_status_changed', '{\"booking_id\":14,\"status\":\"accepted\"}', NULL, '2025-09-26 03:35:38'),
(24, 3, 'booking_created', '{\"booking_id\":16,\"service_id\":7,\"client_id\":2,\"qty\":1}', NULL, '2025-09-26 03:54:29'),
(25, 2, 'booking_status_changed', '{\"booking_id\":16,\"status\":\"accepted\"}', NULL, '2025-09-26 03:54:47'),
(26, 2, 'booking_status_changed', '{\"booking_id\":14,\"status\":\"in_progress\"}', NULL, '2025-09-26 04:06:38'),
(27, 2, 'booking_status_changed', '{\"booking_id\":14,\"status\":\"delivered\"}', NULL, '2025-09-26 04:06:42'),
(28, 2, 'booking_status_changed', '{\"booking_id\":14,\"status\":\"completed\"}', NULL, '2025-09-26 04:06:46'),
(29, 2, 'booking_status_changed', '{\"booking_id\":15,\"status\":\"in_progress\"}', NULL, '2025-09-26 04:07:03'),
(30, 2, 'booking_status_changed', '{\"booking_id\":15,\"status\":\"delivered\"}', NULL, '2025-09-26 04:07:12'),
(31, 2, 'booking_status_changed', '{\"booking_id\":15,\"status\":\"completed\"}', NULL, '2025-09-26 04:07:20'),
(32, 2, 'booking_status_changed', '{\"booking_id\":16,\"status\":\"in_progress\"}', NULL, '2025-09-26 04:08:15'),
(33, 2, 'booking_status_changed', '{\"booking_id\":16,\"status\":\"delivered\"}', NULL, '2025-09-26 04:08:35'),
(34, 2, 'booking_status_changed', '{\"booking_id\":16,\"status\":\"completed\"}', NULL, '2025-09-26 04:08:49'),
(35, 1, 'booking_created', '{\"booking_id\":17,\"service_id\":3,\"client_id\":4,\"qty\":1}', NULL, '2025-09-27 05:36:58'),
(36, 1, 'booking_created', '{\"booking_id\":18,\"service_id\":2,\"client_id\":4,\"qty\":1}', NULL, '2025-09-27 05:37:13'),
(37, 4, 'booking_status_changed', '{\"booking_id\":18,\"status\":\"accepted\"}', NULL, '2025-09-27 05:39:18'),
(38, 4, 'booking_status_changed', '{\"booking_id\":17,\"status\":\"rejected\"}', NULL, '2025-09-27 05:39:20'),
(39, 4, 'booking_status_changed', '{\"booking_id\":18,\"status\":\"in_progress\"}', NULL, '2025-09-27 05:39:53'),
(40, 4, 'booking_status_changed', '{\"booking_id\":18,\"status\":\"delivered\"}', NULL, '2025-09-27 05:41:25'),
(41, 1, 'booking_status_changed', '{\"booking_id\":18,\"status\":\"completed\"}', NULL, '2025-09-27 07:50:10'),
(42, 1, 'booking_created', '{\"booking_id\":19,\"service_id\":3,\"client_id\":4,\"qty\":1}', NULL, '2025-09-27 07:50:26'),
(43, 3, 'booking_created', '{\"booking_id\":20,\"service_id\":8,\"client_id\":2,\"qty\":3}', NULL, '2025-10-01 03:33:52'),
(44, 1, 'booking_created', '{\"booking_id\":21,\"service_id\":3,\"client_id\":2,\"qty\":3}', NULL, '2025-10-01 03:37:19'),
(45, 2, 'booking_status_changed', '{\"booking_id\":21,\"status\":\"accepted\"}', NULL, '2025-10-01 03:58:25'),
(46, 2, 'booking_status_changed', '{\"booking_id\":21,\"status\":\"in_progress\"}', NULL, '2025-10-01 03:58:28'),
(47, 2, 'booking_status_changed', '{\"booking_id\":21,\"status\":\"delivered\"}', NULL, '2025-10-01 03:58:30'),
(48, 2, 'booking_status_changed', '{\"booking_id\":21,\"status\":\"completed\"}', NULL, '2025-10-01 05:39:06'),
(49, 1, 'admin_message', '{\"text\":\"bawal yan bawal lumabas o bawal lumabas sa classroom may batas\"}', NULL, '2025-10-01 06:53:30'),
(50, 4, 'booking_status_changed', '{\"booking_id\":19,\"status\":\"rejected\"}', NULL, '2025-10-01 07:39:32'),
(51, 1, 'admin_message', '{\"text\":\"hi\"}', NULL, '2025-10-01 08:00:56');

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `payment_id` bigint(20) UNSIGNED NOT NULL,
  `booking_id` bigint(20) UNSIGNED NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `currency` char(3) NOT NULL DEFAULT 'PHP',
  `status` enum('escrowed','released','refunded','failed') NOT NULL DEFAULT 'escrowed',
  `payment_phase` enum('full_advance','downpayment','balance','postpaid_full') NOT NULL,
  `method` enum('gcash','paymaya') DEFAULT NULL,
  `payer_details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payer_details`)),
  `reference_code` varchar(120) DEFAULT NULL,
  `paid_at` datetime NOT NULL,
  `otp_verified_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `receiver_method_id` bigint(20) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`payment_id`, `booking_id`, `amount`, `currency`, `status`, `payment_phase`, `method`, `payer_details`, `reference_code`, `paid_at`, `otp_verified_at`, `created_at`, `receiver_method_id`) VALUES
(6, 15, 100.00, 'PHP', 'escrowed', 'full_advance', 'gcash', '{\"channel\":\"gcash\",\"ip\":\"::1\",\"ua\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/140.0.0.0 Safari\\/537.36 Edg\\/140.0.0.0\"}', NULL, '2025-09-26 10:33:53', '2025-09-26 10:33:53', '2025-09-26 10:33:53', 1),
(7, 14, 2500.00, 'PHP', 'escrowed', 'full_advance', 'gcash', '{\"channel\":\"gcash\",\"ip\":\"::1\",\"ua\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/140.0.0.0 Safari\\/537.36 Edg\\/140.0.0.0\"}', NULL, '2025-09-26 10:41:21', '2025-09-26 10:41:21', '2025-09-26 10:41:21', 1),
(8, 18, 1000.00, 'PHP', 'escrowed', 'full_advance', 'gcash', '{\"channel\":\"gcash\",\"ip\":\"::1\",\"ua\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/140.0.0.0 Safari\\/537.36 Edg\\/140.0.0.0\"}', NULL, '2025-09-27 14:35:29', '2025-09-27 14:35:29', '2025-09-27 14:35:29', 3),
(9, 19, 25.00, 'PHP', 'escrowed', 'downpayment', 'gcash', '{\"channel\":\"gcash\",\"ip\":\"::1\",\"ua\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/140.0.0.0 Safari\\/537.36 Edg\\/140.0.0.0\"}', NULL, '2025-09-27 14:51:07', '2025-09-27 14:51:07', '2025-09-27 14:51:07', 3),
(10, 19, 25.00, 'PHP', 'escrowed', 'balance', 'gcash', '{\"channel\":\"gcash\",\"ip\":\"::1\",\"ua\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/140.0.0.0 Safari\\/537.36 Edg\\/140.0.0.0\"}', NULL, '2025-09-27 14:51:16', '2025-09-27 14:51:16', '2025-09-27 14:51:16', NULL),
(11, 21, 75.00, 'PHP', 'escrowed', 'downpayment', 'gcash', '{\"channel\":\"gcash\",\"ip\":\"::1\",\"ua\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/140.0.0.0 Safari\\/537.36 Edg\\/140.0.0.0\"}', NULL, '2025-10-01 10:38:37', '2025-10-01 10:38:37', '2025-10-01 10:38:37', 3),
(12, 21, 75.00, 'PHP', 'escrowed', 'balance', 'gcash', '{\"channel\":\"gcash\",\"ip\":\"::1\",\"ua\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/140.0.0.0 Safari\\/537.36 Edg\\/140.0.0.0\"}', NULL, '2025-10-01 11:46:55', '2025-10-01 11:46:55', '2025-10-01 11:46:55', 3);

-- --------------------------------------------------------

--
-- Table structure for table `plans`
--

CREATE TABLE `plans` (
  `plan_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `monthly_price` decimal(10,2) DEFAULT 0.00,
  `annual_price` decimal(10,2) DEFAULT 0.00,
  `commission_rate` decimal(5,2) DEFAULT 10.00,
  `features` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`features`)),
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reports`
--

CREATE TABLE `reports` (
  `report_id` bigint(20) UNSIGNED NOT NULL,
  `reporter_id` bigint(20) UNSIGNED NOT NULL,
  `report_type` enum('service','message','review','booking','user') NOT NULL,
  `target_id` bigint(20) UNSIGNED NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('open','under_review','resolved','rejected') NOT NULL DEFAULT 'open',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `resolved_at` datetime DEFAULT NULL,
  `admin_id` bigint(20) UNSIGNED DEFAULT NULL,
  `resolution_notes` text DEFAULT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `reports`
--

INSERT INTO `reports` (`report_id`, `reporter_id`, `report_type`, `target_id`, `description`, `status`, `created_at`, `resolved_at`, `admin_id`, `resolution_notes`, `meta`) VALUES
(1, 2, 'user', 1, 'gusto ko lang try lang', 'open', '2025-10-01 07:28:18', NULL, NULL, NULL, '{\"ip\":\"::1\",\"ua\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/140.0.0.0 Safari\\/537.36 Edg\\/140.0.0.0\",\"page\":\"user_profile.php?id=1\"}'),
(2, 2, 'service', 8, 'hindi naman \'to maalam', 'open', '2025-10-01 07:29:44', NULL, NULL, NULL, '{\"ip\":\"::1\",\"ua\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/140.0.0.0 Safari\\/537.36 Edg\\/140.0.0.0\",\"page\":\"service.php?slug=web-development-1\"}');

-- --------------------------------------------------------

--
-- Table structure for table `reviews`
--

CREATE TABLE `reviews` (
  `review_id` bigint(20) UNSIGNED NOT NULL,
  `booking_id` bigint(20) UNSIGNED NOT NULL,
  `reviewer_id` bigint(20) UNSIGNED NOT NULL,
  `reviewee_id` bigint(20) UNSIGNED NOT NULL,
  `rating` tinyint(4) NOT NULL CHECK (`rating` between 1 and 5),
  `comment` text DEFAULT NULL,
  `reply` text DEFAULT NULL,
  `flagged` tinyint(1) NOT NULL DEFAULT 0,
  `flagged_reason` varchar(255) DEFAULT NULL,
  `flagged_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `reviews`
--

INSERT INTO `reviews` (`review_id`, `booking_id`, `reviewer_id`, `reviewee_id`, `rating`, `comment`, `reply`, `flagged`, `flagged_reason`, `flagged_at`, `created_at`, `updated_at`) VALUES
(1, 1, 2, 1, 5, 'GOOD!!', NULL, 0, NULL, NULL, '2025-09-25 08:47:35', '2025-09-25 08:47:35'),
(2, 10, 2, 1, 4, 'AMAZING', NULL, 0, NULL, NULL, '2025-09-25 09:01:02', '2025-09-25 09:01:02'),
(3, 14, 2, 3, 1, 'AMPANGIT', NULL, 0, NULL, NULL, '2025-09-26 04:07:27', '2025-09-26 04:07:27');

--
-- Triggers `reviews`
--
DELIMITER $$
CREATE TRIGGER `trg_reviews_after_delete` AFTER DELETE ON `reviews` FOR EACH ROW BEGIN
  UPDATE users u
    LEFT JOIN (
      SELECT reviewee_id, AVG(rating) AS avg_r, COUNT(*) AS cnt
      FROM reviews
      WHERE reviewee_id = OLD.reviewee_id
      GROUP BY reviewee_id
    ) x ON u.user_id = x.reviewee_id
  SET u.avg_rating = IFNULL(x.avg_r,0), u.total_reviews = IFNULL(x.cnt,0)
  WHERE u.user_id = OLD.reviewee_id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_reviews_after_insert` AFTER INSERT ON `reviews` FOR EACH ROW BEGIN
  UPDATE users u
    JOIN (
      SELECT reviewee_id, AVG(rating) AS avg_r, COUNT(*) AS cnt
      FROM reviews
      WHERE reviewee_id = NEW.reviewee_id
      GROUP BY reviewee_id
    ) x ON u.user_id = x.reviewee_id
  SET u.avg_rating = x.avg_r, u.total_reviews = x.cnt;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_reviews_after_update` AFTER UPDATE ON `reviews` FOR EACH ROW BEGIN
  IF (NEW.rating <> OLD.rating OR NEW.reviewee_id <> OLD.reviewee_id) THEN
    UPDATE users u
      JOIN (
        SELECT reviewee_id, AVG(rating) AS avg_r, COUNT(*) AS cnt
        FROM reviews
        WHERE reviewee_id IN (OLD.reviewee_id, NEW.reviewee_id)
        GROUP BY reviewee_id
      ) x ON u.user_id = x.reviewee_id
    SET u.avg_rating = x.avg_r, u.total_reviews = x.cnt;
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `services`
--

CREATE TABLE `services` (
  `service_id` bigint(20) UNSIGNED NOT NULL,
  `freelancer_id` bigint(20) UNSIGNED NOT NULL,
  `category_id` bigint(20) UNSIGNED DEFAULT NULL,
  `title` varchar(160) NOT NULL,
  `slug` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `base_price` decimal(10,2) NOT NULL,
  `price_unit` enum('fixed','hourly','per_unit') DEFAULT 'fixed',
  `min_units` int(11) DEFAULT 1,
  `is_premium` tinyint(1) DEFAULT 0,
  `status` enum('draft','active','paused','archived') NOT NULL DEFAULT 'draft',
  `flagged` tinyint(1) NOT NULL DEFAULT 0,
  `flagged_reason` varchar(255) DEFAULT NULL,
  `flagged_at` datetime DEFAULT NULL,
  `avg_rating` decimal(3,2) DEFAULT 0.00,
  `total_reviews` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `services`
--

INSERT INTO `services` (`service_id`, `freelancer_id`, `category_id`, `title`, `slug`, `description`, `base_price`, `price_unit`, `min_units`, `is_premium`, `status`, `flagged`, `flagged_reason`, `flagged_at`, `avg_rating`, `total_reviews`, `created_at`, `updated_at`) VALUES
(1, 1, NULL, 'Babysitting', 'babysitting', 'babysitting sbabies', 500.00, 'fixed', 1, 0, 'active', 0, NULL, NULL, 0.00, 0, '2025-09-25 07:10:14', '2025-09-27 05:17:59'),
(2, 1, NULL, 'Web Development', 'web-development', 'make u ur own wibsite', 1000.00, 'fixed', 1, 0, 'active', 0, NULL, NULL, 0.00, 0, '2025-09-25 07:37:03', '2025-09-25 07:37:03'),
(3, 1, NULL, 'VALO BOOSTING SERVICE', 'valo-boosting-service', 'gold to bronze real quick', 50.00, 'fixed', 1, 0, 'active', 0, NULL, NULL, 0.00, 0, '2025-09-25 08:28:09', '2025-09-25 08:28:09'),
(5, 3, NULL, 'UI/UX Design', 'ui-ux-design', 'ako magdedesign ng ui niyo', 2500.00, 'fixed', 1, 0, 'active', 0, NULL, NULL, 0.00, 0, '2025-09-25 21:59:20', '2025-09-25 21:59:20'),
(6, 3, NULL, 'Valo Deranking Service', 'valo-deranking-service', 'dia to bronze easy game ggs', 100.00, 'fixed', 1, 0, 'active', 0, NULL, NULL, 0.00, 0, '2025-09-25 21:59:46', '2025-09-25 21:59:46'),
(7, 3, NULL, 'Deliver', 'deliver', 'call me para makapagdeliver', 60.00, 'fixed', 1, 0, 'active', 0, NULL, NULL, 0.00, 0, '2025-09-26 03:54:06', '2025-09-26 03:54:06'),
(8, 3, NULL, 'Web Development', 'web-development-1', 'kaya ko webdev', 1500.00, 'fixed', 1, 0, 'active', 1, 'hindi naman \'to maalam', '2025-10-01 07:29:44', 0.00, 0, '2025-09-26 03:57:56', '2025-10-01 07:29:44'),
(9, 1, NULL, 'Mathematic Tutor', 'mathematic-tutor', 'turuan ko kayo mag addition at subtraction', 250.00, 'hourly', 1, 0, 'active', 0, NULL, NULL, 0.00, 0, '2025-10-01 07:33:23', '2025-10-01 07:33:23'),
(10, 1, NULL, 'English Tutor Online', 'english-tutor-online', 'teach u english through zoom meetings', 300.00, 'hourly', 1, 0, 'active', 0, NULL, NULL, 0.00, 0, '2025-10-01 07:43:38', '2025-10-01 08:25:02');

-- --------------------------------------------------------

--
-- Table structure for table `service_categories`
--

CREATE TABLE `service_categories` (
  `category_id` bigint(20) UNSIGNED NOT NULL,
  `parent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `name` varchar(120) NOT NULL,
  `slug` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `icon` varchar(120) DEFAULT NULL,
  `depth` tinyint(4) DEFAULT 0,
  `lft` int(11) DEFAULT NULL,
  `rgt` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `service_media`
--

CREATE TABLE `service_media` (
  `media_id` bigint(20) UNSIGNED NOT NULL,
  `service_id` bigint(20) UNSIGNED NOT NULL,
  `url` varchar(255) NOT NULL,
  `media_type` enum('image','video','doc') DEFAULT 'image',
  `position` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `subscriptions`
--

CREATE TABLE `subscriptions` (
  `subscription_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `plan_id` bigint(20) UNSIGNED NOT NULL,
  `status` enum('active','cancelled','expired','trial') DEFAULT 'active',
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ends_at` datetime DEFAULT NULL,
  `cancel_at_end` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `first_name` varchar(80) NOT NULL,
  `last_name` varchar(80) NOT NULL,
  `email` varchar(190) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `user_type` enum('client','freelancer','admin') NOT NULL DEFAULT 'client',
  `profile_picture` varchar(255) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `avg_rating` decimal(3,2) NOT NULL DEFAULT 0.00,
  `total_reviews` int(11) NOT NULL DEFAULT 0,
  `status` enum('active','suspended','deleted') NOT NULL DEFAULT 'active',
  `warnings` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `suspension_reason` varchar(255) DEFAULT NULL,
  `suspended_at` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `full_name` varchar(170) GENERATED ALWAYS AS (trim(concat(`first_name`,' ',`last_name`))) STORED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `first_name`, `last_name`, `email`, `phone`, `password_hash`, `user_type`, `profile_picture`, `bio`, `avg_rating`, `total_reviews`, `status`, `warnings`, `suspension_reason`, `suspended_at`, `last_login_at`, `created_at`, `updated_at`) VALUES
(1, 'Cxyris', 'Tan', 'cxyris0419@gmail.com', '09603070809', '$2y$10$OvO34ayYMscY8IgRvnuH4.LmK6TUKXS/.nX2ZwOLLE/JgkqwcFUDK', 'freelancer', 'uploads/1758801386_6ccbeb91.png', NULL, 4.50, 2, 'active', 0, NULL, NULL, '2025-10-05 04:06:47', '2025-09-25 06:56:27', '2025-10-05 04:06:47'),
(2, 'Cxyris', 'Tan', 'cxyris@gmail.com', '09603070809', '$2y$10$EGmfvsN7FRJz3KWj8GKPwui/1100YVLASit7yaatUUxubNZ9wMLDm', 'client', 'uploads/1758802316_1c516447.jpg', 'signal mom', 0.00, 0, 'active', 0, NULL, NULL, '2025-10-01 03:36:49', '2025-09-25 07:11:57', '2025-10-01 03:36:49'),
(3, 'Marian', 'Manalo', 'marian@gmail.com', '09123456789', '$2y$10$/NcqGVpUvWkRUM5A8EdcL.xAvSPh1qC1fdgSRTZZYHXgGnnnhfy6y', 'freelancer', 'uploads/1758855509_5abd0dce.jpg', 'hello', 1.00, 1, 'active', 0, NULL, NULL, '2025-09-26 04:19:37', '2025-09-25 21:58:30', '2025-10-01 06:15:39'),
(4, 'Gian', 'Buan', 'gian@gmail.com', '09123456780', '$2y$10$LRRPq6IPJrNy9Becn.dkOesL7GAyBj7LhKo5cnl3aNNE.1AMI25Va', 'client', 'uploads/1758855699_234191c3.png', NULL, 0.00, 0, 'active', 0, NULL, NULL, '2025-09-27 07:50:56', '2025-09-25 22:01:40', '2025-09-27 07:50:56'),
(5, 'Admin', 'Ako', 'admin@gmail.com', '09123456787', '$2y$10$LDTZq/GQjFsUp0tVazngA.DQQI/0RVwLA5kb8Nd129weFPVzQsk5C', 'admin', NULL, NULL, 0.00, 0, 'active', 0, NULL, NULL, '2025-10-01 06:54:26', '2025-10-01 05:50:35', '2025-10-01 06:54:26');

-- --------------------------------------------------------

--
-- Table structure for table `user_warnings`
--

CREATE TABLE `user_warnings` (
  `warning_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `admin_id` bigint(20) UNSIGNED NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wallets`
--

CREATE TABLE `wallets` (
  `wallet_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `balance` decimal(12,2) DEFAULT 0.00,
  `pending` decimal(12,2) DEFAULT 0.00,
  `currency` char(3) DEFAULT 'PHP',
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `wallets`
--

INSERT INTO `wallets` (`wallet_id`, `user_id`, `balance`, `pending`, `currency`, `updated_at`) VALUES
(1, 3, 2418.00, 0.00, 'PHP', '2025-09-26 04:07:20'),
(2, 1, 1069.50, 0.00, 'PHP', '2025-10-01 05:39:06');

-- --------------------------------------------------------

--
-- Table structure for table `wallet_transactions`
--

CREATE TABLE `wallet_transactions` (
  `wallet_txn_id` bigint(20) UNSIGNED NOT NULL,
  `wallet_id` bigint(20) UNSIGNED NOT NULL,
  `booking_id` bigint(20) UNSIGNED DEFAULT NULL,
  `txn_type` enum('credit','debit','hold','release','refund') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `status` enum('pending','completed','failed') DEFAULT 'pending',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `wallet_transactions`
--

INSERT INTO `wallet_transactions` (`wallet_txn_id`, `wallet_id`, `booking_id`, `txn_type`, `amount`, `description`, `status`, `created_at`) VALUES
(1, 1, 14, 'credit', 2325.00, 'Release booking funds', 'completed', '2025-09-26 04:06:46'),
(2, 1, 15, 'credit', 93.00, 'Release booking funds', 'completed', '2025-09-26 04:07:20'),
(3, 2, 18, 'credit', 930.00, 'Release booking funds', 'completed', '2025-09-27 07:50:10'),
(4, 2, 21, 'credit', 139.50, 'Release booking funds', 'completed', '2025-10-01 05:39:06');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin_action_logs`
--
ALTER TABLE `admin_action_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `idx_admin_action` (`admin_id`,`action`,`created_at`),
  ADD KEY `idx_admin_target` (`target_type`,`target_id`);

--
-- Indexes for table `bookings`
--
ALTER TABLE `bookings`
  ADD PRIMARY KEY (`booking_id`),
  ADD KEY `service_id` (`service_id`),
  ADD KEY `idx_booking_status` (`status`),
  ADD KEY `idx_booking_client` (`client_id`),
  ADD KEY `idx_booking_freelancer` (`freelancer_id`);

--
-- Indexes for table `commissions`
--
ALTER TABLE `commissions`
  ADD PRIMARY KEY (`commission_id`),
  ADD UNIQUE KEY `uniq_booking_commission` (`booking_id`);

--
-- Indexes for table `conversations`
--
ALTER TABLE `conversations`
  ADD PRIMARY KEY (`conversation_id`),
  ADD UNIQUE KEY `uniq_booking_conv` (`booking_id`),
  ADD KEY `idx_conv_participants` (`client_id`,`freelancer_id`),
  ADD KEY `idx_conv_booking` (`booking_id`),
  ADD KEY `freelancer_id` (`freelancer_id`),
  ADD KEY `idx_conv_pair_type` (`client_id`,`freelancer_id`,`conversation_type`);

--
-- Indexes for table `disputes`
--
ALTER TABLE `disputes`
  ADD PRIMARY KEY (`dispute_id`),
  ADD KEY `raised_by_id` (`raised_by_id`),
  ADD KEY `against_id` (`against_id`),
  ADD KEY `idx_dispute_status` (`status`),
  ADD KEY `idx_dispute_booking` (`booking_id`);

--
-- Indexes for table `dispute_events`
--
ALTER TABLE `dispute_events`
  ADD PRIMARY KEY (`event_id`),
  ADD KEY `actor_id` (`actor_id`),
  ADD KEY `idx_dispute_event` (`dispute_id`);

--
-- Indexes for table `freelancer_payment_methods`
--
ALTER TABLE `freelancer_payment_methods`
  ADD PRIMARY KEY (`method_id`),
  ADD KEY `idx_fpm_user` (`user_id`,`is_active`);

--
-- Indexes for table `freelancer_profiles`
--
ALTER TABLE `freelancer_profiles`
  ADD PRIMARY KEY (`freelancer_profile_id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`message_id`),
  ADD KEY `booking_id` (`booking_id`),
  ADD KEY `idx_conv` (`conversation_id`),
  ADD KEY `idx_sender` (`sender_id`),
  ADD KEY `idx_messages_flagged` (`is_flagged`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `idx_notif_user` (`user_id`),
  ADD KEY `idx_notif_type` (`type`),
  ADD KEY `idx_notif_read` (`read_at`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD KEY `idx_pay_booking` (`booking_id`),
  ADD KEY `idx_pay_receiver_method` (`receiver_method_id`);

--
-- Indexes for table `plans`
--
ALTER TABLE `plans`
  ADD PRIMARY KEY (`plan_id`);

--
-- Indexes for table `reports`
--
ALTER TABLE `reports`
  ADD PRIMARY KEY (`report_id`),
  ADD KEY `idx_reports_status` (`status`),
  ADD KEY `idx_reports_reporter` (`reporter_id`),
  ADD KEY `idx_reports_target` (`report_type`,`target_id`),
  ADD KEY `fk_reports_admin` (`admin_id`);

--
-- Indexes for table `reviews`
--
ALTER TABLE `reviews`
  ADD PRIMARY KEY (`review_id`),
  ADD UNIQUE KEY `uniq_booking_reviewer` (`booking_id`,`reviewer_id`),
  ADD KEY `reviewer_id` (`reviewer_id`),
  ADD KEY `idx_reviewee` (`reviewee_id`),
  ADD KEY `idx_rating` (`rating`),
  ADD KEY `idx_reviews_flagged` (`flagged`);

--
-- Indexes for table `services`
--
ALTER TABLE `services`
  ADD PRIMARY KEY (`service_id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD UNIQUE KEY `ux_services_slug` (`slug`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `idx_service_status` (`status`),
  ADD KEY `idx_service_freelancer` (`freelancer_id`),
  ADD KEY `idx_service_flagged_status` (`flagged`,`status`),
  ADD KEY `idx_service_created` (`created_at`);
ALTER TABLE `services` ADD FULLTEXT KEY `ftx_service_search` (`title`,`description`);

--
-- Indexes for table `service_categories`
--
ALTER TABLE `service_categories`
  ADD PRIMARY KEY (`category_id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD KEY `idx_parent` (`parent_id`),
  ADD KEY `idx_depth` (`depth`);

--
-- Indexes for table `service_media`
--
ALTER TABLE `service_media`
  ADD PRIMARY KEY (`media_id`),
  ADD KEY `idx_media_service` (`service_id`);

--
-- Indexes for table `subscriptions`
--
ALTER TABLE `subscriptions`
  ADD PRIMARY KEY (`subscription_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `plan_id` (`plan_id`),
  ADD KEY `idx_sub_status` (`status`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_user_type` (`user_type`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `user_warnings`
--
ALTER TABLE `user_warnings`
  ADD PRIMARY KEY (`warning_id`),
  ADD KEY `idx_warn_user` (`user_id`),
  ADD KEY `fk_warn_admin` (`admin_id`);

--
-- Indexes for table `wallets`
--
ALTER TABLE `wallets`
  ADD PRIMARY KEY (`wallet_id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `wallet_transactions`
--
ALTER TABLE `wallet_transactions`
  ADD PRIMARY KEY (`wallet_txn_id`),
  ADD KEY `booking_id` (`booking_id`),
  ADD KEY `idx_wallet` (`wallet_id`),
  ADD KEY `idx_txn_type` (`txn_type`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin_action_logs`
--
ALTER TABLE `admin_action_logs`
  MODIFY `log_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `bookings`
--
ALTER TABLE `bookings`
  MODIFY `booking_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `commissions`
--
ALTER TABLE `commissions`
  MODIFY `commission_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `conversations`
--
ALTER TABLE `conversations`
  MODIFY `conversation_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `disputes`
--
ALTER TABLE `disputes`
  MODIFY `dispute_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dispute_events`
--
ALTER TABLE `dispute_events`
  MODIFY `event_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `freelancer_payment_methods`
--
ALTER TABLE `freelancer_payment_methods`
  MODIFY `method_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `freelancer_profiles`
--
ALTER TABLE `freelancer_profiles`
  MODIFY `freelancer_profile_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `message_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=114;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=52;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `payment_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `plans`
--
ALTER TABLE `plans`
  MODIFY `plan_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reports`
--
ALTER TABLE `reports`
  MODIFY `report_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `reviews`
--
ALTER TABLE `reviews`
  MODIFY `review_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `services`
--
ALTER TABLE `services`
  MODIFY `service_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `service_categories`
--
ALTER TABLE `service_categories`
  MODIFY `category_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `service_media`
--
ALTER TABLE `service_media`
  MODIFY `media_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `subscriptions`
--
ALTER TABLE `subscriptions`
  MODIFY `subscription_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `user_warnings`
--
ALTER TABLE `user_warnings`
  MODIFY `warning_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wallets`
--
ALTER TABLE `wallets`
  MODIFY `wallet_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `wallet_transactions`
--
ALTER TABLE `wallet_transactions`
  MODIFY `wallet_txn_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `admin_action_logs`
--
ALTER TABLE `admin_action_logs`
  ADD CONSTRAINT `fk_admin_logs_admin` FOREIGN KEY (`admin_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `bookings`
--
ALTER TABLE `bookings`
  ADD CONSTRAINT `bookings_ibfk_1` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `bookings_ibfk_2` FOREIGN KEY (`client_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `bookings_ibfk_3` FOREIGN KEY (`freelancer_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `commissions`
--
ALTER TABLE `commissions`
  ADD CONSTRAINT `commissions_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`booking_id`) ON DELETE CASCADE;

--
-- Constraints for table `conversations`
--
ALTER TABLE `conversations`
  ADD CONSTRAINT `conversations_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`booking_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `conversations_ibfk_2` FOREIGN KEY (`client_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `conversations_ibfk_3` FOREIGN KEY (`freelancer_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `disputes`
--
ALTER TABLE `disputes`
  ADD CONSTRAINT `disputes_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`booking_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `disputes_ibfk_2` FOREIGN KEY (`raised_by_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `disputes_ibfk_3` FOREIGN KEY (`against_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `dispute_events`
--
ALTER TABLE `dispute_events`
  ADD CONSTRAINT `dispute_events_ibfk_1` FOREIGN KEY (`dispute_id`) REFERENCES `disputes` (`dispute_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `dispute_events_ibfk_2` FOREIGN KEY (`actor_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `freelancer_payment_methods`
--
ALTER TABLE `freelancer_payment_methods`
  ADD CONSTRAINT `fk_fpm_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `freelancer_profiles`
--
ALTER TABLE `freelancer_profiles`
  ADD CONSTRAINT `freelancer_profiles_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `messages`
--
ALTER TABLE `messages`
  ADD CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`conversation_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `messages_ibfk_2` FOREIGN KEY (`sender_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `messages_ibfk_3` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`booking_id`) ON DELETE SET NULL;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `fk_payments_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`booking_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_payments_receiver_method` FOREIGN KEY (`receiver_method_id`) REFERENCES `freelancer_payment_methods` (`method_id`) ON DELETE SET NULL;

--
-- Constraints for table `reports`
--
ALTER TABLE `reports`
  ADD CONSTRAINT `fk_reports_admin` FOREIGN KEY (`admin_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_reports_reporter` FOREIGN KEY (`reporter_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `reviews`
--
ALTER TABLE `reviews`
  ADD CONSTRAINT `reviews_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`booking_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reviews_ibfk_2` FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reviews_ibfk_3` FOREIGN KEY (`reviewee_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `services`
--
ALTER TABLE `services`
  ADD CONSTRAINT `services_ibfk_1` FOREIGN KEY (`freelancer_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `services_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `service_categories` (`category_id`) ON DELETE SET NULL;

--
-- Constraints for table `service_categories`
--
ALTER TABLE `service_categories`
  ADD CONSTRAINT `service_categories_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `service_categories` (`category_id`) ON DELETE SET NULL;

--
-- Constraints for table `service_media`
--
ALTER TABLE `service_media`
  ADD CONSTRAINT `service_media_ibfk_1` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) ON DELETE CASCADE;

--
-- Constraints for table `subscriptions`
--
ALTER TABLE `subscriptions`
  ADD CONSTRAINT `subscriptions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `subscriptions_ibfk_2` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`plan_id`);

--
-- Constraints for table `user_warnings`
--
ALTER TABLE `user_warnings`
  ADD CONSTRAINT `fk_warn_admin` FOREIGN KEY (`admin_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_warn_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `wallets`
--
ALTER TABLE `wallets`
  ADD CONSTRAINT `wallets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `wallet_transactions`
--
ALTER TABLE `wallet_transactions`
  ADD CONSTRAINT `wallet_transactions_ibfk_1` FOREIGN KEY (`wallet_id`) REFERENCES `wallets` (`wallet_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `wallet_transactions_ibfk_2` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`booking_id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
