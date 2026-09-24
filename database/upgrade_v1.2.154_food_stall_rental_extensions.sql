-- TLH v1.2.154 - Food Stall Rental extension audit trail
-- Run only if the application's database user cannot CREATE TABLE automatically.

CREATE TABLE IF NOT EXISTS food_stall_rental_extensions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  rental_id BIGINT UNSIGNED NOT NULL,
  schedule_type VARCHAR(20) NOT NULL DEFAULT 'continuous',
  previous_end_date DATE NOT NULL,
  new_end_date DATE NOT NULL,
  previous_rental_amount DECIMAL(12,2) NOT NULL,
  new_rental_amount DECIMAL(12,2) NOT NULL,
  added_dates TEXT NULL,
  reason VARCHAR(1000) NOT NULL,
  extended_by INT UNSIGNED NULL,
  extended_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_food_stall_extension_rental (rental_id,extended_at),
  KEY idx_food_stall_extension_admin (extended_by),
  CONSTRAINT fk_food_stall_extension_rental FOREIGN KEY (rental_id) REFERENCES food_stall_rentals(id) ON DELETE CASCADE,
  CONSTRAINT fk_food_stall_extension_admin FOREIGN KEY (extended_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
