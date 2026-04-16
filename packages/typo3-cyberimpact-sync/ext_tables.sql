CREATE TABLE tx_cyberimpactsync_run (
  uid INT AUTO_INCREMENT PRIMARY KEY,
  pid INT DEFAULT 0 NOT NULL,
  tstamp INT DEFAULT 0 NOT NULL,
  crdate INT DEFAULT 0 NOT NULL,
  status VARCHAR(32) DEFAULT 'queued' NOT NULL,
  exact_sync TINYINT(1) DEFAULT 0 NOT NULL,
  exact_sync_confirmed TINYINT(1) DEFAULT 0 NOT NULL,
  source_file_uid INT DEFAULT 0 NOT NULL,
  report_file_uid INT DEFAULT 0 NOT NULL,
  total_rows INT DEFAULT 0 NOT NULL,
  processed_rows INT DEFAULT 0 NOT NULL,
  upsert_ok INT DEFAULT 0 NOT NULL,
  upsert_failed INT DEFAULT 0 NOT NULL,
  unsubscribe_planned INT DEFAULT 0 NOT NULL,
  unsubscribe_done INT DEFAULT 0 NOT NULL,
  unsubscribe_failed INT DEFAULT 0 NOT NULL
);

CREATE INDEX idx_cyberimpactsync_run_status_crdate ON tx_cyberimpactsync_run (status, crdate);

CREATE TABLE tx_cyberimpactsync_chunk (
  uid INT AUTO_INCREMENT PRIMARY KEY,
  pid INT DEFAULT 0 NOT NULL,
  tstamp INT DEFAULT 0 NOT NULL,
  crdate INT DEFAULT 0 NOT NULL,
  run_uid INT DEFAULT 0 NOT NULL,
  chunk_index INT DEFAULT 0 NOT NULL,
  status VARCHAR(32) DEFAULT 'pending' NOT NULL,
  attempt_count INT DEFAULT 0 NOT NULL,
  payload_file_uid INT DEFAULT 0 NOT NULL,
  payload_json MEDIUMTEXT
);

CREATE INDEX idx_cyberimpactsync_chunk_run_status_idx ON tx_cyberimpactsync_chunk (run_uid, status, chunk_index);

CREATE TABLE tx_cyberimpactsync_error (
  uid INT AUTO_INCREMENT PRIMARY KEY,
  pid INT DEFAULT 0 NOT NULL,
  tstamp INT DEFAULT 0 NOT NULL,
  crdate INT DEFAULT 0 NOT NULL,
  run_uid INT DEFAULT 0 NOT NULL,
  chunk_uid INT DEFAULT 0 NOT NULL,
  stage VARCHAR(32) DEFAULT 'parse' NOT NULL,
  code VARCHAR(64) DEFAULT '' NOT NULL,
  message TEXT,
  payload TEXT
);

CREATE INDEX idx_cyberimpactsync_error_run_stage_crdate ON tx_cyberimpactsync_error (run_uid, stage, crdate);

CREATE TABLE tx_cyberimpactsync_import_settings (
  uid INT AUTO_INCREMENT PRIMARY KEY,
  pid INT DEFAULT 0 NOT NULL,
  tstamp INT DEFAULT 0 NOT NULL,
  crdate INT DEFAULT 0 NOT NULL,
  source_path VARCHAR(255) DEFAULT 'incoming/' NOT NULL,
  archive_path VARCHAR(255) DEFAULT 'archive/' NOT NULL,
  error_path VARCHAR(255) DEFAULT 'errors/' NOT NULL,
  cron_enabled TINYINT(1) DEFAULT 0 NOT NULL,
  cron_mode VARCHAR(16) DEFAULT 'preset' NOT NULL,
  cron_preset VARCHAR(32) DEFAULT 'every15' NOT NULL,
  cron_daily_time VARCHAR(5) DEFAULT '09:00' NOT NULL,
  cron_expression VARCHAR(255),
  cyberimpact_token TEXT,
  cyberimpact_ping VARCHAR(64),
  cyberimpact_username VARCHAR(255),
  cyberimpact_email VARCHAR(255),
  cyberimpact_account VARCHAR(255),
  cyberimpact_ping_checked_at INT,
  column_mapping LONGTEXT,
  selected_group_id INT DEFAULT 0,
  missing_contacts_action VARCHAR(16) DEFAULT 'unsubscribe' NOT NULL,
  default_consent_proof VARCHAR(255)
);

CREATE INDEX idx_cyberimpactsync_import_settings_pid ON tx_cyberimpactsync_import_settings (pid);
