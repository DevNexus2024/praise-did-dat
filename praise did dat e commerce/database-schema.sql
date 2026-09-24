-- Praise Did Dat e-commerce schema
-- Import this file in phpMyAdmin. It creates a dedicated MySQL database.
-- Compatible with MySQL 8.0+ and current XAMPP MariaDB releases.

CREATE DATABASE IF NOT EXISTS praise_did_dat
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE praise_did_dat;

CREATE TABLE IF NOT EXISTS customers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  first_name VARCHAR(80) NOT NULL,
  last_name VARCHAR(80) NULL,
  email VARCHAR(254) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  email_verified_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_customers_email (email),
  KEY idx_customers_created_at (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admins (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(254) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('owner', 'admin') NOT NULL DEFAULT 'admin',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admins_email (email)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS catalog_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind ENUM('product', 'service') NOT NULL,
  brand VARCHAR(32) NOT NULL DEFAULT 'praise',
  name VARCHAR(160) NOT NULL,
  category VARCHAR(80) NULL,
  description TEXT NOT NULL,
  price DECIMAL(10,2) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'SZL',
  image_url VARCHAR(2048) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_catalog_kind_active (kind, active),
  KEY idx_catalog_brand_kind_active (brand, kind, active),
  KEY idx_catalog_created_at (created_at),
  CONSTRAINT chk_catalog_price_nonnegative CHECK (price IS NULL OR price >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS catalog_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  catalog_item_id BIGINT UNSIGNED NOT NULL,
  event_type ENUM('view', 'click') NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_catalog_events_item_date (catalog_item_id, created_at),
  KEY idx_catalog_events_type_date (event_type, created_at),
  CONSTRAINT fk_catalog_events_item
    FOREIGN KEY (catalog_item_id) REFERENCES catalog_items (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_number VARCHAR(32) NOT NULL,
  customer_id BIGINT UNSIGNED NULL,
  customer_email VARCHAR(254) NOT NULL,
  customer_first_name VARCHAR(80) NOT NULL,
  customer_last_name VARCHAR(80) NULL,
  order_status ENUM('pending', 'confirmed', 'processing', 'completed', 'cancelled', 'refunded') NOT NULL DEFAULT 'pending',
  payment_status ENUM('unpaid', 'pending', 'paid', 'failed', 'refunded') NOT NULL DEFAULT 'unpaid',
  payment_method VARCHAR(40) NULL,
  payment_provider_reference VARCHAR(191) NULL,
  subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  shipping_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  currency CHAR(3) NOT NULL DEFAULT 'SZL',
  shipping_address_line1 VARCHAR(190) NULL,
  shipping_address_line2 VARCHAR(190) NULL,
  shipping_city VARCHAR(100) NULL,
  shipping_region VARCHAR(100) NULL,
  shipping_postal_code VARCHAR(30) NULL,
  shipping_country CHAR(2) NULL,
  customer_note TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_orders_order_number (order_number),
  KEY idx_orders_customer_created (customer_id, created_at),
  KEY idx_orders_status_created (order_status, created_at),
  KEY idx_orders_payment_status (payment_status),
  CONSTRAINT fk_orders_customer
    FOREIGN KEY (customer_id) REFERENCES customers (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT chk_orders_amounts_nonnegative CHECK (
    subtotal >= 0 AND shipping_amount >= 0 AND tax_amount >= 0 AND total_amount >= 0
  )
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  catalog_item_id BIGINT UNSIGNED NULL,
  item_kind ENUM('product', 'service') NOT NULL,
  item_name VARCHAR(160) NOT NULL,
  item_description TEXT NULL,
  quantity INT UNSIGNED NOT NULL DEFAULT 1,
  unit_price DECIMAL(10,2) NOT NULL,
  line_total DECIMAL(10,2) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_order_items_order (order_id),
  KEY idx_order_items_catalog (catalog_item_id),
  CONSTRAINT fk_order_items_order
    FOREIGN KEY (order_id) REFERENCES orders (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_order_items_catalog_item
    FOREIGN KEY (catalog_item_id) REFERENCES catalog_items (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT chk_order_items_quantity CHECK (quantity > 0),
  CONSTRAINT chk_order_items_amounts CHECK (unit_price >= 0 AND line_total >= 0)
) ENGINE=InnoDB;

