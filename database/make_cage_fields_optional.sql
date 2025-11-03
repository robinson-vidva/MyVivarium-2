-- Migration: Make Cage Fields Optional for Quick Cage Creation
-- Description: This migration makes several cage creation fields optional to allow users to quickly
--              create cages with minimal information and complete details later.
-- Created: 2025-11-03
-- Purpose: Improve user experience by reducing required fields during cage creation

-- ==============================================================================
-- HOLDING CAGE FIELDS - Make optional for quick cage creation
-- ==============================================================================

-- Make DOB optional (was previously NOT NULL)
-- Rationale: Users can create cage quickly and add DOB later
ALTER TABLE holding MODIFY COLUMN dob date DEFAULT NULL;

-- Make Parent Cage optional (was previously NOT NULL)
-- Rationale: Not all cages have a known parent cage at creation time
ALTER TABLE holding MODIFY COLUMN parent_cg varchar(255) DEFAULT NULL;

-- ==============================================================================
-- BREEDING CAGE FIELDS - Make optional for quick cage creation
-- ==============================================================================

-- Make Cross optional (was previously NOT NULL)
-- Rationale: Users can add breeding cross information later
ALTER TABLE breeding MODIFY COLUMN cross varchar(255) DEFAULT NULL;

-- Make Male ID optional (was previously NOT NULL)
-- Rationale: Male mouse ID can be added after cage is created
ALTER TABLE breeding MODIFY COLUMN male_id varchar(255) DEFAULT NULL;

-- Make Female ID optional (was previously NOT NULL)
-- Rationale: Female mouse ID can be added after cage is created
ALTER TABLE breeding MODIFY COLUMN female_id varchar(255) DEFAULT NULL;

-- Make Male DOB optional (was previously NOT NULL)
-- Rationale: Male date of birth can be added later
ALTER TABLE breeding MODIFY COLUMN male_dob date DEFAULT NULL;

-- Make Female DOB optional (was previously NOT NULL)
-- Rationale: Female date of birth can be added later
ALTER TABLE breeding MODIFY COLUMN female_dob date DEFAULT NULL;

-- ==============================================================================
-- VERIFICATION QUERIES
-- ==============================================================================

-- Run these queries to verify the changes were applied successfully:

-- Verify holding table changes
-- SHOW COLUMNS FROM holding WHERE Field IN ('dob', 'parent_cg');

-- Verify breeding table changes
-- SHOW COLUMNS FROM breeding WHERE Field IN ('cross', 'male_id', 'female_id', 'male_dob', 'female_dob');

-- ==============================================================================
-- NOTES FOR ADMINISTRATORS
-- ==============================================================================

-- IMPORTANT:
-- 1. This migration is safe to run on existing databases
-- 2. Existing data will NOT be affected - only the column constraints change
-- 3. After running this migration, users can create cages with just a Cage ID
-- 4. The application will show information completeness indicators to encourage
--    users to fill in missing details
-- 5. Consider implementing data validation in the application layer to ensure
--    important fields are eventually filled in

-- ROLLBACK (if needed):
-- If you need to revert these changes, use the following commands:
-- NOTE: This will FAIL if any existing records have NULL values in these fields
-- You must populate all NULL values before running the rollback

-- ALTER TABLE holding MODIFY COLUMN dob date NOT NULL;
-- ALTER TABLE holding MODIFY COLUMN parent_cg varchar(255) NOT NULL;
-- ALTER TABLE breeding MODIFY COLUMN cross varchar(255) NOT NULL;
-- ALTER TABLE breeding MODIFY COLUMN male_id varchar(255) NOT NULL;
-- ALTER TABLE breeding MODIFY COLUMN female_id varchar(255) NOT NULL;
-- ALTER TABLE breeding MODIFY COLUMN male_dob date NOT NULL;
-- ALTER TABLE breeding MODIFY COLUMN female_dob date NOT NULL;
