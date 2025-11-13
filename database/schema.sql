CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(120) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','manager','player') NOT NULL DEFAULT 'player',
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    is_banned TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    email_verify_token VARCHAR(255) DEFAULT NULL,
    email_verify_expires DATETIME DEFAULT NULL,
    INDEX idx_user_active (is_active),
    INDEX idx_user_banned (is_banned)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tournaments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    type ENUM('single', 'double', 'round-robin') NOT NULL,
    description TEXT,
    status ENUM('draft', 'open', 'live', 'completed') NOT NULL DEFAULT 'draft',
    scheduled_at DATETIME DEFAULT NULL,
    location VARCHAR(255) DEFAULT NULL,
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    bracket_json JSON NULL,
    groups_json JSON NULL,
    settings JSON NULL,
    CONSTRAINT fk_tournaments_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tournament_players (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tournament_id INT NOT NULL,
    user_id INT NOT NULL,
    seed INT DEFAULT NULL,
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tournament_player (tournament_id, user_id),
    CONSTRAINT fk_tp_tournament FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
    CONSTRAINT fk_tp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tournament_matches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tournament_id INT NOT NULL,
    stage VARCHAR(50) NOT NULL,
    round INT NOT NULL,
    match_index INT NOT NULL,
    player1_user_id INT DEFAULT NULL,
    player2_user_id INT DEFAULT NULL,
    score1 INT DEFAULT NULL,
    score2 INT DEFAULT NULL,
    winner_user_id INT DEFAULT NULL,
    meta JSON NULL,
    CONSTRAINT fk_tm_tournament FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
    CONSTRAINT fk_tm_player1 FOREIGN KEY (player1_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_tm_player2 FOREIGN KEY (player2_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_tm_winner FOREIGN KEY (winner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_match (tournament_id, stage, round, match_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_stats (
    user_id INT PRIMARY KEY,
    tournaments_played INT NOT NULL DEFAULT 0,
    wins INT NOT NULL DEFAULT 0,
    losses INT NOT NULL DEFAULT 0,
    win_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    last_updated DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_stats_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS snapshots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tournament_id INT NOT NULL,
    snapshot_type VARCHAR(50) NOT NULL,
    payload JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT DEFAULT NULL,
    CONSTRAINT fk_snapshots_tournament FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
    CONSTRAINT fk_snapshots_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS store_products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    image_path VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_store_products_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS store_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    terminal_key VARCHAR(64) NOT NULL,
    opened_by INT NOT NULL,
    opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    starting_cash DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    closing_cash DECIMAL(10,2) DEFAULT NULL,
    closed_at DATETIME DEFAULT NULL,
    closed_by INT DEFAULT NULL,
    is_open TINYINT(1) NOT NULL DEFAULT 1,
    open_lock VARCHAR(64) DEFAULT NULL,
    CONSTRAINT fk_store_sessions_opened_by FOREIGN KEY (opened_by) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_store_sessions_closed_by FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_store_sessions_open_lock (open_lock),
    INDEX idx_store_sessions_terminal (terminal_key),
    INDEX idx_store_sessions_opened_at (opened_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS store_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    terminal_key VARCHAR(64) NOT NULL,
    created_by INT NOT NULL,
    total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_store_transactions_session FOREIGN KEY (session_id) REFERENCES store_sessions(id) ON DELETE CASCADE,
    CONSTRAINT fk_store_transactions_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_store_transactions_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS store_transaction_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT NOT NULL,
    product_id INT DEFAULT NULL,
    product_name VARCHAR(150) NOT NULL,
    product_price DECIMAL(10,2) NOT NULL,
    product_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    quantity INT NOT NULL DEFAULT 1,
    line_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    CONSTRAINT fk_store_items_transaction FOREIGN KEY (transaction_id) REFERENCES store_transactions(id) ON DELETE CASCADE,
    CONSTRAINT fk_store_items_product FOREIGN KEY (product_id) REFERENCES store_products(id) ON DELETE SET NULL,
    INDEX idx_store_items_transaction (transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
