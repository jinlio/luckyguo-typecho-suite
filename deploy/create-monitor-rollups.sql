-- Pre-aggregated monitor history for the 7-day, 30-day, and 1-year panels.
-- Safe to run repeatedly. The collector refreshes the current hour and day.

-- The collector refreshes rollups with INSERT ... SELECT from the raw tables.
-- Keep this access scoped to the monitoring schema and source tables only.
GRANT SELECT ON luckyguo_monitor.metrics TO 'luckyguo_monitor_rw'@'localhost';
GRANT SELECT ON luckyguo_monitor.traffic_min TO 'luckyguo_monitor_rw'@'localhost';

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
       ROUND(AVG(mem_used * 100.0 / GREATEST(mem_total, 1))), ROUND(AVG(swap_used * 100.0 / 4095.0)),
       ROUND(AVG(net_rx_kbps)), ROUND(AVG(net_tx_kbps))
FROM metrics
GROUP BY DATE_FORMAT(ts, '%Y-%m-%d %H:00:00')
ON DUPLICATE KEY UPDATE
    samples = VALUES(samples), cpu = VALUES(cpu), l1 = VALUES(l1), memp = VALUES(memp),
    swapp = VALUES(swapp), rx = VALUES(rx), tx = VALUES(tx);

INSERT INTO metrics_daily (bucket, samples, cpu, l1, memp, swapp, rx, tx)
SELECT DATE(ts), COUNT(*), MAX(cpu_pct), ROUND(AVG(load1), 2),
       ROUND(AVG(mem_used * 100.0 / GREATEST(mem_total, 1))), ROUND(AVG(swap_used * 100.0 / 4095.0)),
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
