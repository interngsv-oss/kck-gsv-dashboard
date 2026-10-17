-- Normal relational schema (real typed columns per field) - replaces the
-- earlier JSON-blob-per-row design. One table per dataset, matching the
-- exact fields parsers.php produces; KEY_FIELDS (see storage.php) are each
-- table's PRIMARY KEY, so the database itself enforces uniqueness instead
-- of an application-level row_key string.

CREATE TABLE IF NOT EXISTS bills (
    branch VARCHAR(64) NOT NULL,
    bill VARCHAR(128) NOT NULL,
    date DATE NOT NULL,
    hour TINYINT NOT NULL,
    covers INT NOT NULL,
    salesValue DECIMAL(14,2) NOT NULL,
    serviceType VARCHAR(32) NOT NULL,
    PRIMARY KEY (branch, bill, date)
);
CREATE INDEX bills_date_idx ON bills (date);

CREATE TABLE IF NOT EXISTS sales (
    branch VARCHAR(64) NOT NULL,
    date DATE NOT NULL,
    category VARCHAR(128) NOT NULL,
    item VARCHAR(255) NOT NULL,
    qty DECIMAL(12,2) NOT NULL,
    rate DECIMAL(12,2) NOT NULL,
    value DECIMAL(14,2) NOT NULL,
    PRIMARY KEY (branch, date, category, item)
);
CREATE INDEX sales_date_idx ON sales (date);

CREATE TABLE IF NOT EXISTS discounts (
    branch VARCHAR(64) NOT NULL,
    bill VARCHAR(128) NOT NULL,
    date DATE NOT NULL,
    lineSeq INT NOT NULL,
    itemValue DECIMAL(14,2) NOT NULL,
    discountValue DECIMAL(14,2) NOT NULL,
    net DECIMAL(14,2) NOT NULL,
    isFull TINYINT(1) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    type VARCHAR(64) NULL,
    remarks VARCHAR(255) NULL,
    PRIMARY KEY (branch, bill, date, lineSeq)
);
CREATE INDEX discounts_date_idx ON discounts (date);

CREATE TABLE IF NOT EXISTS cancellations (
    branch VARCHAR(64) NOT NULL,
    bill VARCHAR(128) NOT NULL,
    date DATE NOT NULL,
    item VARCHAR(255) NOT NULL,
    reason VARCHAR(64) NOT NULL,
    qty DECIMAL(12,2) NOT NULL,
    rate DECIMAL(12,2) NOT NULL,
    value DECIMAL(14,2) NOT NULL,
    PRIMARY KEY (branch, bill, date, item, reason)
);
CREATE INDEX cancellations_date_idx ON cancellations (date);

-- Single-row table for the 3 scalar meta fields that actually change
-- (dataStart/dataEnd/lastRefreshed). "reasons" and "serviceTypes" are fixed
-- constants never mutated by any code path, so they're not stored at all -
-- kck_read_meta() returns them as PHP constants. "discountReasons" is the
-- one meta list that genuinely changes (grows from uploaded data), so it
-- gets its own one-column table instead.
-- Named app_meta/app_upload_history* (not meta/upload_history) solely to
-- avoid colliding with this account's older, differently-shaped tables of
-- those exact names - nothing else about the naming is significant.
CREATE TABLE IF NOT EXISTS app_meta (
    id INT PRIMARY KEY DEFAULT 1,
    dataStart DATE NULL,
    dataEnd DATE NULL,
    lastRefreshed VARCHAR(32) NULL
);

CREATE TABLE IF NOT EXISTS discount_reasons (
    reason VARCHAR(255) PRIMARY KEY
);

CREATE TABLE IF NOT EXISTS app_upload_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    timestamp VARCHAR(32) NOT NULL
);

-- Also "app_"-prefixed: an earlier, abandoned migration attempt already
-- created tables named upload_history_files/_months/_missing/_counts with
-- their foreign key pointing at the wrong parent table name, and altering
-- an existing live table was deliberately avoided - these fresh names just
-- sidestep that leftover rather than touching it.
CREATE TABLE IF NOT EXISTS app_upload_history_files (
    history_id INT NOT NULL,
    filename VARCHAR(255) NOT NULL,
    FOREIGN KEY (history_id) REFERENCES app_upload_history(id) ON DELETE CASCADE
);
CREATE INDEX uh_files_idx ON app_upload_history_files (history_id);

CREATE TABLE IF NOT EXISTS app_upload_history_months (
    history_id INT NOT NULL,
    month VARCHAR(7) NOT NULL,
    FOREIGN KEY (history_id) REFERENCES app_upload_history(id) ON DELETE CASCADE
);
CREATE INDEX uh_months_idx ON app_upload_history_months (history_id);

CREATE TABLE IF NOT EXISTS app_upload_history_missing (
    history_id INT NOT NULL,
    description VARCHAR(255) NOT NULL,
    FOREIGN KEY (history_id) REFERENCES app_upload_history(id) ON DELETE CASCADE
);
CREATE INDEX uh_missing_idx ON app_upload_history_missing (history_id);

CREATE TABLE IF NOT EXISTS app_upload_history_counts (
    history_id INT NOT NULL,
    dataset VARCHAR(32) NOT NULL,
    month VARCHAR(7) NOT NULL,
    added INT NOT NULL,
    updated INT NOT NULL,
    FOREIGN KEY (history_id) REFERENCES app_upload_history(id) ON DELETE CASCADE
);
CREATE INDEX uh_counts_idx ON app_upload_history_counts (history_id);

-- Audit trail and automatic pre-destructive-action backups, kept in the
-- database so they survive redeploys and aren't only in the PHP error log.
CREATE TABLE IF NOT EXISTS app_audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ts VARCHAR(32) NOT NULL,
    message VARCHAR(1000) NOT NULL
);

CREATE TABLE IF NOT EXISTS app_backups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    created VARCHAR(32) NOT NULL,
    label VARCHAR(255) NOT NULL,
    data LONGTEXT NOT NULL
);
