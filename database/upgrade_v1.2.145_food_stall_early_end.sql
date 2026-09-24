-- TLH v1.2.145 - Food Stall Rental early termination tracking
-- Run only if the application's database user cannot ALTER TABLE automatically.

ALTER TABLE food_stall_rentals
  ADD COLUMN original_end_date DATE NULL AFTER end_date,
  ADD COLUMN early_end_reason VARCHAR(255) NULL AFTER original_end_date,
  ADD COLUMN early_ended_at DATETIME NULL AFTER early_end_reason,
  ADD COLUMN early_ended_by INT UNSIGNED NULL AFTER early_ended_at;
