-- Re-validate every foreign key that references `firewalls`.

-- On 2026-08-31 a row was found in `firewall_agents` for firewall_id 25, a
-- firewall deleted months earlier -- despite that table carrying
-- `firewall_agents_ibfk_1 ... ON DELETE CASCADE`. InnoDB enforces that
-- constraint on every ordinary write, so the row can only have arrived while
-- `foreign_key_checks` was 0: a restore or import. The same event left ~65,000
-- orphaned telemetry rows across firewall_latency, system_logs,
-- firewall_system_stats, firewall_traffic_stats and bandwidth_tests, all
-- belonging to the same deleted firewall, all in tables whose FKs said that was
-- impossible.

-- MySQL and MariaDB have no `VALIDATE CONSTRAINT`: a constraint added or bypassed
-- with checks disabled stays permanently unverified against the rows already
-- there. The only way to make the server check is to drop the constraint and add
-- it back with `foreign_key_checks` on.

-- This migration does that in two phases. Phase one counts orphans for every
-- constraint and aborts naming the first offender, changing nothing. Only once
-- every table is proven clean does phase two touch DDL, so a constraint is never
-- dropped that could not be re-added.

-- It cannot stop a FUTURE restore from doing this again: no constraint
-- definition can. `scripts/check_referential_integrity.php` is the recurring
-- guard; run it after any restore.

-- An aborted run leaves the helper procedure behind, because a procedure cannot
-- drop itself. That is harmless: this file opens by dropping it, so the next run
-- replaces it. The migration is safe to re-run in any state.

DELIMITER $$

DROP PROCEDURE IF EXISTS opnmgr_revalidate_firewall_fks $$

CREATE PROCEDURE opnmgr_revalidate_firewall_fks()
BEGIN
    DECLARE v_done      INT DEFAULT 0;
    DECLARE v_name      VARCHAR(64);
    DECLARE v_table     VARCHAR(64);
    DECLARE v_column    VARCHAR(64);
    DECLARE v_delete    VARCHAR(16);
    DECLARE v_update    VARCHAR(16);
    DECLARE v_checked   INT DEFAULT 0;

    DECLARE cur CURSOR FOR
        SELECT constraint_name, table_name, column_name, delete_rule, update_rule
          FROM tmp_firewall_fks
         ORDER BY table_name;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    DROP TEMPORARY TABLE IF EXISTS tmp_firewall_fks;
    CREATE TEMPORARY TABLE tmp_firewall_fks (
        constraint_name VARCHAR(64),
        table_name      VARCHAR(64),
        column_name     VARCHAR(64),
        delete_rule     VARCHAR(16),
        update_rule     VARCHAR(16)
    ) ENGINE=MEMORY;

    INSERT INTO tmp_firewall_fks
    SELECT k.CONSTRAINT_NAME, k.TABLE_NAME, k.COLUMN_NAME,
           rc.DELETE_RULE, rc.UPDATE_RULE
      FROM information_schema.REFERENTIAL_CONSTRAINTS rc
      JOIN information_schema.KEY_COLUMN_USAGE k
        ON k.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
       AND k.CONSTRAINT_NAME   = rc.CONSTRAINT_NAME
     WHERE rc.CONSTRAINT_SCHEMA   = DATABASE()
       AND rc.REFERENCED_TABLE_NAME = 'firewalls'
       AND k.REFERENCED_COLUMN_NAME = 'id';

    -- ---------------------------------------------------------------- phase 1
    -- Prove every child table is clean before any DDL runs. A NULL child value
    -- is legitimate (that is what ON DELETE SET NULL produces), so only
    -- non-NULL values with no surviving parent count as orphans.
    OPEN cur;
    check_loop: LOOP
        FETCH cur INTO v_name, v_table, v_column, v_delete, v_update;
        IF v_done = 1 THEN
            LEAVE check_loop;
        END IF;

        SET @sql = CONCAT(
            'SELECT COUNT(*) INTO @opnmgr_orphans FROM `', v_table, '` x ',
            'LEFT JOIN firewalls f ON f.id = x.`', v_column, '` ',
            'WHERE f.id IS NULL AND x.`', v_column, '` IS NOT NULL');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;

        IF @opnmgr_orphans > 0 THEN
            CLOSE cur;
            SET @msg = CONCAT(
                'Refusing to revalidate: `', v_table, '`.`', v_column, '` has ',
                @opnmgr_orphans, ' row(s) referencing a firewall that no longer ',
                'exists. Clear them first - see scripts/check_referential_integrity.php ',
                '- then re-run this migration. No constraint was changed.');
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = @msg;
        END IF;

        SET v_checked = v_checked + 1;
    END LOOP;
    CLOSE cur;

    -- ---------------------------------------------------------------- phase 2
    -- Drop and re-add each constraint so the server validates it against the
    -- rows that are actually there. Safe now: phase 1 proved every ADD can
    -- succeed. Same name, column and referential actions go back on.
    SET v_done = 0;
    OPEN cur;
    fix_loop: LOOP
        FETCH cur INTO v_name, v_table, v_column, v_delete, v_update;
        IF v_done = 1 THEN
            LEAVE fix_loop;
        END IF;

        SET @sql = CONCAT('ALTER TABLE `', v_table, '` DROP FOREIGN KEY `', v_name, '`');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;

        SET @sql = CONCAT(
            'ALTER TABLE `', v_table, '` ADD CONSTRAINT `', v_name, '` ',
            'FOREIGN KEY (`', v_column, '`) REFERENCES `firewalls` (`id`) ',
            'ON DELETE ', v_delete, ' ON UPDATE ', v_update);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END LOOP;
    CLOSE cur;

    DROP TEMPORARY TABLE IF EXISTS tmp_firewall_fks;

    SELECT CONCAT(v_checked, ' foreign key(s) referencing `firewalls` revalidated')
           AS revalidation_result;
END $$

DELIMITER ;

-- foreign_key_checks is session scoped; make certain it is on for the CALL, or
-- phase 2 would re-add the constraints just as unvalidated as it found them.
SET SESSION foreign_key_checks = 1;

CALL opnmgr_revalidate_firewall_fks();

DROP PROCEDURE IF EXISTS opnmgr_revalidate_firewall_fks;
