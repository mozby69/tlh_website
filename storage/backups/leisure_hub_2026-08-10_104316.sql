-- The Leisure Hub database backup
-- Application version: 1.2.9
-- Database: leisure_hub
-- Server version: 10.4.32-MariaDB
-- Created: 2026-08-10 10:43:16 PST

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;
SET UNIQUE_CHECKS=0;
SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';

-- --------------------------------------------------------
-- Table structure for `admins`

DROP TABLE IF EXISTS `admins`;
CREATE TABLE `admins` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `full_name` varchar(120) NOT NULL,
  `username` varchar(80) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','staff') NOT NULL DEFAULT 'staff',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for `admins`
INSERT INTO `admins` (`id`, `full_name`, `username`, `password_hash`, `role`, `is_active`, `created_at`) VALUES (1, 'System Administrator', 'admin', '$2y$10$pDp3RgxHa5bk.fdoPXqTsu5xEca.P9Bs4HjhmLP0oya1Rmr.0tjoe', 'admin', 1, '2026-08-06 08:17:06');
INSERT INTO `admins` (`id`, `full_name`, `username`, `password_hash`, `role`, `is_active`, `created_at`) VALUES (2, 'JAY DAMALERIO', 'jayjam', '$2y$10$qTBpnrF90sI.6N4/HrNFHOkoJxdhSa/FRPCkK/jKeYmlcqDHmXd7e', 'admin', 1, '2026-08-06 16:00:07');

-- --------------------------------------------------------
-- Table structure for `admin_notifications`

DROP TABLE IF EXISTS `admin_notifications`;
CREATE TABLE `admin_notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `notification_type` varchar(40) NOT NULL DEFAULT 'website_reservation',
  `reservation_id` bigint(20) unsigned DEFAULT NULL,
  `title` varchar(180) NOT NULL,
  `message` varchar(500) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_by` int(10) unsigned DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_notification_type_reservation` (`notification_type`,`reservation_id`),
  KEY `idx_admin_notifications_unread` (`is_read`,`created_at`),
  KEY `idx_admin_notifications_reservation` (`reservation_id`),
  KEY `fk_admin_notification_reader` (`read_by`),
  CONSTRAINT `fk_admin_notification_reader` FOREIGN KEY (`read_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_admin_notification_reservation` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=517 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data for `admin_notifications`
INSERT INTO `admin_notifications` (`id`, `notification_type`, `reservation_id`, `title`, `message`, `is_read`, `read_by`, `read_at`, `created_at`) VALUES (1, 'website_reservation', 99, 'New website reservation request - TLH1', 'JAY PASTOR DAMALERIO | Aug 15, 2026 8:00 AM - 10:00 AM | Pending - Not holding slot', 1, 1, '2026-08-10 08:16:45', '2026-08-10 08:03:42');

-- --------------------------------------------------------
-- Table structure for `announcements`

DROP TABLE IF EXISTS `announcements`;
CREATE TABLE `announcements` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(180) NOT NULL,
  `body` text NOT NULL,
  `publish_start` date DEFAULT NULL,
  `publish_end` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for `announcements`
INSERT INTO `announcements` (`id`, `title`, `body`, `publish_start`, `publish_end`, `is_active`, `created_at`) VALUES (1, 'Welcome to The Leisure Hub', 'Reservations are now open for basketball, volleyball, and private events.', NULL, NULL, 1, '2026-08-06 08:17:06');

-- --------------------------------------------------------
-- Table structure for `batch_payments`

DROP TABLE IF EXISTS `batch_payments`;
CREATE TABLE `batch_payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `batch_id` bigint(20) unsigned NOT NULL,
  `batch_payment_no` varchar(40) NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `payment_method` varchar(80) NOT NULL,
  `payment_reference` varchar(120) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `coverage_type` varchar(30) NOT NULL DEFAULT 'entire_batch',
  `coverage_start` date DEFAULT NULL,
  `coverage_end` date DEFAULT NULL,
  `coverage_label` varchar(255) DEFAULT NULL,
  `coverage_count` int(10) unsigned NOT NULL DEFAULT 0,
  `allocation_count` int(10) unsigned NOT NULL DEFAULT 0,
  `recorded_by` int(10) unsigned DEFAULT NULL,
  `paid_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `batch_payment_no` (`batch_payment_no`),
  KEY `idx_batch_payment_batch` (`batch_id`,`paid_at`),
  KEY `fk_batch_payment_admin` (`recorded_by`),
  CONSTRAINT `fk_batch_payment_admin` FOREIGN KEY (`recorded_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_batch_payment_batch` FOREIGN KEY (`batch_id`) REFERENCES `reservation_batches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for `batch_payments`
INSERT INTO `batch_payments` (`id`, `batch_id`, `batch_payment_no`, `amount`, `payment_method`, `payment_reference`, `notes`, `coverage_type`, `coverage_start`, `coverage_end`, `coverage_label`, `coverage_count`, `allocation_count`, `recorded_by`, `paid_at`, `created_at`) VALUES (3, 5, 'BPMT-260807-D68B63', 100000.00, 'Cash', NULL, NULL, 'entire_batch', '2026-08-07', '2026-08-31', 'Entire payable batch', 17, 11, 1, '2026-08-07 13:06:00', '2026-08-07 13:08:37');
INSERT INTO `batch_payments` (`id`, `batch_id`, `batch_payment_no`, `amount`, `payment_method`, `payment_reference`, `notes`, `coverage_type`, `coverage_start`, `coverage_end`, `coverage_label`, `coverage_count`, `allocation_count`, `recorded_by`, `paid_at`, `created_at`) VALUES (4, 5, 'BPMT-260807-295181', 20000.00, 'Cash', NULL, NULL, 'entire_batch', '2026-08-21', '2026-08-31', 'Entire payable batch', 7, 3, 1, '2026-08-21 13:09:00', '2026-08-07 13:09:41');
INSERT INTO `batch_payments` (`id`, `batch_id`, `batch_payment_no`, `amount`, `payment_method`, `payment_reference`, `notes`, `coverage_type`, `coverage_start`, `coverage_end`, `coverage_label`, `coverage_count`, `allocation_count`, `recorded_by`, `paid_at`, `created_at`) VALUES (5, 5, 'BPMT-260807-F395FC', 41500.00, 'Cash', NULL, NULL, 'entire_batch', '2026-08-25', '2026-08-31', 'Entire payable batch', 5, 5, 1, '2026-08-07 13:10:00', '2026-08-07 13:10:41');
INSERT INTO `batch_payments` (`id`, `batch_id`, `batch_payment_no`, `amount`, `payment_method`, `payment_reference`, `notes`, `coverage_type`, `coverage_start`, `coverage_end`, `coverage_label`, `coverage_count`, `allocation_count`, `recorded_by`, `paid_at`, `created_at`) VALUES (6, 5, 'BPMT-260807-395D6F', 4500.00, 'Cash', NULL, NULL, 'entire_batch', '2026-08-11', '2026-08-11', 'Entire payable batch', 1, 1, 1, '2026-08-07 14:33:00', '2026-08-07 14:34:21');

-- --------------------------------------------------------
-- Table structure for `batch_payment_scopes`

DROP TABLE IF EXISTS `batch_payment_scopes`;
CREATE TABLE `batch_payment_scopes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `batch_payment_id` bigint(20) unsigned NOT NULL,
  `reservation_id` bigint(20) unsigned NOT NULL,
  `reservation_reference` varchar(40) DEFAULT NULL,
  `event_start_snapshot` datetime DEFAULT NULL,
  `event_end_snapshot` datetime DEFAULT NULL,
  `balance_before` decimal(14,2) NOT NULL DEFAULT 0.00,
  `allocated_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_batch_payment_scope` (`batch_payment_id`,`reservation_id`),
  KEY `idx_batch_payment_scope_reservation` (`reservation_id`),
  CONSTRAINT `fk_batch_payment_scope_payment` FOREIGN KEY (`batch_payment_id`) REFERENCES `batch_payments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_batch_payment_scope_reservation` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for `batch_payment_scopes`
INSERT INTO `batch_payment_scopes` (`id`, `batch_payment_id`, `reservation_id`, `reservation_reference`, `event_start_snapshot`, `event_end_snapshot`, `balance_before`, `allocated_amount`, `created_at`) VALUES (1, 3, 79, 'TLH-260807-4F10B8', '2026-08-07 18:00:00', '2026-08-07 20:00:00', 9500.00, 9500.00, '2026-08-07 13:08:37');
INSERT INTO `batch_payment_scopes` (`id`, `batch_payment_id`, `reservation_id`, `reservation_reference`, `event_start_snapshot`, `event_end_snapshot`, `balance_before`, `allocated_amount`, `created_at`) VALUES (2, 3, 80, 'TLH-260807-4AC101', '2026-08-10 18:00:00', '2026-08-10 20:00:00', 9500.00, 9500.00, '2026-08-07 13:08:37');
INSERT INTO `batch_payment_scopes` (`id`, `batch_payment_id`, `reservation_id`, `reservation_reference`, `event_start_snapshot`, `event_end_snapshot`, `balance_before`, `allocated_amount`, `created_at`) VALUES (3, 3, 81, 'TLH-260807-002B53', '2026-08-11 18:00:00', '2026-08-11 20:00:00', 9500.00, 9500.00, '2026-08-07 13:08:37');
INSERT INTO `batch_payment_scopes` (`id`, `batch_payment_id`, `reservation_id`, `reservation_reference`, `event_start_snapshot`, `event_end_snapshot`, `balance_before`, `allocated_amount`, `created_at`) VALUES (4, 3, 82, 'TLH-260807-2E8B6B', '2026-08-12 18:00:00', '2026-08-12 20:00:00', 9500.00, 9500.00, '2026-08-07 13:08:37');
INSERT INTO `batch_payment_scopes` (`id`, `batch_payment_id`, `reservation_id`, `reservation_reference`, `event_start_snapshot`, `event_end_snapshot`, `balance_before`, `allocated_amount`, `created_at`) VALUES (5, 3, 83, 'TLH-260807-20FE07', '2026-08-13 18:00:00', '2026-08-13 20:00:00', 9500.00, 9500.00, '2026-08-07 13:08:37');
INSERT INTO `batch_payment_scopes` (`id`, `batch_payment_id`, `reservation_id`, `reservation_reference`, `event_start_snapshot`, `event_end_snapshot`, `balance_before`, `allocated_amount`, `created_at`) VALUES (6, 3, 84, 'TLH-260807-703080', '2026-08-14 18:00:00', '2026-08-14 20:00:00', 9500.00, 9500.00, '2026-08-07 13:08:37');
INSERT INTO `batch_payment_scopes` (`id`, `batch_payment_id`, `reservation_id`, `reservation_reference`, `event_start_snapshot`, `event_end_snapshot`, `balance_before`, `allocated_amount`, `created_at`) VALUES (7, 3, 85, 'TLH-260807-6DC3BE', '2026-08-17 18:00:00', '2026-08-17 20:00:00', 9500.00, 9500.00, '2026-08-07 13:08:37');
INSERT INTO `batch_payment_scopes` (`id`, `batch_payment_id`, `reservation_id`, `reservation_reference`, `event_start_snapshot`, `event_end_snapshot`, `balance_before`, `allocated_amount`, `created_at`) VALUES (8, 3, 86, 'TLH-260807-B94D87', '2026-08-18 18:00:00', '2026-08-18 20:00:00', 9500.00, 9500.00, '2026-08-07 13:08:37');
INSERT INTO `batch_payment_scopes` (`id`, `batch_payment_id`, `reservation_id`, `reservation_reference`, `event_start_snapshot`, `event_end_snapshot`, `balance_before`, `allocated_amount`, `created_at`) VALUES (9, 3, 87, 'TLH-260807-C60883', '2026-08-19 18:00:00', '2026-08-19 20:00:00', 9500.00, 9500.00, '2026-08-07 13:08:37');
INSERT INTO `batch_payment_scopes` (`id`, `batch_payment_id`, `reservation_id`, `reservation_reference`, `event_start_snapshot`, `event_end_snapshot`, `balance_before`, `allocated_amount`, `created_at`) VALUES (10, 3, 88, 'TLH-260807-CABE9E', '2026-08-20 18:00:00', '2026-08-20 20:00:00', 9500.00, 9500.00, '2026-08-07 13:08:37');
INSERT INTO `batch_payment_scopes` (`id`, `batch_payment_id`, `reservation_id`, `reservation_reference`, `event_start_snapshot`, `event_end_snapshot`, `balance_before`, `allocated_amount`, `created_at`) VALUES (11, 3, 89, 'TLH-260807-41C10A', '2026-08-21 18:00:00', '2026-08-21 20:00:00', 9500.00, 5000.00, '2026-08-07 13:08:37');
INSERT INTO `batch_payment_scopes` (`id`, `batch_payment_id`, `reservation_id`, `reservation_reference`, `event_start_snapshot`, `event_end_snapshot`, `balance_before`, `allocated_amount`, `created_at`) VALUES (12, 3, 90, 'TLH-260807-366C9B', '2026-08-24 18:00:00', '2026-08-24 20:00:00', 9500.00, 0.00, '2026-08-07 13:08:37');
INSERT INTO `batch_payment_scopes` (`id`, `batch_payment_id`, `reservation_id`, `reservation_reference`, `event_start_snapshot`, `event_end_snapshot`, `balance_before`, `allocated_amount`, `created_at`) VALUES (13, 3, 91, 'TLH-260807-EC827F', '2026-08-25 18:00:00', '2026-08-25 20:00:00', 9500.00, 0.00, '2026-08-07 13:08:37');
INSERT INTO `batch_payment_scopes` (`id`, `batch_payment_id`, `reservation_id`, `reservation_reference`, `event_start_snapshot`, `event_end_snapshot`, `balance_before`, `allocated_amount`, `created_at`) VALUES (14, 3, 92, 'TLH-260807-9BFDE6', '2026-08-26 18:00:00', '2026-08-26 20:00:00', 9500.00, 0.00, '2026-08-07 13:08:37');
INSERT INTO `batch_payment_scopes` (`id`, `batch_payment_id`, `reservation_id`, `reservation_reference`, `event_start_snapshot`, `event_end_snapshot`, `balance_before`, `allocated_amount`, `created_at`) VALUES (15, 3, 93, 'TLH-260807-8FC5FE', '2026-08-27 18:00:00', '2026-08-27 20:00:00', 9500.00, 0.00, '2026-08-07 13:08:37');
INSERT INTO `batch_payment_scopes` (`id`, `batch_payment_id`, `reservation_id`, `reservation_reference`, `event_start_snapshot`, `event_end_snapshot`, `balance_before`, `allocated_amount`, `created_at`) VALUES (16, 3, 94, 'TLH-260807-F33591', '2026-08-28 18:00:00', '2026-08-28 20:00:00', 9500.00, 0.00, '2026-08-07 13:08:37');
INSERT INTO `batch_payment_scopes` (`id`, `batch_payment_id`, `reservation_id`, `reservation_reference`, `event_start_snapshot`, `event_end_snapshot`, `balance_before`, `allocated_amount`, `created_at`) VALUES (17, 3, 95, 'TLH-260807-97F657', '2026-08-31 18:00:00', '2026-08-31 20:00:00', 9500.00, 0.00, '2026-08-07 13:08:37');
INSERT INTO `batch_payment_scopes` (`id`, `batch_payment_id`, `reservation_id`, `reservation_reference`, `event_start_snapshot`, `event_end_snapshot`, `balance_before`, `allocated_amount`, `created_at`) VALUES (18, 4, 89, 'TLH-260807-41C10A', '2026-08-21 18:00:00', '2026-08-21 20:00:00', 4500.00, 4500.00, '2026-08-07 13:09:41');
INSERT INTO `batch_payment_scopes` (`id`, `batch_payment_id`, `reservation_id`, `reservation_reference`, `event_start_snapshot`, `event_end_snapshot`, `balance_before`, `allocated_amount`, `created_at`) VALUES (19, 4, 90, 'TLH-260807-366C9B', '2026-08-24 18:00:00', '2026-08-24 20:00:00', 9500.00, 9500.00, '2026-08-07 13:09:41');
INSERT INTO `batch_payment_scopes` (`id`, `batch_payment_id`, `reservation_id`, `reservation_reference`, `event_start_snapshot`, `event_end_snapshot`, `balance_before`, `allocated_amount`, `created_at`) VALUES (20, 4, 91, 'TLH-260807-EC827F', '2026-08-25 18:00:00', '2026-08-25 20:00:00', 9500.00, 6000.00, '2026-08-07 13:09:41');
INSERT INTO `batch_payment_scopes` (`id`, `batch_payment_id`, `reservation_id`, `reservation_reference`, `event_start_snapshot`, `event_end_snapshot`, `balance_before`, `allocated_amount`, `created_at`) VALUES (21, 4, 92, 'TLH-260807-9BFDE6', '2026-08-26 18:00:00', '2026-08-26 20:00:00', 9500.00, 0.00, '2026-08-07 13:09:41');
INSERT INTO `batch_payment_scopes` (`id`, `batch_payment_id`, `reservation_id`, `reservation_reference`, `event_start_snapshot`, `event_end_snapshot`, `balance_before`, `allocated_amount`, `created_at`) VALUES (22, 4, 93, 'TLH-260807-8FC5FE', '2026-08-27 18:00:00', '2026-08-27 20:00:00', 9500.00, 0.00, '2026-08-07 13:09:41');
INSERT INTO `batch_payment_scopes` (`id`, `batch_payment_id`, `reservation_id`, `reservation_reference`, `event_start_snapshot`, `event_end_snapshot`, `balance_before`, `allocated_amount`, `created_at`) VALUES (23, 4, 94, 'TLH-260807-F33591', '2026-08-28 18:00:00', '2026-08-28 20:00:00', 9500.00, 0.00, '2026-08-07 13:09:41');
INSERT INTO `batch_payment_scopes` (`id`, `batch_payment_id`, `reservation_id`, `reservation_reference`, `event_start_snapshot`, `event_end_snapshot`, `balance_before`, `allocated_amount`, `created_at`) VALUES (24, 4, 95, 'TLH-260807-97F657', '2026-08-31 18:00:00', '2026-08-31 20:00:00', 9500.00, 0.00, '2026-08-07 13:09:41');
INSERT INTO `batch_payment_scopes` (`id`, `batch_payment_id`, `reservation_id`, `reservation_reference`, `event_start_snapshot`, `event_end_snapshot`, `balance_before`, `allocated_amount`, `created_at`) VALUES (25, 5, 91, 'TLH-260807-EC827F', '2026-08-25 18:00:00', '2026-08-25 20:00:00', 3500.00, 3500.00, '2026-08-07 13:10:41');
INSERT INTO `batch_payment_scopes` (`id`, `batch_payment_id`, `reservation_id`, `reservation_reference`, `event_start_snapshot`, `event_end_snapshot`, `balance_before`, `allocated_amount`, `created_at`) VALUES (26, 5, 92, 'TLH-260807-9BFDE6', '2026-08-26 18:00:00', '2026-08-26 20:00:00', 9500.00, 9500.00, '2026-08-07 13:10:41');
INSERT INTO `batch_payment_scopes` (`id`, `batch_payment_id`, `reservation_id`, `reservation_reference`, `event_start_snapshot`, `event_end_snapshot`, `balance_before`, `allocated_amount`, `created_at`) VALUES (27, 5, 93, 'TLH-260807-8FC5FE', '2026-08-27 18:00:00', '2026-08-27 20:00:00', 9500.00, 9500.00, '2026-08-07 13:10:41');
INSERT INTO `batch_payment_scopes` (`id`, `batch_payment_id`, `reservation_id`, `reservation_reference`, `event_start_snapshot`, `event_end_snapshot`, `balance_before`, `allocated_amount`, `created_at`) VALUES (28, 5, 94, 'TLH-260807-F33591', '2026-08-28 18:00:00', '2026-08-28 20:00:00', 9500.00, 9500.00, '2026-08-07 13:10:41');
INSERT INTO `batch_payment_scopes` (`id`, `batch_payment_id`, `reservation_id`, `reservation_reference`, `event_start_snapshot`, `event_end_snapshot`, `balance_before`, `allocated_amount`, `created_at`) VALUES (29, 5, 95, 'TLH-260807-97F657', '2026-08-31 18:00:00', '2026-08-31 20:00:00', 9500.00, 9500.00, '2026-08-07 13:10:41');
INSERT INTO `batch_payment_scopes` (`id`, `batch_payment_id`, `reservation_id`, `reservation_reference`, `event_start_snapshot`, `event_end_snapshot`, `balance_before`, `allocated_amount`, `created_at`) VALUES (30, 6, 81, 'TLH-260807-002B53', '2026-08-11 18:00:00', '2026-08-11 21:00:00', 4500.00, 4500.00, '2026-08-07 14:34:21');

-- --------------------------------------------------------
-- Table structure for `gallery_items`

DROP TABLE IF EXISTS `gallery_items`;
CREATE TABLE `gallery_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(150) NOT NULL,
  `image_path` varchar(255) NOT NULL,
  `category` enum('venue','sports','events') NOT NULL DEFAULT 'venue',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `hero_slides`

DROP TABLE IF EXISTS `hero_slides`;
CREATE TABLE `hero_slides` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `eyebrow` varchar(120) NOT NULL DEFAULT 'The Leisure Hub',
  `title` varchar(180) NOT NULL,
  `body` text DEFAULT NULL,
  `primary_label` varchar(80) DEFAULT NULL,
  `primary_url` varchar(255) DEFAULT NULL,
  `secondary_label` varchar(80) DEFAULT NULL,
  `secondary_url` varchar(255) DEFAULT NULL,
  `image_path` varchar(255) NOT NULL,
  `image_position` varchar(40) NOT NULL DEFAULT 'center 50%',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for `hero_slides`
INSERT INTO `hero_slides` (`id`, `eyebrow`, `title`, `body`, `primary_label`, `primary_url`, `secondary_label`, `secondary_url`, `image_path`, `image_position`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES (1, 'Sports & Events Venue', 'Play. Celebrate. Connect.', 'A flexible multipurpose venue for sports, celebrations, corporate functions, and community events.', 'Reserve the Venue', 'reserve.php', 'Check Availability', 'availability.php', 'assets/img/leisure-hub-hero.jpg', 'center 49%', 10, 1, '2026-08-06 08:17:06', '2026-08-06 08:17:06');
INSERT INTO `hero_slides` (`id`, `eyebrow`, `title`, `body`, `primary_label`, `primary_url`, `secondary_label`, `secondary_url`, `image_path`, `image_position`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES (2, 'Commercial Spaces for Lease', 'A place for brands to grow.', 'Bring your concept closer to athletes, families, professionals, and event guests in one active community destination.', 'Explore Leasing', 'leasing.php', 'View Our Stores', 'stores.php', 'assets/img/leisure-hub-hero.jpg', 'center 56%', 20, 1, '2026-08-06 08:17:06', '2026-08-06 08:17:06');
INSERT INTO `hero_slides` (`id`, `eyebrow`, `title`, `body`, `primary_label`, `primary_url`, `secondary_label`, `secondary_url`, `image_path`, `image_position`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES (3, 'Shops, Services & Experiences', 'More than a venue.', 'Discover the businesses, food concepts, fitness services, and everyday experiences that make The Leisure Hub a complete destination.', 'Explore Stores', 'stores.php', 'Visit The Hub', 'venue.php', 'assets/img/leisure-hub-hero.jpg', 'center 43%', 30, 1, '2026-08-06 08:17:06', '2026-08-06 08:17:06');

-- --------------------------------------------------------
-- Table structure for `inquiries`

DROP TABLE IF EXISTS `inquiries`;
CREATE TABLE `inquiries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `email` varchar(150) NOT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `subject` varchar(180) NOT NULL,
  `message` text NOT NULL,
  `status` enum('new','read','resolved') NOT NULL DEFAULT 'new',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `media_assets`

DROP TABLE IF EXISTS `media_assets`;
CREATE TABLE `media_assets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `original_name` varchar(255) DEFAULT NULL,
  `mime_type` varchar(80) NOT NULL,
  `size_bytes` bigint(20) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `media_asset_chunks`

DROP TABLE IF EXISTS `media_asset_chunks`;
CREATE TABLE `media_asset_chunks` (
  `asset_id` bigint(20) unsigned NOT NULL,
  `chunk_no` int(10) unsigned NOT NULL,
  `chunk_data` mediumblob NOT NULL,
  PRIMARY KEY (`asset_id`,`chunk_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `payments`

DROP TABLE IF EXISTS `payments`;
CREATE TABLE `payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reservation_id` bigint(20) unsigned NOT NULL,
  `batch_payment_id` bigint(20) unsigned DEFAULT NULL,
  `transaction_type` varchar(20) NOT NULL DEFAULT 'payment',
  `amount` decimal(12,2) NOT NULL,
  `payment_method` varchar(80) NOT NULL,
  `payment_reference` varchar(120) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `recorded_by` int(10) unsigned DEFAULT NULL,
  `paid_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_payment_reservation` (`reservation_id`),
  KEY `fk_payment_admin` (`recorded_by`),
  KEY `idx_payment_batch_payment` (`batch_payment_id`),
  CONSTRAINT `fk_payment_admin` FOREIGN KEY (`recorded_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_payment_reservation` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=77 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for `payments`
INSERT INTO `payments` (`id`, `reservation_id`, `batch_payment_id`, `transaction_type`, `amount`, `payment_method`, `payment_reference`, `notes`, `recorded_by`, `paid_at`, `created_at`) VALUES (50, 77, NULL, 'payment', 3000.00, 'Cash', NULL, 'Initial payment recorded when the reservation was created.', 1, '2026-08-07 11:51:02', '2026-08-07 11:51:02');
INSERT INTO `payments` (`id`, `reservation_id`, `batch_payment_id`, `transaction_type`, `amount`, `payment_method`, `payment_reference`, `notes`, `recorded_by`, `paid_at`, `created_at`) VALUES (51, 77, NULL, 'payment', 2000.00, 'Cash', NULL, '', 1, '2026-08-07 12:24:00', '2026-08-07 12:24:18');
INSERT INTO `payments` (`id`, `reservation_id`, `batch_payment_id`, `transaction_type`, `amount`, `payment_method`, `payment_reference`, `notes`, `recorded_by`, `paid_at`, `created_at`) VALUES (52, 79, 3, 'payment', 9500.00, 'Cash', NULL, 'Allocated from batch payment BPMT-260807-D68B63 for BATCH-260807-9C59EB. Coverage: Entire payable batch.', 1, '2026-08-07 13:06:00', '2026-08-07 13:08:37');
INSERT INTO `payments` (`id`, `reservation_id`, `batch_payment_id`, `transaction_type`, `amount`, `payment_method`, `payment_reference`, `notes`, `recorded_by`, `paid_at`, `created_at`) VALUES (53, 80, 3, 'payment', 9500.00, 'Cash', NULL, 'Allocated from batch payment BPMT-260807-D68B63 for BATCH-260807-9C59EB. Coverage: Entire payable batch.', 1, '2026-08-07 13:06:00', '2026-08-07 13:08:37');
INSERT INTO `payments` (`id`, `reservation_id`, `batch_payment_id`, `transaction_type`, `amount`, `payment_method`, `payment_reference`, `notes`, `recorded_by`, `paid_at`, `created_at`) VALUES (54, 81, 3, 'payment', 9500.00, 'Cash', NULL, 'Allocated from batch payment BPMT-260807-D68B63 for BATCH-260807-9C59EB. Coverage: Entire payable batch.', 1, '2026-08-07 13:06:00', '2026-08-07 13:08:37');
INSERT INTO `payments` (`id`, `reservation_id`, `batch_payment_id`, `transaction_type`, `amount`, `payment_method`, `payment_reference`, `notes`, `recorded_by`, `paid_at`, `created_at`) VALUES (55, 82, 3, 'payment', 9500.00, 'Cash', NULL, 'Allocated from batch payment BPMT-260807-D68B63 for BATCH-260807-9C59EB. Coverage: Entire payable batch.', 1, '2026-08-07 13:06:00', '2026-08-07 13:08:37');
INSERT INTO `payments` (`id`, `reservation_id`, `batch_payment_id`, `transaction_type`, `amount`, `payment_method`, `payment_reference`, `notes`, `recorded_by`, `paid_at`, `created_at`) VALUES (56, 83, 3, 'payment', 9500.00, 'Cash', NULL, 'Allocated from batch payment BPMT-260807-D68B63 for BATCH-260807-9C59EB. Coverage: Entire payable batch.', 1, '2026-08-07 13:06:00', '2026-08-07 13:08:37');
INSERT INTO `payments` (`id`, `reservation_id`, `batch_payment_id`, `transaction_type`, `amount`, `payment_method`, `payment_reference`, `notes`, `recorded_by`, `paid_at`, `created_at`) VALUES (57, 84, 3, 'payment', 9500.00, 'Cash', NULL, 'Allocated from batch payment BPMT-260807-D68B63 for BATCH-260807-9C59EB. Coverage: Entire payable batch.', 1, '2026-08-07 13:06:00', '2026-08-07 13:08:37');
INSERT INTO `payments` (`id`, `reservation_id`, `batch_payment_id`, `transaction_type`, `amount`, `payment_method`, `payment_reference`, `notes`, `recorded_by`, `paid_at`, `created_at`) VALUES (58, 85, 3, 'payment', 9500.00, 'Cash', NULL, 'Allocated from batch payment BPMT-260807-D68B63 for BATCH-260807-9C59EB. Coverage: Entire payable batch.', 1, '2026-08-07 13:06:00', '2026-08-07 13:08:37');
INSERT INTO `payments` (`id`, `reservation_id`, `batch_payment_id`, `transaction_type`, `amount`, `payment_method`, `payment_reference`, `notes`, `recorded_by`, `paid_at`, `created_at`) VALUES (59, 86, 3, 'payment', 9500.00, 'Cash', NULL, 'Allocated from batch payment BPMT-260807-D68B63 for BATCH-260807-9C59EB. Coverage: Entire payable batch.', 1, '2026-08-07 13:06:00', '2026-08-07 13:08:37');
INSERT INTO `payments` (`id`, `reservation_id`, `batch_payment_id`, `transaction_type`, `amount`, `payment_method`, `payment_reference`, `notes`, `recorded_by`, `paid_at`, `created_at`) VALUES (60, 87, 3, 'payment', 9500.00, 'Cash', NULL, 'Allocated from batch payment BPMT-260807-D68B63 for BATCH-260807-9C59EB. Coverage: Entire payable batch.', 1, '2026-08-07 13:06:00', '2026-08-07 13:08:37');
INSERT INTO `payments` (`id`, `reservation_id`, `batch_payment_id`, `transaction_type`, `amount`, `payment_method`, `payment_reference`, `notes`, `recorded_by`, `paid_at`, `created_at`) VALUES (61, 88, 3, 'payment', 9500.00, 'Cash', NULL, 'Allocated from batch payment BPMT-260807-D68B63 for BATCH-260807-9C59EB. Coverage: Entire payable batch.', 1, '2026-08-07 13:06:00', '2026-08-07 13:08:37');
INSERT INTO `payments` (`id`, `reservation_id`, `batch_payment_id`, `transaction_type`, `amount`, `payment_method`, `payment_reference`, `notes`, `recorded_by`, `paid_at`, `created_at`) VALUES (62, 89, 3, 'payment', 5000.00, 'Cash', NULL, 'Allocated from batch payment BPMT-260807-D68B63 for BATCH-260807-9C59EB. Coverage: Entire payable batch.', 1, '2026-08-07 13:06:00', '2026-08-07 13:08:37');
INSERT INTO `payments` (`id`, `reservation_id`, `batch_payment_id`, `transaction_type`, `amount`, `payment_method`, `payment_reference`, `notes`, `recorded_by`, `paid_at`, `created_at`) VALUES (63, 89, 4, 'payment', 4500.00, 'Cash', NULL, 'Allocated from batch payment BPMT-260807-295181 for BATCH-260807-9C59EB. Coverage: Entire payable batch.', 1, '2026-08-21 13:09:00', '2026-08-07 13:09:41');
INSERT INTO `payments` (`id`, `reservation_id`, `batch_payment_id`, `transaction_type`, `amount`, `payment_method`, `payment_reference`, `notes`, `recorded_by`, `paid_at`, `created_at`) VALUES (64, 90, 4, 'payment', 9500.00, 'Cash', NULL, 'Allocated from batch payment BPMT-260807-295181 for BATCH-260807-9C59EB. Coverage: Entire payable batch.', 1, '2026-08-21 13:09:00', '2026-08-07 13:09:41');
INSERT INTO `payments` (`id`, `reservation_id`, `batch_payment_id`, `transaction_type`, `amount`, `payment_method`, `payment_reference`, `notes`, `recorded_by`, `paid_at`, `created_at`) VALUES (65, 91, 4, 'payment', 6000.00, 'Cash', NULL, 'Allocated from batch payment BPMT-260807-295181 for BATCH-260807-9C59EB. Coverage: Entire payable batch.', 1, '2026-08-21 13:09:00', '2026-08-07 13:09:41');
INSERT INTO `payments` (`id`, `reservation_id`, `batch_payment_id`, `transaction_type`, `amount`, `payment_method`, `payment_reference`, `notes`, `recorded_by`, `paid_at`, `created_at`) VALUES (66, 91, 5, 'payment', 3500.00, 'Cash', NULL, 'Allocated from batch payment BPMT-260807-F395FC for BATCH-260807-9C59EB. Coverage: Entire payable batch.', 1, '2026-08-07 13:10:00', '2026-08-07 13:10:41');
INSERT INTO `payments` (`id`, `reservation_id`, `batch_payment_id`, `transaction_type`, `amount`, `payment_method`, `payment_reference`, `notes`, `recorded_by`, `paid_at`, `created_at`) VALUES (67, 92, 5, 'payment', 9500.00, 'Cash', NULL, 'Allocated from batch payment BPMT-260807-F395FC for BATCH-260807-9C59EB. Coverage: Entire payable batch.', 1, '2026-08-07 13:10:00', '2026-08-07 13:10:41');
INSERT INTO `payments` (`id`, `reservation_id`, `batch_payment_id`, `transaction_type`, `amount`, `payment_method`, `payment_reference`, `notes`, `recorded_by`, `paid_at`, `created_at`) VALUES (68, 93, 5, 'payment', 9500.00, 'Cash', NULL, 'Allocated from batch payment BPMT-260807-F395FC for BATCH-260807-9C59EB. Coverage: Entire payable batch.', 1, '2026-08-07 13:10:00', '2026-08-07 13:10:41');
INSERT INTO `payments` (`id`, `reservation_id`, `batch_payment_id`, `transaction_type`, `amount`, `payment_method`, `payment_reference`, `notes`, `recorded_by`, `paid_at`, `created_at`) VALUES (69, 94, 5, 'payment', 9500.00, 'Cash', NULL, 'Allocated from batch payment BPMT-260807-F395FC for BATCH-260807-9C59EB. Coverage: Entire payable batch.', 1, '2026-08-07 13:10:00', '2026-08-07 13:10:41');
INSERT INTO `payments` (`id`, `reservation_id`, `batch_payment_id`, `transaction_type`, `amount`, `payment_method`, `payment_reference`, `notes`, `recorded_by`, `paid_at`, `created_at`) VALUES (70, 95, 5, 'payment', 9500.00, 'Cash', NULL, 'Allocated from batch payment BPMT-260807-F395FC for BATCH-260807-9C59EB. Coverage: Entire payable batch.', 1, '2026-08-07 13:10:00', '2026-08-07 13:10:41');
INSERT INTO `payments` (`id`, `reservation_id`, `batch_payment_id`, `transaction_type`, `amount`, `payment_method`, `payment_reference`, `notes`, `recorded_by`, `paid_at`, `created_at`) VALUES (71, 96, NULL, 'payment', 5500.00, 'Cash', NULL, 'Client-submitted initial payment during online booking. Verify the transaction reference before approval.', NULL, '2026-08-07 14:17:03', '2026-08-07 14:17:03');
INSERT INTO `payments` (`id`, `reservation_id`, `batch_payment_id`, `transaction_type`, `amount`, `payment_method`, `payment_reference`, `notes`, `recorded_by`, `paid_at`, `created_at`) VALUES (72, 81, 6, 'payment', 4500.00, 'Cash', NULL, 'Allocated from batch payment BPMT-260807-395D6F for BATCH-260807-9C59EB. Coverage: Entire payable batch.', 1, '2026-08-07 14:33:00', '2026-08-07 14:34:21');
INSERT INTO `payments` (`id`, `reservation_id`, `batch_payment_id`, `transaction_type`, `amount`, `payment_method`, `payment_reference`, `notes`, `recorded_by`, `paid_at`, `created_at`) VALUES (73, 96, NULL, 'refund', -2750.00, 'Cash', NULL, '50% client-cancellation refund.', 1, '2026-08-07 15:49:00', '2026-08-07 15:49:56');
INSERT INTO `payments` (`id`, `reservation_id`, `batch_payment_id`, `transaction_type`, `amount`, `payment_method`, `payment_reference`, `notes`, `recorded_by`, `paid_at`, `created_at`) VALUES (74, 97, NULL, 'payment', 9500.00, 'Cash', NULL, 'Initial payment recorded when the reservation was created.', 1, '2026-08-07 15:51:53', '2026-08-07 15:51:53');
INSERT INTO `payments` (`id`, `reservation_id`, `batch_payment_id`, `transaction_type`, `amount`, `payment_method`, `payment_reference`, `notes`, `recorded_by`, `paid_at`, `created_at`) VALUES (75, 98, NULL, 'payment', 3500.00, 'Cash', NULL, '', 1, '2026-08-07 16:20:00', '2026-08-07 16:21:02');
INSERT INTO `payments` (`id`, `reservation_id`, `batch_payment_id`, `transaction_type`, `amount`, `payment_method`, `payment_reference`, `notes`, `recorded_by`, `paid_at`, `created_at`) VALUES (76, 99, NULL, 'payment', 9500.00, 'Cash', NULL, 'Client-submitted initial payment during online booking. Verify the transaction reference before approval.', NULL, '2026-08-10 08:03:42', '2026-08-10 08:03:42');

-- --------------------------------------------------------
-- Table structure for `reference_sequences`

DROP TABLE IF EXISTS `reference_sequences`;
CREATE TABLE `reference_sequences` (
  `sequence_name` varchar(40) NOT NULL,
  `current_value` bigint(20) unsigned NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`sequence_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data for `reference_sequences`
INSERT INTO `reference_sequences` (`sequence_name`, `current_value`, `updated_at`) VALUES ('reservation', 1, '2026-08-10 08:03:42');

-- --------------------------------------------------------
-- Table structure for `reservations`

DROP TABLE IF EXISTS `reservations`;
CREATE TABLE `reservations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reference_no` varchar(30) NOT NULL,
  `reservation_type` enum('basketball','volleyball','event') NOT NULL,
  `purpose` varchar(150) DEFAULT NULL,
  `client_name` varchar(150) NOT NULL,
  `organization` varchar(150) DEFAULT NULL,
  `email` varchar(150) NOT NULL,
  `phone` varchar(40) NOT NULL,
  `booking_payment_method` varchar(80) DEFAULT NULL,
  `booking_payment_reference` varchar(120) DEFAULT NULL,
  `event_start` datetime NOT NULL,
  `event_end` datetime NOT NULL,
  `setup_minutes` int(10) unsigned NOT NULL DEFAULT 0,
  `cleanup_minutes` int(10) unsigned NOT NULL DEFAULT 0,
  `blocked_start` datetime NOT NULL,
  `blocked_end` datetime NOT NULL,
  `guest_count` int(10) unsigned DEFAULT NULL,
  `pricing_package` varchar(30) DEFAULT NULL,
  `cooling_option` varchar(20) DEFAULT NULL,
  `rate_period` varchar(30) DEFAULT NULL,
  `hourly_rate` decimal(12,2) DEFAULT NULL,
  `billable_hours` int(10) unsigned DEFAULT NULL,
  `shower_room_addon` tinyint(1) NOT NULL DEFAULT 0,
  `shower_room_fee` decimal(12,2) NOT NULL DEFAULT 0.00,
  `equipment_bundle_addon` tinyint(1) NOT NULL DEFAULT 0,
  `equipment_bundle_fee` decimal(12,2) NOT NULL DEFAULT 0.00,
  `pricing_snapshot` text DEFAULT NULL,
  `details` text DEFAULT NULL,
  `equipment_requests` text DEFAULT NULL,
  `special_instructions` text DEFAULT NULL,
  `estimated_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `final_amount` decimal(12,2) DEFAULT NULL,
  `amount_paid` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_status` enum('unpaid','partial','paid','refunded') NOT NULL DEFAULT 'unpaid',
  `status` enum('pending','for_review','approved','rejected','cancelled','completed','no_show') NOT NULL DEFAULT 'pending',
  `source` enum('website','walk_in','internal') NOT NULL DEFAULT 'website',
  `admin_notes` text DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL,
  `archived_by` int(10) unsigned DEFAULT NULL,
  `batch_id` bigint(20) unsigned DEFAULT NULL,
  `batch_occurrence` int(10) unsigned DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `reference_no` (`reference_no`),
  KEY `idx_reservation_dates` (`blocked_start`,`blocked_end`),
  KEY `idx_reservation_status` (`status`),
  KEY `fk_reservation_admin` (`created_by`),
  KEY `idx_reservation_archived` (`archived_at`),
  KEY `idx_reservation_batch` (`batch_id`,`batch_occurrence`),
  CONSTRAINT `fk_reservation_admin` FOREIGN KEY (`created_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=100 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for `reservations`
INSERT INTO `reservations` (`id`, `reference_no`, `reservation_type`, `purpose`, `client_name`, `organization`, `email`, `phone`, `booking_payment_method`, `booking_payment_reference`, `event_start`, `event_end`, `setup_minutes`, `cleanup_minutes`, `blocked_start`, `blocked_end`, `guest_count`, `pricing_package`, `cooling_option`, `rate_period`, `hourly_rate`, `billable_hours`, `shower_room_addon`, `shower_room_fee`, `equipment_bundle_addon`, `equipment_bundle_fee`, `pricing_snapshot`, `details`, `equipment_requests`, `special_instructions`, `estimated_amount`, `final_amount`, `amount_paid`, `payment_status`, `status`, `source`, `admin_notes`, `archived_at`, `archived_by`, `batch_id`, `batch_occurrence`, `created_by`, `created_at`, `updated_at`) VALUES (77, 'TLH-260807-8741F7', 'basketball', 'Friendly Game', 'JAY PASTOR DAMALERIO', 'EMB', 'damalerio_jay@yahoo.com', '09565199954', 'Cash', NULL, '2026-08-10 17:00:00', '2026-08-10 18:00:00', 0, 0, '2026-08-10 17:00:00', '2026-08-10 18:00:00', 20, 'regular', 'aircon', 'introductory', 4500.00, 1, 1, 500.00, 0, 0.00, '{\"package\":\"regular\",\"package_label\":\"Regular Booking\",\"rate_period\":\"introductory\",\"cooling_option\":\"aircon\",\"cooling_label\":\"Aircon with Lights\",\"billable_hours\":1,\"hourly_rate\":4500,\"base_amount\":4500,\"shower_room_addon\":true,\"shower_room_fee\":500,\"equipment_bundle_addon\":false,\"equipment_bundle_included\":false,\"equipment_bundle_fee\":0,\"package_inclusions\":[],\"total\":5000,\"intro_start\":\"2026-08-01\",\"intro_end\":\"2026-10-31\"}', NULL, NULL, NULL, 5000.00, NULL, 5000.00, 'paid', 'approved', 'internal', NULL, NULL, NULL, NULL, NULL, 1, '2026-08-07 11:51:02', '2026-08-07 12:24:18');
INSERT INTO `reservations` (`id`, `reference_no`, `reservation_type`, `purpose`, `client_name`, `organization`, `email`, `phone`, `booking_payment_method`, `booking_payment_reference`, `event_start`, `event_end`, `setup_minutes`, `cleanup_minutes`, `blocked_start`, `blocked_end`, `guest_count`, `pricing_package`, `cooling_option`, `rate_period`, `hourly_rate`, `billable_hours`, `shower_room_addon`, `shower_room_fee`, `equipment_bundle_addon`, `equipment_bundle_fee`, `pricing_snapshot`, `details`, `equipment_requests`, `special_instructions`, `estimated_amount`, `final_amount`, `amount_paid`, `payment_status`, `status`, `source`, `admin_notes`, `archived_at`, `archived_by`, `batch_id`, `batch_occurrence`, `created_by`, `created_at`, `updated_at`) VALUES (78, 'TLH-260807-588BB0', 'volleyball', 'Practice', 'JAY PASTOR DAMALERIO', 'EMB', 'damalerio_jay@yahoo.com', '+639565199954', 'Cash', NULL, '2026-08-12 08:00:00', '2026-08-12 10:00:00', 0, 0, '2026-08-12 08:00:00', '2026-08-12 10:00:00', 25, 'regular', 'aircon', 'introductory', 4500.00, 2, 1, 500.00, 0, 0.00, '{\"package\":\"regular\",\"package_label\":\"Regular Booking\",\"rate_period\":\"introductory\",\"cooling_option\":\"aircon\",\"cooling_label\":\"Aircon with Lights\",\"billable_hours\":2,\"hourly_rate\":4500,\"base_amount\":9000,\"shower_room_addon\":true,\"shower_room_fee\":500,\"equipment_bundle_addon\":false,\"equipment_bundle_included\":false,\"equipment_bundle_fee\":0,\"package_inclusions\":[],\"total\":9500,\"intro_start\":\"2026-08-01\",\"intro_end\":\"2026-10-31\"}', NULL, NULL, NULL, 9500.00, NULL, 0.00, 'unpaid', 'approved', 'website', '', NULL, NULL, NULL, NULL, NULL, '2026-08-07 12:26:55', '2026-08-07 12:33:53');
INSERT INTO `reservations` (`id`, `reference_no`, `reservation_type`, `purpose`, `client_name`, `organization`, `email`, `phone`, `booking_payment_method`, `booking_payment_reference`, `event_start`, `event_end`, `setup_minutes`, `cleanup_minutes`, `blocked_start`, `blocked_end`, `guest_count`, `pricing_package`, `cooling_option`, `rate_period`, `hourly_rate`, `billable_hours`, `shower_room_addon`, `shower_room_fee`, `equipment_bundle_addon`, `equipment_bundle_fee`, `pricing_snapshot`, `details`, `equipment_requests`, `special_instructions`, `estimated_amount`, `final_amount`, `amount_paid`, `payment_status`, `status`, `source`, `admin_notes`, `archived_at`, `archived_by`, `batch_id`, `batch_occurrence`, `created_by`, `created_at`, `updated_at`) VALUES (79, 'TLH-260807-4F10B8', 'basketball', 'Practice', 'LEO BESANES', 'EMB', 'damalerio_jay@yahoo.com', '09565199954', NULL, NULL, '2026-08-07 18:00:00', '2026-08-07 20:00:00', 0, 0, '2026-08-07 18:00:00', '2026-08-07 20:00:00', 24, 'regular', 'aircon', 'introductory', 4500.00, 2, 1, 500.00, 0, 0.00, '{\"package\":\"regular\",\"package_label\":\"Regular Booking\",\"rate_period\":\"introductory\",\"cooling_option\":\"aircon\",\"cooling_label\":\"Aircon with Lights\",\"billable_hours\":2,\"hourly_rate\":4500,\"base_amount\":9000,\"shower_room_addon\":true,\"shower_room_fee\":500,\"equipment_bundle_addon\":false,\"equipment_bundle_included\":false,\"equipment_bundle_fee\":0,\"package_inclusions\":[],\"total\":9500,\"intro_start\":\"2026-08-01\",\"intro_end\":\"2026-10-31\"}', NULL, NULL, NULL, 9500.00, NULL, 9500.00, 'paid', 'approved', 'walk_in', NULL, NULL, NULL, 5, 1, 1, '2026-08-07 13:06:39', '2026-08-07 13:08:37');
INSERT INTO `reservations` (`id`, `reference_no`, `reservation_type`, `purpose`, `client_name`, `organization`, `email`, `phone`, `booking_payment_method`, `booking_payment_reference`, `event_start`, `event_end`, `setup_minutes`, `cleanup_minutes`, `blocked_start`, `blocked_end`, `guest_count`, `pricing_package`, `cooling_option`, `rate_period`, `hourly_rate`, `billable_hours`, `shower_room_addon`, `shower_room_fee`, `equipment_bundle_addon`, `equipment_bundle_fee`, `pricing_snapshot`, `details`, `equipment_requests`, `special_instructions`, `estimated_amount`, `final_amount`, `amount_paid`, `payment_status`, `status`, `source`, `admin_notes`, `archived_at`, `archived_by`, `batch_id`, `batch_occurrence`, `created_by`, `created_at`, `updated_at`) VALUES (80, 'TLH-260807-4AC101', 'basketball', 'Practice', 'LEO BESANES', 'EMB', 'damalerio_jay@yahoo.com', '09565199954', NULL, NULL, '2026-08-10 18:00:00', '2026-08-10 20:00:00', 0, 0, '2026-08-10 18:00:00', '2026-08-10 20:00:00', 24, 'regular', 'aircon', 'introductory', 4500.00, 2, 1, 500.00, 0, 0.00, '{\"package\":\"regular\",\"package_label\":\"Regular Booking\",\"rate_period\":\"introductory\",\"cooling_option\":\"aircon\",\"cooling_label\":\"Aircon with Lights\",\"billable_hours\":2,\"hourly_rate\":4500,\"base_amount\":9000,\"shower_room_addon\":true,\"shower_room_fee\":500,\"equipment_bundle_addon\":false,\"equipment_bundle_included\":false,\"equipment_bundle_fee\":0,\"package_inclusions\":[],\"total\":9500,\"intro_start\":\"2026-08-01\",\"intro_end\":\"2026-10-31\"}', NULL, NULL, NULL, 9500.00, NULL, 9500.00, 'paid', 'approved', 'walk_in', NULL, NULL, NULL, 5, 2, 1, '2026-08-07 13:06:39', '2026-08-07 13:08:37');
INSERT INTO `reservations` (`id`, `reference_no`, `reservation_type`, `purpose`, `client_name`, `organization`, `email`, `phone`, `booking_payment_method`, `booking_payment_reference`, `event_start`, `event_end`, `setup_minutes`, `cleanup_minutes`, `blocked_start`, `blocked_end`, `guest_count`, `pricing_package`, `cooling_option`, `rate_period`, `hourly_rate`, `billable_hours`, `shower_room_addon`, `shower_room_fee`, `equipment_bundle_addon`, `equipment_bundle_fee`, `pricing_snapshot`, `details`, `equipment_requests`, `special_instructions`, `estimated_amount`, `final_amount`, `amount_paid`, `payment_status`, `status`, `source`, `admin_notes`, `archived_at`, `archived_by`, `batch_id`, `batch_occurrence`, `created_by`, `created_at`, `updated_at`) VALUES (81, 'TLH-260807-002B53', 'basketball', 'Practice', 'LEO BESANES', 'EMB', 'damalerio_jay@yahoo.com', '09565199954', NULL, NULL, '2026-08-11 18:00:00', '2026-08-11 21:00:00', 0, 0, '2026-08-11 18:00:00', '2026-08-11 21:00:00', 24, 'regular', 'aircon', 'introductory', 4500.00, 3, 1, 500.00, 0, 0.00, '{\"package\":\"regular\",\"package_label\":\"Regular Booking\",\"rate_period\":\"introductory\",\"cooling_option\":\"aircon\",\"cooling_label\":\"Aircon with Lights\",\"billable_hours\":3,\"hourly_rate\":4500,\"base_amount\":13500,\"shower_room_addon\":true,\"shower_room_fee\":500,\"equipment_bundle_addon\":false,\"equipment_bundle_included\":false,\"equipment_bundle_fee\":0,\"package_inclusions\":[],\"total\":14000,\"intro_start\":\"2026-08-01\",\"intro_end\":\"2026-10-31\",\"last_extension\":{\"added_hours\":1,\"additional_charge\":4500,\"previous_event_end\":\"2026-08-11 20:00:00\",\"new_event_end\":\"2026-08-11 21:00:00\"}}', NULL, NULL, NULL, 14000.00, NULL, 14000.00, 'paid', 'approved', 'walk_in', NULL, NULL, NULL, 5, 3, 1, '2026-08-07 13:06:39', '2026-08-07 14:34:21');
INSERT INTO `reservations` (`id`, `reference_no`, `reservation_type`, `purpose`, `client_name`, `organization`, `email`, `phone`, `booking_payment_method`, `booking_payment_reference`, `event_start`, `event_end`, `setup_minutes`, `cleanup_minutes`, `blocked_start`, `blocked_end`, `guest_count`, `pricing_package`, `cooling_option`, `rate_period`, `hourly_rate`, `billable_hours`, `shower_room_addon`, `shower_room_fee`, `equipment_bundle_addon`, `equipment_bundle_fee`, `pricing_snapshot`, `details`, `equipment_requests`, `special_instructions`, `estimated_amount`, `final_amount`, `amount_paid`, `payment_status`, `status`, `source`, `admin_notes`, `archived_at`, `archived_by`, `batch_id`, `batch_occurrence`, `created_by`, `created_at`, `updated_at`) VALUES (82, 'TLH-260807-2E8B6B', 'basketball', 'Practice', 'LEO BESANES', 'EMB', 'damalerio_jay@yahoo.com', '09565199954', NULL, NULL, '2026-08-12 18:00:00', '2026-08-12 20:00:00', 0, 0, '2026-08-12 18:00:00', '2026-08-12 20:00:00', 24, 'regular', 'aircon', 'introductory', 4500.00, 2, 1, 500.00, 0, 0.00, '{\"package\":\"regular\",\"package_label\":\"Regular Booking\",\"rate_period\":\"introductory\",\"cooling_option\":\"aircon\",\"cooling_label\":\"Aircon with Lights\",\"billable_hours\":2,\"hourly_rate\":4500,\"base_amount\":9000,\"shower_room_addon\":true,\"shower_room_fee\":500,\"equipment_bundle_addon\":false,\"equipment_bundle_included\":false,\"equipment_bundle_fee\":0,\"package_inclusions\":[],\"total\":9500,\"intro_start\":\"2026-08-01\",\"intro_end\":\"2026-10-31\"}', NULL, NULL, NULL, 9500.00, NULL, 9500.00, 'paid', 'approved', 'walk_in', NULL, NULL, NULL, 5, 4, 1, '2026-08-07 13:06:39', '2026-08-07 13:08:37');
INSERT INTO `reservations` (`id`, `reference_no`, `reservation_type`, `purpose`, `client_name`, `organization`, `email`, `phone`, `booking_payment_method`, `booking_payment_reference`, `event_start`, `event_end`, `setup_minutes`, `cleanup_minutes`, `blocked_start`, `blocked_end`, `guest_count`, `pricing_package`, `cooling_option`, `rate_period`, `hourly_rate`, `billable_hours`, `shower_room_addon`, `shower_room_fee`, `equipment_bundle_addon`, `equipment_bundle_fee`, `pricing_snapshot`, `details`, `equipment_requests`, `special_instructions`, `estimated_amount`, `final_amount`, `amount_paid`, `payment_status`, `status`, `source`, `admin_notes`, `archived_at`, `archived_by`, `batch_id`, `batch_occurrence`, `created_by`, `created_at`, `updated_at`) VALUES (83, 'TLH-260807-20FE07', 'basketball', 'Practice', 'LEO BESANES', 'EMB', 'damalerio_jay@yahoo.com', '09565199954', NULL, NULL, '2026-08-13 13:00:00', '2026-08-13 15:00:00', 0, 0, '2026-08-13 13:00:00', '2026-08-13 15:00:00', 24, 'regular', 'aircon', 'introductory', 4500.00, 2, 1, 500.00, 0, 0.00, '{\"package\":\"regular\",\"package_label\":\"Regular Booking\",\"rate_period\":\"introductory\",\"cooling_option\":\"aircon\",\"cooling_label\":\"Aircon with Lights\",\"billable_hours\":2,\"hourly_rate\":4500,\"base_amount\":9000,\"shower_room_addon\":true,\"shower_room_fee\":500,\"equipment_bundle_addon\":false,\"equipment_bundle_included\":false,\"equipment_bundle_fee\":0,\"package_inclusions\":[],\"total\":9500,\"intro_start\":\"2026-08-01\",\"intro_end\":\"2026-10-31\"}', NULL, NULL, NULL, 9500.00, 9500.00, 9500.00, 'paid', 'approved', 'walk_in', NULL, NULL, NULL, 5, 5, 1, '2026-08-07 13:06:39', '2026-08-07 13:15:23');
INSERT INTO `reservations` (`id`, `reference_no`, `reservation_type`, `purpose`, `client_name`, `organization`, `email`, `phone`, `booking_payment_method`, `booking_payment_reference`, `event_start`, `event_end`, `setup_minutes`, `cleanup_minutes`, `blocked_start`, `blocked_end`, `guest_count`, `pricing_package`, `cooling_option`, `rate_period`, `hourly_rate`, `billable_hours`, `shower_room_addon`, `shower_room_fee`, `equipment_bundle_addon`, `equipment_bundle_fee`, `pricing_snapshot`, `details`, `equipment_requests`, `special_instructions`, `estimated_amount`, `final_amount`, `amount_paid`, `payment_status`, `status`, `source`, `admin_notes`, `archived_at`, `archived_by`, `batch_id`, `batch_occurrence`, `created_by`, `created_at`, `updated_at`) VALUES (84, 'TLH-260807-703080', 'basketball', 'Practice', 'LEO BESANES', 'EMB', 'damalerio_jay@yahoo.com', '09565199954', NULL, NULL, '2026-08-14 18:00:00', '2026-08-14 20:00:00', 0, 0, '2026-08-14 18:00:00', '2026-08-14 20:00:00', 24, 'regular', 'aircon', 'introductory', 4500.00, 2, 1, 500.00, 0, 0.00, '{\"package\":\"regular\",\"package_label\":\"Regular Booking\",\"rate_period\":\"introductory\",\"cooling_option\":\"aircon\",\"cooling_label\":\"Aircon with Lights\",\"billable_hours\":2,\"hourly_rate\":4500,\"base_amount\":9000,\"shower_room_addon\":true,\"shower_room_fee\":500,\"equipment_bundle_addon\":false,\"equipment_bundle_included\":false,\"equipment_bundle_fee\":0,\"package_inclusions\":[],\"total\":9500,\"intro_start\":\"2026-08-01\",\"intro_end\":\"2026-10-31\"}', NULL, NULL, NULL, 9500.00, NULL, 9500.00, 'paid', 'approved', 'walk_in', NULL, NULL, NULL, 5, 6, 1, '2026-08-07 13:06:39', '2026-08-07 13:08:37');
INSERT INTO `reservations` (`id`, `reference_no`, `reservation_type`, `purpose`, `client_name`, `organization`, `email`, `phone`, `booking_payment_method`, `booking_payment_reference`, `event_start`, `event_end`, `setup_minutes`, `cleanup_minutes`, `blocked_start`, `blocked_end`, `guest_count`, `pricing_package`, `cooling_option`, `rate_period`, `hourly_rate`, `billable_hours`, `shower_room_addon`, `shower_room_fee`, `equipment_bundle_addon`, `equipment_bundle_fee`, `pricing_snapshot`, `details`, `equipment_requests`, `special_instructions`, `estimated_amount`, `final_amount`, `amount_paid`, `payment_status`, `status`, `source`, `admin_notes`, `archived_at`, `archived_by`, `batch_id`, `batch_occurrence`, `created_by`, `created_at`, `updated_at`) VALUES (85, 'TLH-260807-6DC3BE', 'basketball', 'Practice', 'LEO BESANES', 'EMB', 'damalerio_jay@yahoo.com', '09565199954', NULL, NULL, '2026-08-17 18:00:00', '2026-08-17 20:00:00', 0, 0, '2026-08-17 18:00:00', '2026-08-17 20:00:00', 24, 'regular', 'aircon', 'introductory', 4500.00, 2, 1, 500.00, 0, 0.00, '{\"package\":\"regular\",\"package_label\":\"Regular Booking\",\"rate_period\":\"introductory\",\"cooling_option\":\"aircon\",\"cooling_label\":\"Aircon with Lights\",\"billable_hours\":2,\"hourly_rate\":4500,\"base_amount\":9000,\"shower_room_addon\":true,\"shower_room_fee\":500,\"equipment_bundle_addon\":false,\"equipment_bundle_included\":false,\"equipment_bundle_fee\":0,\"package_inclusions\":[],\"total\":9500,\"intro_start\":\"2026-08-01\",\"intro_end\":\"2026-10-31\"}', NULL, NULL, NULL, 9500.00, NULL, 9500.00, 'paid', 'approved', 'walk_in', NULL, NULL, NULL, 5, 7, 1, '2026-08-07 13:06:39', '2026-08-07 13:08:37');
INSERT INTO `reservations` (`id`, `reference_no`, `reservation_type`, `purpose`, `client_name`, `organization`, `email`, `phone`, `booking_payment_method`, `booking_payment_reference`, `event_start`, `event_end`, `setup_minutes`, `cleanup_minutes`, `blocked_start`, `blocked_end`, `guest_count`, `pricing_package`, `cooling_option`, `rate_period`, `hourly_rate`, `billable_hours`, `shower_room_addon`, `shower_room_fee`, `equipment_bundle_addon`, `equipment_bundle_fee`, `pricing_snapshot`, `details`, `equipment_requests`, `special_instructions`, `estimated_amount`, `final_amount`, `amount_paid`, `payment_status`, `status`, `source`, `admin_notes`, `archived_at`, `archived_by`, `batch_id`, `batch_occurrence`, `created_by`, `created_at`, `updated_at`) VALUES (86, 'TLH-260807-B94D87', 'basketball', 'Practice', 'LEO BESANES', 'EMB', 'damalerio_jay@yahoo.com', '09565199954', NULL, NULL, '2026-08-18 18:00:00', '2026-08-18 20:00:00', 0, 0, '2026-08-18 18:00:00', '2026-08-18 20:00:00', 24, 'regular', 'aircon', 'introductory', 4500.00, 2, 1, 500.00, 0, 0.00, '{\"package\":\"regular\",\"package_label\":\"Regular Booking\",\"rate_period\":\"introductory\",\"cooling_option\":\"aircon\",\"cooling_label\":\"Aircon with Lights\",\"billable_hours\":2,\"hourly_rate\":4500,\"base_amount\":9000,\"shower_room_addon\":true,\"shower_room_fee\":500,\"equipment_bundle_addon\":false,\"equipment_bundle_included\":false,\"equipment_bundle_fee\":0,\"package_inclusions\":[],\"total\":9500,\"intro_start\":\"2026-08-01\",\"intro_end\":\"2026-10-31\"}', NULL, NULL, NULL, 9500.00, NULL, 9500.00, 'paid', 'approved', 'walk_in', NULL, NULL, NULL, 5, 8, 1, '2026-08-07 13:06:39', '2026-08-07 13:08:37');
INSERT INTO `reservations` (`id`, `reference_no`, `reservation_type`, `purpose`, `client_name`, `organization`, `email`, `phone`, `booking_payment_method`, `booking_payment_reference`, `event_start`, `event_end`, `setup_minutes`, `cleanup_minutes`, `blocked_start`, `blocked_end`, `guest_count`, `pricing_package`, `cooling_option`, `rate_period`, `hourly_rate`, `billable_hours`, `shower_room_addon`, `shower_room_fee`, `equipment_bundle_addon`, `equipment_bundle_fee`, `pricing_snapshot`, `details`, `equipment_requests`, `special_instructions`, `estimated_amount`, `final_amount`, `amount_paid`, `payment_status`, `status`, `source`, `admin_notes`, `archived_at`, `archived_by`, `batch_id`, `batch_occurrence`, `created_by`, `created_at`, `updated_at`) VALUES (87, 'TLH-260807-C60883', 'basketball', 'Practice', 'LEO BESANES', 'EMB', 'damalerio_jay@yahoo.com', '09565199954', NULL, NULL, '2026-08-19 18:00:00', '2026-08-19 20:00:00', 0, 0, '2026-08-19 18:00:00', '2026-08-19 20:00:00', 24, 'regular', 'aircon', 'introductory', 4500.00, 2, 1, 500.00, 0, 0.00, '{\"package\":\"regular\",\"package_label\":\"Regular Booking\",\"rate_period\":\"introductory\",\"cooling_option\":\"aircon\",\"cooling_label\":\"Aircon with Lights\",\"billable_hours\":2,\"hourly_rate\":4500,\"base_amount\":9000,\"shower_room_addon\":true,\"shower_room_fee\":500,\"equipment_bundle_addon\":false,\"equipment_bundle_included\":false,\"equipment_bundle_fee\":0,\"package_inclusions\":[],\"total\":9500,\"intro_start\":\"2026-08-01\",\"intro_end\":\"2026-10-31\"}', NULL, NULL, NULL, 9500.00, NULL, 9500.00, 'paid', 'approved', 'walk_in', NULL, NULL, NULL, 5, 9, 1, '2026-08-07 13:06:39', '2026-08-07 13:08:37');
INSERT INTO `reservations` (`id`, `reference_no`, `reservation_type`, `purpose`, `client_name`, `organization`, `email`, `phone`, `booking_payment_method`, `booking_payment_reference`, `event_start`, `event_end`, `setup_minutes`, `cleanup_minutes`, `blocked_start`, `blocked_end`, `guest_count`, `pricing_package`, `cooling_option`, `rate_period`, `hourly_rate`, `billable_hours`, `shower_room_addon`, `shower_room_fee`, `equipment_bundle_addon`, `equipment_bundle_fee`, `pricing_snapshot`, `details`, `equipment_requests`, `special_instructions`, `estimated_amount`, `final_amount`, `amount_paid`, `payment_status`, `status`, `source`, `admin_notes`, `archived_at`, `archived_by`, `batch_id`, `batch_occurrence`, `created_by`, `created_at`, `updated_at`) VALUES (88, 'TLH-260807-CABE9E', 'basketball', 'Practice', 'LEO BESANES', 'EMB', 'damalerio_jay@yahoo.com', '09565199954', NULL, NULL, '2026-08-20 18:00:00', '2026-08-20 20:00:00', 0, 0, '2026-08-20 18:00:00', '2026-08-20 20:00:00', 24, 'regular', 'aircon', 'introductory', 4500.00, 2, 1, 500.00, 0, 0.00, '{\"package\":\"regular\",\"package_label\":\"Regular Booking\",\"rate_period\":\"introductory\",\"cooling_option\":\"aircon\",\"cooling_label\":\"Aircon with Lights\",\"billable_hours\":2,\"hourly_rate\":4500,\"base_amount\":9000,\"shower_room_addon\":true,\"shower_room_fee\":500,\"equipment_bundle_addon\":false,\"equipment_bundle_included\":false,\"equipment_bundle_fee\":0,\"package_inclusions\":[],\"total\":9500,\"intro_start\":\"2026-08-01\",\"intro_end\":\"2026-10-31\"}', NULL, NULL, NULL, 9500.00, NULL, 9500.00, 'paid', 'approved', 'walk_in', NULL, NULL, NULL, 5, 10, 1, '2026-08-07 13:06:39', '2026-08-07 13:08:37');
INSERT INTO `reservations` (`id`, `reference_no`, `reservation_type`, `purpose`, `client_name`, `organization`, `email`, `phone`, `booking_payment_method`, `booking_payment_reference`, `event_start`, `event_end`, `setup_minutes`, `cleanup_minutes`, `blocked_start`, `blocked_end`, `guest_count`, `pricing_package`, `cooling_option`, `rate_period`, `hourly_rate`, `billable_hours`, `shower_room_addon`, `shower_room_fee`, `equipment_bundle_addon`, `equipment_bundle_fee`, `pricing_snapshot`, `details`, `equipment_requests`, `special_instructions`, `estimated_amount`, `final_amount`, `amount_paid`, `payment_status`, `status`, `source`, `admin_notes`, `archived_at`, `archived_by`, `batch_id`, `batch_occurrence`, `created_by`, `created_at`, `updated_at`) VALUES (89, 'TLH-260807-41C10A', 'basketball', 'Practice', 'LEO BESANES', 'EMB', 'damalerio_jay@yahoo.com', '09565199954', NULL, NULL, '2026-08-21 18:00:00', '2026-08-21 20:00:00', 0, 0, '2026-08-21 18:00:00', '2026-08-21 20:00:00', 24, 'regular', 'aircon', 'introductory', 4500.00, 2, 1, 500.00, 0, 0.00, '{\"package\":\"regular\",\"package_label\":\"Regular Booking\",\"rate_period\":\"introductory\",\"cooling_option\":\"aircon\",\"cooling_label\":\"Aircon with Lights\",\"billable_hours\":2,\"hourly_rate\":4500,\"base_amount\":9000,\"shower_room_addon\":true,\"shower_room_fee\":500,\"equipment_bundle_addon\":false,\"equipment_bundle_included\":false,\"equipment_bundle_fee\":0,\"package_inclusions\":[],\"total\":9500,\"intro_start\":\"2026-08-01\",\"intro_end\":\"2026-10-31\"}', NULL, NULL, NULL, 9500.00, NULL, 9500.00, 'paid', 'approved', 'walk_in', NULL, NULL, NULL, 5, 11, 1, '2026-08-07 13:06:39', '2026-08-07 13:09:41');
INSERT INTO `reservations` (`id`, `reference_no`, `reservation_type`, `purpose`, `client_name`, `organization`, `email`, `phone`, `booking_payment_method`, `booking_payment_reference`, `event_start`, `event_end`, `setup_minutes`, `cleanup_minutes`, `blocked_start`, `blocked_end`, `guest_count`, `pricing_package`, `cooling_option`, `rate_period`, `hourly_rate`, `billable_hours`, `shower_room_addon`, `shower_room_fee`, `equipment_bundle_addon`, `equipment_bundle_fee`, `pricing_snapshot`, `details`, `equipment_requests`, `special_instructions`, `estimated_amount`, `final_amount`, `amount_paid`, `payment_status`, `status`, `source`, `admin_notes`, `archived_at`, `archived_by`, `batch_id`, `batch_occurrence`, `created_by`, `created_at`, `updated_at`) VALUES (90, 'TLH-260807-366C9B', 'basketball', 'Practice', 'LEO BESANES', 'EMB', 'damalerio_jay@yahoo.com', '09565199954', NULL, NULL, '2026-08-24 18:00:00', '2026-08-24 20:00:00', 0, 0, '2026-08-24 18:00:00', '2026-08-24 20:00:00', 24, 'regular', 'aircon', 'introductory', 4500.00, 2, 1, 500.00, 0, 0.00, '{\"package\":\"regular\",\"package_label\":\"Regular Booking\",\"rate_period\":\"introductory\",\"cooling_option\":\"aircon\",\"cooling_label\":\"Aircon with Lights\",\"billable_hours\":2,\"hourly_rate\":4500,\"base_amount\":9000,\"shower_room_addon\":true,\"shower_room_fee\":500,\"equipment_bundle_addon\":false,\"equipment_bundle_included\":false,\"equipment_bundle_fee\":0,\"package_inclusions\":[],\"total\":9500,\"intro_start\":\"2026-08-01\",\"intro_end\":\"2026-10-31\"}', NULL, NULL, NULL, 9500.00, NULL, 9500.00, 'paid', 'approved', 'walk_in', NULL, NULL, NULL, 5, 12, 1, '2026-08-07 13:06:39', '2026-08-07 13:09:41');
INSERT INTO `reservations` (`id`, `reference_no`, `reservation_type`, `purpose`, `client_name`, `organization`, `email`, `phone`, `booking_payment_method`, `booking_payment_reference`, `event_start`, `event_end`, `setup_minutes`, `cleanup_minutes`, `blocked_start`, `blocked_end`, `guest_count`, `pricing_package`, `cooling_option`, `rate_period`, `hourly_rate`, `billable_hours`, `shower_room_addon`, `shower_room_fee`, `equipment_bundle_addon`, `equipment_bundle_fee`, `pricing_snapshot`, `details`, `equipment_requests`, `special_instructions`, `estimated_amount`, `final_amount`, `amount_paid`, `payment_status`, `status`, `source`, `admin_notes`, `archived_at`, `archived_by`, `batch_id`, `batch_occurrence`, `created_by`, `created_at`, `updated_at`) VALUES (91, 'TLH-260807-EC827F', 'basketball', 'Practice', 'LEO BESANES', 'EMB', 'damalerio_jay@yahoo.com', '09565199954', NULL, NULL, '2026-08-25 18:00:00', '2026-08-25 20:00:00', 0, 0, '2026-08-25 18:00:00', '2026-08-25 20:00:00', 24, 'regular', 'aircon', 'introductory', 4500.00, 2, 1, 500.00, 0, 0.00, '{\"package\":\"regular\",\"package_label\":\"Regular Booking\",\"rate_period\":\"introductory\",\"cooling_option\":\"aircon\",\"cooling_label\":\"Aircon with Lights\",\"billable_hours\":2,\"hourly_rate\":4500,\"base_amount\":9000,\"shower_room_addon\":true,\"shower_room_fee\":500,\"equipment_bundle_addon\":false,\"equipment_bundle_included\":false,\"equipment_bundle_fee\":0,\"package_inclusions\":[],\"total\":9500,\"intro_start\":\"2026-08-01\",\"intro_end\":\"2026-10-31\"}', NULL, NULL, NULL, 9500.00, NULL, 9500.00, 'paid', 'approved', 'walk_in', NULL, NULL, NULL, 5, 13, 1, '2026-08-07 13:06:39', '2026-08-07 13:10:41');
INSERT INTO `reservations` (`id`, `reference_no`, `reservation_type`, `purpose`, `client_name`, `organization`, `email`, `phone`, `booking_payment_method`, `booking_payment_reference`, `event_start`, `event_end`, `setup_minutes`, `cleanup_minutes`, `blocked_start`, `blocked_end`, `guest_count`, `pricing_package`, `cooling_option`, `rate_period`, `hourly_rate`, `billable_hours`, `shower_room_addon`, `shower_room_fee`, `equipment_bundle_addon`, `equipment_bundle_fee`, `pricing_snapshot`, `details`, `equipment_requests`, `special_instructions`, `estimated_amount`, `final_amount`, `amount_paid`, `payment_status`, `status`, `source`, `admin_notes`, `archived_at`, `archived_by`, `batch_id`, `batch_occurrence`, `created_by`, `created_at`, `updated_at`) VALUES (92, 'TLH-260807-9BFDE6', 'basketball', 'Practice', 'LEO BESANES', 'EMB', 'damalerio_jay@yahoo.com', '09565199954', NULL, NULL, '2026-08-26 18:00:00', '2026-08-26 20:00:00', 0, 0, '2026-08-26 18:00:00', '2026-08-26 20:00:00', 24, 'regular', 'aircon', 'introductory', 4500.00, 2, 1, 500.00, 0, 0.00, '{\"package\":\"regular\",\"package_label\":\"Regular Booking\",\"rate_period\":\"introductory\",\"cooling_option\":\"aircon\",\"cooling_label\":\"Aircon with Lights\",\"billable_hours\":2,\"hourly_rate\":4500,\"base_amount\":9000,\"shower_room_addon\":true,\"shower_room_fee\":500,\"equipment_bundle_addon\":false,\"equipment_bundle_included\":false,\"equipment_bundle_fee\":0,\"package_inclusions\":[],\"total\":9500,\"intro_start\":\"2026-08-01\",\"intro_end\":\"2026-10-31\"}', NULL, NULL, NULL, 9500.00, NULL, 9500.00, 'paid', 'approved', 'walk_in', NULL, NULL, NULL, 5, 14, 1, '2026-08-07 13:06:39', '2026-08-07 13:10:41');
INSERT INTO `reservations` (`id`, `reference_no`, `reservation_type`, `purpose`, `client_name`, `organization`, `email`, `phone`, `booking_payment_method`, `booking_payment_reference`, `event_start`, `event_end`, `setup_minutes`, `cleanup_minutes`, `blocked_start`, `blocked_end`, `guest_count`, `pricing_package`, `cooling_option`, `rate_period`, `hourly_rate`, `billable_hours`, `shower_room_addon`, `shower_room_fee`, `equipment_bundle_addon`, `equipment_bundle_fee`, `pricing_snapshot`, `details`, `equipment_requests`, `special_instructions`, `estimated_amount`, `final_amount`, `amount_paid`, `payment_status`, `status`, `source`, `admin_notes`, `archived_at`, `archived_by`, `batch_id`, `batch_occurrence`, `created_by`, `created_at`, `updated_at`) VALUES (93, 'TLH-260807-8FC5FE', 'basketball', 'Practice', 'LEO BESANES', 'EMB', 'damalerio_jay@yahoo.com', '09565199954', NULL, NULL, '2026-08-27 18:00:00', '2026-08-27 20:00:00', 0, 0, '2026-08-27 18:00:00', '2026-08-27 20:00:00', 24, 'regular', 'aircon', 'introductory', 4500.00, 2, 1, 500.00, 0, 0.00, '{\"package\":\"regular\",\"package_label\":\"Regular Booking\",\"rate_period\":\"introductory\",\"cooling_option\":\"aircon\",\"cooling_label\":\"Aircon with Lights\",\"billable_hours\":2,\"hourly_rate\":4500,\"base_amount\":9000,\"shower_room_addon\":true,\"shower_room_fee\":500,\"equipment_bundle_addon\":false,\"equipment_bundle_included\":false,\"equipment_bundle_fee\":0,\"package_inclusions\":[],\"total\":9500,\"intro_start\":\"2026-08-01\",\"intro_end\":\"2026-10-31\"}', NULL, NULL, NULL, 9500.00, NULL, 9500.00, 'paid', 'approved', 'walk_in', NULL, NULL, NULL, 5, 15, 1, '2026-08-07 13:06:39', '2026-08-07 13:10:41');
INSERT INTO `reservations` (`id`, `reference_no`, `reservation_type`, `purpose`, `client_name`, `organization`, `email`, `phone`, `booking_payment_method`, `booking_payment_reference`, `event_start`, `event_end`, `setup_minutes`, `cleanup_minutes`, `blocked_start`, `blocked_end`, `guest_count`, `pricing_package`, `cooling_option`, `rate_period`, `hourly_rate`, `billable_hours`, `shower_room_addon`, `shower_room_fee`, `equipment_bundle_addon`, `equipment_bundle_fee`, `pricing_snapshot`, `details`, `equipment_requests`, `special_instructions`, `estimated_amount`, `final_amount`, `amount_paid`, `payment_status`, `status`, `source`, `admin_notes`, `archived_at`, `archived_by`, `batch_id`, `batch_occurrence`, `created_by`, `created_at`, `updated_at`) VALUES (94, 'TLH-260807-F33591', 'basketball', 'Practice', 'LEO BESANES', 'EMB', 'damalerio_jay@yahoo.com', '09565199954', NULL, NULL, '2026-08-28 18:00:00', '2026-08-28 20:00:00', 0, 0, '2026-08-28 18:00:00', '2026-08-28 20:00:00', 24, 'regular', 'aircon', 'introductory', 4500.00, 2, 1, 500.00, 0, 0.00, '{\"package\":\"regular\",\"package_label\":\"Regular Booking\",\"rate_period\":\"introductory\",\"cooling_option\":\"aircon\",\"cooling_label\":\"Aircon with Lights\",\"billable_hours\":2,\"hourly_rate\":4500,\"base_amount\":9000,\"shower_room_addon\":true,\"shower_room_fee\":500,\"equipment_bundle_addon\":false,\"equipment_bundle_included\":false,\"equipment_bundle_fee\":0,\"package_inclusions\":[],\"total\":9500,\"intro_start\":\"2026-08-01\",\"intro_end\":\"2026-10-31\"}', NULL, NULL, NULL, 9500.00, NULL, 9500.00, 'paid', 'approved', 'walk_in', NULL, NULL, NULL, 5, 16, 1, '2026-08-07 13:06:39', '2026-08-07 13:10:41');
INSERT INTO `reservations` (`id`, `reference_no`, `reservation_type`, `purpose`, `client_name`, `organization`, `email`, `phone`, `booking_payment_method`, `booking_payment_reference`, `event_start`, `event_end`, `setup_minutes`, `cleanup_minutes`, `blocked_start`, `blocked_end`, `guest_count`, `pricing_package`, `cooling_option`, `rate_period`, `hourly_rate`, `billable_hours`, `shower_room_addon`, `shower_room_fee`, `equipment_bundle_addon`, `equipment_bundle_fee`, `pricing_snapshot`, `details`, `equipment_requests`, `special_instructions`, `estimated_amount`, `final_amount`, `amount_paid`, `payment_status`, `status`, `source`, `admin_notes`, `archived_at`, `archived_by`, `batch_id`, `batch_occurrence`, `created_by`, `created_at`, `updated_at`) VALUES (95, 'TLH-260807-97F657', 'basketball', 'Practice', 'LEO BESANES', 'EMB', 'damalerio_jay@yahoo.com', '09565199954', 'Cash', NULL, '2026-08-31 18:00:00', '2026-08-31 20:00:00', 0, 0, '2026-08-31 18:00:00', '2026-08-31 20:00:00', 24, 'regular', 'aircon', 'introductory', 4500.00, 2, 1, 500.00, 0, 0.00, '{\"package\":\"regular\",\"package_label\":\"Regular Booking\",\"rate_period\":\"introductory\",\"cooling_option\":\"aircon\",\"cooling_label\":\"Aircon with Lights\",\"billable_hours\":2,\"hourly_rate\":4500,\"base_amount\":9000,\"shower_room_addon\":true,\"shower_room_fee\":500,\"equipment_bundle_addon\":false,\"equipment_bundle_included\":false,\"equipment_bundle_fee\":0,\"package_inclusions\":[],\"total\":9500,\"intro_start\":\"2026-08-01\",\"intro_end\":\"2026-10-31\"}', NULL, NULL, NULL, 9500.00, NULL, 9500.00, 'paid', 'completed', 'walk_in', '', NULL, NULL, 5, 17, 1, '2026-08-07 13:06:39', '2026-08-07 15:17:58');
INSERT INTO `reservations` (`id`, `reference_no`, `reservation_type`, `purpose`, `client_name`, `organization`, `email`, `phone`, `booking_payment_method`, `booking_payment_reference`, `event_start`, `event_end`, `setup_minutes`, `cleanup_minutes`, `blocked_start`, `blocked_end`, `guest_count`, `pricing_package`, `cooling_option`, `rate_period`, `hourly_rate`, `billable_hours`, `shower_room_addon`, `shower_room_fee`, `equipment_bundle_addon`, `equipment_bundle_fee`, `pricing_snapshot`, `details`, `equipment_requests`, `special_instructions`, `estimated_amount`, `final_amount`, `amount_paid`, `payment_status`, `status`, `source`, `admin_notes`, `archived_at`, `archived_by`, `batch_id`, `batch_occurrence`, `created_by`, `created_at`, `updated_at`) VALUES (96, 'TLH-260807-327C9E', 'basketball', 'Practice', 'JAY PASTOR DAMALERIO', 'EMB', 'damalerio_jay@yahoo.com', '+639565199954', 'Cash', NULL, '2026-09-04 20:00:00', '2026-09-04 22:00:00', 0, 0, '2026-09-04 20:00:00', '2026-09-04 22:00:00', 200, 'tournament', 'fan', 'tournament', 2500.00, 2, 1, 500.00, 1, 0.00, '{\"package\":\"tournament\",\"package_label\":\"Tournament (30–200 guests)\",\"rate_period\":\"tournament\",\"cooling_option\":\"fan\",\"cooling_label\":\"Fan with Lights\",\"billable_hours\":2,\"hourly_rate\":2500,\"base_amount\":5000,\"shower_room_addon\":true,\"shower_room_fee\":500,\"equipment_bundle_addon\":true,\"equipment_bundle_included\":true,\"equipment_bundle_fee\":0,\"package_inclusions\":[\"Shot clocks, scoreboard, controller, and complete sound system\",\"Venue and social-media advertisement\",\"Allocated parking space\"],\"total\":5500,\"intro_start\":\"2026-08-01\",\"intro_end\":\"2026-10-31\"}', NULL, NULL, NULL, 5500.00, 2750.00, 2750.00, 'paid', 'cancelled', 'website', '', '2026-08-10 08:02:50', 1, NULL, NULL, NULL, '2026-08-07 14:17:03', '2026-08-10 08:02:50');
INSERT INTO `reservations` (`id`, `reference_no`, `reservation_type`, `purpose`, `client_name`, `organization`, `email`, `phone`, `booking_payment_method`, `booking_payment_reference`, `event_start`, `event_end`, `setup_minutes`, `cleanup_minutes`, `blocked_start`, `blocked_end`, `guest_count`, `pricing_package`, `cooling_option`, `rate_period`, `hourly_rate`, `billable_hours`, `shower_room_addon`, `shower_room_fee`, `equipment_bundle_addon`, `equipment_bundle_fee`, `pricing_snapshot`, `details`, `equipment_requests`, `special_instructions`, `estimated_amount`, `final_amount`, `amount_paid`, `payment_status`, `status`, `source`, `admin_notes`, `archived_at`, `archived_by`, `batch_id`, `batch_occurrence`, `created_by`, `created_at`, `updated_at`) VALUES (97, 'TLH-260807-2E607C', 'basketball', 'Practice', 'JAY PASTOR DAMALERIO', 'EMB', 'damalerio_jay@yahoo.com', '09565199954', 'Cash', NULL, '2026-09-04 20:00:00', '2026-09-04 22:00:00', 0, 0, '2026-09-04 20:00:00', '2026-09-04 22:00:00', 28, 'regular', 'aircon', 'introductory', 4500.00, 2, 1, 500.00, 0, 0.00, '{\"package\":\"regular\",\"package_label\":\"Regular Booking\",\"rate_period\":\"introductory\",\"cooling_option\":\"aircon\",\"cooling_label\":\"Aircon with Lights\",\"billable_hours\":2,\"hourly_rate\":4500,\"base_amount\":9000,\"shower_room_addon\":true,\"shower_room_fee\":500,\"equipment_bundle_addon\":false,\"equipment_bundle_included\":false,\"equipment_bundle_fee\":0,\"package_inclusions\":[],\"total\":9500,\"intro_start\":\"2026-08-01\",\"intro_end\":\"2026-10-31\"}', NULL, NULL, NULL, 9500.00, NULL, 9500.00, 'paid', 'approved', 'walk_in', NULL, NULL, NULL, NULL, NULL, 1, '2026-08-07 15:51:53', '2026-08-07 15:51:53');
INSERT INTO `reservations` (`id`, `reference_no`, `reservation_type`, `purpose`, `client_name`, `organization`, `email`, `phone`, `booking_payment_method`, `booking_payment_reference`, `event_start`, `event_end`, `setup_minutes`, `cleanup_minutes`, `blocked_start`, `blocked_end`, `guest_count`, `pricing_package`, `cooling_option`, `rate_period`, `hourly_rate`, `billable_hours`, `shower_room_addon`, `shower_room_fee`, `equipment_bundle_addon`, `equipment_bundle_fee`, `pricing_snapshot`, `details`, `equipment_requests`, `special_instructions`, `estimated_amount`, `final_amount`, `amount_paid`, `payment_status`, `status`, `source`, `admin_notes`, `archived_at`, `archived_by`, `batch_id`, `batch_occurrence`, `created_by`, `created_at`, `updated_at`) VALUES (98, 'TLH-260807-261802', 'basketball', 'Practice', 'JAY PASTOR DAMALERIO', 'EMB', 'damalerio_jay@yahoo.com', '09565199954', NULL, NULL, '2026-08-10 08:00:00', '2026-08-10 10:00:00', 0, 0, '2026-08-10 08:00:00', '2026-08-10 10:00:00', 10, 'regular', 'fan', 'introductory', 1500.00, 2, 1, 500.00, 0, 0.00, '{\"package\":\"regular\",\"package_label\":\"Regular Booking\",\"rate_period\":\"introductory\",\"cooling_option\":\"fan\",\"cooling_label\":\"Fan with Lights\",\"billable_hours\":2,\"hourly_rate\":1500,\"base_amount\":3000,\"shower_room_addon\":true,\"shower_room_fee\":500,\"equipment_bundle_addon\":false,\"equipment_bundle_included\":false,\"equipment_bundle_fee\":0,\"package_inclusions\":[],\"total\":3500,\"intro_start\":\"2026-08-01\",\"intro_end\":\"2026-10-31\"}', NULL, NULL, NULL, 3500.00, NULL, 3500.00, 'paid', 'approved', 'walk_in', NULL, NULL, NULL, NULL, NULL, 1, '2026-08-07 16:16:34', '2026-08-07 16:21:02');
INSERT INTO `reservations` (`id`, `reference_no`, `reservation_type`, `purpose`, `client_name`, `organization`, `email`, `phone`, `booking_payment_method`, `booking_payment_reference`, `event_start`, `event_end`, `setup_minutes`, `cleanup_minutes`, `blocked_start`, `blocked_end`, `guest_count`, `pricing_package`, `cooling_option`, `rate_period`, `hourly_rate`, `billable_hours`, `shower_room_addon`, `shower_room_fee`, `equipment_bundle_addon`, `equipment_bundle_fee`, `pricing_snapshot`, `details`, `equipment_requests`, `special_instructions`, `estimated_amount`, `final_amount`, `amount_paid`, `payment_status`, `status`, `source`, `admin_notes`, `archived_at`, `archived_by`, `batch_id`, `batch_occurrence`, `created_by`, `created_at`, `updated_at`) VALUES (99, 'TLH1', 'basketball', 'Practice', 'JAY PASTOR DAMALERIO', 'EMB', 'damalerio_jay@yahoo.com', '+639565199954', 'Cash', NULL, '2026-08-15 08:00:00', '2026-08-15 10:00:00', 0, 0, '2026-08-15 08:00:00', '2026-08-15 10:00:00', 20, 'regular', 'aircon', 'introductory', 4500.00, 2, 1, 500.00, 0, 0.00, '{\"package\":\"regular\",\"package_label\":\"Regular Booking\",\"rate_period\":\"introductory\",\"cooling_option\":\"aircon\",\"cooling_label\":\"Aircon with Lights\",\"billable_hours\":2,\"hourly_rate\":4500,\"base_amount\":9000,\"shower_room_addon\":true,\"shower_room_fee\":500,\"equipment_bundle_addon\":false,\"equipment_bundle_included\":false,\"equipment_bundle_fee\":0,\"package_inclusions\":[],\"total\":9500,\"intro_start\":\"2026-08-01\",\"intro_end\":\"2026-10-31\"}', NULL, NULL, NULL, 9500.00, NULL, 9500.00, 'paid', 'pending', 'website', NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-10 08:03:42', '2026-08-10 08:03:42');

-- --------------------------------------------------------
-- Table structure for `reservation_archives`

DROP TABLE IF EXISTS `reservation_archives`;
CREATE TABLE `reservation_archives` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reservation_id` bigint(20) unsigned NOT NULL,
  `archive_action` varchar(20) NOT NULL,
  `previous_status` varchar(30) NOT NULL,
  `new_status` varchar(30) NOT NULL,
  `notes` text DEFAULT NULL,
  `changed_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_archive_reservation` (`reservation_id`,`created_at`),
  KEY `fk_archive_admin` (`changed_by`),
  CONSTRAINT `fk_archive_admin` FOREIGN KEY (`changed_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_archive_reservation` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for `reservation_archives`
INSERT INTO `reservation_archives` (`id`, `reservation_id`, `archive_action`, `previous_status`, `new_status`, `notes`, `changed_by`, `created_at`) VALUES (3, 96, 'archived', 'cancelled', 'cancelled', 'Marked Done and moved to Archives.', 1, '2026-08-10 08:02:50');

-- --------------------------------------------------------
-- Table structure for `reservation_batches`

DROP TABLE IF EXISTS `reservation_batches`;
CREATE TABLE `reservation_batches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `batch_reference` varchar(40) NOT NULL,
  `client_name` varchar(150) NOT NULL,
  `organization` varchar(150) DEFAULT NULL,
  `phone` varchar(40) NOT NULL,
  `email` varchar(150) NOT NULL,
  `reservation_type` varchar(30) NOT NULL,
  `purpose` varchar(150) DEFAULT NULL,
  `range_start` date NOT NULL,
  `range_end` date NOT NULL,
  `weekdays` varchar(40) NOT NULL,
  `start_time` time NOT NULL,
  `duration_hours` int(10) unsigned NOT NULL,
  `occurrence_count` int(10) unsigned NOT NULL DEFAULT 0,
  `total_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `batch_reference` (`batch_reference`),
  KEY `idx_batch_dates` (`range_start`,`range_end`),
  KEY `idx_batch_client` (`client_name`),
  KEY `fk_batch_admin` (`created_by`),
  CONSTRAINT `fk_batch_admin` FOREIGN KEY (`created_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for `reservation_batches`
INSERT INTO `reservation_batches` (`id`, `batch_reference`, `client_name`, `organization`, `phone`, `email`, `reservation_type`, `purpose`, `range_start`, `range_end`, `weekdays`, `start_time`, `duration_hours`, `occurrence_count`, `total_amount`, `created_by`, `created_at`) VALUES (5, 'BATCH-260807-9C59EB', 'LEO BESANES', 'EMB', '09565199954', 'damalerio_jay@yahoo.com', 'basketball', 'Practice', '2026-08-07', '2026-08-31', '1,2,3,4,5', '18:00:00', 2, 17, 161500.00, 1, '2026-08-07 13:06:39');

-- --------------------------------------------------------
-- Table structure for `reservation_cancellations`

DROP TABLE IF EXISTS `reservation_cancellations`;
CREATE TABLE `reservation_cancellations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reservation_id` bigint(20) unsigned NOT NULL,
  `previous_status` varchar(30) NOT NULL,
  `original_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `cancellation_rate` decimal(5,2) NOT NULL DEFAULT 50.00,
  `cancellation_fee` decimal(12,2) NOT NULL DEFAULT 0.00,
  `paid_before_cancellation` decimal(12,2) NOT NULL DEFAULT 0.00,
  `refund_due` decimal(12,2) NOT NULL DEFAULT 0.00,
  `refund_status` varchar(20) NOT NULL DEFAULT 'not_applicable',
  `refunded_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `refund_method` varchar(80) DEFAULT NULL,
  `refund_reference` varchar(120) DEFAULT NULL,
  `refund_notes` text DEFAULT NULL,
  `refund_payment_id` bigint(20) unsigned DEFAULT NULL,
  `cancellation_reason` text NOT NULL,
  `cancelled_by` int(10) unsigned DEFAULT NULL,
  `cancelled_at` datetime NOT NULL,
  `refunded_by` int(10) unsigned DEFAULT NULL,
  `refunded_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reservation_cancellation` (`reservation_id`),
  KEY `idx_cancellation_refund_status` (`refund_status`,`cancelled_at`),
  KEY `fk_cancellation_admin` (`cancelled_by`),
  KEY `fk_cancellation_refund_admin` (`refunded_by`),
  KEY `fk_cancellation_refund_payment` (`refund_payment_id`),
  CONSTRAINT `fk_cancellation_admin` FOREIGN KEY (`cancelled_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cancellation_refund_admin` FOREIGN KEY (`refunded_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cancellation_refund_payment` FOREIGN KEY (`refund_payment_id`) REFERENCES `payments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cancellation_reservation` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data for `reservation_cancellations`
INSERT INTO `reservation_cancellations` (`id`, `reservation_id`, `previous_status`, `original_total`, `cancellation_rate`, `cancellation_fee`, `paid_before_cancellation`, `refund_due`, `refund_status`, `refunded_amount`, `refund_method`, `refund_reference`, `refund_notes`, `refund_payment_id`, `cancellation_reason`, `cancelled_by`, `cancelled_at`, `refunded_by`, `refunded_at`, `created_at`, `updated_at`) VALUES (1, 96, 'approved', 5500.00, 50.00, 2750.00, 5500.00, 2750.00, 'refunded', 2750.00, 'Cash', NULL, NULL, 73, 'Can\'t make it on the said date.', 1, '2026-08-07 15:49:56', 1, '2026-08-07 15:49:00', '2026-08-07 15:49:56', '2026-08-07 15:49:56');

-- --------------------------------------------------------
-- Table structure for `reservation_extensions`

DROP TABLE IF EXISTS `reservation_extensions`;
CREATE TABLE `reservation_extensions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reservation_id` bigint(20) unsigned NOT NULL,
  `previous_event_end` datetime NOT NULL,
  `new_event_end` datetime NOT NULL,
  `previous_blocked_end` datetime NOT NULL,
  `new_blocked_end` datetime NOT NULL,
  `added_hours` int(10) unsigned NOT NULL,
  `hourly_rate` decimal(12,2) NOT NULL DEFAULT 0.00,
  `additional_charge` decimal(12,2) NOT NULL DEFAULT 0.00,
  `previous_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `new_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `previous_payment_status` varchar(30) NOT NULL,
  `new_payment_status` varchar(30) NOT NULL,
  `reason` text NOT NULL,
  `changed_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_extension_reservation` (`reservation_id`,`created_at`),
  KEY `fk_extension_admin` (`changed_by`),
  CONSTRAINT `fk_extension_admin` FOREIGN KEY (`changed_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_extension_reservation` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for `reservation_extensions`
INSERT INTO `reservation_extensions` (`id`, `reservation_id`, `previous_event_end`, `new_event_end`, `previous_blocked_end`, `new_blocked_end`, `added_hours`, `hourly_rate`, `additional_charge`, `previous_total`, `new_total`, `previous_payment_status`, `new_payment_status`, `reason`, `changed_by`, `created_at`) VALUES (3, 81, '2026-08-11 20:00:00', '2026-08-11 21:00:00', '2026-08-11 20:00:00', '2026-08-11 21:00:00', 1, 4500.00, 4500.00, 9500.00, 14000.00, 'paid', 'partial', 'NEED MORE TIME', 1, '2026-08-07 14:33:26');

-- --------------------------------------------------------
-- Table structure for `reservation_reschedules`

DROP TABLE IF EXISTS `reservation_reschedules`;
CREATE TABLE `reservation_reschedules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reservation_id` bigint(20) unsigned NOT NULL,
  `previous_event_start` datetime NOT NULL,
  `previous_event_end` datetime NOT NULL,
  `new_event_start` datetime NOT NULL,
  `new_event_end` datetime NOT NULL,
  `previous_blocked_start` datetime NOT NULL,
  `previous_blocked_end` datetime NOT NULL,
  `new_blocked_start` datetime NOT NULL,
  `new_blocked_end` datetime NOT NULL,
  `previous_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `new_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `previous_payment_status` varchar(30) NOT NULL,
  `new_payment_status` varchar(30) NOT NULL,
  `reason` text NOT NULL,
  `changed_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_reschedule_reservation` (`reservation_id`,`created_at`),
  KEY `fk_reschedule_admin` (`changed_by`),
  CONSTRAINT `fk_reschedule_admin` FOREIGN KEY (`changed_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_reschedule_reservation` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for `reservation_reschedules`
INSERT INTO `reservation_reschedules` (`id`, `reservation_id`, `previous_event_start`, `previous_event_end`, `new_event_start`, `new_event_end`, `previous_blocked_start`, `previous_blocked_end`, `new_blocked_start`, `new_blocked_end`, `previous_total`, `new_total`, `previous_payment_status`, `new_payment_status`, `reason`, `changed_by`, `created_at`) VALUES (3, 83, '2026-08-13 18:00:00', '2026-08-13 20:00:00', '2026-08-13 13:00:00', '2026-08-13 15:00:00', '2026-08-13 18:00:00', '2026-08-13 20:00:00', '2026-08-13 13:00:00', '2026-08-13 15:00:00', 9500.00, 9500.00, 'paid', 'paid', 'NEED', 1, '2026-08-07 13:15:23');

-- --------------------------------------------------------
-- Table structure for `site_settings`

DROP TABLE IF EXISTS `site_settings`;
CREATE TABLE `site_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for `site_settings`
INSERT INTO `site_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('about_text', 'The Leisure Hub is a modern multipurpose venue designed for sports, private celebrations, corporate functions, and community gatherings. One flexible space, professionally managed for every occasion.', '2026-08-06 08:17:06');
INSERT INTO `site_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('address', 'GM Cordova Avenue, Buri Road', '2026-08-06 08:17:06');
INSERT INTO `site_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('basketball_rate', '800', '2026-08-06 08:17:06');
INSERT INTO `site_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('currency', 'PHP', '2026-08-06 08:17:06');
INSERT INTO `site_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('deposit_percent', '30', '2026-08-06 08:17:06');
INSERT INTO `site_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('email', 'info@theleisurehub.local', '2026-08-06 08:17:06');
INSERT INTO `site_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('event_rate', '1500', '2026-08-06 08:17:06');
INSERT INTO `site_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('facebook_url', '', '2026-08-06 08:17:06');
INSERT INTO `site_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('hero_text', 'A flexible multipurpose venue for basketball, volleyball, celebrations, corporate functions, and community events.', '2026-08-06 08:17:06');
INSERT INTO `site_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('hero_title', 'Designed for Premium Experience', '2026-08-10 08:08:19');
INSERT INTO `site_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('leasing_email', 'info@theleisurehub.local', '2026-08-06 08:17:06');
INSERT INTO `site_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('leasing_text', 'Position your brand inside a destination built for sports, events, dining, services, and community experiences.', '2026-08-06 08:17:06');
INSERT INTO `site_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('leasing_title', 'Grow your business at The Leisure Hub', '2026-08-06 08:17:06');
INSERT INTO `site_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('map_embed', '', '2026-08-06 08:17:06');
INSERT INTO `site_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('operating_hours', 'Monday to Sunday, 6:00 AM to 10:00 PM', '2026-08-06 08:17:06');
INSERT INTO `site_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('phone', '0920-940-0014', '2026-08-06 08:17:06');
INSERT INTO `site_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('pricing_big_event_aircon_rate', '6000', '2026-08-07 08:04:08');
INSERT INTO `site_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('pricing_big_event_fan_rate', '3000', '2026-08-07 08:04:08');
INSERT INTO `site_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('pricing_equipment_bundle_fee', '100', '2026-08-06 14:09:58');
INSERT INTO `site_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('pricing_intro_aircon_rate', '4500', '2026-08-06 14:09:58');
INSERT INTO `site_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('pricing_intro_end', '2026-10-31', '2026-08-06 14:09:58');
INSERT INTO `site_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('pricing_intro_fan_rate', '1500', '2026-08-06 14:09:58');
INSERT INTO `site_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('pricing_intro_start', '2026-08-01', '2026-08-06 14:09:58');
INSERT INTO `site_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('pricing_regular_aircon_rate', '5000', '2026-08-06 14:09:58');
INSERT INTO `site_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('pricing_regular_fan_rate', '2000', '2026-08-06 14:09:58');
INSERT INTO `site_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('pricing_shower_room_fee', '500', '2026-08-06 14:09:58');
INSERT INTO `site_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('pricing_tournament_aircon_rate', '5500', '2026-08-06 14:09:58');
INSERT INTO `site_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('pricing_tournament_fan_rate', '2500', '2026-08-06 14:09:58');
INSERT INTO `site_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('site_name', 'The Leisure Hub', '2026-08-06 08:17:06');
INSERT INTO `site_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('tagline', 'Designed for Premium Experience', '2026-08-06 08:17:06');
INSERT INTO `site_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('volleyball_rate', '800', '2026-08-06 08:17:06');

-- --------------------------------------------------------
-- Table structure for `tenants`

DROP TABLE IF EXISTS `tenants`;
CREATE TABLE `tenants` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `store_name` varchar(160) NOT NULL,
  `slug` varchar(180) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `short_description` text DEFAULT NULL,
  `unit_location` varchar(120) DEFAULT NULL,
  `contact_phone` varchar(50) DEFAULT NULL,
  `website_url` varchar(255) DEFAULT NULL,
  `facebook_url` varchar(255) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `is_featured` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET UNIQUE_CHECKS=1;
SET FOREIGN_KEY_CHECKS=1;
-- Backup completed successfully. Tables: 20; rows: 128.
