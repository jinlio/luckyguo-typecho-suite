-- Optional anonymous statistics tables. Replace typecho_ with your Typecho prefix.
CREATE TABLE IF NOT EXISTS typecho_suite_visits (
  vday DATE NOT NULL,
  bucket TINYINT UNSIGNED NOT NULL,
  pv BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (vday, bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS typecho_suite_views (
  cid INT UNSIGNED NOT NULL,
  bucket TINYINT UNSIGNED NOT NULL,
  views BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (cid, bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS typecho_suite_visitors (
  vday DATE NOT NULL,
  vip VARCHAR(64) NOT NULL COMMENT 'HMAC-SHA256 visitor identifier; never store a raw IP',
  ua VARCHAR(250) NOT NULL DEFAULT '' COMMENT 'Deprecated compatibility column; keep empty',
  first_seen DATETIME NOT NULL,
  last_seen DATETIME NOT NULL,
  PRIMARY KEY (vday, vip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS typecho_suite_visitors_daily (
  vday DATE NOT NULL,
  uv INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (vday)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
