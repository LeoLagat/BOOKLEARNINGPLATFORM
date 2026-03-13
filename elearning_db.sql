-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 17, 2026 at 08:28 AM
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
-- Database: `elearning_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `books`
--

CREATE TABLE `books` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `membership_required` enum('Basic','Premium','VIP') DEFAULT 'Basic',
  `file_path` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `archived_books`
--

CREATE TABLE `archived_books` (
  `id` int(11) NOT NULL,
  `original_book_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `membership_required` enum('Basic','Premium','VIP') DEFAULT 'Basic',
  `file_path` varchar(255) NOT NULL,
  `archived_by` varchar(100) DEFAULT NULL,
  `archived_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_bookmarks`
--

CREATE TABLE `user_bookmarks` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `book_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_book_activity`
--

CREATE TABLE `user_book_activity` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `book_id` int(11) NOT NULL,
  `action` varchar(20) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `books`
--

INSERT INTO `books` (`id`, `title`, `description`, `membership_required`, `file_path`) VALUES
(1, 'Healthy Living Handbook', 'Book One - Nutrition: Encouraging healthy and happy lives through better eating habits.', 'Basic', 'uploads/healthy_living.pdf'),
(2, 'African Recipes: Easy Delicious Meals', 'Big, bold African, Caribbean, and Southern flavors by Immaculate Bites.', 'Basic', 'uploads/african_recipes.pdf'),
(3, 'The Art of Public Speaking', 'Master communication and confidence with this classic guide by Dale Carnegie.', 'Premium', 'uploads/public_speaking.pdf'),
(4, 'Mindset Mastery', 'Think your way to universal wealth and success through mental discipline.', 'Premium', 'uploads/mindset_mastery.pdf'),
(5, 'Python Basics: A Practical Introduction', 'A comprehensive introduction to Python 3 for beginners and intermediate learners.', 'VIP', 'uploads/python_basics.pdf'),
(6, 'Financial Freedom', 'Master your money and build wealth for free.', 'Basic', 'uploads/financial_freedom.pdf'),
(7, 'Real Estate 101', 'A complete guide to property investment.', 'Premium', 'uploads/real_estate_101.pdf'),
(8, 'Digital Marketing', 'How to grow any business online.', 'VIP', 'uploads/digital_marketing.pdf');

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `id` int(11) NOT NULL,
  `fullname` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admin_users`
--

CREATE TABLE `admin_users` (
  `id` int(11) NOT NULL,
  `fullname` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('super_admin','sub_admin') NOT NULL DEFAULT 'sub_admin',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `fullname` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `membership` enum('Basic','Premium','VIP') DEFAULT 'Basic',
  `payment_status` varchar(20) DEFAULT 'Pending',
  `mpesa_receipt` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `fullname`, `email`, `phone`, `password`, `membership`, `payment_status`, `mpesa_receipt`, `created_at`) VALUES
(4, 'Bob Pending', 'bob@example.com', '0744555666', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Premium', 'Pending', NULL, '2026-02-17 04:58:19'),
(6, 'kelly', 'kelly@gmail.com', '0712345678', '$2y$10$xFfFsTeV9xkAb4cJqIXn4eU9rEyXWm7eEMv8t4n6j5eh15CNYW5kC', 'VIP', 'Pending', NULL, '2026-02-17 05:06:46'),
(8, 'lima', 'lima@gmail.com', '0700000234', '$2y$10$8HMg7p1Qd1ad4HigOppSdusCYncmJRTfJGaBGUzX0Ilm/BL1qdOT6', 'Basic', 'Paid', NULL, '2026-02-17 06:11:02'),
(12, 'Leo Lagat', 'lagatleo6602@gmail.com', '254727516126', '$2y$10$7fiQyUZlI.VCHpAx4lN93u025QK0ZOysz4JX7J5v1hysCOce1r8dq', 'Premium', 'Paid', 'UBH6L6VOLN', '2026-02-17 06:52:15');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `books`
--
ALTER TABLE `books`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `archived_books`
--
ALTER TABLE `archived_books`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `user_bookmarks`
--
ALTER TABLE `user_bookmarks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_user_bookmark` (`user_id`,`book_id`),
  ADD KEY `idx_user_bookmarks_user` (`user_id`),
  ADD KEY `idx_user_bookmarks_book` (`book_id`);

--
-- Indexes for table `user_book_activity`
--
ALTER TABLE `user_book_activity`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_activity_user` (`user_id`),
  ADD KEY `idx_user_activity_book` (`book_id`),
  ADD KEY `idx_user_activity_action` (`action`);

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `admin_users`
--
ALTER TABLE `admin_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `books`
--
ALTER TABLE `books`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `archived_books`
--
ALTER TABLE `archived_books`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_bookmarks`
--
ALTER TABLE `user_bookmarks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_book_activity`
--
ALTER TABLE `user_book_activity`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `admin_users`
--
ALTER TABLE `admin_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
