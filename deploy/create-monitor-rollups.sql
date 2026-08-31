-- Migration for monitor installations created before swap_total was collected.
-- New installations only need create-suite-monitor.sql. Run this once before
-- deploying the current collector, then rerun it to rebuild existing rollups.
SET @metrics_swap_total_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'metrics'
      AND column_name = 'swap_total'
);
SET @metrics_swap_total_sql := IF(
    @metrics_swap_total_exists = 0,
    'ALTER TABLE metrics ADD COLUMN swap_total INT UNSIGNED NOT NULL DEFAULT 0 AFTER mem_used',
    'SELECT 1'
);
PREPARE metrics_swap_total_stmt FROM @metrics_swap_total_sql;
EXECUTE metrics_swap_total_stmt;
DEALLOCATE PREPARE metrics_swap_total_stmt;

-- The incident collector reads the latest sample per target. Older monitor
-- databases may lack this index, making the legacy correlated query scan the
-- full site_checks table for every row.
SET @site_checks_index_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'site_checks'
      AND index_name = 'idx_site_checks_target_ts'
);
SET @site_checks_index_sql := IF(
    @site_checks_index_exists = 0,
    'ALTER TABLE site_checks ADD KEY idx_site_checks_target_ts (target, ts)',
    'SELECT 1'
);
PREPARE site_checks_index_stmt FROM @site_checks_index_sql;
EXECUTE site_checks_index_stmt;
DEALLOCATE PREPARE site_checks_index_stmt;

-- The collector refreshes rollups with INSERT ... SELECT from the raw tables.
-- Grant only the required tables in your own database and account. The exact
-- database/user names are intentionally omitted from this reusable example.

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

INSERT INTO metrics_hourly (bucket, samples, cpu, l1, memp, swapp, rx, tx)
SELECT DATE_FORMAT(ts, '%Y-%m-%d %H:00:00'), COUNT(*), MAX(cpu_pct), ROUND(AVG(load1), 2),
       ROUND(AVG(mem_used * 100.0 / GREATEST(mem_total, 1))),
       LEAST(100, GREATEST(0, ROUND(AVG(CASE WHEN swap_total > 0 THEN swap_used * 100.0 / swap_total ELSE 0 END)))),
       ROUND(AVG(net_rx_kbps)), ROUND(AVG(net_tx_kbps))
FROM metrics
GROUP BY DATE_FORMAT(ts, '%Y-%m-%d %H:00:00')
ON DUPLICATE KEY UPDATE
    samples = VALUES(samples), cpu = VALUES(cpu), l1 = VALUES(l1), memp = VALUES(memp),
    swapp = VALUES(swapp), rx = VALUES(rx), tx = VALUES(tx);

INSERT INTO metrics_daily (bucket, samples, cpu, l1, memp, swapp, rx, tx)
SELECT DATE(ts), COUNT(*), MAX(cpu_pct), ROUND(AVG(load1), 2),
       ROUND(AVG(mem_used * 100.0 / GREATEST(mem_total, 1))),
       LEAST(100, GREATEST(0, ROUND(AVG(CASE WHEN swap_total > 0 THEN swap_used * 100.0 / swap_total ELSE 0 END)))),
       ROUND(AVG(net_rx_kbps)), ROUND(AVG(net_tx_kbps))
FROM metrics
GROUP BY DATE(ts)
ON DUPLICATE KEY UPDATE
    samples = VALUES(samples), cpu = VALUES(cpu), l1 = VALUES(l1), memp = VALUES(memp),
    swapp = VALUES(swapp), rx = VALUES(rx), tx = VALUES(tx);

INSERT INTO traffic_hourly (bucket, requests, bytes_kb, s2xx, s3xx, s4xx, s5xx)
SELECT DATE_FORMAT(ts, '%Y-%m-%d %H:00:00'), SUM(requests), SUM(bytes_kb),
       SUM(s2xx), SUM(s3xx), SUM(s4xx), SUM(s5xx)
FROM traffic_min
GROUP BY DATE_FORMAT(ts, '%Y-%m-%d %H:00:00')
ON DUPLICATE KEY UPDATE
    requests = VALUES(requests), bytes_kb = VALUES(bytes_kb), s2xx = VALUES(s2xx), s3xx = VALUES(s3xx),
    s4xx = VALUES(s4xx), s5xx = VALUES(s5xx);

INSERT INTO traffic_daily (bucket, requests, bytes_kb, s2xx, s3xx, s4xx, s5xx)
SELECT DATE(ts), SUM(requests), SUM(bytes_kb), SUM(s2xx), SUM(s3xx), SUM(s4xx), SUM(s5xx)
FROM traffic_min
GROUP BY DATE(ts)
ON DUPLICATE KEY UPDATE
    requests = VALUES(requests), bytes_kb = VALUES(bytes_kb), s2xx = VALUES(s2xx), s3xx = VALUES(s3xx),
    s4xx = VALUES(s4xx), s5xx = VALUES(s5xx);
