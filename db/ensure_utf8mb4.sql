-- Additive SQL to convert tables/columns to utf8mb4 where appropriate.
-- Run only if you know your schema; these are examples and should be adapted.

-- Convert database default charset
ALTER DATABASE `chatmind` CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Example: convert uploads table
ALTER TABLE `uploads` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Example: approved_messages
ALTER TABLE `approved_messages` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Example: users
ALTER TABLE `users` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Note: inspect your columns for types and adjust as needed. These statements are additive (they alter collations) but test first on a copy.
