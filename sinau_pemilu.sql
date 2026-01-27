-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 26, 2026 at 09:52 PM
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
-- Database: `sinau_pemilu`
--

-- --------------------------------------------------------

--
-- Table structure for table `kuis_paket`
--

CREATE TABLE `kuis_paket` (
  `id` int(11) NOT NULL,
  `judul` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `kuis_paket`
--

INSERT INTO `kuis_paket` (`id`, `judul`, `created_at`) VALUES
(1, 'grom', '2026-01-26 02:36:20'),
(2, 'Dasar 😗', '2026-01-26 02:52:10');

-- --------------------------------------------------------

--
-- Table structure for table `kuis_soal`
--

CREATE TABLE `kuis_soal` (
  `id` int(11) NOT NULL,
  `paket_id` int(11) NOT NULL,
  `nomor` int(11) NOT NULL,
  `pertanyaan` text NOT NULL,
  `opsi_a` varchar(255) NOT NULL,
  `opsi_b` varchar(255) NOT NULL,
  `opsi_c` varchar(255) NOT NULL,
  `opsi_d` varchar(255) NOT NULL,
  `jawaban` enum('A','B','C','D') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `kuis_soal`
--

INSERT INTO `kuis_soal` (`id`, `paket_id`, `nomor`, `pertanyaan`, `opsi_a`, `opsi_b`, `opsi_c`, `opsi_d`, `jawaban`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 'apa itu essay?', 'gon', 'gun', 'gen', 'gum', 'B', '2026-01-26 02:36:20', NULL),
(4, 1, 2, 'Pemilu adalah...', 'pemilihan umum', 'pake nanya', 'yaaa', 'okee', 'A', '2026-01-26 02:47:45', NULL),
(6, 2, 1, 'Apa kepanjangan dari KPU?', 'Komisi Pemilihan Umum', 'Komisi Peraturan Umum', 'Komite Pemilihan Umum', 'Kantor Pemilihan Umum', 'A', '2026-01-26 02:52:10', NULL),
(7, 2, 2, 'Pemilu di Indonesia dilaksanakan setiap berapa tahun?', '3 tahun', '4 tahun', '5 tahun', '6 tahun', 'C', '2026-01-26 02:52:10', NULL),
(8, 2, 3, 'Contoh hak warga negara dalam pemilu adalah...', 'Membayar pajak', 'Memilih dalam pemilu', 'Mengurus SIM', 'Menjaga kebersihan', 'B', '2026-01-26 02:52:10', NULL),
(9, 2, 4, 'Apa tujuan utama pemilu?', 'Menentukan pajak daerah', 'Memilih pemimpin secara demokratis', 'Mengatur lalu lintas', 'Membuat aturan sekolah', 'B', '2026-01-26 02:52:10', NULL),
(10, 2, 5, 'Salah satu asas pemilu adalah...', 'Rahasia', 'Komersial', 'Monopoli', 'Diskriminatif', 'A', '2026-01-26 02:52:10', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `materi`
--

CREATE TABLE `materi` (
  `id` int(11) NOT NULL,
  `judul` varchar(255) NOT NULL,
  `tipe` enum('pdf','jpg') NOT NULL,
  `jumlah_slide` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `materi`
--

INSERT INTO `materi` (`id`, `judul`, `tipe`, `jumlah_slide`, `created_at`) VALUES
(9, 'TES', 'pdf', 21, '2026-01-25 20:23:24'),
(10, 'Materi Dasar', 'jpg', 1, '2026-01-25 21:41:48');

-- --------------------------------------------------------

--
-- Table structure for table `materi_media`
--

CREATE TABLE `materi_media` (
  `id` int(11) NOT NULL,
  `materi_id` int(11) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `materi_media`
--

INSERT INTO `materi_media` (`id`, `materi_id`, `file_path`, `sort_order`, `created_at`) VALUES
(2, 9, 'materi_20260125_212324_3a6a86c361a8.pdf', 0, '2026-01-25 20:23:24'),
(3, 10, 'materi_20260125_224148_ce4637f37592.png', 0, '2026-01-25 21:41:48');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `kuis_paket`
--
ALTER TABLE `kuis_paket`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `kuis_soal`
--
ALTER TABLE `kuis_soal`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_paket_nomor` (`paket_id`,`nomor`);

--
-- Indexes for table `materi`
--
ALTER TABLE `materi`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `materi_media`
--
ALTER TABLE `materi_media`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_media_materi` (`materi_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `kuis_paket`
--
ALTER TABLE `kuis_paket`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `kuis_soal`
--
ALTER TABLE `kuis_soal`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `materi`
--
ALTER TABLE `materi`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `materi_media`
--
ALTER TABLE `materi_media`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `kuis_soal`
--
ALTER TABLE `kuis_soal`
  ADD CONSTRAINT `fk_soal_paket` FOREIGN KEY (`paket_id`) REFERENCES `kuis_paket` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `materi_media`
--
ALTER TABLE `materi_media`
  ADD CONSTRAINT `fk_media_materi` FOREIGN KEY (`materi_id`) REFERENCES `materi` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
