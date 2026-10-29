-- Seed database for the reference compatibility tests (prefix wp_).
-- Works on MySQL 8.0 and MariaDB 10.11. wp_empty and wbk_bookings deliberately
-- have no COLLATE clause: MySQL 8 dumps them with utf8mb4_0900_ai_ci.

CREATE TABLE `wp_options` (
  `option_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `option_name` varchar(191) NOT NULL DEFAULT '',
  `option_value` longtext NOT NULL,
  `autoload` varchar(20) NOT NULL DEFAULT 'yes',
  PRIMARY KEY (`option_id`),
  UNIQUE KEY `option_name` (`option_name`),
  KEY `autoload` (`autoload`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
INSERT INTO `wp_options` (`option_id`, `option_name`, `option_value`, `autoload`) VALUES
(1,'siteurl','http://old.test','yes'),
(2,'home','http://old.test','yes'),
(3,'blogname','Árvíztűrő \"tükör\" fúró\'s \\ blog\n\r\Z','yes'),
(4,'wp_user_roles','a:1:{s:13:\"administrator\";a:2:{s:4:\"name\";s:13:\"Administrator\";s:12:\"capabilities\";a:1:{s:14:\"manage_options\";b:1;}}}','yes'),
(5,'active_plugins','a:2:{i:0;s:19:\"akismet/akismet.php\";i:1;s:9:\"hello.php\";}','yes'),
(6,'template','twentytwentyfour','yes'),
(7,'lw_nested','a:2:{s:5:\"image\";s:64:\"a:1:{s:3:\"url\";s:40:\"http://old.test/wp-content/uploads/a.jpg\";}\";s:5:\"width\";i:640;}','no'),
(8,'_transient_foo','bar','no');
CREATE TABLE `wp_users` (
  `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_login` varchar(60) NOT NULL DEFAULT '',
  `user_pass` varchar(255) NOT NULL DEFAULT '',
  `user_nicename` varchar(50) NOT NULL DEFAULT '',
  `user_email` varchar(100) NOT NULL DEFAULT '',
  `user_url` varchar(100) NOT NULL DEFAULT '',
  `user_registered` datetime NOT NULL DEFAULT '2000-01-01 00:00:00',
  `user_activation_key` varchar(255) NOT NULL DEFAULT '',
  `user_status` int(11) NOT NULL DEFAULT '0',
  `display_name` varchar(250) NOT NULL DEFAULT '',
  PRIMARY KEY (`ID`),
  KEY `user_login_key` (`user_login`),
  KEY `user_nicename` (`user_nicename`),
  KEY `user_email` (`user_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
INSERT INTO `wp_users` VALUES (1,'admin','$P$Bxxxxxxxxxxxxxxxxxxxxxxxxxxxxx.','admin','admin@old.test','http://old.test','2026-01-02 03:04:05','',0,'admin');
CREATE TABLE `wp_usermeta` (
  `umeta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `meta_key` varchar(255) DEFAULT NULL,
  `meta_value` longtext,
  PRIMARY KEY (`umeta_id`),
  KEY `user_id` (`user_id`),
  KEY `meta_key` (`meta_key`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
INSERT INTO `wp_usermeta` VALUES (1,1,'wp_capabilities','a:1:{s:13:\"administrator\";b:1;}'),
(2,1,'wp_user_level','10');
CREATE TABLE `wp_posts` (
  `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `post_author` bigint(20) unsigned NOT NULL DEFAULT '0',
  `post_date` datetime NOT NULL DEFAULT '2000-01-01 00:00:00',
  `post_content` longtext NOT NULL,
  `post_title` text NOT NULL,
  `post_status` varchar(20) NOT NULL DEFAULT 'publish',
  `post_name` varchar(200) NOT NULL DEFAULT '',
  `post_parent` bigint(20) unsigned NOT NULL DEFAULT '0',
  `guid` varchar(255) NOT NULL DEFAULT '',
  `post_type` varchar(20) NOT NULL DEFAULT 'post',
  PRIMARY KEY (`ID`),
  KEY `post_name` (`post_name`(191)),
  KEY `type_status_date` (`post_type`,`post_status`,`post_date`,`ID`),
  KEY `post_parent` (`post_parent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
INSERT INTO `wp_posts` VALUES
(1,1,'2026-01-02 03:04:05','<p>Hello <a href=\"http://old.test/hello-world/\">link</a></p>\n<img data-src=\'old.test/x\' src=\"http://old.test/wp-content/uploads/a.jpg\">','Hello world','publish','hello-world',0,'http://old.test/?p=1','post'),
(2,1,'2026-01-02 03:04:06','A page on http://old.test/about/','About','publish','about',0,'http://old.test/?page_id=2','page'),
(3,1,'2026-01-02 03:04:07','Revision text','Hello world','inherit','1-revision-v1',1,'http://old.test/?p=3','revision');
CREATE TABLE `wp_postmeta` (
  `meta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `meta_key` varchar(255) DEFAULT NULL,
  `meta_value` longtext,
  PRIMARY KEY (`meta_id`),
  KEY `post_id` (`post_id`),
  KEY `meta_key` (`meta_key`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
INSERT INTO `wp_postmeta` VALUES (1,1,'_lw_b64','YToyOntzOjM6InVybCI7czo0MDoiaHR0cDovL29sZC50ZXN0L3dwLWNvbnRlbnQvdXBsb2Fkcy9iLmpwZyI7czoxOiJuIjtpOjM7fQ=='),
(2,1,'_edit_lock',NULL);
CREATE TABLE `wp_big` (
  `id` int(11) NOT NULL,
  `label` varchar(32) NOT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
INSERT INTO `wp_big` (`id`, `label`, `amount`)
SELECT d1.n + 10 * d2.n + 100 * d3.n + 1000 * d4.n + 1, CONCAT('row-', d1.n + 10 * d2.n + 100 * d3.n + 1000 * d4.n + 1), (d1.n + 10 * d2.n) / 4
FROM (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS d1
CROSS JOIN (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS d2
CROSS JOIN (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS d3
CROSS JOIN (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS d4
WHERE d1.n + 10 * d2.n + 100 * d3.n + 1000 * d4.n < 2500;
CREATE TABLE `wp_nopk` (
  `pos` int(11) NOT NULL,
  `note` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
INSERT INTO `wp_nopk` VALUES (1,'first'),(2,NULL),(3,'third http://old.test');
CREATE TABLE `wp_bin` (
  `id` int(11) NOT NULL,
  `blob_value` longblob,
  `vb_value` varbinary(64) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
INSERT INTO `wp_bin` VALUES (1,0x00FF275C0A0D1A22E2,0x00DEADBEEF27),
(2,'',''),
(3,NULL,NULL);
CREATE TABLE `wp_empty` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `v` varchar(10) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `wbk_bookings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `day` date DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `wbk_bookings` VALUES (1,'Anna','2026-10-06'),(2,'Béla','2026-10-07');
CREATE VIEW `wp_v` AS SELECT `option_name`, `option_value` FROM `wp_options` WHERE `autoload` = 'yes';
