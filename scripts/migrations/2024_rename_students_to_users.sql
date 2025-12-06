-- Migration: rename legacy student columns/tables to user naming
-- Run this against your booking database (NOT Snipe-IT).

-- 1) Rename students table to users and update key columns/indexes
ALTER TABLE students RENAME TO users;

ALTER TABLE users
    CHANGE COLUMN student_id user_id VARCHAR(64) NOT NULL,
    DROP INDEX uq_students_student_id,
    DROP INDEX uq_students_email,
    ADD UNIQUE KEY uq_users_user_id (user_id),
    ADD UNIQUE KEY uq_users_email (email);

-- 2) Update reservations table columns + indexes
ALTER TABLE reservations
    CHANGE COLUMN student_id user_id VARCHAR(64) NOT NULL,
    CHANGE COLUMN student_name user_name VARCHAR(255) NOT NULL,
    CHANGE COLUMN student_email user_email VARCHAR(255) NOT NULL,
    DROP INDEX idx_reservations_student_id,
    ADD INDEX idx_reservations_user_id (user_id);

-- 3) Optionally update any data references if needed (no data changes required beyond column rename).

-- Verify
-- SELECT user_id, user_name, user_email FROM reservations LIMIT 5;
