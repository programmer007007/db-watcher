<?php

namespace Andrew\StrongBondDBWatcher;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;

class DBWatcher
{

    private $db_name = '';
    private $tables_to_track = [];
    private $log_tbl_name = '';
    private $log_meta_tbl_name = '';
    private $common_columns_to_ignore = [];

    function __construct()
    {
        $this->db_name = config('dbwatcher.db_name');
        $this->tables_to_track = config('dbwatcher.tables_to_track');
        $this->log_tbl_name = config('dbwatcher.log_table_name', 'log');
        $this->log_meta_tbl_name = "$this->log_tbl_name" . '_meta';
        $this->common_columns_to_ignore = config('dbwatcher.common_columns_to_ignore');
        $this->checkIfDBNameProvided();
        foreach ($this->tables_to_track as &$tbl) {
            if (isset($tbl["column"]) && count($tbl["column"])) {
                foreach ($tbl["column"] as &$col_name) {
                    $col_name = strtolower($col_name);
                }
            }
        }
    }

    /**
     * Run this once to create the required tables.
     * @return void
     */
    public function drop_recreate_log_tables()
    {
        $sql = "DROP TABLE IF EXISTS `" . $this->log_meta_tbl_name . "`;
                CREATE TABLE `" . $this->log_meta_tbl_name . "` (
                  `audit_meta_id` bigint unsigned NOT NULL AUTO_INCREMENT,
                  `audit_id` bigint unsigned NOT NULL,
                  `col_name` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
                  `old_value` longtext CHARACTER SET utf8 COLLATE utf8_general_ci,
                  `new_value` longtext CHARACTER SET utf8 COLLATE utf8_general_ci,
                  PRIMARY KEY (`audit_meta_id`) USING BTREE,
                  KEY `log_meta_index` (`audit_id`,`col_name`) USING BTREE
                ) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb3;

                DROP TABLE IF EXISTS `" . $this->log_tbl_name . "`;
                CREATE TABLE `" . $this->log_tbl_name . "` (
                  `id` int NOT NULL AUTO_INCREMENT,
                  `user` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT '',
                  `table_name` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT '',
                  `pk1` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT '',
                  `action` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT '',
                  PRIMARY KEY (`id`)
                ) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=latin1;
        ";
        $this->runDBQuery($sql);
    }

    private function checkIfDBNameProvided()
    {
        if (empty($this->db_name)) {
            throw new Exception("No db name was found. Check you env file.");
        }
    }

    /**
     * Get all tables in the db specified.
     * Note: this also gets the view defined in the db.
     * @return array
     */
    public function getAllTableList()
    {
        $this->checkIfDBNameProvided();
        $data = DB::select("SELECT table_name FROM information_schema.tables  WHERE table_schema = '$this->db_name';");
        $table_name = array_map(function ($item) {
            return $item->TABLE_NAME;
        }, $data);
        return $table_name;
    }

    /**
     * Set the user id for the upcoming actions to be performed.
     * If not provided then mysql root id will be used.
     * @param $user_id
     * @return bool true if user id is set, false if user id is not set.
     */
    public function action_performed_by_user($user_id): bool
    {
        $sql = "set @login_user_id := '$user_id';";
        return $this->runDBQuery($sql);
    }

    /**
     * You should call this function after you have called action_performed_by_user() and are done with the crud operations.
     * or when you would like to switch the user id back to root.
     * Unset the user id that was set by action_performed_by_user.
     * @return bool
     */
    public function unset_action_performed_by_user(): bool
    {
        $sql = "set @login_user_id := null;";
        return $this->runDBQuery($sql);
    }

    /**
     * Calling this function refreshes the tracker linked on tables specified in config/dbwatcher.php
     * Just needs to be every time whenever you would update the values in the above config.
     * @return void
     * @throws Exception
     */
    public function refreshTracker()
    {
        $this->checkIfDBNameProvided();
        foreach ($this->tables_to_track as $tbl_obj) {
            if (!isset($tbl_obj["track_event"])) {
                throw new Exception("No track event was found for table: " . $tbl_obj["table"]);
            }
            if (count($tbl_obj["track_event"]) == 0) {
                $this->insert_tracker();
                $this->update_tracker();
                $this->delete_tracker();
            }
            if (in_array("insert", $tbl_obj["track_event"])) {
                $this->insert_tracker();
            }
            if (in_array("update", $tbl_obj["track_event"])) {
                $this->update_tracker();
            }
            if (in_array("delete", $tbl_obj["track_event"])) {
                $this->delete_tracker();
            }
        }

    }

    private function insert_tracker()
    {
        foreach ($this->tables_to_track as $tbl_obj) {
            if (!isset($tbl_obj["table"])) {
                throw new Exception("Couldn't find table key in config/dbwatcher.php");
            }
            $column_to_track = isset($tbl_obj["column"]) ? $tbl_obj["column"] : [];
            if (count($column_to_track)) {
                if (in_array("*", $column_to_track)) {
                    $column_to_track = [];
                }
            }
            $tbl = $tbl_obj["table"];
            $trigger_insert = "-- Start Insert Trigger " . Carbon::now()->toDateTimeString() . "\n\n";
            $trigger_name = "`$this->db_name`.`sb_tg_in_$tbl`";
            $full_tbl_name = "`$this->db_name`.`$tbl`";
            $trigger_insert .= "DROP TRIGGER IF EXISTS $trigger_name;\n";
            $trigger_insert .= "CREATE TRIGGER $trigger_name AFTER INSERT ON $full_tbl_name FOR EACH ROW \nBEGIN \n";
            $trigger_insert .= "DECLARE last_inserted_id BIGINT(20);\n";
            $primary_key = DB::select("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_NAME = N'$tbl' AND CONSTRAINT_NAME = 'PRIMARY'");
            $primary_key = $primary_key[0]->COLUMN_NAME;
            $trigger_insert .= "INSERT IGNORE INTO `$this->db_name`.$this->log_tbl_name (user, table_name, pk1, action)
            VALUE ( IFNULL( @login_user_id, USER() ), '$tbl', NEW.`$primary_key`, 'INSERT'); \n
            SET last_inserted_id = LAST_INSERT_ID();\n
            ";
            $data = DB::select("SELECT DATA_TYPE,COLUMN_NAME,COLUMN_DEFAULT,IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = N'$tbl'");
            $has_column_to_track = false;
            $trigger_insert_tmp = '';
            foreach ($data as $item) {
                if ((count($column_to_track) == 0) or in_array(strtolower($item->COLUMN_NAME), $column_to_track)) {
                    if (!in_array(strtolower($item->COLUMN_NAME), $this->common_columns_to_ignore)) {
                        $col_name = "'$item->COLUMN_NAME'";
                        $value = "NEW.`$item->COLUMN_NAME`";
                        $trigger_insert_tmp .= "(last_inserted_id, $col_name, NULL, $value),\n";
                        $has_column_to_track = true;
                    }
                }
            }
            if ($has_column_to_track) {
                $trigger_insert .= "INSERT IGNORE INTO `$this->db_name`.$this->log_meta_tbl_name (audit_id, col_name, old_value, new_value) VALUES \n";
                $trigger_insert .= $trigger_insert_tmp;
            }
            $trigger_insert = rtrim(trim($trigger_insert), ",\n");
            $trigger_insert .= ";\n";
            $trigger_insert .= "END;\n
            -- END for table Insert trigger `$this->db_name`.`$tbl`
            -- --------------------------------------------------------------------
            ";
            $this->runDBQuery($trigger_insert);
        }
    }

    private function update_tracker()
    {
        foreach ($this->tables_to_track as $tbl_obj) {
            if (!isset($tbl_obj["table"])) {
                throw new Exception("Couldn't find table key in config/dbwatcher.php");
            }
            $column_to_track = isset($tbl_obj["column"]) ? $tbl_obj["column"] : [];
            if (count($column_to_track)) {
                if (in_array("*", $column_to_track)) {
                    $column_to_track = [];
                }
            }
            $tbl = $tbl_obj["table"];
            $trigger_update = "
            -- ^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^
            -- --------------------------------------------------------------------
            -- Update Trigger Script For `$this->db_name`.`$tbl`
            -- Date Generated: " . Carbon::now()->toDateTimeString() . "
            -- BEGIN
            -- --------------------------------------------------------------------
            ";
            $trigger_name = "`$this->db_name`.`sb_tg_up_$tbl`";
            $full_tbl_name = "`$this->db_name`.`$tbl`";
            $trigger_update .= "DROP TRIGGER IF EXISTS $trigger_name;\n";
            $trigger_update .= "CREATE TRIGGER $trigger_name AFTER UPDATE ON $full_tbl_name FOR EACH ROW \nBEGIN \n";
            $trigger_update .= "DECLARE last_inserted_id BIGINT(20);\n";
            $primary_key = DB::select("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_NAME = N'$tbl' AND CONSTRAINT_NAME = 'PRIMARY'");
            $primary_key = $primary_key[0]->COLUMN_NAME;
            $trigger_update .= "INSERT IGNORE INTO `$this->db_name`.$this->log_tbl_name (user, table_name, pk1, action)
            VALUE ( IFNULL( @login_user_id, USER() ), '$tbl', OLD.`$primary_key`, 'UPDATE'); \n
            SET last_inserted_id = LAST_INSERT_ID();\n
            ";
            $data = DB::select("SELECT DATA_TYPE,COLUMN_NAME,COLUMN_DEFAULT,IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = N'$tbl'");
            foreach ($data as $item) {
                if ((count($column_to_track) == 0) or in_array(strtolower($item->COLUMN_NAME), $column_to_track)) {
                    if (!in_array(strtolower($item->COLUMN_NAME), $this->common_columns_to_ignore)) {
                        $trigger_update .= "IF (OLD.`$item->COLUMN_NAME` <> NEW.`$item->COLUMN_NAME`) THEN \n";
                        $trigger_update .= "    INSERT IGNORE INTO `$this->db_name`.$this->log_meta_tbl_name (audit_id, col_name, old_value, new_value) VALUES \n";
                        $trigger_update .= "    (last_inserted_id, '$item->COLUMN_NAME', OLD.`$item->COLUMN_NAME`, NEW.`$item->COLUMN_NAME`);\n";
                        $trigger_update .= "END IF;\n";
                    }
                }
            }
            $trigger_update .= "\n";
            $trigger_update .= "END;\n
            -- ^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^
            -- --------------------------------------------------------------------
            -- END for table update trigger `$this->db_name`.`$tbl`
            -- --------------------------------------------------------------------
            ";
            $this->runDBQuery($trigger_update);
        }
    }

    private function delete_tracker()
    {
        foreach ($this->tables_to_track as $tbl_obj) {
            if (!isset($tbl_obj["table"])) {
                throw new Exception("Couldn't find table key in config/dbwatcher.php");
            }
            $column_to_track = isset($tbl_obj["column"]) ? $tbl_obj["column"] : [];
            if (count($column_to_track)) {
                if (in_array("*", $column_to_track)) {
                    $column_to_track = [];
                }
            }
            $tbl = $tbl_obj["table"];
            $trigger_delete = "-- Start DELETE Trigger " . Carbon::now()->toDateTimeString() . "\n\n";
            $trigger_name = "`$this->db_name`.`sb_tg_dl_$tbl`";
            $full_tbl_name = "`$this->db_name`.`$tbl`";
            $trigger_delete .= "DROP TRIGGER IF EXISTS $trigger_name;\n";
            $trigger_delete .= "CREATE TRIGGER $trigger_name AFTER DELETE ON $full_tbl_name FOR EACH ROW \nBEGIN \n";
            $trigger_delete .= "DECLARE last_inserted_id BIGINT(20);\n";
            $primary_key = DB::select("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_NAME = N'$tbl' AND CONSTRAINT_NAME = 'PRIMARY'");
            $primary_key = $primary_key[0]->COLUMN_NAME;
            $trigger_delete .= "INSERT IGNORE INTO `$this->db_name`.$this->log_tbl_name (user, table_name, pk1, action)
            VALUE ( IFNULL( @login_user_id, USER() ), '$tbl', OLD.`$primary_key`, 'DELETE'); \n
            SET last_inserted_id = LAST_INSERT_ID();\n
            ";
            $data = DB::select("SELECT DATA_TYPE,COLUMN_NAME,COLUMN_DEFAULT,IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = N'$tbl'");
            $has_column_to_track = false;
            $trigger_delete_tmp = '';
            foreach ($data as $item) {
                if ((count($column_to_track) == 0) or in_array(strtolower($item->COLUMN_NAME), $column_to_track)) {
                    if (!in_array(strtolower($item->COLUMN_NAME), $this->common_columns_to_ignore)) {
                        $col_name = "'$item->COLUMN_NAME'";
                        $value = "OLD.`$item->COLUMN_NAME`";
                        $trigger_delete_tmp .= "(last_inserted_id, $col_name, $value,NULL),\n";
                        $has_column_to_track = true;
                    }
                }
            }
            if ($has_column_to_track) {
                $trigger_delete .= "INSERT IGNORE INTO `$this->db_name`.$this->log_meta_tbl_name (audit_id, col_name, old_value, new_value) VALUES \n";
                $trigger_delete .= $trigger_delete_tmp;
            }
            $trigger_delete = rtrim(trim($trigger_delete), ",\n");
            $trigger_delete .= ";\n";
            $trigger_delete .= "END;\n
            -- END for table Delete trigger `$this->db_name`.`$tbl`
            -- --------------------------------------------------------------------
            ";
            $this->runDBQuery($trigger_delete);
        }
    }

    private function runDBQuery($sql)
    {
        return DB::unprepared($sql);
    }

    /**
     * Run this function every day at midnight to clean up the no-related logs data.
     * @return void
     */
    public function cleanUp()
    {
        $sql = '
        DELETE FROM `' . $this->db_name . '`.`' . $this->log_tbl_name . '`
            WHERE id not in (
                SELECT audit_id FROM `' . $this->db_name . '`.`' . $this->log_meta_tbl_name . '`
            )
        ';
        DB::unprepared($sql);
    }

    public function __destruct()
    {
        $this->cleanUp();
        $this->unset_action_performed_by_user();
    }

}
