ALTER TABLE users
    ADD COLUMN IF NOT EXISTS marketing_opt_out_at DATETIME NULL AFTER marketing_opt_in_at;
