-- PostgreSQL schema for car_repair_db
-- Converted from MariaDB / MySQL

-- -------------------------------------------------------
-- Shared trigger function: auto-update updated_at columns
-- -------------------------------------------------------
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- -------------------------------------------------------
-- bookings
-- -------------------------------------------------------
CREATE TABLE bookings (
    id                  SERIAL NOT NULL,
    user_id             INTEGER NOT NULL,
    vehicle_id          INTEGER NOT NULL,
    service_category_id INTEGER NOT NULL,
    booking_date        DATE NOT NULL,
    booking_time        TIME NOT NULL,
    original_date       DATE DEFAULT NULL,
    original_time       TIME DEFAULT NULL,
    reschedule_reason   TEXT DEFAULT NULL,
    rescheduled_by      TEXT CHECK (rescheduled_by IN ('admin','customer')) DEFAULT NULL,
    status              TEXT CHECK (status IN ('pending','confirmed','repairing','completed','cancelled')) DEFAULT 'pending',
    remarks             TEXT DEFAULT NULL,
    admin_notes         TEXT DEFAULT NULL,
    estimated_price     DECIMAL(10,2) DEFAULT NULL,
    final_price         DECIMAL(10,2) DEFAULT NULL,
    completed_date      DATE DEFAULT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    admin_viewed        SMALLINT DEFAULT 0,
    PRIMARY KEY (id)
);

CREATE INDEX idx_bookings_user_id       ON bookings (user_id);
CREATE INDEX idx_bookings_vehicle_id    ON bookings (vehicle_id);
CREATE INDEX idx_bookings_booking_date  ON bookings (booking_date);
CREATE INDEX idx_bookings_status        ON bookings (status);
CREATE INDEX idx_bookings_date_time     ON bookings (booking_date, booking_time);

CREATE TRIGGER set_bookings_updated_at
    BEFORE UPDATE ON bookings
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

-- -------------------------------------------------------
-- Booking status → service_suggestions triggers
-- -------------------------------------------------------
CREATE OR REPLACE FUNCTION trigger_booking_cancelled()
RETURNS TRIGGER AS $$
BEGIN
    IF NEW.status = 'cancelled' AND OLD.status <> 'cancelled' THEN
        UPDATE service_suggestions
        SET status          = 'pending',
            completed_notes = CONCAT('↩️ Cancelled on ', TO_CHAR(NOW(), 'DD/MM/YYYY'), ' (Booking #', NEW.id::TEXT, ')'),
            updated_at      = NOW()
        WHERE vehicle_id          = NEW.vehicle_id
          AND service_category_id = NEW.service_category_id
          AND status IN ('booked', 'in_progress');
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER update_suggestion_on_booking_cancelled
    AFTER UPDATE ON bookings
    FOR EACH ROW EXECUTE FUNCTION trigger_booking_cancelled();

CREATE OR REPLACE FUNCTION trigger_booking_confirmed()
RETURNS TRIGGER AS $$
BEGIN
    IF NEW.status = 'confirmed' AND OLD.status <> 'confirmed' THEN
        UPDATE service_suggestions
        SET status          = 'booked',
            completed_notes = CONCAT('📅 Booked on ', TO_CHAR(NEW.booking_date, 'DD/MM/YYYY'), ' (Booking #', NEW.id::TEXT, ')'),
            updated_at      = NOW()
        WHERE vehicle_id          = NEW.vehicle_id
          AND service_category_id = NEW.service_category_id
          AND status = 'pending';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER update_suggestion_on_booking_confirmed
    AFTER UPDATE ON bookings
    FOR EACH ROW EXECUTE FUNCTION trigger_booking_confirmed();

CREATE OR REPLACE FUNCTION trigger_booking_progress()
RETURNS TRIGGER AS $$
BEGIN
    IF NEW.status = 'repairing' AND OLD.status <> 'repairing' THEN
        UPDATE service_suggestions
        SET status          = 'in_progress',
            completed_notes = CONCAT('🔧 In progress since ', TO_CHAR(NOW(), 'DD/MM/YYYY'), ' (Booking #', NEW.id::TEXT, ')'),
            updated_at      = NOW()
        WHERE vehicle_id          = NEW.vehicle_id
          AND service_category_id = NEW.service_category_id
          AND status IN ('pending', 'booked');
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER update_suggestion_on_booking_progress
    AFTER UPDATE ON bookings
    FOR EACH ROW EXECUTE FUNCTION trigger_booking_progress();

-- -------------------------------------------------------
-- booking_timeslots
-- -------------------------------------------------------
CREATE TABLE booking_timeslots (
    id           SERIAL NOT NULL,
    slot_time    TIME NOT NULL,
    max_bookings INTEGER DEFAULT 3,
    is_active    SMALLINT DEFAULT 1,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE (slot_time)
);

-- -------------------------------------------------------
-- brands
-- -------------------------------------------------------
CREATE TABLE brands (
    id          SERIAL NOT NULL,
    brand_name  VARCHAR(100) NOT NULL,
    brand_logo  VARCHAR(255) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    is_active   SMALLINT DEFAULT 1,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE (brand_name)
);

CREATE INDEX idx_brands_brand_name ON brands (brand_name);

-- -------------------------------------------------------
-- password_resets
-- -------------------------------------------------------
CREATE TABLE password_resets (
    id                 SERIAL NOT NULL,
    user_id            INTEGER NOT NULL,
    temp_password_hash VARCHAR(255) NOT NULL,
    expires_at         TIMESTAMP NOT NULL,
    used               SMALLINT DEFAULT 0,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
);

CREATE INDEX idx_password_resets_user_id   ON password_resets (user_id);
CREATE INDEX idx_password_resets_expires   ON password_resets (expires_at);
CREATE INDEX idx_password_resets_used      ON password_resets (used);

-- -------------------------------------------------------
-- security_images
-- -------------------------------------------------------
CREATE TABLE security_images (
    id         SERIAL NOT NULL,
    image_name VARCHAR(100) NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    category   VARCHAR(50) DEFAULT NULL,
    is_active  SMALLINT DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
);

-- -------------------------------------------------------
-- service_categories
-- -------------------------------------------------------
CREATE TABLE service_categories (
    id               SERIAL NOT NULL,
    type             TEXT CHECK (type IN (
                         'Oil & Fluid Service','Engine Service','Brake Service',
                         'Cooling System Service','Electrical Service',
                         'Air Conditioning Service','Suspension & Steering Service',
                         'Tire Service','Exhaust Service'
                     )) DEFAULT NULL,
    category_name    VARCHAR(100) NOT NULL,
    description      TEXT DEFAULT NULL,
    base_price       DECIMAL(10,2) NOT NULL,
    estimated_hours  DECIMAL(5,2) DEFAULT NULL,
    is_active        SMALLINT DEFAULT 1,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
);

CREATE INDEX idx_service_categories_name ON service_categories (category_name);

-- -------------------------------------------------------
-- service_parts  (total_price is a generated column — requires PG 12+)
-- -------------------------------------------------------
CREATE TABLE service_parts (
    id              SERIAL NOT NULL,
    booking_id      INTEGER NOT NULL,
    part_name       VARCHAR(255) NOT NULL,
    part_code       VARCHAR(100) DEFAULT NULL,
    quantity        INTEGER DEFAULT 1,
    unit_price      DECIMAL(10,2) NOT NULL,
    total_price     DECIMAL(10,2) GENERATED ALWAYS AS (quantity * unit_price) STORED,
    warranty_months INTEGER DEFAULT 0,
    warranty_info   TEXT DEFAULT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
);

CREATE INDEX idx_service_parts_booking ON service_parts (booking_id);

-- -------------------------------------------------------
-- service_progress
-- -------------------------------------------------------
CREATE TABLE service_progress (
    id            SERIAL NOT NULL,
    booking_id    INTEGER NOT NULL,
    technician_id INTEGER NOT NULL,
    status        TEXT CHECK (status IN ('pending','in_progress','completed','cancelled')) DEFAULT 'in_progress',
    started_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at  TIMESTAMP DEFAULT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
);

CREATE INDEX idx_service_progress_booking    ON service_progress (booking_id);
CREATE INDEX idx_service_progress_technician ON service_progress (technician_id);
CREATE INDEX idx_service_progress_status     ON service_progress (status);

-- -------------------------------------------------------
-- service_suggestions
-- -------------------------------------------------------
CREATE TABLE service_suggestions (
    id                  SERIAL NOT NULL,
    booking_id          INTEGER NOT NULL DEFAULT 0,
    vehicle_id          INTEGER NOT NULL,
    user_id             INTEGER NOT NULL,
    service_category_id INTEGER NOT NULL,
    suggested_date      DATE NOT NULL,
    notes               TEXT DEFAULT NULL,
    status              TEXT CHECK (status IN ('pending','booked','in_progress','done','skipped')) DEFAULT 'pending',
    completed_notes     TEXT DEFAULT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
);

CREATE INDEX idx_service_suggestions_vehicle        ON service_suggestions (vehicle_id);
CREATE INDEX idx_service_suggestions_status         ON service_suggestions (status);
CREATE INDEX idx_service_suggestions_suggested_date ON service_suggestions (suggested_date);

CREATE TRIGGER set_service_suggestions_updated_at
    BEFORE UPDATE ON service_suggestions
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

-- -------------------------------------------------------
-- service_suggestions_backup  (no serial — preserves existing IDs)
-- -------------------------------------------------------
CREATE TABLE service_suggestions_backup (
    id                  INTEGER NOT NULL DEFAULT 0,
    booking_id          INTEGER NOT NULL DEFAULT 0,
    vehicle_id          INTEGER NOT NULL,
    user_id             INTEGER NOT NULL,
    service_category_id INTEGER NOT NULL,
    suggested_date      DATE NOT NULL,
    notes               TEXT DEFAULT NULL,
    status              TEXT CHECK (status IN ('pending','booked','in_progress','done','skipped')) DEFAULT 'pending',
    completed_notes     TEXT DEFAULT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TRIGGER set_suggestions_backup_updated_at
    BEFORE UPDATE ON service_suggestions_backup
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

-- -------------------------------------------------------
-- service_updates
-- -------------------------------------------------------
CREATE TABLE service_updates (
    id                      SERIAL NOT NULL,
    booking_id              INTEGER NOT NULL,
    technician_id           INTEGER DEFAULT NULL,
    message                 TEXT NOT NULL,
    update_type             TEXT CHECK (update_type IN ('info','waiting','issue','complete')) DEFAULT NULL,
    is_visible_to_customer  SMALLINT DEFAULT 1,
    created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
);

CREATE INDEX idx_service_updates_booking    ON service_updates (booking_id);
CREATE INDEX idx_service_updates_technician ON service_updates (technician_id);

-- -------------------------------------------------------
-- spare_parts
-- -------------------------------------------------------
CREATE TABLE spare_parts (
    id          SERIAL NOT NULL,
    part_name   VARCHAR(255) NOT NULL,
    category    VARCHAR(100) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    price_min   DECIMAL(10,2) DEFAULT NULL,
    price_max   DECIMAL(10,2) DEFAULT NULL,
    image_path  VARCHAR(255) DEFAULT NULL,
    status      TEXT CHECK (status IN ('active','inactive')) DEFAULT 'active',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
);

CREATE INDEX idx_spare_parts_part_name ON spare_parts (part_name);
CREATE INDEX idx_spare_parts_category  ON spare_parts (category);
CREATE INDEX idx_spare_parts_status    ON spare_parts (status);

CREATE TRIGGER set_spare_parts_updated_at
    BEFORE UPDATE ON spare_parts
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

-- -------------------------------------------------------
-- spare_parts_categories
-- -------------------------------------------------------
CREATE TABLE spare_parts_categories (
    id            SERIAL NOT NULL,
    category_name VARCHAR(100) NOT NULL,
    description   TEXT DEFAULT NULL,
    is_active     SMALLINT DEFAULT 1,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
);

-- -------------------------------------------------------
-- spare_part_brands
-- -------------------------------------------------------
CREATE TABLE spare_part_brands (
    id            SERIAL NOT NULL,
    spare_part_id INTEGER NOT NULL,
    brand_id      INTEGER NOT NULL,
    price         DECIMAL(10,2) DEFAULT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE (spare_part_id, brand_id)
);

CREATE INDEX idx_spare_part_brands_spare_part ON spare_part_brands (spare_part_id);
CREATE INDEX idx_spare_part_brands_brand      ON spare_part_brands (brand_id);

-- -------------------------------------------------------
-- technicians
-- -------------------------------------------------------
CREATE TABLE technicians (
    id         SERIAL NOT NULL,
    name       VARCHAR(100) NOT NULL,
    phone      VARCHAR(20) DEFAULT NULL,
    email      VARCHAR(100) DEFAULT NULL,
    is_active  SMALLINT DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
);

-- -------------------------------------------------------
-- users
-- -------------------------------------------------------
CREATE TABLE users (
    id                  SERIAL NOT NULL,
    email               VARCHAR(100) NOT NULL,
    username            VARCHAR(50) NOT NULL,
    password_hash       VARCHAR(255) NOT NULL,
    full_name           VARCHAR(100) NOT NULL,
    phone               VARCHAR(20) DEFAULT NULL,
    security_image_path VARCHAR(255) DEFAULT NULL,
    security_phrase     VARCHAR(255) DEFAULT NULL,
    role                TEXT CHECK (role IN ('admin','customer','staff')) DEFAULT 'customer',
    is_active           SMALLINT DEFAULT 1,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE (email),
    UNIQUE (username)
);

CREATE INDEX idx_users_role     ON users (role);
CREATE INDEX idx_users_username ON users (username);

-- -------------------------------------------------------
-- vehicles
-- -------------------------------------------------------
CREATE TABLE vehicles (
    id           SERIAL NOT NULL,
    user_id      INTEGER NOT NULL,
    brand_name   VARCHAR(50) NOT NULL,
    model        VARCHAR(50) NOT NULL,
    year         INTEGER NOT NULL,
    color        VARCHAR(30) DEFAULT NULL,
    number_plate VARCHAR(20) NOT NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
);

CREATE INDEX idx_vehicles_user_id      ON vehicles (user_id);
CREATE INDEX idx_vehicles_number_plate ON vehicles (number_plate);

-- -------------------------------------------------------
-- Foreign keys
-- -------------------------------------------------------
ALTER TABLE bookings
    ADD CONSTRAINT bookings_user_id_fk            FOREIGN KEY (user_id)             REFERENCES users (id) ON DELETE CASCADE,
    ADD CONSTRAINT bookings_vehicle_id_fk          FOREIGN KEY (vehicle_id)          REFERENCES vehicles (id) ON DELETE CASCADE,
    ADD CONSTRAINT bookings_service_category_id_fk FOREIGN KEY (service_category_id) REFERENCES service_categories (id);

ALTER TABLE password_resets
    ADD CONSTRAINT password_resets_user_id_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE;

ALTER TABLE service_parts
    ADD CONSTRAINT service_parts_booking_id_fk FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE;

ALTER TABLE service_updates
    ADD CONSTRAINT service_updates_booking_id_fk    FOREIGN KEY (booking_id)    REFERENCES bookings (id) ON DELETE CASCADE,
    ADD CONSTRAINT service_updates_technician_id_fk FOREIGN KEY (technician_id) REFERENCES technicians (id);

ALTER TABLE spare_part_brands
    ADD CONSTRAINT spare_part_brands_spare_part_id_fk FOREIGN KEY (spare_part_id) REFERENCES spare_parts (id) ON DELETE CASCADE,
    ADD CONSTRAINT spare_part_brands_brand_id_fk      FOREIGN KEY (brand_id)      REFERENCES brands (id) ON DELETE CASCADE;

ALTER TABLE vehicles
    ADD CONSTRAINT vehicles_user_id_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE;

-- -------------------------------------------------------
-- Seed data
-- -------------------------------------------------------
INSERT INTO booking_timeslots (slot_time, max_bookings, is_active) VALUES
('09:00:00', 3, 1), ('10:00:00', 3, 1), ('11:00:00', 3, 1), ('12:00:00', 3, 1),
('13:00:00', 3, 1), ('14:00:00', 3, 1), ('15:00:00', 3, 1), ('16:00:00', 3, 1),
('17:00:00', 3, 1);

INSERT INTO security_images (image_name, image_path, category) VALUES
('Beach',    'beach.jpg',    'Beach'),
('City',     'city.jpg',     'City'),
('Desert',   'desert.jpg',   'Desert'),
('Forest',   'forest.jpg',   'Forest'),
('Garden',   'garden.jpg',   'Garden'),
('Lake',     'lake.jpg',     'Lake'),
('Mountain', 'mountain.jpg', 'Mountain'),
('River',    'river.jpg',    'River'),
('Snow',     'snow.jpg',     'Snow'),
('Sunset',   'sunset.jpg',   'Sunset');

INSERT INTO technicians (name, phone) VALUES
('Ahmad Muafaz', '012-3456789'),
('Raj Kumar',    '013-4567890'),
('Siva Nathan',  '015-6789012'),
('Mohd Hafiz',   '016-7890123');

-- Admin user  (password: Admin@12345 — change this!)
INSERT INTO users (email, username, password_hash, full_name, phone, security_image_path, security_phrase, role) VALUES
('admin@gmail.com', 'admin', '$2y$10$3SCpFh7AkAUfleaVrBvA.OzBhDOVF3ST6WTk/sp9IUQrr32sP3R8i', 'Admin', '01126658335', 'red-car.jpg', 'Admin123', 'admin');

-- Reset sequences to safe starting points
SELECT setval('bookings_id_seq',          200);
SELECT setval('booking_timeslots_id_seq',  20);
SELECT setval('brands_id_seq',            100);
SELECT setval('password_resets_id_seq',    10);
SELECT setval('security_images_id_seq',    15);
SELECT setval('service_categories_id_seq', 80);
SELECT setval('service_parts_id_seq',      50);
SELECT setval('service_progress_id_seq',   35);
SELECT setval('service_suggestions_id_seq',150);
SELECT setval('service_updates_id_seq',    250);
SELECT setval('spare_parts_id_seq',        40);
SELECT setval('spare_parts_categories_id_seq', 30);
SELECT setval('spare_part_brands_id_seq',  210);
SELECT setval('technicians_id_seq',        10);
SELECT setval('users_id_seq',              25);
SELECT setval('vehicles_id_seq',           30);
