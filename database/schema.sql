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
  support_text TEXT DEFAULT NULL,
  pay_text TEXT DEFAULT NULL,
  card_pay_enabled TINYINT(1) NOT NULL DEFAULT 1,
  btc_pay_enabled TINYINT(1) NOT NULL DEFAULT 0,
  balance_pay_enabled TINYINT(1) NOT NULL DEFAULT 1,
  referral_enabled TINYINT(1) NOT NULL DEFAULT 1,
  referral_percent DECIMAL(8,2) NOT NULL DEFAULT 5.00,
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
  referrer_bot_user_id INT DEFAULT NULL,
  balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  state VARCHAR(80) DEFAULT NULL,
  state_data JSON DEFAULT NULL,
  last_seen_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_bot_tg (bot_id, telegram_id),
  KEY idx_bot_users_bot (bot_id),
  KEY idx_bot_users_referrer (referrer_bot_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS balance_ledger (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  bot_id INT NOT NULL,
  bot_user_id INT NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  direction ENUM('credit','debit') NOT NULL,
  source ENUM('referral','topup','refund','order','manual') NOT NULL,
  order_id INT DEFAULT NULL,
  comment TEXT DEFAULT NULL,
  created_by INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_balance_user (bot_id, bot_user_id),
  KEY idx_balance_order (order_id)
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
  photo_file_id VARCHAR(255) DEFAULT NULL,
  photo_path VARCHAR(255) DEFAULT NULL,
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
  price DECIMAL(12,2) NOT NULL,
  stock INT DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 100,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_variants_bot_product (bot_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shop_stock_items (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  bot_id INT NOT NULL,
  product_id INT NOT NULL,
  variant_id INT NOT NULL,
  city_id INT DEFAULT NULL,
  district_id INT DEFAULT NULL,
  title VARCHAR(190) DEFAULT NULL,
  delivery_text MEDIUMTEXT DEFAULT NULL,
  photo_file_id VARCHAR(255) DEFAULT NULL,
  file_id VARCHAR(255) DEFAULT NULL,
  status ENUM('available','reserved','delivered','disabled') NOT NULL DEFAULT 'available',
  reserved_order_id INT DEFAULT NULL,
  delivered_order_id INT DEFAULT NULL,
  reserved_at DATETIME DEFAULT NULL,
  delivered_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_stock_lookup (bot_id, variant_id, city_id, district_id, status),
  KEY idx_stock_orders (reserved_order_id, delivered_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shop_payment_methods (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bot_id INT NOT NULL,
  title VARCHAR(190) NOT NULL,
  type ENUM('card','sbp','bank','bitcoin') NOT NULL DEFAULT 'card',
  bank_name VARCHAR(190) DEFAULT NULL,
  card_number VARCHAR(80) DEFAULT NULL,
  recipient_name VARCHAR(190) DEFAULT NULL,
  sbp_phone VARCHAR(80) DEFAULT NULL,
  btc_address VARCHAR(190) DEFAULT NULL,
  min_amount DECIMAL(12,2) DEFAULT NULL,
  max_amount DECIMAL(12,2) DEFAULT NULL,
  daily_limit DECIMAL(12,2) DEFAULT NULL,
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
  stock_item_id BIGINT DEFAULT NULL,
  payment_method_id INT DEFAULT NULL,
  payment_type ENUM('balance','card','bitcoin') DEFAULT NULL,
  base_amount DECIMAL(12,2) NOT NULL,
  random_increment DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  final_amount DECIMAL(12,2) NOT NULL,
  status ENUM('draft','waiting_payment','payment_review','paid','delivered','cancelled','refunded','expired','rejected') NOT NULL DEFAULT 'waiting_payment',
  expires_at DATETIME NOT NULL,
  paid_at DATETIME DEFAULT NULL,
  delivered_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_orders_bot_status (bot_id, status),
  KEY idx_orders_amount (bot_id, final_amount, status),
  KEY idx_orders_user (bot_id, bot_user_id),
  KEY idx_orders_stock (stock_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shop_payments (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  bot_id INT NOT NULL,
  order_id INT NOT NULL,
  bot_user_id INT NOT NULL,
  method_id INT DEFAULT NULL,
  type ENUM('balance','card','bitcoin') NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  status ENUM('created','review','confirmed','rejected','cancelled') NOT NULL DEFAULT 'created',
  external_hash VARCHAR(255) DEFAULT NULL,
  operator_comment TEXT DEFAULT NULL,
  confirmed_by INT DEFAULT NULL,
  confirmed_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_payments_order (order_id),
  KEY idx_payments_status (bot_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS support_tickets (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  bot_id INT NOT NULL,
  bot_user_id INT NOT NULL,
  status ENUM('open','answered','closed') NOT NULL DEFAULT 'open',
  subject VARCHAR(190) DEFAULT NULL,
  last_message TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_support_bot_status (bot_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS telegram_updates (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  bot_id INT DEFAULT NULL,
  update_id BIGINT DEFAULT NULL,
  payload JSON NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tg_updates_bot (bot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  bot_id INT DEFAULT NULL,
  user_id INT DEFAULT NULL,
  bot_user_id INT DEFAULT NULL,
  order_id INT DEFAULT NULL,
  action VARCHAR(190) NOT NULL,
  details TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_bot (bot_id),
  KEY idx_audit_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admin_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT DEFAULT NULL,
  action VARCHAR(190) NOT NULL,
  details TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
