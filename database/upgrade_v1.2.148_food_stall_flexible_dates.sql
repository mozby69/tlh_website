-- TLH v1.2.148 - Flexible dates for Food Stall Rentals
-- Existing rentals remain continuous.

ALTER TABLE food_stall_rentals
  ADD COLUMN schedule_type VARCHAR(20) NOT NULL DEFAULT 'continuous' AFTER organization;

CREATE TABLE IF NOT EXISTS food_stall_rental_dates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  rental_id BIGINT UNSIGNED NOT NULL,
  rental_date DATE NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
  released_at DATETIME NULL,
  released_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_food_stall_rental_date (rental_id,rental_date),
  KEY idx_food_stall_rental_dates_date (rental_date,status),
  KEY idx_food_stall_rental_dates_rental (rental_id,status,rental_date),
  CONSTRAINT fk_food_stall_rental_date_rental FOREIGN KEY (rental_id) REFERENCES food_stall_rentals(id) ON DELETE CASCADE,
  CONSTRAINT fk_food_stall_rental_date_admin FOREIGN KEY (released_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
