-- Run once on an existing Mira Plastik database.
CREATE TABLE IF NOT EXISTS pengingat_piutang_harian (
    reminder_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    receivable_id BIGINT UNSIGNED NOT NULL,
    reminder_category VARCHAR(20) NOT NULL,
    reminder_date DATE NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (reminder_id),
    UNIQUE KEY uq_pengingat_piutang_harian (receivable_id, reminder_category, reminder_date),
    CONSTRAINT chk_pengingat_piutang_category
        CHECK (reminder_category IN ('overdue', 'due_today', 'h3')),
    CONSTRAINT fk_pengingat_piutang_receivable
        FOREIGN KEY (receivable_id)
        REFERENCES piutang (receivable_id) ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE=InnoDB;
