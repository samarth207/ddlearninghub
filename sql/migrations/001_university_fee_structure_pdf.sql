-- Existing installations only. Fresh databases already get this column from schema.sql.
ALTER TABLE universities
    ADD COLUMN fee_structure_pdf VARCHAR(500) NULL AFTER logo_path;
