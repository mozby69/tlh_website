-- TLH v1.2.143 - Food Stall post-rental final billing
-- Run once only if you prefer a manual migration. The application also performs this migration automatically.
ALTER TABLE food_stall_rentals ADD COLUMN electricity_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER rental_amount;
ALTER TABLE food_stall_rentals ADD COLUMN water_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER electricity_amount;
ALTER TABLE food_stall_rentals ADD COLUMN final_billing_notes TEXT NULL AFTER water_amount;
ALTER TABLE food_stall_rentals ADD COLUMN final_billed_at DATETIME NULL AFTER final_billing_notes;
ALTER TABLE food_stall_rentals ADD COLUMN final_billed_by INT UNSIGNED NULL AFTER final_billed_at;
