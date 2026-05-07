-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 19, 2026 at 02:18 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `kody2`
--

-- --------------------------------------------------------

--
-- Table structure for table `acc_groups`
--

CREATE TABLE `acc_groups` (
  `id` int(1) NOT NULL,
  `aname` varchar(40) NOT NULL,
  `acc_type` int(1) NOT NULL,
  `parent_id` int(1) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `code` varchar(30) DEFAULT NULL,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `acc_head`
--

CREATE TABLE `acc_head` (
  `id` int(1) NOT NULL,
  `code` varchar(20) NOT NULL,
  `deletable` int(11) DEFAULT 1,
  `editable` int(1) NOT NULL DEFAULT 1,
  `aname` varchar(50) NOT NULL,
  `phone` varchar(200) DEFAULT NULL,
  `address` varchar(200) DEFAULT NULL,
  `e_mail` varchar(100) DEFAULT NULL,
  `constant` int(11) NOT NULL DEFAULT 0,
  `is_stock` int(1) NOT NULL DEFAULT 0,
  `is_fund` int(11) DEFAULT 0,
  `rentable` int(11) DEFAULT NULL,
  `parent_id` int(1) NOT NULL,
  `nature` int(1) DEFAULT NULL,
  `kind` int(1) DEFAULT NULL,
  `is_basic` int(1) NOT NULL,
  `start_balance` decimal(3,0) NOT NULL DEFAULT 0,
  `credit` decimal(3,0) NOT NULL DEFAULT 0,
  `debit` decimal(3,0) NOT NULL DEFAULT 0,
  `balance` decimal(12,3) NOT NULL DEFAULT 0.000,
  `secret` int(1) NOT NULL DEFAULT 0,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `info` varchar(250) DEFAULT NULL,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `acc_head`
--

INSERT INTO `acc_head` (`id`, `code`, `deletable`, `editable`, `aname`, `phone`, `address`, `e_mail`, `constant`, `is_stock`, `is_fund`, `rentable`, `parent_id`, `nature`, `kind`, `is_basic`, `start_balance`, `credit`, `debit`, `balance`, `secret`, `crtime`, `mdtime`, `info`, `isdeleted`, `tenant`, `branch`) VALUES
(1, '1', 0, 0, 'الاصول', NULL, NULL, NULL, 0, 0, 0, NULL, 0, 1, 1, 1, 0, 0, 0, 0.000, 0, '2023-11-25 00:55:49', '2024-11-16 20:16:48', NULL, 0, 0, 0),
(2, '2', 0, 0, 'الخصوم', NULL, NULL, NULL, 0, 0, 0, NULL, 0, 1, 1, 1, 0, 0, 0, 0.000, 0, '2023-11-26 02:10:59', '2024-11-16 20:16:50', NULL, 0, 0, 0),
(3, '22', 0, 0, 'حقوق الملكية', NULL, NULL, NULL, 0, 0, 0, NULL, 2, 1, 1, 1, 0, 0, 0, 0.000, 0, '2023-11-26 02:14:04', '2024-11-16 20:16:53', NULL, 0, 0, 0),
(4, '3', 0, 0, 'الايرادات', NULL, NULL, NULL, 0, 0, 0, NULL, 0, 1, 2, 1, 0, 0, 0, 0.000, 0, '2023-11-26 02:14:29', '2024-06-11 02:16:29', NULL, 0, 0, 0),
(5, '4', 0, 0, 'المصروفات', NULL, NULL, NULL, 0, 0, 0, NULL, 0, 1, 2, 1, 0, 0, 0, 0.000, 0, '2023-11-26 02:14:59', '2024-06-11 02:16:35', NULL, 0, 0, 0),
(6, '11', 0, 0, 'الاصول الثابته', NULL, NULL, NULL, 0, 0, 0, NULL, 1, 1, 1, 1, 0, 0, 0, 0.000, 0, '2023-11-26 02:38:23', '2024-06-11 02:16:38', NULL, 0, 0, 0),
(7, '12', 0, 0, 'الاصول المتداوله', NULL, NULL, NULL, 0, 0, 0, NULL, 1, NULL, 1, 1, 0, 0, 0, 0.000, 0, '2023-11-26 02:45:08', '2024-06-11 02:16:42', NULL, 0, 0, 0),
(8, '21', 0, 0, 'الخصوم المتداولة', NULL, NULL, NULL, 0, 0, 0, NULL, 2, NULL, 1, 1, 0, 0, 0, 0.000, 0, '2023-11-26 02:45:47', '2024-06-11 02:16:46', NULL, 0, 0, 0),
(9, '221', 0, 0, 'الشركاء', NULL, NULL, NULL, 0, 0, 0, NULL, 3, NULL, 1, 1, 0, 0, 0, 0.000, 0, '2023-11-26 02:46:20', '2024-06-11 02:16:52', NULL, 0, 0, 0),
(10, '222', 0, 0, 'ارباح غير موزعة', NULL, NULL, NULL, 0, 0, 0, NULL, 3, NULL, 1, 1, 0, 0, 0, 0.000, 0, '2023-11-26 02:47:03', '2024-11-16 20:23:26', NULL, 0, 0, 0),
(11, '223', 0, 1, 'ارباح غير موزعة لفترات سابقة', NULL, NULL, NULL, 0, 0, 0, NULL, 3, NULL, 1, 0, 0, 0, 0, 0.000, 0, '2023-11-26 02:47:50', '2025-10-28 08:51:19', NULL, 0, 0, 0),
(13, '31', 0, 0, 'ايرادات المبيعات', NULL, NULL, NULL, 0, 0, 0, NULL, 4, NULL, 2, 1, 0, 0, 0, 0.000, 0, '2023-11-26 19:37:49', '2024-11-16 20:23:31', NULL, 0, 0, 0),
(14, '32', 0, 0, 'ايرادات غير تشغيليه', NULL, NULL, NULL, 0, 0, 0, NULL, 4, NULL, 2, 1, 0, 0, 0, 0.000, 0, '2023-11-26 19:38:15', '2024-11-16 20:23:33', NULL, 0, 0, 0),
(15, '41', 0, 0, 'تكاليف المبيعات', NULL, NULL, NULL, 0, 0, 0, 0, 5, NULL, 2, 1, 0, 0, 0, 0.000, 0, '2023-11-26 19:39:10', '2024-11-16 20:23:36', NULL, 0, 0, 0),
(16, '42', 0, 0, 'تكلفه البضاعه المباعه', NULL, NULL, NULL, 0, 0, 0, NULL, 5, NULL, 2, 1, 0, 0, 0, 0.000, 0, '2023-11-26 19:39:49', '2024-06-11 02:17:12', NULL, 0, 0, 0),
(17, '43', 0, 0, 'رواتب و اجور', NULL, NULL, NULL, 0, 0, 0, NULL, 5, NULL, 2, 1, 0, 0, 0, 0.000, 0, '2023-11-26 19:40:07', '2024-06-11 02:17:16', NULL, 0, 0, 0),
(18, '121', 0, 0, 'الصناديق', NULL, NULL, NULL, 0, 0, 0, NULL, 7, NULL, 1, 1, 0, 0, 0, 0.000, 0, '2023-12-08 10:50:49', '2024-06-11 02:17:18', NULL, 0, 0, 0),
(19, '122', 0, 0, 'العملاء', NULL, NULL, NULL, 0, 0, 0, NULL, 7, NULL, 1, 1, 0, 0, 0, 0.000, 0, '2023-12-08 10:52:13', '2024-06-11 02:17:22', NULL, 0, 0, 0),
(20, '123', 0, 0, 'المخزون', NULL, NULL, NULL, 0, 0, 0, NULL, 7, NULL, 1, 1, 0, 0, 0, 0.000, 0, '2023-12-08 10:52:51', '2024-06-11 02:17:27', NULL, 0, 0, 0),
(21, '1211', 0, 1, 'الصندوق الافتراضي', NULL, NULL, NULL, 0, 0, 1, NULL, 18, NULL, 1, 0, 0, 0, 0, 186017.000, 0, '2023-12-09 08:46:52', '2026-02-18 15:34:21', NULL, 0, 0, 0),
(24, '1221', 0, 0, 'العميل النقدي', NULL, NULL, NULL, 0, 0, 0, 0, 19, NULL, 1, 1, 0, 0, 0, 0.000, 0, '2023-12-27 23:25:46', '2024-06-22 16:37:57', NULL, 0, 0, 0),
(27, '123001', 0, 0, 'المخزن الرئيسي', NULL, NULL, NULL, 0, 1, 0, 0, 20, NULL, 1, 0, 0, 0, 0, -120253.000, 0, '2023-12-28 01:35:35', '2026-02-18 15:35:06', NULL, 0, 0, 0),
(29, '2211', 0, 0, 'الشريك الرئيسي', NULL, NULL, NULL, 0, 0, 0, NULL, 9, NULL, 1, 0, 0, 0, 0, 0.000, 0, '2023-12-30 00:12:22', '2025-10-28 08:51:21', NULL, 0, 0, 0),
(33, '211', 0, 0, 'الموردين', NULL, NULL, NULL, 0, 0, 0, NULL, 8, NULL, 1, 1, 0, 0, 0, 0.000, 0, '2024-01-22 03:41:26', '2024-06-11 02:17:55', NULL, 0, 0, 0),
(34, '212', 0, 1, 'الدائنين الاخرين', NULL, NULL, NULL, 0, 0, 0, NULL, 8, NULL, 1, 0, 0, 0, 0, 0.000, 0, '2024-01-22 03:42:08', '2025-10-28 08:51:21', NULL, 0, 0, 0),
(35, '213', 0, 0, 'الموظفين', NULL, NULL, NULL, 0, 0, 0, NULL, 8, NULL, 1, 1, 0, 0, 0, 0.000, 0, '2024-01-22 03:42:29', '2024-06-11 02:18:01', NULL, 0, 0, 0),
(36, '2111', 0, 0, 'المورد الافتراضي', NULL, NULL, NULL, 0, 0, 0, NULL, 33, NULL, 1, 0, 0, 0, 0, -1485.000, 0, '2024-01-23 04:17:26', '2025-10-28 08:51:23', NULL, 0, 0, 0),
(37, '124', 0, 0, 'البنوك', NULL, NULL, NULL, 0, 0, 0, NULL, 7, NULL, 1, 1, 0, 0, 0, 0.000, 0, '2024-01-23 04:22:23', '2024-06-11 02:18:07', NULL, 0, 0, 0),
(38, '125', 0, 0, 'مدينين آخرين', NULL, NULL, NULL, 0, 0, 0, NULL, 7, NULL, 1, 1, 0, 0, 0, 0.000, 0, '2024-01-23 04:30:11', '2024-06-11 02:18:13', NULL, 0, 0, 0),
(39, '1241', 0, 1, 'البنك الافتراضي', NULL, NULL, NULL, 0, 0, 0, NULL, 37, NULL, 1, 0, 0, 0, 0, -70350.000, 0, '2024-01-23 04:32:21', '2025-10-28 08:51:20', NULL, 0, 0, 0),
(40, '44', 0, 0, 'مصروفات عامه ', NULL, NULL, NULL, 0, 0, 0, NULL, 5, NULL, 2, 1, 0, 0, 0, 0.000, 0, '2024-01-23 04:34:08', '2024-06-11 02:18:24', NULL, 0, 0, 0),
(55, '112', 0, 0, 'اصول قابله للتأجير', NULL, NULL, NULL, 0, 0, 0, 0, 6, NULL, 1, 1, 0, 0, 0, 0.000, 0, '2024-02-20 03:16:57', '2024-06-11 02:18:30', NULL, 0, 0, 0),
(86, '411', 0, 0, 'صافي المشتريات', NULL, NULL, NULL, 0, 0, 0, 0, 15, NULL, 2, 1, 0, 0, 0, 0.000, 0, '2024-03-07 22:56:24', '2024-06-11 02:18:46', NULL, 0, 0, 0),
(89, '4111', 0, 0, 'المشتربات', NULL, NULL, NULL, 0, 0, 0, 0, 86, NULL, 2, 1, 0, 0, 0, 0.000, 0, '2024-03-08 19:37:09', '2024-06-11 02:18:53', NULL, 0, 0, 0),
(90, '4112', 0, 0, 'مردود المشتريات', NULL, NULL, NULL, 0, 0, 0, 0, 86, NULL, 2, 1, 0, 0, 0, 0.000, 0, '2024-03-08 19:38:25', '2024-06-11 02:18:56', NULL, 0, 0, 0),
(91, '41103', 0, 1, 'خصم مسموح به', NULL, NULL, NULL, 0, 0, 0, 0, 86, NULL, 2, 0, 0, 0, 0, -379.000, 0, '2024-03-08 19:43:40', '2026-02-12 10:04:48', NULL, 0, 0, 0),
(92, '311', 0, 0, 'صافي المبيعات', NULL, NULL, NULL, 0, 0, 0, 0, 13, NULL, 2, 1, 0, 0, 0, 0.000, 0, '2024-03-08 19:48:15', '2024-06-11 02:19:02', NULL, 0, 0, 0),
(93, '3111', 0, 0, 'المبيعات', NULL, NULL, NULL, 0, 0, 0, 0, 92, NULL, 2, 1, 0, 0, 0, 0.000, 0, '2024-03-08 19:49:07', '2024-06-11 02:19:05', NULL, 0, 0, 0),
(94, '3112', 0, 0, 'مردود المبيعات', NULL, NULL, NULL, 0, 0, 0, 0, 92, NULL, 2, 1, 0, 0, 0, 0.000, 0, '2024-03-08 19:50:03', '2024-06-11 02:19:07', NULL, 0, 0, 0),
(95, '3113', 0, 0, 'خصومات تشغيل', NULL, NULL, NULL, 0, 0, 0, 0, 92, NULL, 2, 1, 0, 0, 0, 0.000, 0, '2024-03-08 19:54:56', '2024-06-11 02:19:10', NULL, 0, 0, 0),
(97, '31131', 0, 1, 'خصم مكتسب', NULL, NULL, NULL, 0, 0, 0, 0, 95, NULL, 2, 0, 0, 0, 0, 0.000, 0, '2024-03-09 22:38:24', '2024-11-16 20:48:00', NULL, 0, 0, 0),
(98, '321', 0, 0, 'ايرادات من تأجير أصول', NULL, NULL, NULL, 0, 0, 0, 0, 14, NULL, 2, 1, 0, 0, 0, 0.000, 0, '2024-03-14 12:58:05', '2024-06-11 02:19:27', NULL, 0, 0, 0),
(99, '32101', 0, 0, 'ايرادات من التأجير', NULL, NULL, NULL, 0, 0, 0, 0, 98, NULL, 2, 0, 0, 0, 0, 0.000, 0, '2024-03-14 13:01:19', '2024-11-16 20:49:22', NULL, 0, 0, 0),
(131, '213001', 0, 1, 'الموظف 1', NULL, NULL, NULL, 0, 0, 0, 0, 35, NULL, 1, 0, 0, 0, 0, 20535.000, 0, '2024-06-18 06:41:56', '2025-10-28 08:51:24', NULL, 0, 0, 0),
(148, '122001', 0, 0, 'العميل الافتراضي', '', '', NULL, 0, 0, 0, 0, 19, NULL, 1, 0, 0, 0, 0, 595.000, 0, '2024-06-22 22:26:46', '2025-10-28 08:51:22', NULL, 0, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `allowances`
--

CREATE TABLE `allowances` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `info` varchar(250) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `tybe` int(1) NOT NULL,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `analisys`
--

CREATE TABLE `analisys` (
  `id` int(11) NOT NULL,
  `client` int(11) NOT NULL,
  `lap` int(11) NOT NULL,
  `name` varchar(250) DEFAULT NULL,
  `comment` varchar(250) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `img` varchar(250) DEFAULT NULL,
  `info` varchar(250) DEFAULT NULL,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attandance`
--

CREATE TABLE `attandance` (
  `id` int(11) NOT NULL,
  `employee` int(11) NOT NULL,
  `fptybe` int(1) NOT NULL,
  `fpdate` date NOT NULL DEFAULT current_timestamp(),
  `time` time NOT NULL DEFAULT current_timestamp(),
  `user` int(1) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `isdeleted` tinyint(1) NOT NULL DEFAULT 0,
  `fromwhere` varchar(10) DEFAULT NULL,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attandance`
--

INSERT INTO `attandance` (`id`, `employee`, `fptybe`, `fpdate`, `time`, `user`, `crtime`, `isdeleted`, `fromwhere`, `tenant`, `branch`) VALUES
(757, 138, 1, '2026-02-03', '10:00:00', 1, '2026-02-03 12:27:07', 1, NULL, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `attdocs`
--

CREATE TABLE `attdocs` (
  `id` int(11) NOT NULL,
  `empid` int(11) NOT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `fromdate` date DEFAULT NULL,
  `todate` date NOT NULL,
  `alldays` double NOT NULL,
  `workdays` double NOT NULL,
  `exphours` double NOT NULL,
  `accualhours` double NOT NULL,
  `attdays` int(11) NOT NULL,
  `absdays` int(11) NOT NULL,
  `holidays` int(11) NOT NULL,
  `earlyminits` double NOT NULL,
  `entitle` double NOT NULL DEFAULT 0,
  `info` varchar(250) DEFAULT NULL,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attdocs`
--

INSERT INTO `attdocs` (`id`, `empid`, `crtime`, `mdtime`, `fromdate`, `todate`, `alldays`, `workdays`, `exphours`, `accualhours`, `attdays`, `absdays`, `holidays`, `earlyminits`, `entitle`, `info`, `isdeleted`, `tenant`, `branch`) VALUES
(138, 138, '2026-02-03 12:47:33', '2026-02-03 12:47:33', '2007-02-22', '2026-12-12', 7234, 6200, 37200, 3, 1, 1, 1034, 0, 0.57, ' احتساب الرواتب من يوم 2007-02-22 الي يوم 2026-12-12 بنظام الاستحقاق بالساعات فقط', 0, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `attlog`
--

CREATE TABLE `attlog` (
  `id` int(11) NOT NULL,
  `employee` int(1) NOT NULL,
  `day` date NOT NULL,
  `starttime` time DEFAULT NULL,
  `endtime` time DEFAULT NULL,
  `fpin` time DEFAULT NULL,
  `fpout` time DEFAULT NULL,
  `defhours` double DEFAULT NULL,
  `curhours` double DEFAULT NULL,
  `dueforhour` double DEFAULT NULL,
  `realdue` double DEFAULT NULL,
  `statue` int(1) NOT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `info` int(11) DEFAULT NULL,
  `attdoc` int(1) DEFAULT NULL,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `barcodes`
--

CREATE TABLE `barcodes` (
  `id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `barcode` varchar(25) NOT NULL,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `booking_cards`
--

CREATE TABLE `booking_cards` (
  `id` int(11) NOT NULL,
  `client` varchar(100) NOT NULL,
  `barcode` varchar(50) NOT NULL,
  `rtybe` varchar(20) NOT NULL,
  `rcost` int(11) NOT NULL,
  `qty` int(11) NOT NULL,
  `remain` int(11) NOT NULL,
  `bcase` int(11) NOT NULL,
  `fromdate` date NOT NULL,
  `todate` date NOT NULL,
  `crtime` datetime NOT NULL DEFAULT current_timestamp(),
  `isdeleted` int(1) NOT NULL DEFAULT 0,
  `user` int(1) NOT NULL DEFAULT 0,
  `bransh` int(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `book_tybes`
--

CREATE TABLE `book_tybes` (
  `id` int(1) NOT NULL,
  `name` varchar(25) NOT NULL,
  `value` double DEFAULT NULL,
  `qty` int(1) NOT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `calls`
--

CREATE TABLE `calls` (
  `id` int(11) NOT NULL,
  `subject` varchar(100) NOT NULL,
  `call_type` int(1) NOT NULL DEFAULT 1,
  `call_date` date NOT NULL DEFAULT current_timestamp(),
  `call_time` time NOT NULL DEFAULT current_timestamp(),
  `duration` varchar(100) DEFAULT NULL,
  `client_id` int(11) NOT NULL DEFAULT 1,
  `emp_comment` varchar(250) DEFAULT NULL,
  `content` text DEFAULT NULL,
  `next_date` date NOT NULL DEFAULT current_timestamp(),
  `next_time` time NOT NULL DEFAULT current_timestamp(),
  `mod_comment` varchar(250) DEFAULT NULL,
  `mod_rate` int(1) NOT NULL DEFAULT 5,
  `user_id` int(11) NOT NULL DEFAULT 1,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cases`
--

CREATE TABLE `cases` (
  `id` int(1) NOT NULL,
  `cname` varchar(50) NOT NULL,
  `info` varchar(100) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chances`
--

CREATE TABLE `chances` (
  `id` int(1) NOT NULL,
  `client` varchar(50) DEFAULT NULL,
  `cname` varchar(50) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `cdate` date DEFAULT NULL,
  `important` int(1) DEFAULT NULL,
  `expected` double NOT NULL DEFAULT 0,
  `tybe` int(1) NOT NULL DEFAULT 1,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chances_tybes`
--

CREATE TABLE `chances_tybes` (
  `id` int(1) NOT NULL,
  `cname` varchar(50) NOT NULL,
  `info` varchar(50) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `chances_tybes`
--

INSERT INTO `chances_tybes` (`id`, `cname`, `info`, `crtime`, `isdeleted`, `tenant`, `branch`) VALUES
(1, 'جديد', NULL, '2023-11-28 01:20:13', 0, 0, 0),
(2, 'تم الاتفاق', NULL, '2023-11-28 01:27:21', 0, 0, 0),
(3, 'دفع عربون', NULL, '2023-11-28 01:27:21', 0, 0, 0),
(4, 'صفقه تامه', NULL, '2023-11-28 01:27:42', 0, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `cities`
--

CREATE TABLE `cities` (
  `id` int(1) NOT NULL,
  `cname` varchar(150) NOT NULL,
  `info` varchar(150) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `user` int(1) NOT NULL DEFAULT 1,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `clients`
--

CREATE TABLE `clients` (
  `id` int(1) NOT NULL,
  `name` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `phone2` varchar(150) DEFAULT NULL,
  `address` varchar(250) DEFAULT NULL,
  `address2` varchar(150) DEFAULT NULL,
  `address3` varchar(150) DEFAULT NULL,
  `city` int(11) DEFAULT NULL,
  `height` double DEFAULT NULL,
  `weight` double DEFAULT NULL,
  `dateofbirth` date DEFAULT NULL,
  `ref` varchar(20) DEFAULT NULL,
  `diseses` varchar(200) DEFAULT NULL,
  `info` varchar(200) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `imgs` varchar(250) DEFAULT NULL,
  `jop` varchar(50) DEFAULT NULL,
  `gender` int(1) DEFAULT NULL,
  `drugs` varchar(250) DEFAULT NULL,
  `seriousdes` varchar(250) DEFAULT NULL,
  `familydes` varchar(250) DEFAULT NULL,
  `allergy` varchar(250) DEFAULT NULL,
  `temp` varchar(9) DEFAULT NULL,
  `pressure` varchar(9) DEFAULT NULL,
  `diabetes` varchar(9) DEFAULT NULL,
  `brate` varchar(9) DEFAULT NULL,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `closed_orders`
--

CREATE TABLE `closed_orders` (
  `id` int(11) NOT NULL,
  `shift` varchar(10) NOT NULL,
  `user` varchar(10) DEFAULT NULL,
  `date` date DEFAULT NULL,
  `strttime` datetime DEFAULT NULL,
  `endtime` time DEFAULT NULL,
  `total_sales` double NOT NULL DEFAULT 0,
  `delevery` double NOT NULL DEFAULT 0,
  `tables` double NOT NULL DEFAULT 0,
  `takeaway` double NOT NULL DEFAULT 0,
  `expenses` double NOT NULL DEFAULT 0,
  `fund_before` double NOT NULL DEFAULT 0,
  `fund_after` double NOT NULL DEFAULT 0,
  `exp_notes` varchar(30) DEFAULT NULL,
  `cash` double NOT NULL DEFAULT 0,
  `info` varchar(50) DEFAULT NULL,
  `crtime` datetime NOT NULL DEFAULT current_timestamp(),
  `mdtime` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `info2` varchar(20) DEFAULT NULL,
  `tenant` int(11) NOT NULL DEFAULT 1,
  `branch` int(11) NOT NULL DEFAULT 1,
  `total_cash` decimal(10,2) DEFAULT 0.00,
  `total_visa` decimal(10,2) DEFAULT 0.00,
  `total_discount` decimal(10,2) DEFAULT 0.00,
  `total_returns` decimal(10,2) DEFAULT 0.00,
  `start_cash` decimal(10,2) DEFAULT 0.00,
  `actual_cash` decimal(10,2) DEFAULT 0.00,
  `actual_visa` decimal(10,2) DEFAULT 0.00,
  `deficit` decimal(10,2) DEFAULT 0.00,
  `status` tinyint(1) DEFAULT 1,
  `json_details` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cost_centers`
--

CREATE TABLE `cost_centers` (
  `id` int(11) NOT NULL,
  `cname` varchar(100) NOT NULL,
  `info` varchar(200) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cost_centers`
--

INSERT INTO `cost_centers` (`id`, `cname`, `info`, `crtime`, `mdtime`, `isdeleted`, `tenant`, `branch`) VALUES
(1, 'المركز الافتراضي', NULL, '2024-01-19 01:17:02', '2024-01-19 01:17:02', 0, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `criminals`
--

CREATE TABLE `criminals` (
  `id` int(11) NOT NULL,
  `cname` varchar(200) NOT NULL,
  `nickname` varchar(200) NOT NULL,
  `dateofbirth` date DEFAULT NULL,
  `jop` varchar(200) NOT NULL,
  `station` varchar(111) DEFAULT NULL,
  `mname` varchar(200) NOT NULL,
  `crmaddress` varchar(200) NOT NULL,
  `idcardnum` varchar(200) NOT NULL,
  `scar` int(11) NOT NULL,
  `otherdetails` varchar(200) NOT NULL,
  `prtnrs` varchar(200) NOT NULL,
  `crmstyle` int(11) DEFAULT NULL,
  `dngrs` int(11) DEFAULT NULL,
  `fesh` int(11) DEFAULT NULL,
  `karta` int(11) DEFAULT NULL,
  `entry` int(11) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NULL DEFAULT current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crm_style`
--

CREATE TABLE `crm_style` (
  `id` int(11) NOT NULL,
  `cname` varchar(200) NOT NULL,
  `info` varchar(200) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NULL DEFAULT current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ctp`
--

CREATE TABLE `ctp` (
  `id` int(1) NOT NULL,
  `cname` varchar(50) NOT NULL,
  `type` varchar(100) DEFAULT NULL,
  `number` varchar(100) DEFAULT NULL,
  `info` varchar(100) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cvs`
--

CREATE TABLE `cvs` (
  `id` int(1) NOT NULL,
  `userid` int(1) NOT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `name` varchar(50) NOT NULL,
  `degree` varchar(50) DEFAULT NULL,
  `address` varchar(100) DEFAULT NULL,
  `birthdate` date DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(50) NOT NULL,
  `skills` text DEFAULT NULL,
  `exp1` varchar(250) DEFAULT NULL,
  `exp2` varchar(250) DEFAULT NULL,
  `exp3` varchar(250) DEFAULT NULL,
  `lastsalary` varchar(50) NOT NULL,
  `expsalary` varchar(50) NOT NULL DEFAULT '0',
  `referances` text DEFAULT NULL,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cvs`
--

INSERT INTO `cvs` (`id`, `userid`, `crtime`, `mdtime`, `name`, `degree`, `address`, `birthdate`, `phone`, `email`, `skills`, `exp1`, `exp2`, `exp3`, `lastsalary`, `expsalary`, `referances`, `isdeleted`, `tenant`, `branch`) VALUES
(1, 1, '2026-02-03 13:15:52', '2026-02-03 13:15:52', 'e', 'rfvgg', 'NO. 342 - London Oxford Street, 012 United Kingdom', '2026-02-03', '12345678912', 'e@e.com', 'reresvv', 'efwe', 'wfwsf', 'sdvdvd', '', '', '', 0, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `delivery_clients`
--

CREATE TABLE `delivery_clients` (
  `id` int(11) NOT NULL,
  `client_name` varchar(255) NOT NULL COMMENT 'Customer name',
  `phone` varchar(20) NOT NULL COMMENT 'Phone number',
  `address` text NOT NULL COMMENT 'Address',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'Created date',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT 'Updated date',
  `isdeleted` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Is deleted'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Delivery clients table';

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` int(1) NOT NULL,
  `name` varchar(50) NOT NULL,
  `info` varchar(250) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`id`, `name`, `info`, `crtime`, `isdeleted`, `tenant`, `branch`) VALUES
(8, 'IT', 'Information technology \r\n', '2026-01-28 11:58:58', 0, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `drugs`
--

CREATE TABLE `drugs` (
  `id` int(11) NOT NULL,
  `name` varchar(40) NOT NULL,
  `purpose` varchar(200) DEFAULT NULL,
  `effectivematerial` varchar(200) DEFAULT NULL,
  `sideeffects` text DEFAULT NULL,
  `info` varchar(250) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `user` int(11) NOT NULL DEFAULT 1,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `emplog`
--

CREATE TABLE `emplog` (
  `id` int(1) NOT NULL,
  `employee` int(1) NOT NULL,
  `date` date NOT NULL,
  `chkin` time DEFAULT NULL,
  `chkout` time DEFAULT NULL,
  `addin` time DEFAULT NULL,
  `addout` time DEFAULT NULL,
  `latecost` double DEFAULT NULL,
  `earlcost` double DEFAULT NULL,
  `absent` int(11) DEFAULT NULL,
  `holiday` int(11) DEFAULT NULL,
  `deducation` double DEFAULT NULL,
  `additional` double DEFAULT NULL,
  `user` int(11) NOT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `employees`
--

CREATE TABLE `employees` (
  `id` int(11) NOT NULL,
  `basma_id` int(11) DEFAULT NULL,
  `basma_name` varchar(50) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `info` varchar(250) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `imgs` varchar(250) DEFAULT NULL,
  `email` varchar(50) DEFAULT NULL,
  `number` varchar(13) DEFAULT NULL,
  `active` int(1) NOT NULL DEFAULT 1,
  `dateofbirth` date DEFAULT NULL,
  `gender` varchar(10) DEFAULT NULL,
  `country` int(1) DEFAULT NULL,
  `address` varchar(250) DEFAULT NULL,
  `address2` varchar(250) DEFAULT NULL,
  `town` int(1) DEFAULT NULL,
  `jop` int(1) DEFAULT NULL,
  `department` int(1) DEFAULT NULL,
  `joptybe` int(1) DEFAULT NULL,
  `joplevel` int(1) DEFAULT NULL,
  `dateofhire` date DEFAULT NULL,
  `dateofend` date DEFAULT NULL,
  `shift` int(1) DEFAULT NULL,
  `vacancy` int(1) DEFAULT NULL,
  `holiday` int(1) DEFAULT NULL,
  `salary` decimal(11,2) DEFAULT NULL,
  `password` varchar(250) DEFAULT NULL,
  `education` varchar(100) DEFAULT NULL,
  `skills` varchar(200) DEFAULT NULL,
  `hour_extra` decimal(10,2) NOT NULL DEFAULT 0.00,
  `day_extra` decimal(10,2) NOT NULL DEFAULT 0.00,
  `ent_tybe` int(11) NOT NULL DEFAULT 1,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `employees`
--

INSERT INTO `employees` (`id`, `basma_id`, `basma_name`, `name`, `info`, `crtime`, `imgs`, `email`, `number`, `active`, `dateofbirth`, `gender`, `country`, `address`, `address2`, `town`, `jop`, `department`, `joptybe`, `joplevel`, `dateofhire`, `dateofend`, `shift`, `vacancy`, `holiday`, `salary`, `password`, `education`, `skills`, `hour_extra`, `day_extra`, `ent_tybe`, `isdeleted`, `tenant`, `branch`) VALUES
(138, 1, 'nour', 'نور ابراهيم', 'dd', '2026-01-28 12:51:44', NULL, 'nour.ibrahim.dev@gmail.com', '0101111111111', 1, '1997-09-28', '0', NULL, '', '', 4, 10, 8, 0, 6, '2026-01-15', '2026-03-31', 12, NULL, NULL, 7000.00, '1234', NULL, NULL, 1.50, 1.50, 1, 0, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `employee_operations`
--

CREATE TABLE `employee_operations` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `operation_id` int(11) NOT NULL,
  `status` varchar(50) DEFAULT 'assigned',
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `emp_allowences`
--

CREATE TABLE `emp_allowences` (
  `id` int(11) NOT NULL,
  `empid` int(1) NOT NULL,
  `allowid` int(1) NOT NULL,
  `value` double NOT NULL,
  `info` int(1) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `emp_kbis`
--

CREATE TABLE `emp_kbis` (
  `id` int(11) NOT NULL,
  `emp_id` int(1) NOT NULL,
  `kbi_id` int(1) DEFAULT NULL,
  `kbi_weight` decimal(10,2) DEFAULT NULL,
  `kbi_rate` decimal(10,2) DEFAULT NULL,
  `kbi_sum` decimal(10,2) DEFAULT NULL,
  `user` int(11) DEFAULT 1,
  `crtime` datetime DEFAULT current_timestamp(),
  `mdtime` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `isdeleted` int(1) DEFAULT 0,
  `tenant` int(1) DEFAULT 0,
  `branch` int(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `entitles`
--

CREATE TABLE `entitles` (
  `id` int(11) NOT NULL,
  `tybe` varchar(50) NOT NULL,
  `info` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `extras`
--

CREATE TABLE `extras` (
  `id` int(11) NOT NULL,
  `empid` int(1) NOT NULL,
  `info` varchar(100) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `val` double NOT NULL,
  `tybe` int(1) NOT NULL,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fats`
--

CREATE TABLE `fats` (
  `id` int(11) NOT NULL,
  `fat_id` int(11) NOT NULL,
  `zanka_id` int(11) NOT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fat_details`
--

CREATE TABLE `fat_details` (
  `id` int(11) NOT NULL,
  `pro_tybe` int(11) DEFAULT NULL,
  `det_store` int(11) DEFAULT 1,
  `pro_id` int(11) DEFAULT NULL,
  `item_id` int(11) DEFAULT 0,
  `u_val` decimal(10,3) NOT NULL DEFAULT 1.000,
  `qty_in` double DEFAULT 0,
  `qty_out` double DEFAULT 0,
  `price` double DEFAULT 0,
  `cost_price` double(12,2) DEFAULT NULL,
  `stock_value` double(12,2) DEFAULT NULL,
  `discount` double DEFAULT NULL,
  `plus` double DEFAULT NULL,
  `det_value` double DEFAULT 0,
  `profit` float NOT NULL DEFAULT 0,
  `fatid` int(11) DEFAULT NULL,
  `fat_tybe` int(11) NOT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers `fat_details`
--
DELIMITER $$
CREATE TRIGGER `update_after_update` AFTER UPDATE ON `fat_details` FOR EACH ROW BEGIN
    UPDATE myitems
    SET itmqty = (
        SELECT COALESCE(SUM(qty_in), 0) - COALESCE(SUM(qty_out), 0)
        FROM fat_details
        WHERE item_id = NEW.item_id AND isdeleted = 0 
    )
    WHERE id = NEW.item_id AND isdeleted = 0 ;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `update_balance_trigger` AFTER INSERT ON `fat_details` FOR EACH ROW BEGIN
    UPDATE myitems
    SET itmqty = (
        SELECT COALESCE(SUM(qty_in), 0)  - COALESCE(SUM(qty_out), 0)
        FROM fat_details
        WHERE item_id = NEW.item_id  AND isdeleted = 0
    )
    WHERE id = NEW.item_id ;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `fat_tybes`
--

CREATE TABLE `fat_tybes` (
  `id` int(11) NOT NULL,
  `fname` varchar(200) NOT NULL,
  `info` varchar(200) DEFAULT NULL,
  `crttime` timestamp NOT NULL DEFAULT current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `fat_tybes`
--

INSERT INTO `fat_tybes` (`id`, `fname`, `info`, `crttime`, `isdeleted`, `tenant`, `branch`) VALUES
(1, 'فاتورة مبيعات', NULL, '2024-01-29 16:39:27', 0, 0, 0),
(2, 'فاتورة مشنريات', NULL, '2024-01-29 16:41:22', 0, 0, 0),
(3, 'فاتورة مردود مبيعات', NULL, '2024-03-06 15:25:41', 0, 0, 0),
(4, 'فاتورة مردود مشتريات', NULL, '2024-03-06 15:26:30', 0, 0, 0),
(5, 'اذن تسليم بضاعه', NULL, '2024-03-06 15:26:30', 0, 0, 0),
(6, 'اذن استلام بضاعه', NULL, '2024-03-06 15:26:57', 0, 0, 0),
(7, 'اذن تسليم بضاعه', NULL, '2024-03-06 15:26:57', 0, 0, 0),
(8, 'اذن حجز', NULL, '2024-03-06 15:29:32', 0, 0, 0),
(9, 'امر بيع', NULL, '2024-03-06 15:29:32', 0, 0, 0),
(10, 'امر شراء', NULL, '2024-03-06 15:29:32', 0, 0, 0),
(11, 'فاتورة تصنيع حر', NULL, '2024-03-06 15:29:32', 0, 0, 0),
(12, 'تصنيع نموذجي', NULL, '2024-03-06 15:29:32', 0, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `fptybes`
--

CREATE TABLE `fptybes` (
  `id` int(1) NOT NULL,
  `name` varchar(50) NOT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `fptybes`
--

INSERT INTO `fptybes` (`id`, `name`, `crtime`, `isdeleted`, `tenant`, `branch`) VALUES
(1, 'حضور', '2023-07-31 22:57:14', NULL, 0, 0),
(2, 'انصراف', '2023-07-31 22:57:14', NULL, 0, 0),
(3, 'حضور اضافي', '2023-07-31 22:57:42', NULL, 0, 0),
(4, 'انصراف اضافي', '2023-07-31 22:58:34', NULL, 0, 0),
(5, 'invalid', '2023-08-10 04:45:50', NULL, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `hiringcontracts`
--

CREATE TABLE `hiringcontracts` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `employee` int(11) NOT NULL,
  `jop` int(11) NOT NULL,
  `jopdescription` varchar(250) DEFAULT NULL,
  `joprule1` text DEFAULT NULL,
  `joprule2` text DEFAULT NULL,
  `joprule3` text DEFAULT NULL,
  `joprule4` text DEFAULT NULL,
  `workhours` int(11) DEFAULT NULL,
  `inorderhours` int(11) DEFAULT NULL,
  `workdaysoff` int(11) DEFAULT NULL,
  `salary` int(11) DEFAULT NULL,
  `salaryraise` int(11) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `user` int(11) NOT NULL,
  `info` varchar(250) DEFAULT NULL,
  `startcontract` date DEFAULT NULL,
  `endcontract` date DEFAULT NULL,
  `type` int(11) NOT NULL,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `holidays`
--

CREATE TABLE `holidays` (
  `id` int(11) NOT NULL,
  `name` varchar(10) NOT NULL,
  `info` text DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `hr_operations`
--

CREATE TABLE `hr_operations` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `hr_operation_steps`
--

CREATE TABLE `hr_operation_steps` (
  `id` int(11) NOT NULL,
  `operation_id` int(11) NOT NULL,
  `description` text NOT NULL,
  `step_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `imgs`
--

CREATE TABLE `imgs` (
  `id` int(11) NOT NULL,
  `iname` text NOT NULL,
  `cname` int(11) DEFAULT NULL,
  `itemid` int(11) NOT NULL,
  `size` int(11) NOT NULL,
  `clprofile` int(11) DEFAULT NULL,
  `img_date` date DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `imporfplog`
--

CREATE TABLE `imporfplog` (
  `id` int(1) DEFAULT NULL,
  `basma_id` int(11) NOT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `item_group`
--

CREATE TABLE `item_group` (
  `id` int(11) NOT NULL,
  `gname` varchar(100) NOT NULL,
  `info` varchar(200) DEFAULT NULL,
  `parent` int(1) DEFAULT 0,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `user` int(1) NOT NULL DEFAULT 0,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `item_group2`
--

CREATE TABLE `item_group2` (
  `id` int(11) NOT NULL,
  `gname` varchar(100) NOT NULL,
  `info` varchar(100) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `user` int(11) NOT NULL DEFAULT 0,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `item_group3`
--

CREATE TABLE `item_group3` (
  `id` int(11) NOT NULL,
  `gname` varchar(100) NOT NULL,
  `info` varchar(200) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `user` int(1) NOT NULL DEFAULT 0,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `item_units`
--

CREATE TABLE `item_units` (
  `id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `unit_id` int(11) NOT NULL,
  `u_val` decimal(10,3) NOT NULL,
  `def_sale` int(11) NOT NULL DEFAULT 0,
  `def_buy` int(11) NOT NULL DEFAULT 0,
  `def_stock` int(11) NOT NULL DEFAULT 0,
  `cost_price` int(11) NOT NULL,
  `price1` decimal(10,3) NOT NULL DEFAULT 0.000,
  `price2` decimal(10,3) NOT NULL DEFAULT 0.000,
  `price3` decimal(10,3) NOT NULL DEFAULT 0.000,
  `price4` decimal(10,3) DEFAULT 0.000,
  `unit_barcode` varchar(20) NOT NULL,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `joplevels`
--

CREATE TABLE `joplevels` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `info` varchar(250) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `joplevels`
--

INSERT INTO `joplevels` (`id`, `name`, `info`, `crtime`, `isdeleted`, `tenant`, `branch`) VALUES
(2, 'junior', '', '2026-01-28 12:02:31', 0, 0, 0),
(3, 'fresh', '', '2026-01-28 12:02:49', 0, 0, 0),
(4, 'fresh', 'س', '2026-01-28 12:34:11', 0, 0, 0),
(5, 'mid level', '', '2026-01-28 12:34:35', 0, 0, 0),
(6, 'senior', '', '2026-01-28 12:34:43', 0, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `joprules`
--

CREATE TABLE `joprules` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `info` varchar(250) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `jops`
--

CREATE TABLE `jops` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `info` varchar(250) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `joptybes`
--

CREATE TABLE `joptybes` (
  `id` int(11) NOT NULL,
  `name` varchar(20) NOT NULL,
  `info` text DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `journal_entries`
--

CREATE TABLE `journal_entries` (
  `id` int(1) NOT NULL,
  `journal_id` int(1) NOT NULL,
  `account_id` int(1) NOT NULL,
  `debit` int(11) NOT NULL DEFAULT 0,
  `credit` int(11) NOT NULL DEFAULT 0,
  `tybe` int(1) NOT NULL,
  `info` varchar(150) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `op2` int(11) DEFAULT 0,
  `op_id` int(11) DEFAULT 0,
  `isdeleted` tinyint(1) DEFAULT 0,
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers `journal_entries`
--
DELIMITER $$
CREATE TRIGGER `balance_after_delete` AFTER DELETE ON `journal_entries` FOR EACH ROW BEGIN
    UPDATE acc_head
    SET balance = (
        SELECT COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0)
        FROM journal_entries
        WHERE account_id = OLD.account_id AND isdeleted = 0
    )
    WHERE id = OLD.account_id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `balance_after_insert` BEFORE INSERT ON `journal_entries` FOR EACH ROW BEGIN
    UPDATE acc_head
    SET balance = (
        SELECT SUM(debit) - SUM(credit) 
        FROM journal_entries 
        WHERE account_id = NEW.account_id AND isdeleted != 1
    )
    WHERE id = NEW.account_id ;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `balance_after_update` AFTER UPDATE ON `journal_entries` FOR EACH ROW BEGIN
    UPDATE acc_head
    SET balance = (
        SELECT COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0)
        FROM journal_entries
        WHERE account_id = NEW.account_id AND isdeleted = 0
    )
    WHERE id = NEW.account_id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `update_all_ins` AFTER INSERT ON `journal_entries` FOR EACH ROW BEGIN
    DECLARE debit_sum DECIMAL(18,2);
    DECLARE credit_sum DECIMAL(18,2);

    -- Calculate sum of debit and credit for the new entry
    SELECT COALESCE(SUM(debit), 0), COALESCE(SUM(credit), 0)
    INTO debit_sum, credit_sum
    FROM journal_entries
    WHERE account_id = NEW.account_id;

    -- Update balance in acc_head table
    UPDATE acc_head
    SET balance = balance + NEW.debit - NEW.credit
    WHERE id = NEW.account_id;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `journal_heads`
--

CREATE TABLE `journal_heads` (
  `id` int(11) NOT NULL,
  `journal_id` int(11) NOT NULL,
  `total` double NOT NULL,
  `jdate` date NOT NULL DEFAULT current_timestamp(),
  `op_id` int(11) DEFAULT NULL,
  `pro_tybe` int(11) DEFAULT NULL,
  `details` varchar(250) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `op2` int(11) DEFAULT 0,
  `isdeleted` tinyint(1) DEFAULT 0,
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `user` int(1) DEFAULT NULL,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `journal_tybes`
--

CREATE TABLE `journal_tybes` (
  `id` int(11) NOT NULL,
  `journal_id` int(11) DEFAULT NULL,
  `jname` varchar(222) DEFAULT NULL,
  `jtext` varchar(222) DEFAULT NULL,
  `info` varchar(222) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `journal_tybes`
--

INSERT INTO `journal_tybes` (`id`, `journal_id`, `jname`, `jtext`, `info`, `crtime`, `mdtime`, `isdeleted`, `tenant`, `branch`) VALUES
(1, 1, 'purchases', 'يومية المقبوضات', NULL, '2024-03-14 00:34:38', '2024-03-14 00:34:38', 0, 0, 0),
(2, 2, 'sales', 'يومية المدفوعات', NULL, '2024-03-14 00:34:38', '2024-03-14 00:34:38', 0, 0, 0),
(3, 3, 'Payments', 'المبيعات', NULL, '2024-03-14 00:34:38', '2024-03-14 00:34:38', 0, 0, 0),
(4, 4, 'receipts', 'يومية المشتريات', NULL, '2024-03-14 00:34:38', '2024-03-14 00:34:38', 0, 0, 0),
(5, 5, 'Accrueds', 'ايراد مستحق', NULL, '2024-03-14 00:34:38', '2024-03-14 00:34:38', 0, 0, 0),
(6, 6, 'Accrueds', 'خصم مكتسب', NULL, '2024-03-14 00:34:38', '2024-03-14 00:34:38', 0, 0, 0),
(7, 7, 'Accrueds', 'خصم مسموح به', NULL, '2024-03-14 00:34:38', '2024-03-14 00:34:38', 0, 0, 0),
(8, 8, 'journal', 'القيود اليومية', NULL, '2024-03-14 00:34:38', '2024-03-14 00:34:38', 0, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `karta`
--

CREATE TABLE `karta` (
  `id` int(11) NOT NULL,
  `kname` varchar(200) NOT NULL,
  `info` varchar(200) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NULL DEFAULT current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `kbis`
--

CREATE TABLE `kbis` (
  `id` int(11) NOT NULL,
  `kname` varchar(100) NOT NULL,
  `info` varchar(100) DEFAULT NULL,
  `user` int(11) DEFAULT 1,
  `isdeleted` tinyint(1) DEFAULT 0,
  `crtime` datetime DEFAULT current_timestamp(),
  `mdtime` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `ktybe` varchar(100) DEFAULT NULL,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `kbis`
--

INSERT INTO `kbis` (`id`, `kname`, `info`, `user`, `isdeleted`, `crtime`, `mdtime`, `ktybe`, `tenant`, `branch`) VALUES
(1, 'معدل الانجاز', 'المهمات المكتملة/ المهمات الموكلة', 1, 0, '2024-07-24 20:10:58', '2024-07-24 20:25:02', 'الانتاجية', 0, 0),
(2, 'معدل العمل الفعلي', 'وقت العمل الفعلي/وقت العمل', 1, 0, '2024-07-24 20:24:47', '2024-07-24 20:24:47', 'الانتاجية', 0, 0),
(3, 'معدل الاخطاء', '100% بدون اخطاء  ||    0% اخطاء اكثر من المسموح', 1, 0, '2024-07-24 20:26:59', '2024-07-24 20:26:59', '', 0, 0),
(4, 'معدل جودة المخرجات', 'يتم التقييم من ادارة الجودة', 1, 0, '2024-07-24 20:27:55', '2024-07-24 20:27:55', 'الجودة', 0, 0),
(5, 'معدل الحضور', 'الحضور بالساعات / الساعات المقررة', 1, 0, '2024-07-24 20:29:18', '2024-07-24 20:29:18', 'الالتزام', 0, 0),
(6, 'معدل التطور', 'احساب المهارات المضافة شهريا', 1, 0, '2024-07-24 20:30:04', '2024-07-24 20:30:04', 'التطوير', 0, 0),
(7, 'تقييم الزملاء', 'يتم من خلال استطلاعات\r\n', 1, 0, '2024-07-24 20:32:32', '2024-07-24 20:32:32', 'العمل الجماعي', 0, 0),
(8, 'معدل المشاركة في الاجتماعات', 'المشاركة / الاجتماعات', 1, 0, '2024-07-24 20:35:16', '2024-07-24 20:35:16', 'العمل الجماعي', 0, 0),
(9, 'تقييم القائد', 'تقييم الفريق للقائد', 1, 0, '2024-07-24 20:37:33', '2024-07-24 20:37:33', 'المديرين', 0, 0),
(10, 'نسبه الحقيق للفريق', 'نسبة تحقيق الاهداف .. لمتخذي القرار', 1, 0, '2024-07-24 20:38:11', '2024-07-24 20:38:11', 'المديرين', 0, 0),
(11, 'وقت الاستجابة', 'يتم عن طريق التغذية العكسية', 1, 0, '2024-07-24 20:43:24', '2024-07-24 20:43:24', 'خدمة العملاء', 0, 0),
(12, 'معدل حل المشكلات', 'المشكلات المحلولة / عدد المشكلات', 1, 0, '2024-07-24 20:44:03', '2024-07-24 20:44:03', 'خدمة العملاء', 0, 0),
(13, 'نسبة رضا العميل', 'العملاء الراضين / عدد عملاء الاستطلاع', 1, 0, '2024-07-24 20:44:36', '2024-07-24 20:44:36', 'خدمة العملاء', 0, 0),
(14, 'معدل تقليل التكاليف', 'تقييم المشرف', 1, 0, '2024-07-24 20:46:07', '2024-07-24 20:46:07', 'الكفاءة التشغيلية', 0, 0),
(15, 'استغلال الموارد', 'تقييم المديرين', 1, 0, '2024-07-24 20:49:12', '2024-07-24 20:49:12', 'الكفاءة التشغيلية', 0, 0),
(16, 'عدد الافكار الجديدة المنفذه', 'عدد الافكار المنفذه / عدد الافكار الجديدة المقدمة', 1, 0, '2024-07-24 21:03:59', '2024-07-24 21:03:59', 'الابتكار', 0, 0),
(17, 'اه', '', 1, 0, '2026-02-03 14:50:44', '2026-02-03 14:50:44', 'بدوي', 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `myinstallments`
--

CREATE TABLE `myinstallments` (
  `id` int(11) NOT NULL,
  `cl_id` int(11) NOT NULL,
  `rent_id` int(11) NOT NULL,
  `contract` int(11) DEFAULT 0,
  `ins_value` double NOT NULL DEFAULT 0,
  `ins_date` date NOT NULL,
  `ins_case` int(11) NOT NULL,
  `ins_paid` double NOT NULL,
  `voucher` int(11) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `info` varchar(250) DEFAULT NULL,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `myitems`
--

CREATE TABLE `myitems` (
  `id` int(11) NOT NULL,
  `iname` varchar(200) NOT NULL,
  `name2` varchar(200) DEFAULT NULL,
  `code` int(11) DEFAULT NULL,
  `salesqty` double DEFAULT 1,
  `barcode` varchar(25) DEFAULT NULL,
  `itmqty` double NOT NULL DEFAULT 0,
  `info` varchar(250) DEFAULT NULL,
  `market_price` float NOT NULL DEFAULT 0,
  `cost_price` float NOT NULL DEFAULT 0,
  `last_price` int(11) NOT NULL DEFAULT 0,
  `price1` float NOT NULL DEFAULT 0,
  `price2` float NOT NULL DEFAULT 0,
  `price3` float NOT NULL,
  `group1` int(11) NOT NULL DEFAULT 0,
  `group2` int(11) NOT NULL DEFAULT 0,
  `group3` int(11) NOT NULL DEFAULT 0,
  `isdeleted` tinyint(1) DEFAULT 0,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `user` int(11) NOT NULL DEFAULT 1,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0,
  `manual_price_edit` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `myoper_det`
--

CREATE TABLE `myoper_det` (
  `oper_det_id` int(11) NOT NULL,
  `int_oper_det_date` int(11) DEFAULT NULL,
  `oper_head_id` int(11) DEFAULT NULL,
  `comp_id` int(11) DEFAULT NULL,
  `debit` decimal(26,4) DEFAULT NULL,
  `credit` decimal(26,4) DEFAULT NULL,
  `eng_debit` decimal(26,4) DEFAULT NULL,
  `eng_credit` decimal(26,4) DEFAULT NULL,
  `model_val` decimal(26,4) DEFAULT NULL,
  `def_val` decimal(26,4) DEFAULT NULL,
  `acc_id` int(11) DEFAULT NULL,
  `stor_id` int(11) DEFAULT NULL,
  `group_id` int(11) DEFAULT NULL,
  `man_id` int(11) DEFAULT NULL,
  `cost_center_id` int(11) DEFAULT NULL,
  `has_costed_link` tinyint(4) DEFAULT NULL,
  `is_not_active` tinyint(4) DEFAULT NULL,
  `notes` varchar(50) DEFAULT NULL,
  `mst_no` varchar(20) DEFAULT NULL,
  `mst_date` varchar(12) DEFAULT NULL,
  `balance_befor` decimal(26,4) DEFAULT NULL,
  `balance_after` decimal(26,4) DEFAULT NULL,
  `det_Currency_id` int(11) DEFAULT NULL,
  `det_Currency_unit_convert` decimal(12,6) DEFAULT NULL,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `myoptions`
--

CREATE TABLE `myoptions` (
  `id` int(11) NOT NULL,
  `oname` varchar(30) NOT NULL,
  `info` varchar(250) DEFAULT NULL,
  `def_value` int(11) NOT NULL DEFAULT 0,
  `cur_value` int(11) NOT NULL DEFAULT 0,
  `op_tybe` int(11) NOT NULL DEFAULT 0,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `myoptions`
--

INSERT INTO `myoptions` (`id`, `oname`, `info`, `def_value`, `cur_value`, `op_tybe`, `crtime`, `mdtime`, `isdeleted`, `tenant`, `branch`) VALUES
(1, 'def_cl', 'العميل الافتراضي', 24, 24, 1, '2024-02-19 20:10:57', '2024-02-19 20:21:14', 0, 0, 0),
(2, 'def_prod', 'المورد الافتراضي', 36, 36, 1, '2024-02-19 20:10:57', '2024-02-19 20:21:17', 0, 0, 0),
(3, 'def_emp', 'الموظف الافتراضي', 41, 42, 1, '2024-02-19 20:10:57', '2024-02-20 01:09:18', 0, 0, 0),
(4, 'def_store', 'المخزن الافتراضي', 27, 27, 1, '2024-02-19 20:10:57', '2024-02-19 20:21:23', 0, 0, 0),
(5, 'def_fund', 'الصندوق الافتراضي', 21, 21, 1, '2024-02-19 20:10:57', '2024-02-19 20:21:26', 0, 0, 0),
(6, 'def_bank', 'البتك الافتراضي', 39, 39, 1, '2024-02-19 20:10:57', '2024-02-19 20:21:31', 0, 0, 0),
(7, 'def_store', 'المخزن الافتراضي', 27, 27, 1, '2024-02-19 20:10:57', '2024-03-09 21:48:39', 0, 0, 0),
(8, 'def_disc_acc1', 'حساب الخصم المكتسب الافتراضي', 97, 97, 1, '2024-02-19 20:10:57', '2024-03-09 21:48:39', 0, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `mypatterns`
--

CREATE TABLE `mypatterns` (
  `id` int(11) NOT NULL,
  `pname` varchar(100) NOT NULL,
  `ptext` varchar(100) NOT NULL,
  `is_def` int(11) NOT NULL DEFAULT 0,
  `is_basic` int(11) NOT NULL DEFAULT 0,
  `ptybe` int(11) NOT NULL DEFAULT 4,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `info` varchar(100) DEFAULT NULL,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `mypowers`
--

CREATE TABLE `mypowers` (
  `power_id` int(11) NOT NULL,
  `section_type_no` int(11) DEFAULT NULL,
  `power_name` varchar(100) DEFAULT NULL,
  `eng_power_name` varchar(100) DEFAULT NULL,
  `is_hide_in_casher` int(11) DEFAULT NULL,
  `level_no` tinyint(4) DEFAULT NULL,
  `is_for_view_only` tinyint(4) DEFAULT NULL,
  `power_code` varchar(100) DEFAULT NULL,
  `power_class` tinyint(4) DEFAULT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `col_index` int(11) DEFAULT NULL,
  `stoped` tinyint(4) DEFAULT NULL,
  `tmp_state_no` tinyint(4) DEFAULT NULL,
  `power_type` tinyint(4) DEFAULT NULL,
  `menu_type` tinyint(4) DEFAULT NULL,
  `def_state` varchar(20) DEFAULT NULL,
  `user_1` varchar(20) DEFAULT NULL,
  `kind` varchar(10) DEFAULT NULL,
  `is_on_my_thread` tinyint(4) DEFAULT NULL,
  `is_calling_from_main` tinyint(4) DEFAULT NULL,
  `calling_from` varchar(10) DEFAULT NULL,
  `edit_mode` tinyint(4) DEFAULT NULL,
  `frist_shown_id` varchar(10) DEFAULT NULL,
  `is_casher_from` tinyint(4) DEFAULT NULL,
  `is_op_paper` tinyint(4) DEFAULT NULL,
  `is_hiddin` tinyint(4) DEFAULT NULL,
  `prog_id` tinyint(4) DEFAULT NULL,
  `is_pure_kitchen` tinyint(4) DEFAULT NULL,
  `is_for_api` tinyint(4) DEFAULT NULL,
  `t_stamp` timestamp NULL DEFAULT NULL,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `myrents`
--

CREATE TABLE `myrents` (
  `id` int(11) NOT NULL,
  `cl_id` int(11) NOT NULL,
  `rent_id` int(11) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `idintity` varchar(50) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `pay_tybe` int(11) NOT NULL DEFAULT 1,
  `r_value` double NOT NULL DEFAULT 0,
  `bnd1` varchar(250) DEFAULT NULL,
  `bnd2` varchar(250) DEFAULT NULL,
  `bnd3` varchar(250) DEFAULT NULL,
  `bnd4` varchar(250) DEFAULT NULL,
  `info` varchar(250) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `myrents`
--

INSERT INTO `myrents` (`id`, `cl_id`, `rent_id`, `phone`, `idintity`, `start_date`, `end_date`, `pay_tybe`, `r_value`, `bnd1`, `bnd2`, `bnd3`, `bnd4`, `info`, `crtime`, `mdtime`, `isdeleted`, `tenant`, `branch`) VALUES
(1, 148, 257, '1223345665466', '456565656576575', '2025-05-03', '2027-10-03', 1, 700, 'test item 1 ', '', '', '', NULL, '2026-02-03 12:02:56', '2026-02-03 12:06:51', 0, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `myunits`
--

CREATE TABLE `myunits` (
  `id` int(11) NOT NULL,
  `uname` varchar(60) NOT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `myvouchers`
--

CREATE TABLE `myvouchers` (
  `id` int(11) NOT NULL,
  `vdate` date NOT NULL DEFAULT current_timestamp(),
  `tybe` int(1) NOT NULL,
  `val` double DEFAULT NULL,
  `account` int(1) NOT NULL,
  `fund_account` int(1) NOT NULL,
  `voucher_id` varchar(15) NOT NULL,
  `serial_number` varchar(20) DEFAULT NULL,
  `cost_center` int(1) DEFAULT NULL,
  `info` varchar(200) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `user` int(11) NOT NULL DEFAULT 1,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `my_news`
--

CREATE TABLE `my_news` (
  `id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `img` varchar(250) DEFAULT NULL,
  `tags` varchar(250) DEFAULT NULL,
  `content` text NOT NULL,
  `user` int(11) NOT NULL DEFAULT 1,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notes`
--

CREATE TABLE `notes` (
  `id` int(11) NOT NULL,
  `n1` varchar(100) DEFAULT '0',
  `n2` varchar(100) DEFAULT '0',
  `n3` varchar(100) DEFAULT '0',
  `n4` varchar(100) DEFAULT '0',
  `n5` varchar(100) DEFAULT '0',
  `n6` varchar(100) DEFAULT '0',
  `n7` varchar(100) DEFAULT '0',
  `n8` varchar(100) DEFAULT '0',
  `n9` varchar(100) DEFAULT '0',
  `n10` varchar(100) DEFAULT '0',
  `n11` varchar(100) DEFAULT '0',
  `n12` varchar(100) DEFAULT '0',
  `n13` varchar(100) DEFAULT '0',
  `n14` varchar(100) DEFAULT '0',
  `n15` varchar(100) DEFAULT '0',
  `n16` varchar(100) DEFAULT '0',
  `n17` varchar(100) DEFAULT '0',
  `n18` varchar(100) DEFAULT '0',
  `n19` varchar(100) DEFAULT '0',
  `crtime` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `oppatterns`
--

CREATE TABLE `oppatterns` (
  `id` int(11) NOT NULL,
  `pame` varchar(100) DEFAULT NULL,
  `ptext` varchar(100) DEFAULT NULL,
  `def_width` int(11) NOT NULL DEFAULT 50,
  `cur_width` int(11) NOT NULL DEFAULT 50,
  `shown` int(11) NOT NULL DEFAULT 1,
  `is_edit` int(11) NOT NULL DEFAULT 1,
  `is_print` int(11) NOT NULL DEFAULT 1,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` int(11) NOT NULL,
  `tybe` int(11) DEFAULT NULL,
  `employee` int(11) DEFAULT NULL,
  `status` int(11) NOT NULL,
  `applyingdate` date NOT NULL,
  `curdate` date NOT NULL,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `order_status`
--

CREATE TABLE `order_status` (
  `id` int(11) NOT NULL,
  `name` varchar(50) DEFAULT NULL,
  `user` int(1) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `order_types`
--

CREATE TABLE `order_types` (
  `id` int(11) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `info` varchar(100) DEFAULT NULL,
  `user` int(1) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ot_head`
--

CREATE TABLE `ot_head` (
  `id` int(11) NOT NULL,
  `pro_id` int(11) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `table_id` int(11) DEFAULT NULL COMMENT 'رقم الطاولة',
  `order_type` enum('dine_in','takeaway','delivery','table') DEFAULT 'takeaway',
  `pro_tybe` int(1) DEFAULT NULL,
  `is_stock` int(11) DEFAULT NULL,
  `is_finance` int(11) DEFAULT NULL,
  `is_manager` int(11) DEFAULT NULL,
  `is_journal` int(11) DEFAULT NULL,
  `journal_tybe` int(11) DEFAULT NULL,
  `info` varchar(200) DEFAULT NULL,
  `start_time` time DEFAULT current_timestamp(),
  `end_time` time DEFAULT current_timestamp(),
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `pro_date` date DEFAULT NULL,
  `accural_date` date DEFAULT NULL,
  `pro_pattren` int(11) DEFAULT NULL,
  `pro_num` varchar(50) DEFAULT NULL,
  `receipt_number` varchar(50) DEFAULT NULL,
  `pro_serial` varchar(50) DEFAULT NULL,
  `tax_num` varchar(50) DEFAULT NULL,
  `price_list` int(11) DEFAULT NULL,
  `store_id` int(11) DEFAULT NULL,
  `emp_id` int(11) DEFAULT NULL,
  `emp2_id` int(11) DEFAULT NULL,
  `acc1` int(11) DEFAULT NULL,
  `acc1_before` double DEFAULT NULL,
  `acc1_after` double DEFAULT NULL,
  `acc2` int(11) DEFAULT NULL,
  `acc2_before` double DEFAULT NULL,
  `acc2_after` double DEFAULT NULL,
  `pro_value` double DEFAULT NULL,
  `fat_cost` double DEFAULT NULL,
  `cost_center` int(11) DEFAULT NULL,
  `profit` double DEFAULT NULL,
  `fat_total` double DEFAULT NULL,
  `fat_net` double DEFAULT 0,
  `fat_disc` double DEFAULT NULL,
  `fat_disc_per` double DEFAULT NULL,
  `fat_plus` double DEFAULT NULL,
  `fat_plus_per` double DEFAULT NULL,
  `fat_tax` double DEFAULT NULL,
  `fat_tax_per` double DEFAULT NULL,
  `paid_amount` decimal(15,2) DEFAULT 0.00,
  `remaining_amount` decimal(15,2) DEFAULT 0.00,
  `payment_status` enum('unpaid','partial','paid','refunded') DEFAULT 'unpaid',
  `invoice_status` enum('draft','completed','cancelled') DEFAULT 'completed',
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `acc_fund` int(1) DEFAULT 0,
  `op2` int(11) DEFAULT 0,
  `isdeleted` tinyint(1) DEFAULT 0,
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `user` int(11) NOT NULL DEFAULT 1,
  `waiter_id` int(11) DEFAULT NULL COMMENT 'معرف الويتر',
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0,
  `closed` int(1) DEFAULT 0,
  `order_status` enum('draft','active','completed','cancelled') DEFAULT 'active' COMMENT 'حالة الطلب',
  `payment_method` varchar(20) DEFAULT 'cash' COMMENT 'طريقة الدفع',
  `payment_notes` text DEFAULT NULL COMMENT 'ملاحظات الدفع',
  `payment_date` datetime DEFAULT NULL COMMENT 'تاريخ الدفع',
  `jal_name` varchar(255) DEFAULT NULL,
  `jal_notes` text DEFAULT NULL,
  `jal_amount` decimal(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `paper_types`
--

CREATE TABLE `paper_types` (
  `id` int(1) NOT NULL,
  `pname` varchar(50) NOT NULL,
  `info` varchar(100) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `patt_cols`
--

CREATE TABLE `patt_cols` (
  `id` int(11) NOT NULL,
  `cname` varchar(100) NOT NULL,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permits`
--

CREATE TABLE `permits` (
  `id` int(11) NOT NULL,
  `empid` int(1) NOT NULL,
  `info` varchar(100) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `curdate` date NOT NULL,
  `startdate` date NOT NULL,
  `enddate` date NOT NULL,
  `val` double DEFAULT NULL,
  `statue` int(1) NOT NULL,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `prescdetails`
--

CREATE TABLE `prescdetails` (
  `id` int(11) NOT NULL,
  `prescid` int(11) DEFAULT NULL,
  `drug` int(11) DEFAULT NULL,
  `dose` varchar(200) NOT NULL,
  `info` varchar(200) NOT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `prescs`
--

CREATE TABLE `prescs` (
  `id` int(11) NOT NULL,
  `client` int(11) DEFAULT NULL,
  `visit` int(11) DEFAULT NULL,
  `analayses` varchar(250) DEFAULT NULL,
  `info` varchar(200) NOT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `price_lists`
--

CREATE TABLE `price_lists` (
  `id` int(11) NOT NULL,
  `pname` varchar(100) NOT NULL,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `price_lists`
--

INSERT INTO `price_lists` (`id`, `pname`, `isdeleted`, `tenant`, `branch`) VALUES
(1, 'سعر 1', 0, 0, 0),
(2, 'سعر 2', 0, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `print`
--

CREATE TABLE `print` (
  `id` int(1) NOT NULL,
  `pname` varchar(50) NOT NULL,
  `type` varchar(11) NOT NULL,
  `number` varchar(11) NOT NULL,
  `info` varchar(100) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `process`
--

CREATE TABLE `process` (
  `id` int(11) NOT NULL,
  `type` varchar(255) NOT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `prods`
--

CREATE TABLE `prods` (
  `id` int(1) NOT NULL,
  `pname` varchar(50) NOT NULL,
  `info` varchar(100) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `productions`
--

CREATE TABLE `productions` (
  `id` int(11) NOT NULL,
  `snd_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `emp_name` varchar(100) NOT NULL,
  `qty` double NOT NULL,
  `price` double NOT NULL,
  `value` double NOT NULL,
  `info` varchar(150) DEFAULT NULL,
  `info2` varchar(150) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `user` int(11) NOT NULL DEFAULT 1,
  `isdeleted` int(11) NOT NULL DEFAULT 0,
  `tenant` int(11) NOT NULL DEFAULT 0,
  `branch` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pro_tybes`
--

CREATE TABLE `pro_tybes` (
  `id` int(11) NOT NULL,
  `pname` varchar(200) DEFAULT NULL,
  `ptext` varchar(200) DEFAULT NULL,
  `ptybe` int(11) DEFAULT NULL,
  `info` varchar(200) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pro_tybes`
--

INSERT INTO `pro_tybes` (`id`, `pname`, `ptext`, `ptybe`, `info`, `crtime`, `mdtime`, `isdeleted`, `tenant`, `branch`) VALUES
(1, 'سند قبض', NULL, 1, NULL, '2024-03-14 02:01:35', '2024-03-14 02:01:35', 0, 0, 0),
(2, 'سند دفع', NULL, 2, NULL, '2024-03-14 02:01:35', '2024-03-14 02:02:22', 0, 0, 0),
(3, 'فاتورة مبيعات', NULL, 3, NULL, '2024-03-14 02:01:58', '2024-03-14 02:02:26', 0, 0, 0),
(4, 'فاتورة مشتريات', NULL, 4, NULL, '2024-03-14 02:01:58', '2024-03-14 02:02:28', 0, 0, 0),
(5, 'استحقاق قسط', NULL, 5, NULL, '2024-03-17 03:53:16', '2024-03-17 03:53:27', 0, 0, 0),
(6, 'خصم مكتسب', NULL, 6, NULL, '2024-03-17 03:53:16', '2024-03-17 03:53:27', 0, 0, 0),
(7, 'خصم مسموح به', NULL, 7, NULL, '2024-03-17 03:53:16', '2024-03-17 03:53:27', 0, 0, 0),
(8, 'قيد يومية', NULL, 8, NULL, '2024-05-14 11:06:41', '2024-05-14 11:06:54', 0, 0, 0),
(9, 'فاتورة كاشير', NULL, 9, NULL, '2024-05-14 11:06:41', '2024-07-19 17:25:29', 0, 0, 0),
(10, 'فاتورة مردود مبيعات', NULL, 10, NULL, '2024-05-14 11:06:41', '2024-11-21 15:25:06', 0, 0, 0),
(11, 'فاتورة مردود مشتريات', NULL, 11, NULL, '2024-05-14 11:06:41', '2024-11-21 15:25:10', 0, 0, 0),
(12, 'أمر شراء', NULL, 12, NULL, '2024-05-14 11:06:41', '2024-11-21 15:25:12', 0, 0, 0),
(13, 'أمر بيع', NULL, 13, NULL, '2024-05-14 11:06:41', '2024-11-21 15:25:16', 0, 0, 0),
(14, 'رصيد افتتاحي مخازن', NULL, 14, NULL, '2024-05-14 11:06:41', '2024-11-23 11:40:49', 0, 0, 0),
(15, 'رصيد افتتاحي حسابات', NULL, 15, NULL, '2024-05-14 11:06:41', '2024-11-23 11:40:52', 0, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `pst_activities`
--

CREATE TABLE `pst_activities` (
  `id` int(1) NOT NULL,
  `aname` varchar(111) NOT NULL,
  `info` varchar(111) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `user` int(1) DEFAULT 0,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pst_criminals`
--

CREATE TABLE `pst_criminals` (
  `id` int(1) NOT NULL,
  `cname` varchar(150) NOT NULL,
  `otherdetails` varchar(200) DEFAULT NULL,
  `karta` int(1) DEFAULT NULL,
  `dngrs` int(1) DEFAULT NULL,
  `fesh` int(1) DEFAULT NULL,
  `prtnrs` varchar(150) DEFAULT NULL,
  `crmaddress` varchar(150) DEFAULT NULL,
  `idcardnum` varchar(150) DEFAULT NULL,
  `scar` varchar(150) DEFAULT NULL,
  `mname` varchar(150) DEFAULT NULL,
  `nickname` varchar(150) DEFAULT NULL,
  `tybe` varchar(150) DEFAULT NULL,
  `danger_factor` int(1) NOT NULL DEFAULT 1,
  `info` varchar(150) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `user` int(1) NOT NULL,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pst_crmstyles`
--

CREATE TABLE `pst_crmstyles` (
  `id` int(1) NOT NULL,
  `cname` varchar(150) NOT NULL,
  `info` varchar(150) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `user` int(1) NOT NULL,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pst_gangs`
--

CREATE TABLE `pst_gangs` (
  `id` int(1) NOT NULL,
  `gname` varchar(150) NOT NULL,
  `info` varchar(150) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `user` int(1) NOT NULL,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pst_issues`
--

CREATE TABLE `pst_issues` (
  `id` int(1) NOT NULL,
  `iname` varchar(150) NOT NULL,
  `issue_tybe` int(1) NOT NULL DEFAULT 1,
  `info` varchar(150) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `user` int(1) NOT NULL,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rays`
--

CREATE TABLE `rays` (
  `id` int(11) NOT NULL,
  `client` int(11) NOT NULL,
  `lap` int(11) NOT NULL,
  `name` varchar(250) NOT NULL,
  `comment` varchar(250) DEFAULT NULL,
  `img` varchar(250) DEFAULT NULL,
  `crtime` timestamp NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `info` varchar(250) DEFAULT NULL,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reservations`
--

CREATE TABLE `reservations` (
  `id` int(11) NOT NULL,
  `client` int(11) NOT NULL,
  `diseses` varchar(250) DEFAULT '0',
  `phone` varchar(15) DEFAULT NULL,
  `date` date NOT NULL,
  `time` time NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `duration` double DEFAULT NULL,
  `visittybe` int(11) NOT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `paid` int(11) DEFAULT 0,
  `deserved` int(11) DEFAULT 0,
  `rest` int(11) DEFAULT 0,
  `done` int(11) DEFAULT 0,
  `info` varchar(250) DEFAULT NULL,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `reservations`
--

INSERT INTO `reservations` (`id`, `client`, `diseses`, `phone`, `date`, `time`, `start_time`, `end_time`, `duration`, `visittybe`, `crtime`, `mdtime`, `paid`, `deserved`, `rest`, `done`, `info`, `isdeleted`, `tenant`, `branch`) VALUES
(2, 9, 'برد\r\n', '', '2026-02-03', '10:00:00', '14:13:00', '14:13:00', 0, 3, '2026-02-03 12:07:33', '2026-02-03 12:13:50', 500, 0, 0, 0, '', 0, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `salaries`
--

CREATE TABLE `salaries` (
  `id` int(11) NOT NULL,
  `empid` int(1) NOT NULL,
  `info` varchar(100) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `starttime` time NOT NULL,
  `endtime` time NOT NULL,
  `salary` double DEFAULT 0,
  `extra` double DEFAULT 0,
  `disc` double DEFAULT 0,
  `allow` double DEFAULT 0,
  `dedu` double DEFAULT 0,
  `total` double NOT NULL DEFAULT 0,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `services`
--

CREATE TABLE `services` (
  `id` int(11) NOT NULL,
  `sname` varchar(50) NOT NULL,
  `info` varchar(100) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `session_time`
--

CREATE TABLE `session_time` (
  `id` int(1) NOT NULL,
  `user` int(1) NOT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` int(1) NOT NULL,
  `company_name` varchar(200) DEFAULT NULL,
  `company_add` varchar(200) DEFAULT NULL,
  `company_email` varchar(50) DEFAULT NULL,
  `company_tel` varchar(200) DEFAULT NULL,
  `edit_pass` varchar(50) DEFAULT NULL,
  `lic` varchar(250) DEFAULT NULL,
  `updateline` text DEFAULT NULL,
  `acc_rent` int(11) DEFAULT 0,
  `startdate` date DEFAULT NULL,
  `enddate` date DEFAULT NULL,
  `lang` varchar(20) DEFAULT 'ar',
  `bodycolor` varchar(50) DEFAULT NULL,
  `showhr` int(1) NOT NULL DEFAULT 1,
  `showclinc` int(1) NOT NULL DEFAULT 1,
  `showatt` int(11) NOT NULL DEFAULT 1,
  `showpayroll` int(11) NOT NULL DEFAULT 1,
  `showrent` int(11) NOT NULL DEFAULT 1,
  `showpay` int(11) DEFAULT 1,
  `showtsk` int(11) NOT NULL DEFAULT 1,
  `def_pos_client` int(1) DEFAULT NULL,
  `def_pos_store` int(1) DEFAULT NULL,
  `def_pos_employee` int(1) DEFAULT NULL,
  `def_pos_fund` int(1) DEFAULT NULL,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0,
  `logo` varchar(255) DEFAULT NULL,
  `show_all_tasks` int(1) DEFAULT NULL,
  `pos_type` varchar(20) DEFAULT 'barcode' COMMENT 'نوع نظام POS: barcode أو clothes',
  `pos_has_password` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = POS محمي بباركود، 0 = POS مفتوح'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`id`, `company_name`, `company_add`, `company_email`, `company_tel`, `edit_pass`, `lic`, `updateline`, `acc_rent`, `startdate`, `enddate`, `lang`, `bodycolor`, `showhr`, `showclinc`, `showatt`, `showpayroll`, `showrent`, `showpay`, `showtsk`, `def_pos_client`, `def_pos_store`, `def_pos_employee`, `def_pos_fund`, `isdeleted`, `tenant`, `branch`, `logo`, `show_all_tasks`, `pos_type`, `pos_has_password`) VALUES
(1, 'FOCUS HOUSE', 'سمنود - برج زايد - الدور الخامس', 'abdelhadeeladawy@gmail.com', '010053662038', '125', 'd35c99e7485691ea14f829029dc03e69A67b8d2f92148f52cad46e331936922e8', '', 99, '2024-01-01', '2024-12-31', 'ar', '#f0f0f0', 1, 1, 1, 1, 1, 1, 1, 155, 27, 131, 21, 0, 0, 0, NULL, NULL, 'barcode', 1);

-- --------------------------------------------------------

--
-- Table structure for table `shifts`
--

CREATE TABLE `shifts` (
  `id` int(11) NOT NULL,
  `name` varchar(20) NOT NULL,
  `info` text DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `shiftstart` time DEFAULT NULL,
  `shiftend` time DEFAULT NULL,
  `hours` double(11,2) DEFAULT NULL,
  `instart` time DEFAULT NULL,
  `inend` time DEFAULT NULL,
  `outstart` time DEFAULT NULL,
  `outend` time DEFAULT NULL,
  `latelimit` int(1) DEFAULT NULL,
  `earlylimit` int(11) DEFAULT NULL,
  `workingdays` varchar(20) DEFAULT NULL,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `shw_optns`
--

CREATE TABLE `shw_optns` (
  `id` int(11) NOT NULL,
  `sname` varchar(100) NOT NULL,
  `is_show` int(11) NOT NULL DEFAULT 0,
  `def_width` int(11) NOT NULL DEFAULT 50,
  `cur_width` int(11) NOT NULL DEFAULT 50,
  `info` varchar(150) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `shw_optns`
--

INSERT INTO `shw_optns` (`id`, `sname`, `is_show`, `def_width`, `cur_width`, `info`, `crtime`, `mdtime`, `isdeleted`, `tenant`, `branch`) VALUES
(1, 'op_id', 0, 50, 50, 'معرف العملية', '2024-03-13 17:54:16', '2024-03-13 18:32:33', 0, 0, 0),
(2, 'op_date', 0, 50, 50, 'التاريخ', '2024-03-13 18:32:08', '2024-03-13 18:32:46', 0, 0, 0),
(3, 'op_tybe', 0, 50, 50, 'نوع العمليه', '2024-03-13 18:32:08', '2024-03-13 18:32:54', 0, 0, 0),
(4, 'op_store', 0, 50, 50, 'المستودع', '2024-03-13 18:32:08', '2024-03-13 18:32:08', 0, 0, 0),
(5, 'op_num', 0, 50, 50, 'رقم السند', '2024-03-13 18:32:08', '2024-03-13 18:32:08', 0, 0, 0),
(6, 'acc_num', 0, 50, 50, 'رقم الحساب', '2024-03-13 18:32:08', '2024-03-13 18:32:08', 0, 0, 0),
(7, 'acc_id', 0, 50, 50, 'اسم الحساب', '2024-03-13 18:32:08', '2024-03-13 18:32:08', 0, 0, 0),
(8, 'op_val', 0, 50, 50, 'قيمه العمليه', '2024-03-13 18:32:08', '2024-03-13 18:32:08', 0, 0, 0),
(9, 'op_profit', 0, 50, 50, 'الربح', '2024-03-13 18:32:08', '2024-03-13 18:33:07', 0, 0, 0),
(10, 'emb_id', 0, 50, 50, 'البائع', '2024-03-13 18:32:08', '2024-03-13 18:33:15', 0, 0, 0),
(11, 'usid', 0, 50, 50, 'المستخدم', '2024-03-13 18:32:08', '2024-03-13 18:33:22', 0, 0, 0),
(12, 'fatcost', 0, 50, 50, 'تكلفه المبيعات', '2024-03-13 18:32:08', '2024-03-13 18:33:28', 0, 0, 0),
(13, 'crtime', 0, 50, 50, 'الوقت', '2024-03-13 18:32:08', '2024-03-13 18:32:08', 0, 0, 0),
(14, 'cl_code', 0, 50, 50, 'رقم العميل', '2024-03-13 18:47:10', '2024-03-13 18:47:10', 0, 0, 0),
(15, 'cl_id', 0, 50, 50, 'اسم العميل', '2024-03-13 18:47:10', '2024-03-13 18:47:10', 0, 0, 0),
(16, 'acc_blance', 0, 50, 50, 'الرصيد الحالى-بالعمله المحليه', '2024-03-13 18:47:10', '2024-03-13 18:47:10', 0, 0, 0),
(17, 'acc_cur', 0, 50, 50, 'عمله الحساب', '2024-03-13 18:47:10', '2024-03-13 18:47:10', 0, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `sitting_items`
--

CREATE TABLE `sitting_items` (
  `id` int(11) NOT NULL,
  `iname` varchar(250) NOT NULL,
  `item_value` int(1) NOT NULL DEFAULT 0,
  `item_description` varchar(250) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sitting_items`
--

INSERT INTO `sitting_items` (`id`, `iname`, `item_value`, `item_description`, `crtime`, `mdtime`, `isdeleted`, `tenant`, `branch`) VALUES
(1, 'الموظف يحاسب علي اساس ساعات العمل', 0, NULL, '2023-12-26 22:32:16', '2023-12-26 22:32:16', 0, 0, 0),
(2, 'الموظف يحاسب علي اساس ساعات العمل التقديريه 26 يوم', 0, NULL, '2023-12-26 22:33:03', '2023-12-26 22:33:03', 0, 0, 0),
(3, 'الشهر عباره عن 30 يوم', 0, NULL, '2023-12-26 22:35:34', '2023-12-26 22:35:34', 0, 0, 0),
(4, 'البصمه المفقوده يتم تجاهلها', 0, NULL, '2023-12-26 22:35:34', '2023-12-26 22:35:34', 0, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `skills`
--

CREATE TABLE `skills` (
  `id` int(11) NOT NULL,
  `sname` varchar(200) NOT NULL,
  `icon` varchar(100) DEFAULT NULL,
  `info` varchar(200) DEFAULT NULL,
  `scolor` varchar(100) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `user` int(11) NOT NULL DEFAULT 1,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_logs`
--

CREATE TABLE `system_logs` (
  `id` int(11) NOT NULL,
  `timestamp` datetime NOT NULL,
  `level` varchar(20) NOT NULL,
  `message` text NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(100) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `request_uri` varchar(500) DEFAULT NULL,
  `context` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tables`
--

CREATE TABLE `tables` (
  `id` int(11) NOT NULL,
  `tname` varchar(255) NOT NULL,
  `table_case` int(11) NOT NULL DEFAULT 0,
  `crtime` datetime DEFAULT current_timestamp(),
  `mdtime` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `isdeleted` tinyint(1) NOT NULL DEFAULT 0,
  `branch` varchar(255) DEFAULT NULL,
  `tatnet` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tasks`
--

CREATE TABLE `tasks` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `ch_tybe` int(11) NOT NULL,
  `info` text DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `user` int(1) NOT NULL,
  `tasktybe` int(1) NOT NULL,
  `important` int(1) NOT NULL,
  `urgent` int(1) NOT NULL,
  `emp_comment` varchar(200) DEFAULT NULL,
  `cl_comment` varchar(200) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tasktybes`
--

CREATE TABLE `tasktybes` (
  `id` int(1) NOT NULL,
  `name` varchar(25) NOT NULL,
  `info` text DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tasktybes`
--

INSERT INTO `tasktybes` (`id`, `name`, `info`, `crtime`, `isdeleted`, `tenant`, `branch`) VALUES
(1, 'زياره اعطال', NULL, '2023-07-27 12:13:07', 0, 0, 0),
(2, 'زياره تسويق', NULL, '2023-07-27 12:13:07', 0, 0, 0),
(3, 'زياره علاقات', NULL, '2023-07-27 12:13:07', 0, 0, 0),
(4, 'تركيب', NULL, '2023-12-23 01:44:24', 0, 0, 0),
(5, 'كلينت', NULL, '2023-12-23 01:44:24', 0, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `test`
--

CREATE TABLE `test` (
  `id` int(11) NOT NULL,
  `first_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) DEFAULT NULL,
  `email` varchar(50) DEFAULT NULL,
  `phone` varchar(15) DEFAULT NULL,
  `created` datetime NOT NULL,
  `modified` datetime NOT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `name` varchar(50) DEFAULT NULL,
  `date` date DEFAULT NULL,
  `test` varchar(1) DEFAULT NULL,
  `time` time DEFAULT NULL,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `towns`
--

CREATE TABLE `towns` (
  `id` int(11) NOT NULL,
  `name` varchar(20) NOT NULL,
  `info` text DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `id` int(11) NOT NULL,
  `tdate` date NOT NULL,
  `details` varchar(200) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(1) NOT NULL,
  `uname` varchar(20) NOT NULL,
  `password` varchar(255) NOT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `usertype` int(11) NOT NULL,
  `userrole` int(11) NOT NULL DEFAULT 1,
  `is_waiter` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = ويتر، 0 = مستخدم عادي',
  `img` varchar(255) NOT NULL,
  `def_client` int(11) DEFAULT NULL,
  `def_fund` int(11) DEFAULT NULL,
  `def_store` int(11) DEFAULT NULL,
  `def_prod` int(11) DEFAULT NULL,
  `def_emp` int(11) DEFAULT NULL,
  `tasksindex` int(11) DEFAULT NULL,
  `tasksadd` int(11) DEFAULT NULL,
  `tasksedit` int(11) DEFAULT NULL,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `uname`, `password`, `crtime`, `isdeleted`, `usertype`, `userrole`, `is_waiter`, `img`, `def_client`, `def_fund`, `def_store`, `def_prod`, `def_emp`, `tasksindex`, `tasksadd`, `tasksedit`, `tenant`, `branch`) VALUES
(1, 'admin', '$2y$10$P4w1yXa1z1yQqUKh2q4lX.vU0vDb.fpRztEGUrFWx5Y7MQRR8iyCm', '2022-12-05 15:01:33', 0, 2, 1, 0, '22947314.png', NULL, NULL, NULL, NULL, NULL, NULL, 1, NULL, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `usr_pwrs`
--

CREATE TABLE `usr_pwrs` (
  `id` int(11) NOT NULL,
  `rollname` varchar(50) DEFAULT NULL,
  `is_active` int(11) DEFAULT 1,
  `is_fav_users` int(11) DEFAULT 0,
  `show_users` int(11) DEFAULT 1,
  `add_users` int(11) DEFAULT 1,
  `edit_users` int(11) DEFAULT 1,
  `delete_users` int(11) DEFAULT 1,
  `is_fav_general_entrys` int(11) DEFAULT 0,
  `show_general_entrys` int(11) DEFAULT 1,
  `add_general_entrys` int(11) DEFAULT 1,
  `edit_general_entrys` int(11) DEFAULT 1,
  `delete_general_entrys` int(11) DEFAULT 1,
  `is_fav_clients` int(11) DEFAULT 0,
  `show_clients` int(11) DEFAULT 1,
  `add_clients` int(11) DEFAULT 1,
  `edit_clients` int(11) DEFAULT 1,
  `is_fav_suppliers` int(11) DEFAULT 0,
  `delete_clients` int(11) DEFAULT 1,
  `show_suppliers` int(11) DEFAULT 1,
  `add_suppliers` int(11) DEFAULT 1,
  `edit_suppliers` int(11) DEFAULT 1,
  `delete_suppliers` int(11) DEFAULT 1,
  `is_fav_funds` int(11) DEFAULT 0,
  `show_funds` int(11) DEFAULT 1,
  `add_funds` int(11) DEFAULT 1,
  `edit_funds` int(11) DEFAULT 1,
  `delete_funds` int(11) DEFAULT 1,
  `is_fav_banks` int(11) DEFAULT 0,
  `show_banks` int(11) DEFAULT 1,
  `add_banks` int(11) DEFAULT 1,
  `edit_banks` int(11) DEFAULT 1,
  `delete_banks` int(11) DEFAULT 1,
  `is_fav_stock` int(11) DEFAULT 0,
  `show_stock` int(11) DEFAULT 1,
  `add_stock` int(11) DEFAULT 1,
  `edit_stock` int(11) DEFAULT 1,
  `delete_stock` int(11) DEFAULT 1,
  `is_fav_expenses` int(11) DEFAULT 0,
  `show_expenses` int(11) DEFAULT 1,
  `add_expenses` int(11) DEFAULT 1,
  `edit_expenses` int(11) DEFAULT 1,
  `delete_expenses` int(11) DEFAULT 1,
  `is_fav_revenuses` int(11) DEFAULT 0,
  `show_revenuses` int(11) DEFAULT 1,
  `add_revenuses` int(11) DEFAULT 1,
  `edit_revenuses` int(11) DEFAULT 1,
  `delete_revenuses` int(11) DEFAULT 1,
  `is_fav_credits` int(11) DEFAULT 0,
  `show_credits` int(11) DEFAULT 1,
  `add_credits` int(11) DEFAULT 1,
  `edit_credits` int(11) DEFAULT 1,
  `delete_credits` int(11) DEFAULT 1,
  `is_fav_depits` int(11) DEFAULT 0,
  `show_depits` int(11) DEFAULT 1,
  `add_depits` int(11) DEFAULT 1,
  `edit_depits` int(11) DEFAULT 1,
  `delete_depits` int(11) DEFAULT 1,
  `is_fav_partners` int(11) DEFAULT 0,
  `show_partners` int(11) DEFAULT 1,
  `add_partners` int(11) DEFAULT 1,
  `edit_partners` int(11) DEFAULT 1,
  `delete_partners` int(11) DEFAULT 1,
  `is_fav_assets` int(11) DEFAULT 0,
  `show_assets` int(11) DEFAULT 1,
  `add_assets` int(11) DEFAULT 1,
  `edit_assets` int(11) DEFAULT 1,
  `delete_assets` int(11) DEFAULT 1,
  `is_fav_rentables` int(11) DEFAULT 0,
  `show_rentables` int(11) DEFAULT 1,
  `add_rentables` int(11) DEFAULT 1,
  `edit_rentables` int(11) DEFAULT 1,
  `delete_rentables` int(11) DEFAULT 1,
  `is_fav_employees` int(11) DEFAULT 0,
  `show_employees` int(11) DEFAULT 1,
  `add_employees` int(11) DEFAULT 1,
  `edit_employees` int(11) DEFAULT 1,
  `delete_employees` int(11) DEFAULT 1,
  `is_fav_items` int(11) DEFAULT 0,
  `show_items` int(11) DEFAULT 1,
  `add_items` int(11) DEFAULT 1,
  `edit_items` int(11) DEFAULT 1,
  `delete_items` int(11) DEFAULT 1,
  `is_fav_item_groups` int(11) DEFAULT 0,
  `show_item_groups` int(11) DEFAULT 1,
  `add_item_groups` int(11) DEFAULT 1,
  `edit_item_groups` int(11) DEFAULT 1,
  `delete_item_groups` int(11) DEFAULT 1,
  `is_fav_sales` int(11) DEFAULT 0,
  `show_sales` int(11) DEFAULT 1,
  `add_sales` int(11) DEFAULT 1,
  `edit_sales` int(11) DEFAULT 1,
  `delete_sales` int(11) DEFAULT 1,
  `is_fav_resale` int(11) DEFAULT 0,
  `show_resale` int(11) DEFAULT 1,
  `add_resale` int(11) DEFAULT 1,
  `edit_resale` int(11) DEFAULT 1,
  `delete_resale` int(11) DEFAULT 1,
  `is_fav_purcases` int(11) DEFAULT 0,
  `show_purchases` int(11) DEFAULT 1,
  `add_purchases` int(11) DEFAULT 1,
  `edit_purchases` int(11) DEFAULT 1,
  `delete_purchases` int(11) DEFAULT 1,
  `is_fav_repurchases` int(11) DEFAULT 0,
  `show_repurchases` int(11) DEFAULT 1,
  `add_repurchases` int(11) DEFAULT 1,
  `edit_repurchases` int(11) DEFAULT 1,
  `delete_repurchases` int(11) DEFAULT 1,
  `is_fav_recive` int(11) DEFAULT 0,
  `show_recive` int(11) DEFAULT 1,
  `add_recive` int(11) DEFAULT 1,
  `edit_recive` int(11) DEFAULT 1,
  `delete_recive` int(11) DEFAULT 1,
  `show_payment` int(11) DEFAULT 1,
  `is_fav_payment` int(11) DEFAULT 0,
  `add_payment` int(11) DEFAULT 1,
  `edit_payment` int(11) DEFAULT 1,
  `delete_payment` int(11) DEFAULT 1,
  `is_fav_clinic_clients` int(11) DEFAULT 0,
  `show_clinic_clients` int(11) DEFAULT 1,
  `add_clinic_clients` int(11) DEFAULT 1,
  `edit_clinic_clients` int(11) DEFAULT 1,
  `delete_clinic_clients` int(11) DEFAULT 1,
  `is_fav_reservations` int(11) DEFAULT 0,
  `show_reservations` int(11) DEFAULT 1,
  `add_reservations` int(11) DEFAULT 1,
  `edit_reservations` int(11) DEFAULT 1,
  `delete_reservations` int(11) DEFAULT 1,
  `is_fav_drugs` int(11) DEFAULT 0,
  `show_drugs` int(11) DEFAULT 1,
  `add_drugs` int(11) DEFAULT 1,
  `edit_drugs` int(11) DEFAULT 1,
  `is_fav_attandance` int(11) DEFAULT 1,
  `delete_attandance` int(11) DEFAULT 1,
  `edit_attandance` int(11) DEFAULT 1,
  `show_attandance` int(11) DEFAULT 1,
  `add_attandance` int(11) DEFAULT 1,
  `delete_drugs` int(11) DEFAULT 1,
  `is_fav_client_profile` int(11) DEFAULT 0,
  `show_client_profile` int(11) DEFAULT 1,
  `add_client_profile` int(11) DEFAULT 1,
  `edit_client_profile` int(11) DEFAULT 1,
  `delete_client_profile` int(11) DEFAULT 1,
  `is_fav_advanced_clients` int(11) DEFAULT 0,
  `show_advanced_clients` int(11) DEFAULT 1,
  `add_advanced_clients` int(11) DEFAULT 1,
  `edit_advanced_clients` int(11) DEFAULT 1,
  `delete_advanced_clients` int(11) DEFAULT 1,
  `is_fav_chances` int(11) DEFAULT 0,
  `show_chances` int(11) DEFAULT 1,
  `add_chances` int(11) DEFAULT 1,
  `edit_chances` int(11) DEFAULT 1,
  `delete_chances` int(11) DEFAULT 1,
  `is_fav_calls` int(11) DEFAULT 0,
  `show_calls` int(11) DEFAULT 1,
  `add_calls` int(11) DEFAULT 1,
  `edit_calls` int(11) DEFAULT 1,
  `delete_calls` int(11) DEFAULT 1,
  `is_fav_journals` int(11) DEFAULT 0,
  `show_journals` int(11) DEFAULT 1,
  `add_journals` int(11) DEFAULT 1,
  `edit_journals` int(11) DEFAULT 1,
  `delete_journals` int(11) DEFAULT 1,
  `show_gl_reports` int(11) DEFAULT 1,
  `show_clinic_reports` int(11) DEFAULT 1,
  `show_rent_reports` int(11) DEFAULT 1,
  `show_payroll_report` int(11) DEFAULT 1,
  `show_hr_report` int(11) DEFAULT 1,
  `sid_entry` int(11) DEFAULT 1,
  `sid_stock` int(11) DEFAULT 1,
  `sid_sales` int(11) DEFAULT 1,
  `sid_purchases` int(11) DEFAULT 1,
  `sid_vouchers` int(11) DEFAULT 1,
  `sid_clinics` int(11) DEFAULT 1,
  `sid_crm` int(11) DEFAULT 1,
  `sid_accounts` int(11) DEFAULT 1,
  `sid_assets` int(11) DEFAULT 1,
  `sid_reports` int(11) DEFAULT 1,
  `sid_hr` int(11) DEFAULT 1,
  `sid_payroll` int(11) DEFAULT 1,
  `sid_rents` int(11) NOT NULL DEFAULT 1,
  `show_total_reservation` int(11) DEFAULT 1,
  `show_ended_reservation` int(11) DEFAULT 1,
  `info` varchar(250) DEFAULT NULL,
  `isdeleted` tinyint(1) DEFAULT 0,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0,
  `show_all_tasks` int(1) DEFAULT NULL,
  `show_main_cards` tinyint(1) NOT NULL DEFAULT 1,
  `show_main_elements` tinyint(1) NOT NULL DEFAULT 1,
  `show_main_tables` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `usr_pwrs`
--

INSERT INTO `usr_pwrs` (`id`, `rollname`, `is_active`, `is_fav_users`, `show_users`, `add_users`, `edit_users`, `delete_users`, `is_fav_general_entrys`, `show_general_entrys`, `add_general_entrys`, `edit_general_entrys`, `delete_general_entrys`, `is_fav_clients`, `show_clients`, `add_clients`, `edit_clients`, `is_fav_suppliers`, `delete_clients`, `show_suppliers`, `add_suppliers`, `edit_suppliers`, `delete_suppliers`, `is_fav_funds`, `show_funds`, `add_funds`, `edit_funds`, `delete_funds`, `is_fav_banks`, `show_banks`, `add_banks`, `edit_banks`, `delete_banks`, `is_fav_stock`, `show_stock`, `add_stock`, `edit_stock`, `delete_stock`, `is_fav_expenses`, `show_expenses`, `add_expenses`, `edit_expenses`, `delete_expenses`, `is_fav_revenuses`, `show_revenuses`, `add_revenuses`, `edit_revenuses`, `delete_revenuses`, `is_fav_credits`, `show_credits`, `add_credits`, `edit_credits`, `delete_credits`, `is_fav_depits`, `show_depits`, `add_depits`, `edit_depits`, `delete_depits`, `is_fav_partners`, `show_partners`, `add_partners`, `edit_partners`, `delete_partners`, `is_fav_assets`, `show_assets`, `add_assets`, `edit_assets`, `delete_assets`, `is_fav_rentables`, `show_rentables`, `add_rentables`, `edit_rentables`, `delete_rentables`, `is_fav_employees`, `show_employees`, `add_employees`, `edit_employees`, `delete_employees`, `is_fav_items`, `show_items`, `add_items`, `edit_items`, `delete_items`, `is_fav_item_groups`, `show_item_groups`, `add_item_groups`, `edit_item_groups`, `delete_item_groups`, `is_fav_sales`, `show_sales`, `add_sales`, `edit_sales`, `delete_sales`, `is_fav_resale`, `show_resale`, `add_resale`, `edit_resale`, `delete_resale`, `is_fav_purcases`, `show_purchases`, `add_purchases`, `edit_purchases`, `delete_purchases`, `is_fav_repurchases`, `show_repurchases`, `add_repurchases`, `edit_repurchases`, `delete_repurchases`, `is_fav_recive`, `show_recive`, `add_recive`, `edit_recive`, `delete_recive`, `show_payment`, `is_fav_payment`, `add_payment`, `edit_payment`, `delete_payment`, `is_fav_clinic_clients`, `show_clinic_clients`, `add_clinic_clients`, `edit_clinic_clients`, `delete_clinic_clients`, `is_fav_reservations`, `show_reservations`, `add_reservations`, `edit_reservations`, `delete_reservations`, `is_fav_drugs`, `show_drugs`, `add_drugs`, `edit_drugs`, `is_fav_attandance`, `delete_attandance`, `edit_attandance`, `show_attandance`, `add_attandance`, `delete_drugs`, `is_fav_client_profile`, `show_client_profile`, `add_client_profile`, `edit_client_profile`, `delete_client_profile`, `is_fav_advanced_clients`, `show_advanced_clients`, `add_advanced_clients`, `edit_advanced_clients`, `delete_advanced_clients`, `is_fav_chances`, `show_chances`, `add_chances`, `edit_chances`, `delete_chances`, `is_fav_calls`, `show_calls`, `add_calls`, `edit_calls`, `delete_calls`, `is_fav_journals`, `show_journals`, `add_journals`, `edit_journals`, `delete_journals`, `show_gl_reports`, `show_clinic_reports`, `show_rent_reports`, `show_payroll_report`, `show_hr_report`, `sid_entry`, `sid_stock`, `sid_sales`, `sid_purchases`, `sid_vouchers`, `sid_clinics`, `sid_crm`, `sid_accounts`, `sid_assets`, `sid_reports`, `sid_hr`, `sid_payroll`, `sid_rents`, `show_total_reservation`, `show_ended_reservation`, `info`, `isdeleted`, `crtime`, `mdtime`, `tenant`, `branch`, `show_all_tasks`, `show_main_cards`, `show_main_elements`, `show_main_tables`) VALUES
(1, 'admin', 1, 1, 1, 1, 1, 1, 0, 0, 0, 0, 0, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, 0, 0, 0, 0, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 'wwww', 1, '2024-05-12 15:05:26', '2025-12-18 10:37:11', 0, 0, 1, 1, 1, 1),
(2, 'cashier', 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 'cshier', 1, '2024-05-12 15:11:21', '2025-03-05 19:32:34', 0, 0, 0, 0, 1, 0),
(26, 'دكتور', 1, 1, 1, 1, 1, 1, 0, 0, 0, 0, 0, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, 0, 0, 0, 0, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, 0, 0, 0, 0, 1, 0, 1, 0, 0, 0, 0, 0, 1, 1, 'دكتور', 1, '2024-05-19 19:04:27', '2024-08-21 13:00:57', 0, 0, 0, 1, 1, 1),
(27, 'مساعد دكتور', 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 'مساعد دكتور', 1, '2024-05-30 20:48:11', '2024-05-30 22:02:13', 0, 0, NULL, 1, 1, 1),
(28, 'بائع كاشير', 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 1, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 'مبيعات كاشير فقط', 0, '2024-07-28 17:02:09', '2024-07-28 17:02:09', 0, 0, NULL, 1, 1, 1);

-- --------------------------------------------------------

--
-- Table structure for table `vacancies`
--

CREATE TABLE `vacancies` (
  `id` int(1) NOT NULL,
  `name` varchar(20) NOT NULL,
  `info` text DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `visits`
--

CREATE TABLE `visits` (
  `id` int(11) NOT NULL,
  `client` int(11) NOT NULL,
  `complaint` varchar(250) DEFAULT NULL,
  `diagnosis` varchar(250) DEFAULT NULL,
  `recommendation` varchar(250) DEFAULT NULL,
  `prescription` varchar(250) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `mdtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `info` varchar(250) NOT NULL,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `visittybes`
--

CREATE TABLE `visittybes` (
  `id` int(1) NOT NULL,
  `name` varchar(25) NOT NULL,
  `value` double DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `visittybes`
--

INSERT INTO `visittybes` (`id`, `name`, `value`, `crtime`, `isdeleted`, `tenant`, `branch`) VALUES
(1, 'كشف 1', 400, '2023-09-03 23:57:36', 0, 0, 0),
(2, 'اعادة', 250, '2023-09-03 23:57:36', 0, 0, 0),
(3, 'مستعجل', 500, '2024-05-03 20:57:27', 0, 0, 0),
(4, 'زيارة 2', 500, '2024-05-04 17:57:54', 0, 0, 0),
(5, 'private', 800, '2024-05-04 17:58:28', 0, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `zankat`
--

CREATE TABLE `zankat` (
  `id` int(1) NOT NULL,
  `zname` varchar(100) NOT NULL,
  `colors` int(1) NOT NULL DEFAULT 1,
  `ctp` int(1) DEFAULT NULL,
  `zncase` int(1) DEFAULT NULL,
  `print` int(1) DEFAULT NULL,
  `ptype` int(1) DEFAULT NULL,
  `service` int(1) DEFAULT NULL,
  `prod` int(1) DEFAULT NULL,
  `measure` int(1) NOT NULL,
  `draw` int(1) NOT NULL,
  `farkh` int(1) NOT NULL,
  `info` varchar(255) DEFAULT NULL,
  `crtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `date` varchar(20) NOT NULL,
  `user` varchar(20) NOT NULL,
  `fatid` int(11) DEFAULT 0,
  `isdeleted` tinyint(1) DEFAULT 0,
  `tenant` int(11) DEFAULT 0,
  `branch` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `acc_groups`
--
ALTER TABLE `acc_groups`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `aname` (`aname`);

--
-- Indexes for table `acc_head`
--
ALTER TABLE `acc_head`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD UNIQUE KEY `aname` (`aname`);

--
-- Indexes for table `allowances`
--
ALTER TABLE `allowances`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `analisys`
--
ALTER TABLE `analisys`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `attandance`
--
ALTER TABLE `attandance`
  ADD PRIMARY KEY (`id`),
  ADD KEY `employee` (`employee`);

--
-- Indexes for table `attdocs`
--
ALTER TABLE `attdocs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `attlog`
--
ALTER TABLE `attlog`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `barcodes`
--
ALTER TABLE `barcodes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `barcode` (`barcode`);

--
-- Indexes for table `booking_cards`
--
ALTER TABLE `booking_cards`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `book_tybes`
--
ALTER TABLE `book_tybes`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `calls`
--
ALTER TABLE `calls`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `cases`
--
ALTER TABLE `cases`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `chances`
--
ALTER TABLE `chances`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `chances_tybes`
--
ALTER TABLE `chances_tybes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `cname` (`cname`);

--
-- Indexes for table `cities`
--
ALTER TABLE `cities`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `cname` (`cname`);

--
-- Indexes for table `clients`
--
ALTER TABLE `clients`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `closed_orders`
--
ALTER TABLE `closed_orders`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `cost_centers`
--
ALTER TABLE `cost_centers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `cname` (`cname`);

--
-- Indexes for table `criminals`
--
ALTER TABLE `criminals`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `ctp`
--
ALTER TABLE `ctp`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `cvs`
--
ALTER TABLE `cvs`
  ADD PRIMARY KEY (`id`,`email`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `delivery_clients`
--
ALTER TABLE `delivery_clients`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `phone` (`phone`),
  ADD KEY `isdeleted` (`isdeleted`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `drugs`
--
ALTER TABLE `drugs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `emplog`
--
ALTER TABLE `emplog`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `employee_operations`
--
ALTER TABLE `employee_operations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `operation_id` (`operation_id`);

--
-- Indexes for table `emp_allowences`
--
ALTER TABLE `emp_allowences`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `emp_kbis`
--
ALTER TABLE `emp_kbis`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `entitles`
--
ALTER TABLE `entitles`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `fats`
--
ALTER TABLE `fats`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `fat_details`
--
ALTER TABLE `fat_details`
  ADD PRIMARY KEY (`id`),
  ADD KEY `item_id` (`item_id`);

--
-- Indexes for table `fat_tybes`
--
ALTER TABLE `fat_tybes`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `fptybes`
--
ALTER TABLE `fptybes`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `hiringcontracts`
--
ALTER TABLE `hiringcontracts`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `holidays`
--
ALTER TABLE `holidays`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `hr_operations`
--
ALTER TABLE `hr_operations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `parent_id` (`parent_id`);

--
-- Indexes for table `hr_operation_steps`
--
ALTER TABLE `hr_operation_steps`
  ADD PRIMARY KEY (`id`),
  ADD KEY `operation_id` (`operation_id`);

--
-- Indexes for table `imgs`
--
ALTER TABLE `imgs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `item_group`
--
ALTER TABLE `item_group`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `gname` (`gname`);

--
-- Indexes for table `item_group2`
--
ALTER TABLE `item_group2`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `gname` (`gname`);

--
-- Indexes for table `item_group3`
--
ALTER TABLE `item_group3`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `gname` (`gname`);

--
-- Indexes for table `item_units`
--
ALTER TABLE `item_units`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `joplevels`
--
ALTER TABLE `joplevels`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `joprules`
--
ALTER TABLE `joprules`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `jops`
--
ALTER TABLE `jops`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `joptybes`
--
ALTER TABLE `joptybes`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `journal_entries`
--
ALTER TABLE `journal_entries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `account_id` (`account_id`),
  ADD KEY `journal_id` (`journal_id`);

--
-- Indexes for table `journal_heads`
--
ALTER TABLE `journal_heads`
  ADD PRIMARY KEY (`id`),
  ADD KEY `journal_heads_ibfk_1` (`pro_tybe`);

--
-- Indexes for table `journal_tybes`
--
ALTER TABLE `journal_tybes`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `karta`
--
ALTER TABLE `karta`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `kbis`
--
ALTER TABLE `kbis`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `myinstallments`
--
ALTER TABLE `myinstallments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cl_id` (`cl_id`),
  ADD KEY `rent_id` (`rent_id`),
  ADD KEY `contract` (`contract`);

--
-- Indexes for table `myitems`
--
ALTER TABLE `myitems`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `iname` (`iname`),
  ADD KEY `idx_myitems_iname` (`iname`),
  ADD KEY `idx_myitems_name2` (`name2`),
  ADD KEY `idx_myitems_barcode` (`barcode`),
  ADD KEY `idx_myitems_isdeleted` (`isdeleted`);

--
-- Indexes for table `myoptions`
--
ALTER TABLE `myoptions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `mypatterns`
--
ALTER TABLE `mypatterns`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `myrents`
--
ALTER TABLE `myrents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cl_id` (`cl_id`),
  ADD KEY `rent_id` (`rent_id`);

--
-- Indexes for table `myunits`
--
ALTER TABLE `myunits`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `myvouchers`
--
ALTER TABLE `myvouchers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `my_news`
--
ALTER TABLE `my_news`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `notes`
--
ALTER TABLE `notes`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `oppatterns`
--
ALTER TABLE `oppatterns`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `employee_id` (`employee`),
  ADD KEY `tybe` (`tybe`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `order_status`
--
ALTER TABLE `order_status`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `order_types`
--
ALTER TABLE `order_types`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `ot_head`
--
ALTER TABLE `ot_head`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `receipt_number` (`receipt_number`),
  ADD KEY `acc1` (`acc1`),
  ADD KEY `acc2` (`acc2`),
  ADD KEY `emp2_id` (`emp2_id`),
  ADD KEY `emp_id` (`emp_id`),
  ADD KEY `journal_tybe` (`journal_tybe`),
  ADD KEY `user` (`user`),
  ADD KEY `cost_center` (`cost_center`),
  ADD KEY `store_id` (`store_id`),
  ADD KEY `price_list` (`price_list`),
  ADD KEY `idx_table` (`table_id`),
  ADD KEY `idx_order_type` (`order_type`),
  ADD KEY `idx_payment_status` (`payment_status`),
  ADD KEY `idx_isdeleted` (`isdeleted`),
  ADD KEY `waiter_id` (`waiter_id`);

--
-- Indexes for table `paper_types`
--
ALTER TABLE `paper_types`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `patt_cols`
--
ALTER TABLE `patt_cols`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `permits`
--
ALTER TABLE `permits`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `prescdetails`
--
ALTER TABLE `prescdetails`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `prescs`
--
ALTER TABLE `prescs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `price_lists`
--
ALTER TABLE `price_lists`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `print`
--
ALTER TABLE `print`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `process`
--
ALTER TABLE `process`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `prods`
--
ALTER TABLE `prods`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `productions`
--
ALTER TABLE `productions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `pro_tybes`
--
ALTER TABLE `pro_tybes`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `pst_activities`
--
ALTER TABLE `pst_activities`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `pst_criminals`
--
ALTER TABLE `pst_criminals`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `pst_crmstyles`
--
ALTER TABLE `pst_crmstyles`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `pst_gangs`
--
ALTER TABLE `pst_gangs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `pst_issues`
--
ALTER TABLE `pst_issues`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `rays`
--
ALTER TABLE `rays`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `reservations`
--
ALTER TABLE `reservations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `salaries`
--
ALTER TABLE `salaries`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `services`
--
ALTER TABLE `services`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `session_time`
--
ALTER TABLE `session_time`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `shifts`
--
ALTER TABLE `shifts`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `shw_optns`
--
ALTER TABLE `shw_optns`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sitting_items`
--
ALTER TABLE `sitting_items`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `skills`
--
ALTER TABLE `skills`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `system_logs`
--
ALTER TABLE `system_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tables`
--
ALTER TABLE `tables`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tasks`
--
ALTER TABLE `tasks`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tasktybes`
--
ALTER TABLE `tasktybes`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `test`
--
ALTER TABLE `test`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `towns`
--
ALTER TABLE `towns`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD KEY `is_waiter` (`is_waiter`);

--
-- Indexes for table `usr_pwrs`
--
ALTER TABLE `usr_pwrs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `rollname` (`rollname`);

--
-- Indexes for table `vacancies`
--
ALTER TABLE `vacancies`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `visits`
--
ALTER TABLE `visits`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `visittybes`
--
ALTER TABLE `visittybes`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `zankat`
--
ALTER TABLE `zankat`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `acc_groups`
--
ALTER TABLE `acc_groups`
  MODIFY `id` int(1) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `acc_head`
--
ALTER TABLE `acc_head`
  MODIFY `id` int(1) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=274;

--
-- AUTO_INCREMENT for table `allowances`
--
ALTER TABLE `allowances`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `analisys`
--
ALTER TABLE `analisys`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attandance`
--
ALTER TABLE `attandance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=758;

--
-- AUTO_INCREMENT for table `attdocs`
--
ALTER TABLE `attdocs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=139;

--
-- AUTO_INCREMENT for table `attlog`
--
ALTER TABLE `attlog`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `booking_cards`
--
ALTER TABLE `booking_cards`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `book_tybes`
--
ALTER TABLE `book_tybes`
  MODIFY `id` int(1) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `calls`
--
ALTER TABLE `calls`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cases`
--
ALTER TABLE `cases`
  MODIFY `id` int(1) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chances`
--
ALTER TABLE `chances`
  MODIFY `id` int(1) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chances_tybes`
--
ALTER TABLE `chances_tybes`
  MODIFY `id` int(1) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `cities`
--
ALTER TABLE `cities`
  MODIFY `id` int(1) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `clients`
--
ALTER TABLE `clients`
  MODIFY `id` int(1) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `closed_orders`
--
ALTER TABLE `closed_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cost_centers`
--
ALTER TABLE `cost_centers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `criminals`
--
ALTER TABLE `criminals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ctp`
--
ALTER TABLE `ctp`
  MODIFY `id` int(1) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cvs`
--
ALTER TABLE `cvs`
  MODIFY `id` int(1) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `delivery_clients`
--
ALTER TABLE `delivery_clients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` int(1) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `drugs`
--
ALTER TABLE `drugs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `emplog`
--
ALTER TABLE `emplog`
  MODIFY `id` int(1) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=139;

--
-- AUTO_INCREMENT for table `employee_operations`
--
ALTER TABLE `employee_operations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `emp_allowences`
--
ALTER TABLE `emp_allowences`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `emp_kbis`
--
ALTER TABLE `emp_kbis`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `entitles`
--
ALTER TABLE `entitles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fats`
--
ALTER TABLE `fats`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1012;

--
-- AUTO_INCREMENT for table `fat_details`
--
ALTER TABLE `fat_details`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fat_tybes`
--
ALTER TABLE `fat_tybes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `fptybes`
--
ALTER TABLE `fptybes`
  MODIFY `id` int(1) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `hiringcontracts`
--
ALTER TABLE `hiringcontracts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `holidays`
--
ALTER TABLE `holidays`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hr_operations`
--
ALTER TABLE `hr_operations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `hr_operation_steps`
--
ALTER TABLE `hr_operation_steps`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `imgs`
--
ALTER TABLE `imgs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `item_group`
--
ALTER TABLE `item_group`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `item_group2`
--
ALTER TABLE `item_group2`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `item_group3`
--
ALTER TABLE `item_group3`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `item_units`
--
ALTER TABLE `item_units`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `joplevels`
--
ALTER TABLE `joplevels`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `joprules`
--
ALTER TABLE `joprules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `jops`
--
ALTER TABLE `jops`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `joptybes`
--
ALTER TABLE `joptybes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `journal_entries`
--
ALTER TABLE `journal_entries`
  MODIFY `id` int(1) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `journal_heads`
--
ALTER TABLE `journal_heads`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `journal_tybes`
--
ALTER TABLE `journal_tybes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `karta`
--
ALTER TABLE `karta`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `kbis`
--
ALTER TABLE `kbis`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `myinstallments`
--
ALTER TABLE `myinstallments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `myitems`
--
ALTER TABLE `myitems`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `myoptions`
--
ALTER TABLE `myoptions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `mypatterns`
--
ALTER TABLE `mypatterns`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `myrents`
--
ALTER TABLE `myrents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `myunits`
--
ALTER TABLE `myunits`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `myvouchers`
--
ALTER TABLE `myvouchers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `my_news`
--
ALTER TABLE `my_news`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notes`
--
ALTER TABLE `notes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `oppatterns`
--
ALTER TABLE `oppatterns`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `order_status`
--
ALTER TABLE `order_status`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `order_types`
--
ALTER TABLE `order_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `ot_head`
--
ALTER TABLE `ot_head`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `paper_types`
--
ALTER TABLE `paper_types`
  MODIFY `id` int(1) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `patt_cols`
--
ALTER TABLE `patt_cols`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permits`
--
ALTER TABLE `permits`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `prescdetails`
--
ALTER TABLE `prescdetails`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `prescs`
--
ALTER TABLE `prescs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `price_lists`
--
ALTER TABLE `price_lists`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `print`
--
ALTER TABLE `print`
  MODIFY `id` int(1) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `process`
--
ALTER TABLE `process`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `prods`
--
ALTER TABLE `prods`
  MODIFY `id` int(1) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `productions`
--
ALTER TABLE `productions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pro_tybes`
--
ALTER TABLE `pro_tybes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `pst_activities`
--
ALTER TABLE `pst_activities`
  MODIFY `id` int(1) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pst_criminals`
--
ALTER TABLE `pst_criminals`
  MODIFY `id` int(1) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pst_crmstyles`
--
ALTER TABLE `pst_crmstyles`
  MODIFY `id` int(1) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pst_gangs`
--
ALTER TABLE `pst_gangs`
  MODIFY `id` int(1) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pst_issues`
--
ALTER TABLE `pst_issues`
  MODIFY `id` int(1) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `rays`
--
ALTER TABLE `rays`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reservations`
--
ALTER TABLE `reservations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `salaries`
--
ALTER TABLE `salaries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `services`
--
ALTER TABLE `services`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `session_time`
--
ALTER TABLE `session_time`
  MODIFY `id` int(1) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int(1) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `shifts`
--
ALTER TABLE `shifts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `shw_optns`
--
ALTER TABLE `shw_optns`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `sitting_items`
--
ALTER TABLE `sitting_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `skills`
--
ALTER TABLE `skills`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_logs`
--
ALTER TABLE `system_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tables`
--
ALTER TABLE `tables`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tasks`
--
ALTER TABLE `tasks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tasktybes`
--
ALTER TABLE `tasktybes`
  MODIFY `id` int(1) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `test`
--
ALTER TABLE `test`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `towns`
--
ALTER TABLE `towns`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(1) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `usr_pwrs`
--
ALTER TABLE `usr_pwrs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `vacancies`
--
ALTER TABLE `vacancies`
  MODIFY `id` int(1) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `visits`
--
ALTER TABLE `visits`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `visittybes`
--
ALTER TABLE `visittybes`
  MODIFY `id` int(1) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `zankat`
--
ALTER TABLE `zankat`
  MODIFY `id` int(1) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `attandance`
--
ALTER TABLE `attandance`
  ADD CONSTRAINT `attandance_ibfk_1` FOREIGN KEY (`employee`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `employee_operations`
--
ALTER TABLE `employee_operations`
  ADD CONSTRAINT `employee_operations_ibfk_1` FOREIGN KEY (`operation_id`) REFERENCES `hr_operations` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `fat_details`
--
ALTER TABLE `fat_details`
  ADD CONSTRAINT `fat_details_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `myitems` (`id`);

--
-- Constraints for table `hr_operations`
--
ALTER TABLE `hr_operations`
  ADD CONSTRAINT `hr_operations_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `hr_operations` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `hr_operation_steps`
--
ALTER TABLE `hr_operation_steps`
  ADD CONSTRAINT `hr_operation_steps_ibfk_1` FOREIGN KEY (`operation_id`) REFERENCES `hr_operations` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `journal_entries`
--
ALTER TABLE `journal_entries`
  ADD CONSTRAINT `journal_entries_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `acc_head` (`id`),
  ADD CONSTRAINT `journal_entries_ibfk_2` FOREIGN KEY (`journal_id`) REFERENCES `journal_heads` (`id`);

--
-- Constraints for table `myinstallments`
--
ALTER TABLE `myinstallments`
  ADD CONSTRAINT `myinstallments_ibfk_1` FOREIGN KEY (`cl_id`) REFERENCES `acc_head` (`id`),
  ADD CONSTRAINT `myinstallments_ibfk_2` FOREIGN KEY (`rent_id`) REFERENCES `acc_head` (`id`),
  ADD CONSTRAINT `myinstallments_ibfk_3` FOREIGN KEY (`contract`) REFERENCES `myrents` (`id`);

--
-- Constraints for table `myrents`
--
ALTER TABLE `myrents`
  ADD CONSTRAINT `myrents_ibfk_1` FOREIGN KEY (`cl_id`) REFERENCES `acc_head` (`id`),
  ADD CONSTRAINT `myrents_ibfk_2` FOREIGN KEY (`rent_id`) REFERENCES `acc_head` (`id`);

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`employee`) REFERENCES `employees` (`id`),
  ADD CONSTRAINT `orders_ibfk_2` FOREIGN KEY (`tybe`) REFERENCES `order_types` (`id`),
  ADD CONSTRAINT `orders_ibfk_3` FOREIGN KEY (`status`) REFERENCES `order_status` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
