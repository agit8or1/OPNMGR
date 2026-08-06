-- Migration: v3.2.2 - Add missing user profile fields
-- Date: 2026-02-08
-- Description: Adds timezone and last_login columns to users table for profile functionality

-- Add timezone column for user preferences
ALTER TABLE users
ADD COLUMN IF NOT EXISTS timezone VARCHAR(50) DEFAULT 'America/Chicago'
AFTER email;

-- Add last_login tracking column
ALTER TABLE users
ADD COLUMN IF NOT EXISTS last_login TIMESTAMP NULL
AFTER recovery_codes;

-- Verify columns were added
SELECT 'Migration completed successfully' as status;
