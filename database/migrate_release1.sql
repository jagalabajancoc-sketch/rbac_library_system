-- A persistent token makes retries return the original desk receipt.
ALTER TABLE in_library_form ADD COLUMN IF NOT EXISTS IssueToken CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_desk_issue_token ON in_library_form (IssueToken);
CREATE INDEX IF NOT EXISTS idx_catalog_title ON book (IsArchived, Title);
-- Member verification fields used by the current Members page (older reconstructed schemas omitted them).
ALTER TABLE user_account
  ADD COLUMN IF NOT EXISTS VerifiedByUserID INT NULL,
  ADD COLUMN IF NOT EXISTS VerifiedAt DATETIME NULL;
