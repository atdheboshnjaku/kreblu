-- Kreblu MySQL Initialization
-- This runs automatically on first docker compose up

-- Ensure the database uses utf8mb4
ALTER DATABASE kreblu CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create a separate test database for PHPUnit
CREATE DATABASE IF NOT EXISTS kreblu_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON kreblu_test.* TO 'kreblu'@'%';
FLUSH PRIVILEGES;
