-- Optional SuiteMonitor schema. Run this file inside a dedicated monitor database.
-- The collector writes raw samples every minute; the panel reads raw data for
-- 24 hours and the rollups for longer ranges.

CREATE TABLE IF NOT EXISTS metrics (
    ts DATETIME NOT NULL,
    load1 DECIMAL(8,2) NOT NULL,
    load5 DECIMAL(8,2) NOT NULL,
    load15 DECIMAL(8,2) NOT NULL,
    cpu_pct TINYINT UNSIGNED NOT NULL,
    mem_total INT UNSIGNED NOT NULL,
    mem_used INT UNSIGNED NOT NULL,
    swap_total INT UNSIGNED NOT NULL,
    swap_used INT UNSIGNED NOT NULL,
    disk_total INT UNSIGNED NOT NULL,
    disk_used INT UNSIGNED NOT NULL,
    net_rx_kbps INT UNSIGNED NOT NULL,
    net_tx_kbps INT UNSIGNED NOT NULL,
    procs INT UNSIGNED NOT NULL,
    uptime_min INT UNSIGNED NOT NULL,
    PRIMARY KEY (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS site_checks (
    ts DATETIME NOT NULL,
    target VARCHAR(32) NOT NULL,
    http_code SMALLINT UNSIGNED NOT NULL,
    ttfb_ms INT UNSIGNED NOT NULL,
    PRIMARY KEY (ts, target),
    KEY idx_site_checks_target_ts (target, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS traffic_min (
    ts DATETIME NOT NULL,
    requests BIGINT UNSIGNED NOT NULL,
    bytes_kb BIGINT UNSIGNED NOT NULL,
    s2xx BIGINT UNSIGNED NOT NULL,
    s3xx BIGINT UNSIGNED NOT NULL,
    s4xx BIGINT UNSIGNED NOT NULL,
    s5xx BIGINT UNSIGNED NOT NULL,
    top_ips JSON NOT NULL,
    PRIMARY KEY (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Rollup tables use a compact bucket schema rather than the raw sample schema.
CREATE TABLE IF NOT EXISTS metrics_hourly (
    bucket DATETIME NOT NULL,
    samples SMALLINT UNSIGNED NOT NULL,
    cpu TINYINT UNSIGNED NOT NULL,
    l1 DECIMAL(8,2) NOT NULL,
    memp TINYINT UNSIGNED NOT NULL,
    swapp TINYINT UNSIGNED NOT NULL,
    rx INT UNSIGNED NOT NULL,
    tx INT UNSIGNED NOT NULL,
    PRIMARY KEY (bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS metrics_daily LIKE metrics_hourly;

CREATE TABLE IF NOT EXISTS traffic_hourly (
    bucket DATETIME NOT NULL,
    requests BIGINT UNSIGNED NOT NULL,
    bytes_kb BIGINT UNSIGNED NOT NULL,
    s2xx BIGINT UNSIGNED NOT NULL,
    s3xx BIGINT UNSIGNED NOT NULL,
    s4xx BIGINT UNSIGNED NOT NULL,
    s5xx BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS traffic_daily LIKE traffic_hourly;

-- Optional event stream used by the dashboard's 24-hour exception log.
-- A collector may populate this table from local files or journald.
CREATE TABLE IF NOT EXISTS log_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ts DATETIME NOT NULL,
    source VARCHAR(32) NOT NULL,
    level ENUM('info', 'warn', 'error') NOT NULL DEFAULT 'warn',
    message VARCHAR(500) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_log_events_ts (ts),
    KEY idx_log_events_source_ts (source, ts),
    KEY idx_log_events_level_ts (level, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
