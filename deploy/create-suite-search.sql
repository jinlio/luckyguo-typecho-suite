-- Optional SuiteSearch schema. Replace typecho_ with your Typecho prefix.
CREATE TABLE IF NOT EXISTS typecho_suite_changequeue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cid INT UNSIGNED NOT NULL,
  op VARCHAR(8) NOT NULL,
  created_at DATETIME(6) NOT NULL,
  processed_at DATETIME(6) NULL,
  rebuild_batch_id VARCHAR(32) NULL,
  PRIMARY KEY (id), KEY idx_cid (cid), KEY idx_processed (processed_at), KEY idx_batch (rebuild_batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS typecho_suite_searchmeta (
  id INT UNSIGNED NOT NULL,
  search_index_version VARCHAR(32) NOT NULL,
  rebuild_batch_id VARCHAR(32) NULL,
  build_start DATETIME(6) NULL,
  build_end DATETIME(6) NULL,
  document_count INT UNSIGNED NULL,
  swap_task_uid BIGINT UNSIGNED NULL,
  rebuild_state ENUM('UNLOCKED','LOCKED','RECOVERY') NOT NULL DEFAULT 'UNLOCKED',
  rebuild_phase ENUM('IDLE','BUILD','FENCE','SWAP','POST_SWAP','ROLLBACK') NOT NULL DEFAULT 'IDLE',
  created_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS typecho_suite_rebuildtask (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  batch_id VARCHAR(32) NOT NULL,
  task_uid BIGINT UNSIGNED NULL,
  operation VARCHAR(16) NOT NULL,
  index_uid VARCHAR(128) NOT NULL,
  status VARCHAR(16) NOT NULL,
  submitted_at DATETIME(6) NOT NULL,
  finished_at DATETIME(6) NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_task_uid (task_uid), KEY idx_batch_status (batch_id, status), KEY idx_index_time (index_uid, submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO typecho_suite_searchmeta (id, search_index_version, rebuild_state, rebuild_phase, created_at)
VALUES (1, 'initial', 'UNLOCKED', 'IDLE', NOW(6))
ON DUPLICATE KEY UPDATE id = VALUES(id);
