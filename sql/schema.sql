CREATE DATABASE IF NOT EXISTS changeover_db;

USE changeover_db;

CREATE TABLE IF NOT EXISTS analysis_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    setup_minutes INT NOT NULL,
    job_count INT NOT NULL,
    baseline_setup INT NOT NULL,
    grouped_setup INT NOT NULL,
    baseline_total INT NOT NULL,
    grouped_total INT NOT NULL
);

CREATE TABLE IF NOT EXISTS analysis_run_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    run_id INT NOT NULL,
    job_code VARCHAR(50) NOT NULL,
    product_family VARCHAR(50) NOT NULL,
    processing_minutes INT NOT NULL,
    baseline_position INT NOT NULL,
    grouped_position INT NOT NULL,

    FOREIGN KEY (run_id)
        REFERENCES analysis_runs(id)
        ON DELETE CASCADE
);