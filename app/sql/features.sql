CREATE TABLE IF NOT EXISTS `dnsmgr_dns_snapshot` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `did` int unsigned NOT NULL,
  `uid` int unsigned NOT NULL DEFAULT 0,
  `domain` varchar(253) NOT NULL,
  `aid` int unsigned NOT NULL,
  `thirdid` varchar(255) NOT NULL DEFAULT '',
  `label` varchar(120) NOT NULL,
  `record_count` int unsigned NOT NULL,
  `records` longtext NOT NULL,
  `checksum` char(64) NOT NULL,
  `addtime` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `did_id` (`did`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `dnsmgr_cert_dns_lease` (
  `oid` int unsigned NOT NULL,
  `claim` char(64) NOT NULL,
  `did` int unsigned NOT NULL,
  `aid` int unsigned NOT NULL,
  `thirdid` varchar(255) NOT NULL,
  `domain` varchar(253) NOT NULL,
  `rr` varchar(253) NOT NULL,
  `type` varchar(16) NOT NULL,
  `value` text NOT NULL,
  `managed` tinyint unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`oid`,`claim`),
  KEY `claim` (`claim`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `dnsmgr_batch_task` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uid` int unsigned NOT NULL,
  `kid` int unsigned NOT NULL DEFAULT 0,
  `did` int unsigned NOT NULL,
  `actor` char(64) NOT NULL,
  `name` varchar(120) NOT NULL,
  `identity` text NOT NULL,
  `plan` longtext NOT NULL,
  `results` mediumtext NOT NULL,
  `total` int unsigned NOT NULL,
  `completed` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint unsigned NOT NULL DEFAULT 0,
  `cancel_requested` tinyint unsigned NOT NULL DEFAULT 0,
  `attempts` int unsigned NOT NULL DEFAULT 0,
  `error` varchar(1000) DEFAULT NULL,
  `snapshot_id` bigint unsigned DEFAULT NULL,
  `addtime` datetime NOT NULL,
  `updatetime` datetime NOT NULL,
  `expiretime` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `status_id` (`status`,`id`),
  KEY `owner` (`uid`,`kid`,`id`),
  KEY `did` (`did`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `dnsmgr_notification_job` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `dedup` char(64) NOT NULL,
  `event` char(64) NOT NULL,
  `channel` varchar(32) NOT NULL,
  `payload` mediumtext NOT NULL,
  `status` tinyint NOT NULL DEFAULT 0,
  `attempts` int unsigned NOT NULL DEFAULT 0,
  `nexttime` int unsigned NOT NULL,
  `addtime` int unsigned NOT NULL,
  `error` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `dedup` (`dedup`), KEY `pending` (`status`,`nexttime`), KEY `event` (`event`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `dnsmgr_api_nonce` (
  `id` char(64) NOT NULL, `expires` int unsigned NOT NULL,
  PRIMARY KEY (`id`), KEY `expires` (`expires`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `dnsmgr_api_receipt` (
  `id` char(64) NOT NULL, `digest` char(64) NOT NULL, `authorization` char(64) NOT NULL,
  `status` tinyint NOT NULL DEFAULT 0, `body` mediumtext, `http_code` int DEFAULT NULL,
  `expires` int unsigned NOT NULL, PRIMARY KEY (`id`), KEY `expires` (`expires`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `dnsmgr_dns_watch` (
  `did` int unsigned NOT NULL, `enabled` tinyint unsigned NOT NULL DEFAULT 1,
  `identity` char(64) NOT NULL, `checksum` char(64) NOT NULL, `baseline` longtext NOT NULL,
  `observed` char(64) DEFAULT NULL, `diff` mediumtext, `error` varchar(500),
  `interval_seconds` int unsigned NOT NULL DEFAULT 3600,
  `checked_at` int unsigned NOT NULL DEFAULT 0, `nexttime` int unsigned NOT NULL DEFAULT 0,
  `alert_signature` char(64) DEFAULT NULL,
  PRIMARY KEY (`did`), KEY `pending` (`enabled`,`nexttime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `dnsmgr_credential_health` (
  `kind` varchar(16) NOT NULL, `aid` int unsigned NOT NULL, `enabled` tinyint unsigned NOT NULL DEFAULT 0,
  `expires_at` datetime DEFAULT NULL, `rotated_at` datetime DEFAULT NULL, `rotation_days` int unsigned NOT NULL DEFAULT 90,
  `version` char(64) NOT NULL DEFAULT '', `status` varchar(16) NOT NULL DEFAULT 'unknown',
  `error` varchar(500), `checked_at` int unsigned NOT NULL DEFAULT 0, `nexttime` int unsigned NOT NULL DEFAULT 0,
  `alert_signature` char(64) DEFAULT NULL,
  PRIMARY KEY (`kind`,`aid`), KEY `pending` (`enabled`,`nexttime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
