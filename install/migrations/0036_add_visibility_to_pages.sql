ALTER TABLE {prefix}pages ADD COLUMN visibility VARCHAR(20) NOT NULL DEFAULT 'public' AFTER status;
