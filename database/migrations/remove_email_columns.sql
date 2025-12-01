-- Migration: Remove email column from customers and users tables
-- Date: 2025-01-XX
-- Description: Remove email column from customers and users tables as it's no longer needed

-- Remove email column from customers table
ALTER TABLE `customers` DROP COLUMN IF EXISTS `email`;

-- Remove email column from users table
-- Note: This will also remove the UNIQUE constraint on email if it exists
ALTER TABLE `users` DROP COLUMN IF EXISTS `email`;

-- Remove UNIQUE index on email if it still exists (some MySQL versions may keep it)
ALTER TABLE `users` DROP INDEX IF EXISTS `email`;

