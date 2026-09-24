-- TLH v1.2.158 - Complimentary / Free Food Stall Rentals
-- Run manually only if the web application's MySQL user cannot alter the schema automatically.

ALTER TABLE food_stall_rentals
  ADD COLUMN is_complimentary TINYINT(1) NOT NULL DEFAULT 0 AFTER rental_amount,
  ADD COLUMN complimentary_reason VARCHAR(1000) NULL AFTER is_complimentary,
  ADD COLUMN complimentary_by INT UNSIGNED NULL AFTER complimentary_reason,
  ADD COLUMN complimentary_at DATETIME NULL AFTER complimentary_by,
  ADD KEY idx_food_stall_complimentary (is_complimentary);
