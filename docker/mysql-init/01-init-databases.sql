-- Auto-create isolated databases per microservice (Database per Service Pattern)
-- 1. Auth & Identity Service Database
CREATE DATABASE IF NOT EXISTS `codebridge_auth` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 2. Operations Service Database (Modular Monolith: Catalog, Inventory, Sales, Finance)
CREATE DATABASE IF NOT EXISTS `codebridge_operations` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 3. Legacy / Fallback Database
CREATE DATABASE IF NOT EXISTS `codebridge` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Grant all privileges to root user across all databases
GRANT ALL PRIVILEGES ON *.* TO 'root'@'%';
FLUSH PRIVILEGES;
