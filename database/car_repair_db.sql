-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 24, 2026 at 07:21 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET SESSION sql_require_primary_key = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `car_repair_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `bookings`
--

CREATE TABLE `bookings` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `service_category_id` int(11) NOT NULL,
  `booking_date` date NOT NULL,
  `booking_time` time NOT NULL,
  `original_date` date DEFAULT NULL,
  `original_time` time DEFAULT NULL,
  `reschedule_reason` text DEFAULT NULL,
  `rescheduled_by` enum('admin','customer') DEFAULT NULL,
  `status` enum('pending','confirmed','repairing','completed','cancelled') DEFAULT 'pending',
  `remarks` text DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  `estimated_price` decimal(10,2) DEFAULT NULL,
  `final_price` decimal(10,2) DEFAULT NULL,
  `completed_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `admin_viewed` tinyint(4) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `bookings`
--

INSERT INTO `bookings` (`id`, `user_id`, `vehicle_id`, `service_category_id`, `booking_date`, `booking_time`, `original_date`, `original_time`, `reschedule_reason`, `rescheduled_by`, `status`, `remarks`, `admin_notes`, `estimated_price`, `final_price`, `completed_date`, `created_at`, `updated_at`, `admin_viewed`) VALUES
(173, 3, 5, 1, '2026-05-23', '15:00:00', NULL, NULL, NULL, NULL, 'completed', '', NULL, 120.00, NULL, '2026-05-18', '2026-05-18 04:48:51', '2026-05-18 04:49:47', 1),
(174, 3, 11, 19, '2026-05-21', '11:00:00', NULL, NULL, NULL, NULL, 'completed', '766t', NULL, 250.00, NULL, '2026-05-19', '2026-05-19 08:47:57', '2026-05-19 08:52:44', 1),
(175, 3, 11, 4, '2026-05-28', '14:00:00', NULL, NULL, NULL, NULL, 'completed', '', NULL, 150.00, NULL, '2026-05-19', '2026-05-19 08:55:14', '2026-05-19 08:56:00', 1),
(179, 3, 11, 11, '2026-05-21', '14:00:00', NULL, NULL, NULL, NULL, 'repairing', 'gregeg', NULL, 190.00, NULL, NULL, '2026-05-20 09:06:48', '2026-05-20 09:07:47', 1),
(180, 3, 11, 5, '2026-05-23', '15:00:00', NULL, NULL, NULL, NULL, 'pending', '', NULL, 140.00, NULL, NULL, '2026-05-21 16:16:27', '2026-05-22 13:22:49', 1);

--
-- Triggers `bookings`
--
CREATE TRIGGER `update_suggestion_on_booking_cancelled` AFTER UPDATE ON `bookings` FOR EACH ROW BEGIN
    IF NEW.status = 'cancelled' AND OLD.status != 'cancelled' THEN
        UPDATE service_suggestions s
        SET s.status = 'pending',
            s.completed_notes = CONCAT('Cancelled on ', DATE_FORMAT(NOW(), '%d/%m/%Y'), ' (Booking #', NEW.id, ')'),
            s.updated_at = NOW()
        WHERE s.vehicle_id = NEW.vehicle_id
        AND s.service_category_id = NEW.service_category_id
        AND s.status IN ('booked', 'in_progress');
    END IF;
END;
CREATE TRIGGER `update_suggestion_on_booking_confirmed` AFTER UPDATE ON `bookings` FOR EACH ROW BEGIN
    IF NEW.status = 'confirmed' AND OLD.status != 'confirmed' THEN
        UPDATE service_suggestions s
        SET s.status = 'booked',
            s.completed_notes = CONCAT('Booked on ', DATE_FORMAT(NEW.booking_date, '%d/%m/%Y'), ' (Booking #', NEW.id, ')'),
            s.updated_at = NOW()
        WHERE s.vehicle_id = NEW.vehicle_id
        AND s.service_category_id = NEW.service_category_id
        AND s.status = 'pending';
    END IF;
END;
CREATE TRIGGER `update_suggestion_on_booking_progress` AFTER UPDATE ON `bookings` FOR EACH ROW BEGIN
    IF NEW.status = 'repairing' AND OLD.status != 'repairing' THEN
        UPDATE service_suggestions s
        SET s.status = 'in_progress',
            s.completed_notes = CONCAT('In progress since ', DATE_FORMAT(NOW(), '%d/%m/%Y'), ' (Booking #', NEW.id, ')'),
            s.updated_at = NOW()
        WHERE s.vehicle_id = NEW.vehicle_id
        AND s.service_category_id = NEW.service_category_id
        AND s.status IN ('pending', 'booked');
    END IF;
END;

-- --------------------------------------------------------

--
-- Table structure for table `booking_timeslots`
--

CREATE TABLE `booking_timeslots` (
  `id` int(11) NOT NULL,
  `slot_time` time NOT NULL,
  `max_bookings` int(11) DEFAULT 3,
  `is_active` tinyint(4) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `booking_timeslots`
--

INSERT INTO `booking_timeslots` (`id`, `slot_time`, `max_bookings`, `is_active`, `created_at`) VALUES
(1, '09:00:00', 3, 1, '2026-02-26 02:10:28'),
(2, '10:00:00', 3, 1, '2026-02-26 02:10:28'),
(3, '11:00:00', 3, 1, '2026-02-26 02:10:28'),
(4, '12:00:00', 3, 1, '2026-02-26 02:10:28'),
(5, '13:00:00', 3, 1, '2026-02-26 02:10:28'),
(6, '14:00:00', 3, 1, '2026-02-26 02:10:28'),
(7, '15:00:00', 3, 1, '2026-02-26 02:10:28'),
(8, '16:00:00', 3, 1, '2026-02-26 02:10:28'),
(9, '17:00:00', 3, 1, '2026-02-26 02:10:28');

-- --------------------------------------------------------

--
-- Table structure for table `brands`
--

CREATE TABLE `brands` (
  `id` int(11) NOT NULL,
  `brand_name` varchar(100) NOT NULL,
  `brand_logo` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `brands`
--

INSERT INTO `brands` (`id`, `brand_name`, `brand_logo`, `description`, `is_active`, `created_at`) VALUES
(1, 'Michelin', NULL, '', 1, '2026-04-27 05:38:18'),
(2, 'Bridgestone', NULL, '', 1, '2026-04-27 05:38:18'),
(3, 'Continental', NULL, '', 1, '2026-04-27 05:38:18'),
(4, 'Goodyear', NULL, '', 1, '2026-04-27 05:38:18'),
(5, 'Pirelli', NULL, '', 1, '2026-04-27 05:38:18'),
(6, 'Dunlop', NULL, '', 1, '2026-04-27 05:38:18'),
(7, 'Yokohama', NULL, '', 1, '2026-04-27 05:38:18'),
(8, 'Hankook', NULL, '', 1, '2026-04-27 05:38:18'),
(9, 'Falken', NULL, '', 1, '2026-04-27 05:38:18'),
(10, 'Kumho', NULL, '', 1, '2026-04-27 05:38:18'),
(11, 'wefwef', NULL, NULL, 1, '2026-04-27 05:40:44'),
(12, 'Century', NULL, NULL, 1, '2026-05-06 22:53:22'),
(13, 'Varta', NULL, NULL, 1, '2026-05-06 22:54:30'),
(14, 'Amaron', NULL, NULL, 1, '2026-05-06 22:54:30'),
(15, 'Bosch', NULL, NULL, 1, '2026-05-06 23:08:33'),
(16, 'Petronas', NULL, NULL, 1, '2026-05-06 23:12:09'),
(17, 'Shell', NULL, NULL, 1, '2026-05-06 23:44:56'),
(18, 'Castrol', NULL, NULL, 1, '2026-05-06 23:44:56'),
(19, 'Brembo', NULL, NULL, 1, '2026-05-06 23:44:56'),
(20, 'NGK', NULL, NULL, 1, '2026-05-06 23:44:56'),
(21, 'Denso', NULL, NULL, 1, '2026-05-06 23:44:56'),
(22, 'Gates', NULL, NULL, 1, '2026-05-06 23:44:56'),
(23, 'wrwfw', NULL, NULL, 1, '2026-05-14 05:45:21'),
(24, 'sdfgdgf', NULL, NULL, 1, '2026-05-14 05:45:21'),
(25, 'rfwrf', NULL, NULL, 1, '2026-05-14 07:02:45'),
(26, 'Toyo', NULL, NULL, 1, '2026-05-14 07:13:37'),
(27, 'Maxxis', NULL, NULL, 1, '2026-05-14 07:13:37'),
(42, 'Yuasa', NULL, NULL, 1, '2026-05-14 07:22:01'),
(43, 'Panasonic', NULL, NULL, 1, '2026-05-14 07:22:01'),
(44, 'Exide', NULL, NULL, 1, '2026-05-14 07:22:01'),
(45, 'Delkor', NULL, NULL, 1, '2026-05-14 07:22:01'),
(50, 'sfvsfv', NULL, NULL, 1, '2026-05-14 07:28:55'),
(59, 'awd', NULL, NULL, 1, '2026-05-14 07:31:56'),
(60, 'ad', NULL, NULL, 1, '2026-05-14 07:31:56'),
(61, 'Pennzoil', NULL, NULL, 1, '2026-05-14 07:47:21'),
(62, 'Mobil 1', NULL, NULL, 1, '2026-05-14 07:47:21'),
(63, 'Liqui Moly', NULL, NULL, 1, '2026-05-14 07:47:21'),
(64, 'Total', NULL, NULL, 1, '2026-05-14 07:47:21'),
(65, 'Motul', NULL, NULL, 1, '2026-05-14 07:47:21'),
(66, 'Champion', NULL, NULL, 1, '2026-05-14 18:16:10'),
(67, 'Beru', NULL, NULL, 1, '2026-05-14 18:16:10'),
(68, 'Autolite', NULL, NULL, 1, '2026-05-14 18:16:10'),
(69, 'ACDelco', NULL, NULL, 1, '2026-05-14 18:16:10'),
(73, 'MANN-FILTER', NULL, NULL, 1, '2026-05-14 18:20:38'),
(74, 'K&N', NULL, NULL, 1, '2026-05-14 18:20:38'),
(75, 'MAHLE', NULL, NULL, 1, '2026-05-14 18:20:38'),
(76, 'FRAM', NULL, NULL, 1, '2026-05-14 18:20:38'),
(77, 'WIX', NULL, NULL, 1, '2026-05-14 18:20:38'),
(78, 'Sakura', NULL, NULL, 1, '2026-05-14 18:20:38'),
(79, 'Ryco', NULL, NULL, 1, '2026-05-14 18:20:38'),
(80, 'Proton', NULL, NULL, 1, '2026-05-14 18:20:38'),
(81, 'Perodua', NULL, NULL, 1, '2026-05-14 18:20:38'),
(82, 'Honda', NULL, NULL, 1, '2026-05-14 18:20:38'),
(83, 'Toyota', NULL, NULL, 1, '2026-05-14 18:20:38'),
(84, 'Hyundai', NULL, NULL, 1, '2026-05-14 18:20:38'),
(86, 'awdawd', NULL, NULL, 1, '2026-05-22 13:44:22'),
(87, '123123', NULL, NULL, 1, '2026-05-22 13:44:22');

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `temp_password_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(4) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `password_resets`
--

INSERT INTO `password_resets` (`id`, `user_id`, `temp_password_hash`, `expires_at`, `used`, `created_at`) VALUES
(3, 3, '$2y$10$0UVBjMdBp2lXBoKP0G/1keFpd3u6dzqQWhnNJnCwxv022Sw8iJOAa', '2026-05-18 14:28:24', 1, '2026-05-18 06:13:24'),
(4, 3, '$2y$10$tiTIaNz.iLhRQVk/SKZxLOVa8CJBiRfgeA0Bunjd.FV4GrYamer8e', '2026-05-18 14:29:16', 0, '2026-05-18 06:14:16'),
(5, 3, '$2y$10$vRVZxe9Ps6KlDir/p8Pr2.8seEzHU8r/u.F5XgyPkkiDrGChG8Csq', '2026-05-18 18:06:10', 1, '2026-05-18 09:51:10'),
(6, 3, '$2y$10$AbCOJ90dCllCh4I32HJzAe6EXUJ8kP4sBWx0gbyWtR85qC8VZUF1G', '2026-05-18 18:17:27', 0, '2026-05-18 10:02:27'),
(7, 3, '$2y$10$t2S6SO8pUFPJzuzzV1BRxeIyZmSwygulUu46p/Ez47dWNCK.Jsk8W', '2026-05-18 18:19:06', 1, '2026-05-18 10:04:06');

-- --------------------------------------------------------

--
-- Table structure for table `security_images`
--

CREATE TABLE `security_images` (
  `id` int(11) NOT NULL,
  `image_name` varchar(100) NOT NULL,
  `image_path` varchar(255) NOT NULL,
  `category` varchar(50) DEFAULT NULL,
  `is_active` tinyint(4) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `security_images`
--

INSERT INTO `security_images` (`id`, `image_name`, `image_path`, `category`, `is_active`, `created_at`) VALUES
(2, 'Beach', 'beach.jpg', 'Beach', 1, '2026-02-24 02:19:41'),
(3, 'City', 'city.jpg', 'City', 1, '2026-02-24 02:19:41'),
(4, 'Desert', 'desert.jpg', 'Desert', 1, '2026-02-24 02:19:41'),
(5, 'Forest', 'forest.jpg', 'Forest', 1, '2026-02-24 02:19:41'),
(6, 'Garden', 'garden.jpg', 'Garden', 1, '2026-02-24 02:19:41'),
(7, 'Lake', 'lake.jpg', 'Lake', 1, '2026-02-24 02:19:41'),
(8, 'Mountain', 'mountain.jpg', 'Mountain', 1, '2026-02-24 02:19:41'),
(9, 'River', 'river.jpg', 'River', 1, '2026-02-24 02:19:41'),
(10, 'Snow', 'snow.jpg', 'Snow', 1, '2026-02-24 02:19:41'),
(11, 'Sunset', 'sunset.jpg', 'Sunset', 1, '2026-02-24 02:19:41');

-- --------------------------------------------------------

--
-- Table structure for table `service_categories`
--

CREATE TABLE `service_categories` (
  `id` int(11) NOT NULL,
  `type` enum('Oil & Fluid Service','Engine Service','Brake Service','Cooling System Service','Electrical Service','Air Conditioning Service','Suspension & Steering Service','Tire Service','Exhaust Service') DEFAULT NULL,
  `category_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `base_price` decimal(10,2) NOT NULL,
  `estimated_hours` decimal(5,2) DEFAULT NULL,
  `is_active` tinyint(4) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `service_categories`
--

INSERT INTO `service_categories` (`id`, `type`, `category_name`, `description`, `base_price`, `estimated_hours`, `is_active`, `created_at`) VALUES
(1, 'Oil & Fluid Service', 'Engine Oil Change', 'Complete engine oil drain and refill with new oil filter. Includes up to 5 liters of semi-synthetic oil.', 120.00, 1.00, 1, '2026-02-25 02:30:13'),
(2, 'Oil & Fluid Service', 'Premium Synthetic Oil Change', 'Full synthetic oil change for better engine protection and performance. Includes oil filter.', 220.00, 1.00, 1, '2026-02-25 02:30:13'),
(3, 'Oil & Fluid Service', 'Transmission Fluid Change', 'Automatic or manual transmission fluid drain and refill with new fluid.', 180.00, 1.50, 1, '2026-02-25 02:30:13'),
(4, 'Oil & Fluid Service', 'Brake Fluid Flush', 'Complete brake fluid replacement to maintain braking performance and safety.', 150.00, 1.00, 1, '2026-02-25 02:30:13'),
(5, 'Oil & Fluid Service', 'Coolant Flush', 'Radiator coolant drain, flush, and refill with new coolant mixture.', 140.00, 1.50, 1, '2026-02-25 02:30:13'),
(6, 'Oil & Fluid Service', 'Power Steering Fluid Change', 'Power steering fluid replacement to ensure smooth steering operation.', 90.00, 0.75, 1, '2026-02-25 02:30:13'),
(7, 'Oil & Fluid Service', 'Differential Fluid Change', 'Front or rear differential oil change for proper gear lubrication.', 130.00, 1.00, 1, '2026-02-25 02:30:13'),
(8, 'Engine Service', 'Engine Tune-Up', 'Complete engine inspection, spark plug replacement, and performance check.', 280.00, 2.50, 1, '2026-02-25 02:32:41'),
(9, 'Engine Service', 'Spark Plug Replacement', 'Replace all spark plugs with OEM or premium replacement plugs.', 160.00, 1.50, 1, '2026-02-25 02:32:41'),
(10, 'Engine Service', 'Ignition Coil Replacement', 'Replace faulty ignition coil(s) and test ignition system.', 200.00, 1.50, 1, '2026-02-25 02:32:41'),
(11, 'Engine Service', 'Fuel Injector Cleaning', 'Professional fuel injector cleaning service to restore performance.', 190.00, 2.00, 1, '2026-02-25 02:32:41'),
(12, 'Engine Service', 'Throttle Body Cleaning', 'Clean throttle body and intake system for better air flow.', 120.00, 1.00, 1, '2026-02-25 02:32:41'),
(13, 'Engine Service', 'Mass Air Flow Sensor Cleaning', 'Clean MAF sensor to improve fuel efficiency and performance.', 90.00, 0.75, 1, '2026-02-25 02:32:41'),
(14, 'Engine Service', 'Engine Compression Test', 'Test engine compression to check internal engine health.', 150.00, 1.50, 1, '2026-02-25 02:32:41'),
(15, 'Engine Service', 'Timing Belt Replacement', 'Replace timing belt and inspect tensioners and pulleys.', 550.00, 4.00, 1, '2026-02-25 02:32:41'),
(16, 'Engine Service', 'Timing Chain Inspection', 'Inspect timing chain condition and tension.', 180.00, 1.50, 1, '2026-02-25 02:32:41'),
(17, 'Engine Service', 'Valve Cover Gasket Replacement', 'Replace leaking valve cover gasket.', 200.00, 2.00, 1, '2026-02-25 02:32:41'),
(18, 'Engine Service', 'Intake Manifold Gasket Replacement', 'Replace faulty intake manifold gasket.', 350.00, 3.00, 1, '2026-02-25 02:32:41'),
(19, 'Brake Service', 'Brake Pad Replacement - Front', 'Replace front brake pads with new premium ceramic pads. Includes inspection.', 250.00, 1.50, 1, '2026-02-25 02:33:46'),
(20, 'Brake Service', 'Brake Pad Replacement - Rear', 'Replace rear brake pads with new premium ceramic pads. Includes inspection.', 230.00, 1.50, 1, '2026-02-25 02:33:46'),
(21, 'Brake Service', 'Brake Rotor Resurfacing - Front', 'Machine front brake rotors to remove grooves and ensure smooth braking.', 180.00, 1.50, 1, '2026-02-25 02:33:46'),
(22, 'Brake Service', 'Brake Rotor Resurfacing - Rear', 'Machine rear brake rotors to remove grooves and ensure smooth braking.', 170.00, 1.50, 1, '2026-02-25 02:33:46'),
(23, 'Brake Service', 'Brake Rotor Replacement - Front', 'Replace front brake rotors with new high-quality rotors.', 350.00, 2.00, 1, '2026-02-25 02:33:46'),
(24, 'Brake Service', 'Brake Rotor Replacement - Rear', 'Replace rear brake rotors with new high-quality rotors.', 320.00, 2.00, 1, '2026-02-25 02:33:46'),
(25, 'Brake Service', 'Brake Caliper Replacement', 'Replace faulty brake caliper and bleed brake system.', 280.00, 2.00, 1, '2026-02-25 02:33:46'),
(26, 'Brake Service', 'Parking Brake Adjustment', 'Adjust parking brake cable and mechanism for proper operation.', 80.00, 0.75, 1, '2026-02-25 02:33:46'),
(27, 'Brake Service', 'Complete Brake System Overhaul', 'Comprehensive brake service including pads, rotors, and fluid replacement.', 850.00, 4.00, 1, '2026-02-25 02:33:46'),
(28, 'Cooling System Service', 'Radiator Replacement', 'Replace old or leaking radiator with new unit.', 380.00, 2.50, 1, '2026-02-25 02:34:43'),
(29, 'Cooling System Service', 'Water Pump Replacement', 'Replace faulty water pump and check cooling system.', 320.00, 2.50, 1, '2026-02-25 02:34:43'),
(30, 'Cooling System Service', 'Thermostat Replacement', 'Replace thermostat and test cooling system operation.', 110.00, 1.00, 1, '2026-02-25 02:34:43'),
(31, 'Cooling System Service', 'Radiator Hose Replacement', 'Replace cracked or leaking radiator hoses.', 130.00, 1.00, 1, '2026-02-25 02:34:43'),
(32, 'Cooling System Service', 'Heater Core Flush', 'Flush heater core to restore heating performance.', 140.00, 1.50, 1, '2026-02-25 02:34:43'),
(33, 'Cooling System Service', 'Cooling System Pressure Test', 'Pressure test cooling system to find leaks.', 70.00, 0.50, 1, '2026-02-25 02:34:43'),
(34, 'Cooling System Service', 'Electric Cooling Fan Repair', 'Diagnose and repair electric cooling fan issues.', 200.00, 1.50, 1, '2026-02-25 02:34:43'),
(35, 'Electrical Service', 'Battery Replacement', 'Replace old battery and test charging system.', 180.00, 0.50, 1, '2026-02-25 02:35:26'),
(36, 'Electrical Service', 'Alternator Replacement', 'Replace faulty alternator and test charging system.', 380.00, 2.00, 1, '2026-02-25 02:35:26'),
(37, 'Electrical Service', 'Starter Motor Replacement', 'Replace faulty starter motor and test starting system.', 320.00, 2.00, 1, '2026-02-25 02:35:26'),
(38, 'Electrical Service', 'Battery Testing', 'Comprehensive battery and charging system test.', 40.00, 0.25, 1, '2026-02-25 02:35:26'),
(39, 'Electrical Service', 'Diagnostic Scan', 'Complete vehicle computer diagnostic scan.', 120.00, 0.75, 1, '2026-02-25 02:35:26'),
(40, 'Electrical Service', 'Check Engine Light Diagnosis', 'Diagnose check engine light and provide repair options.', 120.00, 0.75, 1, '2026-02-25 02:35:26'),
(41, 'Electrical Service', 'Headlight Bulb Replacement', 'Replace burnt out headlight bulb.', 45.00, 0.25, 1, '2026-02-25 02:35:26'),
(42, 'Electrical Service', 'Tail Light Bulb Replacement', 'Replace burnt out tail light bulb.', 40.00, 0.25, 1, '2026-02-25 02:35:26'),
(43, 'Electrical Service', 'Fog Light Installation', 'Install aftermarket fog lights with switch.', 180.00, 1.50, 1, '2026-02-25 02:35:26'),
(44, 'Electrical Service', 'Wiring Repair', 'Diagnose and repair electrical wiring issues.', 150.00, 1.50, 1, '2026-02-25 02:35:26'),
(45, 'Electrical Service', 'Central Locking Repair', 'Diagnose and repair central locking system.', 160.00, 1.50, 1, '2026-02-25 02:35:26'),
(46, 'Electrical Service', 'Power Window Repair', 'Fix faulty power window mechanism or motor.', 180.00, 1.50, 1, '2026-02-25 02:35:26'),
(47, 'Air Conditioning Service', 'AC Recharge', 'Air conditioning system refrigerant recharge and performance check.', 180.00, 1.00, 1, '2026-02-25 02:36:55'),
(48, 'Air Conditioning Service', 'AC Compressor Replacement', 'Replace faulty AC compressor and receiver drier.', 650.00, 3.50, 1, '2026-02-25 02:36:55'),
(49, 'Air Conditioning Service', 'AC Condenser Replacement', 'Replace damaged AC condenser.', 450.00, 2.50, 1, '2026-02-25 02:36:55'),
(50, 'Air Conditioning Service', 'AC Evaporator Cleaning', 'Clean AC evaporator to eliminate odors.', 200.00, 2.00, 1, '2026-02-25 02:36:55'),
(51, 'Air Conditioning Service', 'Cabin Air Filter Replacement', 'Replace cabin air filter for better air quality.', 60.00, 0.25, 1, '2026-02-25 02:36:55'),
(52, 'Air Conditioning Service', 'AC System Leak Test', 'Pressure test AC system to find refrigerant leaks.', 90.00, 0.75, 1, '2026-02-25 02:36:55'),
(53, 'Air Conditioning Service', 'AC Blower Motor Replacement', 'Replace faulty AC blower motor.', 220.00, 1.50, 1, '2026-02-25 02:36:55'),
(54, 'Suspension & Steering Service', 'Shock Absorber Replacement - Front', 'Replace front shock absorbers.', 380.00, 2.00, 1, '2026-02-25 02:37:26'),
(55, 'Suspension & Steering Service', 'Shock Absorber Replacement - Rear', 'Replace rear shock absorbers.', 350.00, 2.00, 1, '2026-02-25 02:37:26'),
(56, 'Suspension & Steering Service', 'Strut Assembly Replacement - Front', 'Replace complete front strut assembly.', 420.00, 2.50, 1, '2026-02-25 02:37:26'),
(57, 'Suspension & Steering Service', 'Strut Assembly Replacement - Rear', 'Replace complete rear strut assembly.', 400.00, 2.50, 1, '2026-02-25 02:37:26'),
(58, 'Suspension & Steering Service', 'Ball Joint Replacement', 'Replace worn ball joints.', 200.00, 2.00, 1, '2026-02-25 02:37:26'),
(59, 'Suspension & Steering Service', 'Tie Rod End Replacement', 'Replace inner or outer tie rod ends.', 160.00, 1.50, 1, '2026-02-25 02:37:26'),
(60, 'Suspension & Steering Service', 'Control Arm Replacement', 'Replace worn control arm and bushings.', 280.00, 2.00, 1, '2026-02-25 02:37:26'),
(61, 'Suspension & Steering Service', 'Sway Bar Link Replacement', 'Replace worn sway bar links.', 120.00, 1.00, 1, '2026-02-25 02:37:26'),
(62, 'Suspension & Steering Service', 'Wheel Alignment', 'Computerized 4-wheel alignment.', 120.00, 1.00, 1, '2026-02-25 02:37:26'),
(63, 'Suspension & Steering Service', 'Wheel Balancing', 'Dynamic wheel balancing for all 4 wheels.', 60.00, 0.50, 1, '2026-02-25 02:37:26'),
(64, 'Suspension & Steering Service', 'Power Steering Rack Replacement', 'Replace leaking or faulty steering rack.', 580.00, 4.00, 1, '2026-02-25 02:37:26'),
(65, 'Tire Service', 'Tire Rotation', 'Rotate tires to ensure even wear.', 40.00, 0.50, 1, '2026-02-25 02:38:37'),
(66, 'Tire Service', 'Tire Repair - Puncture', 'Repair punctured tire (if repairable).', 45.00, 0.50, 1, '2026-02-25 02:38:37'),
(67, 'Tire Service', 'New Tire Installation', 'Mount and balance new tires (per tire).', 35.00, 0.50, 1, '2026-02-25 02:38:37'),
(68, 'Tire Service', 'TPMS Sensor Replacement', 'Replace faulty tire pressure sensor.', 120.00, 0.75, 1, '2026-02-25 02:38:37'),
(69, 'Tire Service', 'TPMS System Reset', 'Reset tire pressure monitoring system.', 50.00, 0.25, 1, '2026-02-25 02:38:37'),
(70, 'Tire Service', 'Winter Tire Changeover', 'Swap summer/winter tires and adjust pressure.', 80.00, 1.00, 1, '2026-02-25 02:38:37'),
(71, 'Exhaust Service', 'Muffler Replacement', 'Replace rusted or damaged muffler.', 250.00, 1.50, 1, '2026-02-25 02:38:37'),
(72, 'Exhaust Service', 'Catalytic Converter Replacement', 'Replace failed catalytic converter.', 650.00, 2.50, 1, '2026-02-25 02:38:37'),
(73, 'Exhaust Service', 'Oxygen Sensor Replacement', 'Replace faulty oxygen sensor.', 180.00, 1.00, 1, '2026-02-25 02:38:37'),
(74, 'Exhaust Service', 'Exhaust Pipe Repair', 'Repair damaged or leaking exhaust pipe.', 150.00, 1.50, 1, '2026-02-25 02:38:37'),
(75, 'Exhaust Service', 'Exhaust System Inspection', 'Complete exhaust system check for leaks and damage.', 60.00, 0.50, 1, '2026-02-25 02:38:37'),
(76, 'Exhaust Service', 'Exhaust Manifold Gasket Replacement', 'Replace leaking exhaust manifold gasket.', 280.00, 2.00, 1, '2026-02-25 02:38:37');

-- --------------------------------------------------------

--
-- Table structure for table `service_parts`
--

CREATE TABLE `service_parts` (
  `id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `part_name` varchar(255) NOT NULL,
  `part_code` varchar(100) DEFAULT NULL,
  `quantity` int(11) DEFAULT 1,
  `unit_price` decimal(10,2) NOT NULL,
  `total_price` decimal(10,2) GENERATED ALWAYS AS (`quantity` * `unit_price`) STORED,
  `warranty_months` int(11) DEFAULT 0,
  `warranty_info` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `service_parts`
--

INSERT INTO `service_parts` (`id`, `booking_id`, `part_name`, `part_code`, `quantity`, `unit_price`, `warranty_months`, `warranty_info`, `created_at`) VALUES
(37, 175, 'tyre', NULL, 2, 120.00, 1, '', '2026-05-21 14:37:13'),
(38, 174, 'engine oil', NULL, 1, 190.00, 0, '', '2026-05-21 14:37:34'),
(39, 173, 'engine oil', NULL, 1, 190.00, 0, '', '2026-05-21 14:38:07'),
(40, 173, 'oil filter', NULL, 1, 50.00, 0, '', '2026-05-21 14:38:07');

-- --------------------------------------------------------

--
-- Table structure for table `service_progress`
--

CREATE TABLE `service_progress` (
  `id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `technician_id` int(11) NOT NULL,
  `status` enum('pending','in_progress','completed','cancelled') DEFAULT 'in_progress',
  `started_at` datetime NOT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `service_progress`
--

INSERT INTO `service_progress` (`id`, `booking_id`, `technician_id`, `status`, `started_at`, `completed_at`, `created_at`) VALUES
(30, 179, 2, 'pending', '0000-00-00 00:00:00', NULL, '2026-05-21 16:25:54');

-- --------------------------------------------------------

--
-- Table structure for table `service_suggestions`
--

CREATE TABLE `service_suggestions` (
  `id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL DEFAULT 0,
  `vehicle_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `service_category_id` int(11) NOT NULL,
  `suggested_date` date NOT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('pending','booked','in_progress','done','skipped') DEFAULT 'pending',
  `completed_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `service_suggestions`
--

INSERT INTO `service_suggestions` (`id`, `booking_id`, `vehicle_id`, `user_id`, `service_category_id`, `suggested_date`, `notes`, `status`, `completed_notes`, `created_at`, `updated_at`) VALUES
(143, 0, 11, 3, 1, '2026-08-21', '50000 km', 'pending', NULL, '2026-05-21 18:34:51', '2026-05-21 18:34:51');

-- --------------------------------------------------------

--
-- Table structure for table `service_suggestions_backup`
--

CREATE TABLE `service_suggestions_backup` (
  `id` int(11) NOT NULL DEFAULT 0,
  `booking_id` int(11) NOT NULL DEFAULT 0,
  `vehicle_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `service_category_id` int(11) NOT NULL,
  `suggested_date` date NOT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('pending','booked','in_progress','done','skipped') DEFAULT 'pending',
  `completed_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `service_suggestions_backup`
--

INSERT INTO `service_suggestions_backup` (`id`, `booking_id`, `vehicle_id`, `user_id`, `service_category_id`, `suggested_date`, `notes`, `status`, `completed_notes`, `created_at`, `updated_at`) VALUES
(16, 0, 4, 3, 5, '2026-02-03', '', 'done', '', '2026-03-09 04:25:20', '2026-03-09 04:41:52'),
(17, 0, 4, 3, 3, '2025-11-19', '', 'done', 'dah luar', '2026-03-09 04:25:20', '2026-03-09 04:44:00'),
(18, 0, 4, 3, 5, '2026-06-09', '', '', 'âœ“ Booked on 11/03/2026 (Booking #21)', '2026-03-09 04:45:25', '2026-03-09 04:55:14'),
(19, 0, 4, 3, 1, '2026-06-09', 'tet', '', 'âœ“ Booked on 13/03/2026 (Booking #22)', '2026-03-09 04:45:36', '2026-03-09 05:42:12'),
(20, 0, 4, 3, 6, '2026-06-10', '', '', 'ðŸ“… Booked on 10/03/2026 (Booking #25)', '2026-03-10 02:39:46', '2026-03-12 04:22:30'),
(21, 0, 4, 3, 2, '2026-06-10', '', '', 'âœ“ Booked on 10/03/2026 (Booking #23)', '2026-03-10 02:39:46', '2026-03-10 03:53:57'),
(22, 0, 4, 3, 3, '2026-06-10', '', '', 'ðŸ“… Booked on 10/03/2026 (Booking #26)', '2026-03-10 02:39:46', '2026-03-12 06:09:07'),
(23, 0, 4, 3, 4, '2026-06-10', '', '', 'ðŸ“… Booked on 11/03/2026 (Booking #27)', '2026-03-10 03:55:27', '2026-03-12 06:24:55'),
(24, 0, 4, 3, 5, '2026-06-10', '', 'done', 'âœ“ Completed on 09/03/2026 (Booking #21)', '2026-03-10 03:55:27', '2026-03-10 03:55:28'),
(25, 0, 4, 3, 7, '2026-06-10', '', '', 'âœ“ Booked on 28/03/2026 (Booking #28)', '2026-03-10 03:55:27', '2026-03-10 03:59:03'),
(26, 0, 4, 3, 1, '2026-06-10', '', 'done', 'âœ“ Completed on 09/03/2026 (Booking #22)', '2026-03-10 03:55:27', '2026-03-10 03:55:28'),
(27, 0, 4, 3, 2, '2026-06-10', '', 'done', 'âœ“ Completed on 10/03/2026 (Booking #23)', '2026-03-10 03:55:27', '2026-03-10 03:55:28');

-- --------------------------------------------------------

--
-- Table structure for table `service_updates`
--

CREATE TABLE `service_updates` (
  `id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `technician_id` int(11) DEFAULT NULL,
  `message` text NOT NULL,
  `update_type` enum('info','waiting','issue','complete') DEFAULT NULL,
  `is_visible_to_customer` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `service_updates`
--

INSERT INTO `service_updates` (`id`, `booking_id`, `technician_id`, `message`, `update_type`, `is_visible_to_customer`, `created_at`) VALUES
(241, 179, NULL, 'Technician assigned', 'info', 1, '2026-05-21 16:25:54');

-- --------------------------------------------------------

--
-- Table structure for table `spare_parts`
--

CREATE TABLE `spare_parts` (
  `id` int(11) NOT NULL,
  `part_name` varchar(255) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `price_min` decimal(10,2) DEFAULT NULL,
  `price_max` decimal(10,2) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `spare_parts`
--

INSERT INTO `spare_parts` (`id`, `part_name`, `category`, `description`, `price_min`, `price_max`, `image_path`, `status`, `created_at`, `updated_at`) VALUES
(13, '14-Inch Tyre', 'Tyres', '14-inch tyre suitable for compact cars', NULL, NULL, 'uploads/spare_parts/1778743157_14_Inch_Tyre.jpg', 'active', '2026-05-14 07:14:59', '2026-05-14 07:19:17'),
(14, '15-Inch Tyre', 'Tyres', '15-inch tyre suitable for sedans', NULL, NULL, 'uploads/spare_parts/1778743162_15_Inch_Tyre.jpg', 'active', '2026-05-14 07:14:59', '2026-05-14 07:19:22'),
(15, '17-Inch Tyre', 'Tyres', '17-inch tyre suitable for sports sedans', NULL, NULL, 'uploads/spare_parts/1778743168_17_Inch_Tyre.jpg', 'active', '2026-05-14 07:14:59', '2026-05-14 07:19:28'),
(16, '20-Inch Tyre', 'Tyres', '20-inch tyre suitable for SUVs and luxury vehicles', NULL, NULL, 'uploads/spare_parts/1778743124_20_Inch_Tyre.jpg', 'active', '2026-05-14 07:14:59', '2026-05-14 07:18:44'),
(21, 'NS40 Battery', 'Batteries', '12V 35AH maintenance-free battery suitable for small cars', NULL, NULL, 'uploads/spare_parts/1778743852_NS40_Battery.png', 'active', '2026-05-14 07:29:38', '2026-05-14 07:30:52'),
(22, 'NS60 Battery', 'Batteries', '12V 45AH maintenance-free battery suitable for sedans', NULL, NULL, 'uploads/spare_parts/1778743858_NS60_Battery.png', 'active', '2026-05-14 07:29:38', '2026-05-14 07:30:58'),
(23, 'NS70 Battery', 'Batteries', '12V 55AH maintenance-free battery suitable for mid-size SUVs', NULL, NULL, 'uploads/spare_parts/1778743864_NS70_Battery.png', 'active', '2026-05-14 07:29:38', '2026-05-14 07:31:04'),
(24, 'DIN66 Battery', 'Batteries', '12V 70AH heavy-duty battery suitable for large SUVs and luxury cars', 380.00, 650.00, 'uploads/spare_parts/1778743869_DIN66_Battery.png', 'active', '2026-05-14 07:29:38', '2026-05-14 07:39:17'),
(26, '10W-40 Engine Oil', 'Engine Oil', 'Semi-synthetic 10W-40 engine oil. Suitable for most Asian cars (Proton, Perodua, Honda, Toyota). Provides good protection and fuel economy.', 35.00, 65.00, 'uploads/spare_parts/1778782116_10W_40_Engine_Oil.jpg', 'active', '2026-05-14 07:47:21', '2026-05-14 18:08:36'),
(27, '20W-50 Engine Oil', 'Engine Oil', 'Mineral 20W-50 engine oil. Suitable for older engines, high-mileage vehicles, and hot climates. Provides excellent engine protection.', 30.00, 55.00, 'uploads/spare_parts/1778782108_20W_50_Engine_Oil.jpg', 'active', '2026-05-14 07:47:21', '2026-05-14 18:08:28'),
(28, '5W-30 Engine Oil', 'Engine Oil', 'Fully synthetic 5W-30 engine oil. Suitable for modern engines, turbocharged cars, and cold start protection. Provides superior fuel economy.', 45.00, 85.00, 'uploads/spare_parts/1778781952_5W_30_Engine_Oil.jpg', 'active', '2026-05-14 07:47:21', '2026-05-14 18:05:53'),
(29, 'Standard Copper Spark Plug', 'Spark Plugs', 'Copper core spark plug with nickel alloy electrode. Standard replacement for most vehicles. Provides reliable performance and good conductivity. Recommended change interval: 20,000-30,000 km.', 8.00, 25.00, 'uploads/spare_parts/1778782625_Standard_Copper_Spark_Plug.jpg', 'active', '2026-05-14 18:16:10', '2026-05-14 18:17:05'),
(30, 'Iridium Spark Plug', 'Spark Plugs', 'Premium iridium-tipped spark plug with fine wire center electrode. Provides better ignition, fuel efficiency, and longer lifespan. Recommended change interval: 60,000-100,000 km.', 25.00, 60.00, 'uploads/spare_parts/1778782617_Iridium_Spark_Plug.jpg', 'active', '2026-05-14 18:16:10', '2026-05-14 18:16:57'),
(31, 'Standard Air Filter (OEM Replacement)', 'Air Filters', 'Standard paper/cellulose air filter element. OEM replacement quality. Traps dust, dirt, and debris from entering engine. Recommended change interval: 10,000-15,000 km or 12 months.', 15.00, 45.00, 'uploads/spare_parts/1778783025_Standard_Air_Filter__OEM_Replacement_.jpg', 'active', '2026-05-14 18:20:38', '2026-05-14 18:23:45'),
(32, 'High Performance Air Filter', 'Air Filters', 'High-flow cotton gauze or foam air filter. Washable and reusable. Better airflow for improved engine performance and fuel economy. Recommended cleaning: 50,000 km.', 80.00, 250.00, 'uploads/spare_parts/1778783008_High_Performance_Air_Filter.jpg', 'active', '2026-05-14 18:20:38', '2026-05-14 18:23:28');

-- --------------------------------------------------------

--
-- Table structure for table `spare_parts_categories`
--

CREATE TABLE `spare_parts_categories` (
  `id` int(11) NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `spare_parts_categories`
--

INSERT INTO `spare_parts_categories` (`id`, `category_name`, `description`, `is_active`, `created_at`) VALUES
(16, 'Tyres', '', 1, '2026-04-27 05:38:18'),
(17, 'Batteries', '', 1, '2026-04-27 05:38:18'),
(18, 'Brake Pads', '', 1, '2026-04-27 05:38:18'),
(19, 'Engine Oil', '', 1, '2026-04-27 05:38:18'),
(20, 'Air Filters', '', 1, '2026-04-27 05:38:18'),
(21, 'Spark Plugs', '', 1, '2026-04-27 05:38:18'),
(22, 'Belts', '', 1, '2026-04-27 05:38:18'),
(23, 'Lights', '', 1, '2026-04-27 05:38:18');

-- --------------------------------------------------------

--
-- Table structure for table `spare_part_brands`
--

CREATE TABLE `spare_part_brands` (
  `id` int(11) NOT NULL,
  `spare_part_id` int(11) NOT NULL,
  `brand_id` int(11) NOT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `spare_part_brands`
--

INSERT INTO `spare_part_brands` (`id`, `spare_part_id`, `brand_id`, `price`, `created_at`) VALUES
(21, 13, 1, 150.00, '2026-05-14 07:14:59'),
(22, 13, 4, 120.00, '2026-05-14 07:14:59'),
(23, 14, 7, 100.00, '2026-05-14 07:14:59'),
(24, 14, 26, 120.00, '2026-05-14 07:14:59'),
(25, 14, 6, 90.00, '2026-05-14 07:14:59'),
(26, 14, 27, 110.00, '2026-05-14 07:14:59'),
(27, 15, 2, 210.00, '2026-05-14 07:14:59'),
(28, 15, 3, 260.00, '2026-05-14 07:14:59'),
(29, 15, 7, 190.00, '2026-05-14 07:14:59'),
(30, 15, 26, 200.00, '2026-05-14 07:14:59'),
(31, 16, 2, 460.00, '2026-05-14 07:14:59'),
(32, 16, 3, 500.00, '2026-05-14 07:14:59'),
(33, 16, 7, 500.00, '2026-05-14 07:14:59'),
(34, 16, 26, 300.00, '2026-05-14 07:14:59'),
(56, 21, 12, 180.00, '2026-05-14 07:29:38'),
(57, 21, 13, 220.00, '2026-05-14 07:29:38'),
(58, 21, 14, 200.00, '2026-05-14 07:29:38'),
(59, 21, 15, 250.00, '2026-05-14 07:29:38'),
(60, 22, 12, 220.00, '2026-05-14 07:29:38'),
(61, 22, 13, 280.00, '2026-05-14 07:29:38'),
(62, 22, 14, 260.00, '2026-05-14 07:29:38'),
(63, 22, 15, 320.00, '2026-05-14 07:29:38'),
(64, 22, 42, 250.00, '2026-05-14 07:29:38'),
(65, 23, 12, 280.00, '2026-05-14 07:29:38'),
(66, 23, 13, 380.00, '2026-05-14 07:29:38'),
(67, 23, 14, 350.00, '2026-05-14 07:29:38'),
(68, 23, 15, 450.00, '2026-05-14 07:29:38'),
(69, 23, 43, 400.00, '2026-05-14 07:29:38'),
(90, 24, 12, 380.00, '2026-05-14 07:39:17'),
(91, 24, 13, 550.00, '2026-05-14 07:39:17'),
(92, 24, 14, 500.00, '2026-05-14 07:39:17'),
(93, 24, 15, 650.00, '2026-05-14 07:39:17'),
(94, 24, 44, 450.00, '2026-05-14 07:39:17'),
(95, 24, 45, 480.00, '2026-05-14 07:39:17'),
(118, 27, 16, 30.00, '2026-05-14 18:08:28'),
(119, 27, 17, 40.00, '2026-05-14 18:08:28'),
(120, 27, 18, 45.00, '2026-05-14 18:08:28'),
(121, 27, 61, 50.00, '2026-05-14 18:08:28'),
(122, 27, 64, 55.00, '2026-05-14 18:08:28'),
(123, 26, 16, 35.00, '2026-05-14 18:08:36'),
(124, 26, 17, 45.00, '2026-05-14 18:08:36'),
(125, 26, 18, 50.00, '2026-05-14 18:08:36'),
(126, 26, 61, 55.00, '2026-05-14 18:08:36'),
(127, 26, 62, 65.00, '2026-05-14 18:08:36'),
(145, 29, 15, 12.00, '2026-05-14 18:17:05'),
(146, 29, 20, 8.00, '2026-05-14 18:17:05'),
(147, 29, 21, 10.00, '2026-05-14 18:17:05'),
(148, 29, 66, 15.00, '2026-05-14 18:17:05'),
(149, 29, 69, 18.00, '2026-05-14 18:17:05'),
(179, 30, 15, 55.00, '2026-05-14 18:21:57'),
(180, 30, 20, 45.00, '2026-05-14 18:21:57'),
(181, 30, 21, 50.00, '2026-05-14 18:21:57'),
(182, 30, 68, 40.00, '2026-05-14 18:21:57'),
(183, 28, 16, 45.00, '2026-05-14 18:22:17'),
(184, 28, 17, 65.00, '2026-05-14 18:22:17'),
(185, 28, 18, 70.00, '2026-05-14 18:22:17'),
(186, 28, 62, 85.00, '2026-05-14 18:22:17'),
(187, 32, 21, 110.00, '2026-05-14 18:23:28'),
(188, 32, 74, 180.00, '2026-05-14 18:23:28'),
(189, 32, 75, 130.00, '2026-05-14 18:23:28'),
(190, 32, 79, 150.00, '2026-05-14 18:23:28'),
(191, 31, 15, 25.00, '2026-05-14 18:23:45'),
(192, 31, 21, 22.00, '2026-05-14 18:23:45'),
(193, 31, 80, 18.00, '2026-05-14 18:23:45'),
(194, 31, 82, 25.00, '2026-05-14 18:23:45');

-- --------------------------------------------------------

--
-- Table structure for table `technicians`
--

CREATE TABLE `technicians` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `technicians`
--

INSERT INTO `technicians` (`id`, `name`, `phone`, `email`, `is_active`, `created_at`) VALUES
(1, 'Ahmad Muafaz', '012-3456789', NULL, 1, '2026-02-27 03:47:18'),
(2, 'Raj Kumar', '013-4567890', NULL, 1, '2026-02-27 03:47:18'),
(4, 'Siva Nathan', '015-6789012', NULL, 1, '2026-02-27 03:47:18'),
(5, 'Mohd Hafiz', '016-7890123', NULL, 1, '2026-02-27 03:47:18');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `security_image_path` varchar(255) DEFAULT NULL,
  `security_phrase` varchar(255) DEFAULT NULL,
  `role` enum('admin','customer','staff') DEFAULT 'customer',
  `is_active` tinyint(4) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `email`, `username`, `password_hash`, `full_name`, `phone`, `security_image_path`, `security_phrase`, `role`, `is_active`, `created_at`) VALUES
(2, 'admin@gmail.com', 'admin', '$2y$10$3SCpFh7AkAUfleaVrBvA.OzBhDOVF3ST6WTk/sp9IUQrr32sP3R8i', 'Admin', '01126658335', 'red-car.jpg', 'Admin123', 'admin', 1, '2026-02-23 13:15:38'),
(3, 'tarvenmurugarajan@gmail.com', 'Tarven', '$2y$10$LpS766EHmdgXZCohiRR4y.HYon8AKPnzVltRUv.piYB1X87mhR9Jq', 'Tarven', '011-26658335', 'desert.jpg', '12345', 'customer', 1, '2026-02-24 03:01:51');

-- --------------------------------------------------------

--
-- Table structure for table `vehicles`
--

CREATE TABLE `vehicles` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `brand_name` varchar(50) NOT NULL,
  `model` varchar(50) NOT NULL,
  `year` year(4) NOT NULL,
  `color` varchar(30) DEFAULT NULL,
  `number_plate` varchar(20) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `vehicles`
--

INSERT INTO `vehicles` (`id`, `user_id`, `brand_name`, `model`, `year`, `color`, `number_plate`, `created_at`) VALUES
(5, 3, 'SAGA', 'LMCD', '2010', 'ORANGE', 'WQN3867', '2026-05-07 22:34:03'),
(11, 3, 'HONDA', 'CIVIC', '2016', 'BLACK', '12MNW', '2026-05-18 07:04:34');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `bookings`
--
ALTER TABLE `bookings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `service_category_id` (`service_category_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_vehicle_id` (`vehicle_id`),
  ADD KEY `idx_booking_date` (`booking_date`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_date_time` (`booking_date`,`booking_time`);

--
-- Indexes for table `booking_timeslots`
--
ALTER TABLE `booking_timeslots`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_slot_time` (`slot_time`);

--
-- Indexes for table `brands`
--
ALTER TABLE `brands`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `brand_name` (`brand_name`),
  ADD KEY `idx_brand_name` (`brand_name`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_expires` (`expires_at`),
  ADD KEY `idx_used` (`used`);

--
-- Indexes for table `security_images`
--
ALTER TABLE `security_images`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `service_categories`
--
ALTER TABLE `service_categories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_category_name` (`category_name`);

--
-- Indexes for table `service_parts`
--
ALTER TABLE `service_parts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_booking` (`booking_id`);

--
-- Indexes for table `service_progress`
--
ALTER TABLE `service_progress`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_booking` (`booking_id`),
  ADD KEY `idx_technician` (`technician_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `service_suggestions`
--
ALTER TABLE `service_suggestions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_vehicle` (`vehicle_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_suggested_date` (`suggested_date`);

--
-- Indexes for table `service_updates`
--
ALTER TABLE `service_updates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `booking_id` (`booking_id`),
  ADD KEY `technician_id` (`technician_id`);

--
-- Indexes for table `spare_parts`
--
ALTER TABLE `spare_parts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_part_name` (`part_name`),
  ADD KEY `idx_category` (`category`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `spare_parts_categories`
--
ALTER TABLE `spare_parts_categories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `spare_part_brands`
--
ALTER TABLE `spare_part_brands`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_part_brand` (`spare_part_id`,`brand_id`),
  ADD KEY `idx_spare_part` (`spare_part_id`),
  ADD KEY `idx_brand` (`brand_id`);

--
-- Indexes for table `technicians`
--
ALTER TABLE `technicians`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `idx_role` (`role`),
  ADD KEY `idx_username` (`username`);

--
-- Indexes for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_number_plate` (`number_plate`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `bookings`
--
ALTER TABLE `bookings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=181;

--
-- AUTO_INCREMENT for table `booking_timeslots`
--
ALTER TABLE `booking_timeslots`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `brands`
--
ALTER TABLE `brands`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=88;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `security_images`
--
ALTER TABLE `security_images`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `service_categories`
--
ALTER TABLE `service_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=77;

--
-- AUTO_INCREMENT for table `service_parts`
--
ALTER TABLE `service_parts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

--
-- AUTO_INCREMENT for table `service_progress`
--
ALTER TABLE `service_progress`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `service_suggestions`
--
ALTER TABLE `service_suggestions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=145;

--
-- AUTO_INCREMENT for table `service_updates`
--
ALTER TABLE `service_updates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=242;

--
-- AUTO_INCREMENT for table `spare_parts`
--
ALTER TABLE `spare_parts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- AUTO_INCREMENT for table `spare_parts_categories`
--
ALTER TABLE `spare_parts_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `spare_part_brands`
--
ALTER TABLE `spare_part_brands`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=200;

--
-- AUTO_INCREMENT for table `technicians`
--
ALTER TABLE `technicians`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `vehicles`
--
ALTER TABLE `vehicles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `bookings`
--
ALTER TABLE `bookings`
  ADD CONSTRAINT `bookings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `bookings_ibfk_2` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `bookings_ibfk_3` FOREIGN KEY (`service_category_id`) REFERENCES `service_categories` (`id`);

--
-- Constraints for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD CONSTRAINT `password_resets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `service_parts`
--
ALTER TABLE `service_parts`
  ADD CONSTRAINT `service_parts_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `service_updates`
--
ALTER TABLE `service_updates`
  ADD CONSTRAINT `service_updates_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `service_updates_ibfk_2` FOREIGN KEY (`technician_id`) REFERENCES `technicians` (`id`);

--
-- Constraints for table `spare_part_brands`
--
ALTER TABLE `spare_part_brands`
  ADD CONSTRAINT `spare_part_brands_ibfk_1` FOREIGN KEY (`spare_part_id`) REFERENCES `spare_parts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `spare_part_brands_ibfk_2` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD CONSTRAINT `vehicles_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

