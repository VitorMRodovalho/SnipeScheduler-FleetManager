-- v2.0.0: Store company info on reservations for badge display
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS company_name VARCHAR(255) DEFAULT NULL;
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS company_abbr VARCHAR(10) DEFAULT NULL;
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS company_color VARCHAR(10) DEFAULT NULL;
