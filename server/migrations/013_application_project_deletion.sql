-- Keep a tombstone so a stale/offline client cannot recreate a deleted project.
ALTER TABLE application_projects
    ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL;
