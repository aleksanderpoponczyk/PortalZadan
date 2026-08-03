-- Zadania·AP — schemat bazy danych (referencyjny; install.php tworzy to samo).
-- Kodowanie: utf8mb4 (pełne polskie znaki diakrytyczne + emoji).
-- Jeśli baza/serwer sortuje polskie znaki nie po Twojej myśli, możesz zmienić
-- COLLATE na utf8mb4_polish_ci (dostępne w MySQL 5.7+/MariaDB).

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tasks (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description MEDIUMTEXT NULL,
    sphere ENUM('prywatne','sluzbowe') NOT NULL DEFAULT 'prywatne',
    status ENUM('nowe','w_toku','oczekuje','zrobione','anulowane') NOT NULL DEFAULT 'nowe',
    priority TINYINT UNSIGNED NOT NULL DEFAULT 3, -- 1 = najwyższy, 5 = najniższy
    due_date DATE NULL,          -- twardy termin (deadline)
    scheduled_date DATE NULL,    -- dzień, na który praca jest zaplanowana
    source VARCHAR(50) NULL,     -- ręcznie / outlook / teams / wklejka / inbox / transkrypcja
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    INDEX idx_status (status),
    INDEX idx_sphere (sphere),
    INDEX idx_due (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_entries (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    task_id INT UNSIGNED NOT NULL,
    author ENUM('ja','ai','system') NOT NULL DEFAULT 'ja',
    content MEDIUMTEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_entry_task FOREIGN KEY (task_id)
        REFERENCES tasks(id) ON DELETE CASCADE,
    INDEX idx_task (task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
