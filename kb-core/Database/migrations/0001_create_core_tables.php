<?php declare(strict_types=1);

/**
 * Migration: Create core Kreblu tables
 *
 * All tables use InnoDB, utf8mb4_unicode_ci, and BIGINT UNSIGNED for IDs.
 * JSON columns replace the WordPress postmeta EAV pattern.
 * FULLTEXT indexes provide built-in search without plugins.
 * Foreign keys enforce referential integrity.
 *
 * Tables are created in dependency order (referenced tables first).
 */

use Kreblu\Core\Database\Connection;

return [

	'up' => function (Connection $db): void {
		$prefix = $db->prefix();

		// -- Users (no dependencies) --
		$db->execute("
			CREATE TABLE IF NOT EXISTS {$prefix}users (
				id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				email           VARCHAR(255) NOT NULL,
				username        VARCHAR(100) NOT NULL,
				password_hash   VARCHAR(255) NOT NULL,
				display_name    VARCHAR(200) NOT NULL DEFAULT '',
				role            VARCHAR(50) NOT NULL DEFAULT 'subscriber',
				status          VARCHAR(20) NOT NULL DEFAULT 'active',
				meta            JSON DEFAULT NULL,
				two_factor_secret VARCHAR(255) DEFAULT NULL,
				last_login      DATETIME DEFAULT NULL,
				created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

				UNIQUE KEY idx_email (email),
				UNIQUE KEY idx_username (username),
				INDEX idx_role (role),
				INDEX idx_status (status)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		");

		// -- Sessions (depends on users) --
		$db->execute("
			CREATE TABLE IF NOT EXISTS {$prefix}sessions (
				id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				user_id         BIGINT UNSIGNED NOT NULL,
				token_hash      VARCHAR(255) NOT NULL,
				ip_address      VARCHAR(45) DEFAULT NULL,
				user_agent      VARCHAR(500) DEFAULT NULL,
				expires_at      DATETIME NOT NULL,
				created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

				INDEX idx_token (token_hash),
				INDEX idx_user (user_id),
				INDEX idx_expires (expires_at),
				FOREIGN KEY (user_id) REFERENCES {$prefix}users(id) ON DELETE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		");

		// -- Media (depends on users) --
		$db->execute("
			CREATE TABLE IF NOT EXISTS {$prefix}media (
				id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				user_id         BIGINT UNSIGNED NOT NULL,
				filename        VARCHAR(500) NOT NULL,
				filepath        VARCHAR(1000) NOT NULL,
				mime_type       VARCHAR(100) NOT NULL,
				file_size       BIGINT UNSIGNED NOT NULL DEFAULT 0,
				width           INT UNSIGNED DEFAULT NULL,
				height          INT UNSIGNED DEFAULT NULL,
				alt_text        VARCHAR(500) NOT NULL DEFAULT '',
				title           VARCHAR(500) NOT NULL DEFAULT '',
				caption         TEXT DEFAULT NULL,
				meta            JSON DEFAULT NULL,
				created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

				INDEX idx_user (user_id),
				INDEX idx_mime (mime_type),
				INDEX idx_created (created_at),
				FOREIGN KEY (user_id) REFERENCES {$prefix}users(id) ON DELETE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		");

		// -- Posts (depends on users, media) --
		$db->execute("
			CREATE TABLE IF NOT EXISTS {$prefix}posts (
				id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				type            VARCHAR(32) NOT NULL DEFAULT 'post',
				status          VARCHAR(20) NOT NULL DEFAULT 'draft',
				title           VARCHAR(500) NOT NULL DEFAULT '',
				slug            VARCHAR(500) NOT NULL DEFAULT '',
				body            LONGTEXT DEFAULT NULL,
				body_raw        LONGTEXT DEFAULT NULL,
				excerpt         TEXT DEFAULT NULL,
				author_id       BIGINT UNSIGNED NOT NULL,
				parent_id       BIGINT UNSIGNED DEFAULT NULL,
				menu_order      INT NOT NULL DEFAULT 0,
				comment_status  VARCHAR(20) NOT NULL DEFAULT 'open',
				meta            JSON DEFAULT NULL,
				featured_image  BIGINT UNSIGNED DEFAULT NULL,
				password        VARCHAR(255) DEFAULT NULL,
				published_at    DATETIME DEFAULT NULL,
				created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

				INDEX idx_type_status (type, status),
				INDEX idx_author (author_id),
				INDEX idx_parent (parent_id),
				INDEX idx_slug (slug(191)),
				INDEX idx_published (published_at),
				INDEX idx_type_status_date (type, status, published_at),
				FULLTEXT idx_search (title, body),

				FOREIGN KEY (author_id) REFERENCES {$prefix}users(id) ON DELETE CASCADE,
				FOREIGN KEY (featured_image) REFERENCES {$prefix}media(id) ON DELETE SET NULL
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		");

		// -- Revisions (depends on posts, users) --
		$db->execute("
			CREATE TABLE IF NOT EXISTS {$prefix}revisions (
				id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				post_id         BIGINT UNSIGNED NOT NULL,
				author_id       BIGINT UNSIGNED NOT NULL,
				title           VARCHAR(500) DEFAULT NULL,
				body            LONGTEXT DEFAULT NULL,
				body_raw        LONGTEXT DEFAULT NULL,
				meta            JSON DEFAULT NULL,
				created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

				INDEX idx_post (post_id),
				INDEX idx_created (created_at),
				FOREIGN KEY (post_id) REFERENCES {$prefix}posts(id) ON DELETE CASCADE,
				FOREIGN KEY (author_id) REFERENCES {$prefix}users(id) ON DELETE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		");

		// -- Terms (self-referencing for hierarchy) --
		$db->execute("
			CREATE TABLE IF NOT EXISTS {$prefix}terms (
				id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				taxonomy        VARCHAR(64) NOT NULL,
				name            VARCHAR(255) NOT NULL,
				slug            VARCHAR(255) NOT NULL,
				description     TEXT DEFAULT NULL,
				parent_id       BIGINT UNSIGNED DEFAULT NULL,
				meta            JSON DEFAULT NULL,
				count           INT UNSIGNED NOT NULL DEFAULT 0,
				sort_order      INT NOT NULL DEFAULT 0,

				INDEX idx_taxonomy (taxonomy),
				INDEX idx_slug (slug(191)),
				INDEX idx_parent (parent_id),
				UNIQUE KEY idx_tax_slug (taxonomy, slug(191)),
				FOREIGN KEY (parent_id) REFERENCES {$prefix}terms(id) ON DELETE SET NULL
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		");

		// -- Term Relationships (depends on posts, terms) --
		$db->execute("
			CREATE TABLE IF NOT EXISTS {$prefix}term_relationships (
				post_id         BIGINT UNSIGNED NOT NULL,
				term_id         BIGINT UNSIGNED NOT NULL,
				sort_order      INT NOT NULL DEFAULT 0,

				PRIMARY KEY (post_id, term_id),
				INDEX idx_term (term_id),
				FOREIGN KEY (post_id) REFERENCES {$prefix}posts(id) ON DELETE CASCADE,
				FOREIGN KEY (term_id) REFERENCES {$prefix}terms(id) ON DELETE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		");

		// -- Comments (depends on posts, users) --
		$db->execute("
			CREATE TABLE IF NOT EXISTS {$prefix}comments (
				id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				post_id         BIGINT UNSIGNED NOT NULL,
				parent_id       BIGINT UNSIGNED DEFAULT NULL,
				user_id         BIGINT UNSIGNED DEFAULT NULL,
				author_name     VARCHAR(200) DEFAULT NULL,
				author_email    VARCHAR(255) DEFAULT NULL,
				author_url      VARCHAR(500) DEFAULT NULL,
				author_ip       VARCHAR(45) DEFAULT NULL,
				body            TEXT NOT NULL,
				status          VARCHAR(20) NOT NULL DEFAULT 'pending',
				meta            JSON DEFAULT NULL,
				created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

				INDEX idx_post_status (post_id, status),
				INDEX idx_parent (parent_id),
				INDEX idx_created (created_at),
				FOREIGN KEY (post_id) REFERENCES {$prefix}posts(id) ON DELETE CASCADE,
				FOREIGN KEY (parent_id) REFERENCES {$prefix}comments(id) ON DELETE CASCADE,
				FOREIGN KEY (user_id) REFERENCES {$prefix}users(id) ON DELETE SET NULL
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		");

		// -- Menus (no dependencies) --
		$db->execute("
			CREATE TABLE IF NOT EXISTS {$prefix}menus (
				id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				name            VARCHAR(200) NOT NULL,
				slug            VARCHAR(200) NOT NULL,
				location        VARCHAR(100) DEFAULT NULL,
				description     VARCHAR(500) NOT NULL DEFAULT '',
				created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

				UNIQUE KEY idx_slug (slug),
				INDEX idx_location (location)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		");

		// -- Menu Items (depends on menus) --
		$db->execute("
			CREATE TABLE IF NOT EXISTS {$prefix}menu_items (
				id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				menu_id         BIGINT UNSIGNED NOT NULL,
				parent_id       BIGINT UNSIGNED DEFAULT NULL,
				type            VARCHAR(50) NOT NULL DEFAULT 'custom',
				label           VARCHAR(500) NOT NULL,
				url             VARCHAR(1000) NOT NULL DEFAULT '',
				target          VARCHAR(20) NOT NULL DEFAULT '_self',
				object_type     VARCHAR(50) DEFAULT NULL,
				object_id       BIGINT UNSIGNED DEFAULT NULL,
				css_classes     VARCHAR(500) NOT NULL DEFAULT '',
				sort_order      INT NOT NULL DEFAULT 0,
				meta            JSON DEFAULT NULL,

				INDEX idx_menu (menu_id),
				INDEX idx_parent (parent_id),
				INDEX idx_sort (menu_id, sort_order),
				FOREIGN KEY (menu_id) REFERENCES {$prefix}menus(id) ON DELETE CASCADE,
				FOREIGN KEY (parent_id) REFERENCES {$prefix}menu_items(id) ON DELETE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		");

		// -- Options (site settings, no dependencies) --
		$db->execute("
			CREATE TABLE IF NOT EXISTS {$prefix}options (
				id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				option_key      VARCHAR(191) NOT NULL,
				option_value    LONGTEXT DEFAULT NULL,
				autoload        TINYINT(1) NOT NULL DEFAULT 1,

				UNIQUE KEY idx_key (option_key)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		");

		// -- Redirects (for SEO module, no dependencies) --
		$db->execute("
			CREATE TABLE IF NOT EXISTS {$prefix}redirects (
				id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				source_path     VARCHAR(500) NOT NULL,
				target_path     VARCHAR(500) NOT NULL,
				redirect_type   SMALLINT UNSIGNED NOT NULL DEFAULT 301,
				hit_count       INT UNSIGNED NOT NULL DEFAULT 0,
				created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

				INDEX idx_source (source_path(191))
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		");
	},

	'down' => function (Connection $db): void {
		$prefix = $db->prefix();

		// Drop in reverse dependency order
		$tables = [
			'menu_items',
			'menus',
			'redirects',
			'options',
			'comments',
			'term_relationships',
			'terms',
			'revisions',
			'posts',
			'media',
			'sessions',
			'users',
		];

		// Disable foreign key checks for clean teardown
		$db->execute('SET FOREIGN_KEY_CHECKS = 0');

		foreach ($tables as $table) {
			$db->execute("DROP TABLE IF EXISTS {$prefix}{$table}");
		}

		$db->execute('SET FOREIGN_KEY_CHECKS = 1');
	},

];
