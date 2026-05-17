CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(50) NOT NULL DEFAULT 'admin',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bots (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  username VARCHAR(190) DEFAULT NULL,
  token TEXT NOT NULL,
  webhook_secret VARCHAR(64) NOT NULL UNIQUE,
  status ENUM('active','paused','error') NOT NULL DEFAULT 'paused',
  welcome_text TEXT DEFAULT NULL,
  pay_text TEXT DEFAULT NULL,
  min_add INT NOT NULL DEFAULT 1,
  max_add INT NOT NULL DEFAULT 19,
  order_ttl_minutes INT NOT NULL DEFAULT 30,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bot_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bot_id INT NOT NULL,
  telegram_id BIGINT NOT NULL,
  username VARCHAR(190) DEFAULT NULL,
  first_name VARCHAR(190) DEFAULT NULL,
  last_name VARCHAR(190) DEFAULT NULL,
  state VARCHAR(80) DEFAULT NULL,
  state_data JSON DEFAULT NULL,
  last_seen_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_bot_tg (bot_id, telegram_id),
  KEY idx_bot_users_bot (bot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shop_cities (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bot_id INT NOT NULL,
  name VARCHAR(190) NOT NULL,
  sort_order INT NOT NULL DEFAULT 100,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cities_bot (bot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shop_districts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bot_id INT NOT NULL,
  city_id INT NOT NULL,
  name VARCHAR(190) NOT NULL,
  sort_order INT NOT NULL DEFAULT 100,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_districts_bot_city (bot_id, city_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shop_products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bot_id INT NOT NULL,
  name VARCHAR(190) NOT NULL,
  description TEXT DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 100,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_products_bot (bot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shop_product_variants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bot_id INT NOT NULL,
  product_id INT NOT NULL,
  name VARCHAR(190) NOT NULL,
  price INT NOT NULL,
  stock INT DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 100,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_variants_bot_product (bot_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shop_payment_methods (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bot_id INT NOT NULL,
  title VARCHAR(190) NOT NULL,
  type ENUM('card','sbp','bank') NOT NULL DEFAULT 'card',
  bank_name VARCHAR(190) DEFAULT NULL,
  card_number VARCHAR(80) DEFAULT NULL,
  recipient_name VARCHAR(190) DEFAULT NULL,
  sbp_phone VARCHAR(80) DEFAULT NULL,
  min_amount INT DEFAULT NULL,
  max_amount INT DEFAULT NULL,
  daily_limit INT DEFAULT NULL,
  is_online TINYINT(1) NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_methods_bot (bot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shop_orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bot_id INT NOT NULL,
  bot_user_id INT NOT NULL,
  telegram_id BIGINT NOT NULL,
  city_id INT NOT NULL,
  district_id INT NOT NULL,
  product_id INT NOT NULL,
  variant_id INT NOT NULL,
  payment_method_id INT DEFAULT NULL,
  base_amount INT NOT NULL,
  random_increment INT NOT NULL DEFAULT 0,
  final_amount INT NOT NULL,
  status ENUM('waiting_pay','paid','rejected','expired','cancelled') NOT NULL DEFAULT 'waiting_pay',
  expires_at DATETIME NOT NULL,
  paid_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_orders_bot_status (bot_id, status),
  KEY idx_orders_amount (bot_id, final_amount, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS telegram_updates (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  bot_id INT DEFAULT NULL,
  update_id BIGINT DEFAULT NULL,
  payload JSON NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tg_updates_bot (bot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admin_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT DEFAULT NULL,
  action VARCHAR(190) NOT NULL,
  details TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
