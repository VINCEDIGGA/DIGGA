-- DIGGA additive student deployment migration (MySQL/MariaDB, selected database).
-- Creates missing tables and the known older application_json column only.
-- No seed inserts, user-data updates, DELETE, DROP, TRUNCATE, CREATE DATABASE or USE.
-- Existing incompatible custom schemas need separate review; IF NOT EXISTS is not a full migration engine.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS reference_documents (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  service VARCHAR(80) NOT NULL,
  source_url VARCHAR(700) NOT NULL,
  content MEDIUMTEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_reference_source (source_url(255)),
  FULLTEXT KEY ft_reference (title, service, content)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_embeddings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_id INT UNSIGNED NOT NULL,
  model VARCHAR(80) NOT NULL,
  text_hash CHAR(64) NOT NULL,
  embedding JSON NOT NULL,
  vector_norm DOUBLE NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_document_embedding (document_id, model),
  INDEX idx_embedding_model (model),
  INDEX idx_embedding_hash (text_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  question TEXT NOT NULL,
  answer MEDIUMTEXT NOT NULL,
  source_ids JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guide_step_context (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  case_id VARCHAR(120) NOT NULL,
  step_index INT UNSIGNED NOT NULL,
  check_index INT UNSIGNED NOT NULL,
  step_title VARCHAR(255) NOT NULL,
  check_text VARCHAR(500) NOT NULL,
  organization_name VARCHAR(255) NOT NULL DEFAULT '',
  organization_number VARCHAR(80) NOT NULL DEFAULT '',
  contact_name VARCHAR(255) NOT NULL DEFAULT '',
  contact_email VARCHAR(255) NOT NULL DEFAULT '',
  contact_role VARCHAR(160) NOT NULL DEFAULT '',
  short_description TEXT NULL,
  need_text TEXT NULL,
  application_json MEDIUMTEXT NULL,
  files_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_guide_step_context (case_id, step_index, check_index),
  INDEX idx_guide_step_context_case (case_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS case_attachments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  case_id VARCHAR(120) NOT NULL,
  message_text TEXT NULL,
  name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(120) NOT NULL DEFAULT '',
  size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
  preview MEDIUMTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_case_attachments_case (case_id),
  INDEX idx_case_attachments_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reformat_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  case_id VARCHAR(120) NOT NULL,
  attachment_id BIGINT UNSIGNED NULL,
  template_key VARCHAR(80) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  detected_format VARCHAR(40) NOT NULL,
  source_preview MEDIUMTEXT NULL,
  reformatted_name VARCHAR(255) NOT NULL,
  reformatted_text MEDIUMTEXT NOT NULL,
  issues_json JSON NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'draft',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_reformat_jobs_case (case_id),
  INDEX idx_reformat_jobs_attachment (attachment_id),
  INDEX idx_reformat_jobs_status (status),
  INDEX idx_reformat_jobs_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS validation_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  profile VARCHAR(32) NOT NULL,
  detected_format VARCHAR(16) NOT NULL,
  is_valid TINYINT(1) NOT NULL,
  issues JSON NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mock_organizations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL DEFAULT 'Mock kommun',
  org_number VARCHAR(32) NOT NULL DEFAULT '',
  public_actor_type VARCHAR(120) NOT NULL DEFAULT 'Kommunal myndighet',
  contact_name VARCHAR(255) NOT NULL DEFAULT '',
  contact_email VARCHAR(255) NOT NULL DEFAULT '',
  contact_phone VARCHAR(80) NOT NULL DEFAULT '',
  it_contact VARCHAR(255) NOT NULL DEFAULT '',
  business_owner VARCHAR(255) NOT NULL DEFAULT '',
  peppol_id VARCHAR(120) NOT NULL DEFAULT '',
  supplier_strategy VARCHAR(255) NOT NULL DEFAULT 'Egen PoC med framtida leverantörsval',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS readiness_progress (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id INT UNSIGNED NOT NULL,
  service VARCHAR(32) NOT NULL,
  item_key VARCHAR(80) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'todo',
  note TEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_readiness_item (organization_id, service, item_key),
  INDEX idx_readiness_org (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evidence_exports (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id INT UNSIGNED NOT NULL,
  payload JSON NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_evidence_org (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS integration_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run_id VARCHAR(80) NOT NULL,
  source_system VARCHAR(120) NOT NULL,
  target_system VARCHAR(120) NOT NULL,
  profile VARCHAR(32) NOT NULL,
  mode VARCHAR(32) NOT NULL,
  status VARCHAR(32) NOT NULL,
  source_payload JSON NOT NULL,
  transformed_payload JSON NOT NULL,
  validation_result JSON NOT NULL,
  delivery_result JSON NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_integration_run_id (run_id),
  INDEX idx_integration_created (created_at),
  INDEX idx_integration_profile (profile)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mock_source_cases (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  case_id VARCHAR(80) NOT NULL,
  case_type VARCHAR(160) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  body TEXT NOT NULL,
  sender JSON NOT NULL,
  recipient JSON NOT NULL,
  person JSON NOT NULL,
  attachments JSON NOT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'ready',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mock_source_case (case_id),
  INDEX idx_mock_source_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sdk_source_chunks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_id VARCHAR(80) NOT NULL,
    chunk_number INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    service VARCHAR(80) NOT NULL,
    source_url VARCHAR(700) NOT NULL,
    content MEDIUMTEXT NOT NULL,
    metadata_json JSON NOT NULL,
    content_hash CHAR(64) NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sdk_chunk (source_id, chunk_number),
    FULLTEXT KEY ft_sdk_chunk (title,service,content)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Portable conditional ADD COLUMN for old project revisions (no procedure permission required).
SET @digga_has_application_json = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'guide_step_context'
      AND COLUMN_NAME = 'application_json'
);
SET @digga_add_application_json = IF(
    @digga_has_application_json = 0,
    'ALTER TABLE guide_step_context ADD COLUMN application_json MEDIUMTEXT NULL',
    'SELECT 1 AS application_json_already_present'
);
PREPARE digga_schema_patch FROM @digga_add_application_json;
EXECUTE digga_schema_patch;
DEALLOCATE PREPARE digga_schema_patch;
