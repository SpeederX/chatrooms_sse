CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message TEXT NOT NULL,
    user_id VARCHAR(32) NOT NULL,
    timestamp DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS sessions (
    id VARCHAR(64) PRIMARY KEY,
    nickname VARCHAR(32) NOT NULL,
    cooldown_attempts INT NOT NULL DEFAULT 0,
    send_blocked_until DATETIME NULL,
    created_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    UNIQUE KEY uk_sessions_nickname (nickname)
);

CREATE TABLE IF NOT EXISTS stats (
    id TINYINT PRIMARY KEY DEFAULT 1,
    total_messages BIGINT NOT NULL DEFAULT 0,
    total_chars BIGINT NOT NULL DEFAULT 0,
    total_users BIGINT NOT NULL DEFAULT 0,
    CHECK (id = 1)
);

INSERT INTO stats (id) VALUES (1)
ON DUPLICATE KEY UPDATE id = id;

CREATE TABLE IF NOT EXISTS seen_users (
    nickname VARCHAR(32) PRIMARY KEY,
    first_seen_at DATETIME NOT NULL
);
