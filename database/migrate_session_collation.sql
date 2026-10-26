-- Shared string keys must use the same collation for desk-form joins.
ALTER TABLE in_library_use MODIFY SessionRef VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL;
ALTER TABLE in_library_form MODIFY FormNo VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL;
