-- ---------------------------------------------------------------------------
-- 12-tone sudoku repository
-- MySQL 8 / MariaDB 10.5+ . See notes at the foot for SQLite and Postgres.
--
-- Two identities, deliberately separated:
--
--   puzzles     the mathematical object: grids, solution, solving steps.
--               Identified by a content hash, so it is stable forever.
--
--   renderings  one engraving of a puzzle in a particular clef, spelling,
--               range, size and format. Many per puzzle. Identified by a hash
--               of the LilyPond source, which is the complete description of
--               what was drawn.
-- ---------------------------------------------------------------------------

CREATE TABLE puzzles (
    id                BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,

    -- Crockford base32, from sha256(variant|puzzleGrid|solutionGrid).
    -- Collation is _ci so a lowercase URL still resolves; normalise I/L -> 1
    -- and O -> 0 in the application before looking up.
    puzzle_id         CHAR(10)         NOT NULL,

    -- shape ------------------------------------------------------------------
    variant           VARCHAR(32)      NOT NULL,   -- chromatic | diatonic
    grid_size         TINYINT UNSIGNED NOT NULL,   -- 12 | 8
    box_width         TINYINT UNSIGNED NOT NULL,
    box_height        TINYINT UNSIGNED NOT NULL,
    alphabet          VARCHAR(32)      NOT NULL,   -- '0123456789AB' | '12345678'

    -- content ----------------------------------------------------------------
    -- One char per cell, '.' for empty. 256 leaves room for a 16x16 variant.
    puzzle_grid       VARCHAR(256)     NOT NULL,
    solution_grid     VARCHAR(256)     NOT NULL,
    givens            SMALLINT UNSIGNED NOT NULL,

    -- grading ----------------------------------------------------------------
    difficulty        VARCHAR(16)      NOT NULL,   -- band requested
    difficulty_label  VARCHAR(16)      NOT NULL,   -- band actually achieved
    max_tier          TINYINT UNSIGNED NOT NULL,   -- hardest technique needed, 1-7
    score             INT UNSIGNED     NOT NULL,
    techniques        JSON             NOT NULL,   -- {"Naked single": 40, ...}

    -- solving walkthrough ----------------------------------------------------
    steps             JSON             NOT NULL,   -- ordered placements + eliminations
    step_count        SMALLINT UNSIGNED NOT NULL,
    placement_count   SMALLINT UNSIGNED NOT NULL,

    -- provenance -------------------------------------------------------------
    seed              BIGINT           NOT NULL,
    attempt           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    -- Fingerprints of the code that produced this row. The content id does NOT
    -- change when the generator or solver changes, so without these a solver
    -- improvement silently leaves stale steps and grades behind.
    engine_version    VARCHAR(32)      NOT NULL,
    solver_version    VARCHAR(32)      NOT NULL,

    -- lifecycle --------------------------------------------------------------
    verified          TINYINT(1)       NOT NULL DEFAULT 0,
    verify_errors     JSON             NULL,
    published         TINYINT(1)       NOT NULL DEFAULT 0,
    notes             TEXT             NULL,

    created_at        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP
                                       ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_puzzle_id (puzzle_id),
    KEY ix_browse  (published, variant, difficulty_label, max_tier),
    KEY ix_seed    (variant, difficulty, seed),
    KEY ix_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE renderings (
    id                BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    puzzle_id         CHAR(10)         NOT NULL,

    -- sha256 of the LilyPond source. THE key: the source already encodes the
    -- grid, clef, spelling, range, staff size and line width, so adding a new
    -- rendering option later cannot break the naming scheme.
    source_sha        CHAR(64)         NOT NULL,

    format            ENUM('svg','pdf','png') NOT NULL DEFAULT 'svg',
    is_solution       TINYINT(1)       NOT NULL DEFAULT 0,

    -- denormalised so the UI can filter without parsing the source
    clef_mode         VARCHAR(16)      NOT NULL,   -- treble|alto|tenor|bass|random
    spelling          VARCHAR(16)      NOT NULL,   -- sharps|flats|mixed
    range_mode        VARCHAR(16)      NOT NULL,   -- octave|full
    staff_size        TINYINT UNSIGNED NOT NULL,
    -- NULL means a standalone Letter sheet; a value means it was engraved to
    -- fit a page of that content width. Same puzzle, different margin,
    -- different engraving - easy to overlook.
    line_width_mm     DECIMAL(6,2)     NULL,

    ly_source         MEDIUMTEXT       NOT NULL,   -- re-render without recomputing

    -- Store a path relative to an asset root, never an absolute URL: the host
    -- moves from i-a-n.ca to ianring.com and baked-in hosts become a migration.
    asset_path        VARCHAR(255)     NOT NULL,   -- e.g. K7M3QX9AZ2/9f2c...c1.svg
    bytes             INT UNSIGNED     NOT NULL,
    content_sha       CHAR(64)         NOT NULL,   -- detects files changed or deleted underneath
    width_mm          DECIMAL(7,2)     NULL,
    height_mm         DECIMAL(7,2)     NULL,

    lilypond_version  VARCHAR(16)      NULL,
    rendered_at       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_source (source_sha),
    KEY ix_puzzle (puzzle_id, format, is_solution),
    KEY ix_params (puzzle_id, clef_mode, spelling, range_mode, staff_size),
    CONSTRAINT fk_rendering_puzzle
        FOREIGN KEY (puzzle_id) REFERENCES puzzles (puzzle_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Optional, for when the PDF work resumes: which puzzles went on which sheet.
CREATE TABLE worksheets (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug          VARCHAR(64)     NOT NULL,
    title         VARCHAR(200)    NOT NULL,
    subtitle      VARCHAR(200)    NULL,
    intro         TEXT            NULL,
    notes         TEXT            NULL,
    layout        JSON            NULL,   -- the PageOptions used
    published     TINYINT(1)      NOT NULL DEFAULT 0,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE worksheet_items (
    worksheet_id  BIGINT UNSIGNED NOT NULL,
    position      SMALLINT UNSIGNED NOT NULL,   -- page number within the sheet
    puzzle_id     CHAR(10)        NOT NULL,
    rendering_id  BIGINT UNSIGNED NULL,         -- the exact engraving printed
    PRIMARY KEY (worksheet_id, position),
    KEY ix_puzzle (puzzle_id),
    CONSTRAINT fk_item_worksheet FOREIGN KEY (worksheet_id) REFERENCES worksheets (id) ON DELETE CASCADE,
    CONSTRAINT fk_item_puzzle    FOREIGN KEY (puzzle_id)    REFERENCES puzzles (puzzle_id),
    CONSTRAINT fk_item_rendering FOREIGN KEY (rendering_id) REFERENCES renderings (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- Porting notes
--
-- SQLite    : drop ENGINE/CHARSET/COLLATE, use INTEGER PRIMARY KEY AUTOINCREMENT,
--             TEXT for JSON, and CHECK(format IN (...)) for the enum.
-- Postgres  : BIGSERIAL, JSONB (better - indexable), TIMESTAMPTZ, and either a
--             CHECK constraint or a real enum type for format. citext or
--             lower() indexes if you want case-insensitive puzzle_id lookups.
-- ---------------------------------------------------------------------------
