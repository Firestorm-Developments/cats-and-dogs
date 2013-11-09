<?php
/**
 * 
 * cats-and-dogs.php
 *
 * Copyright 2013 Bill Karwin
 * 
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

class DumpScript
{
    const VERSION = "10.13 Distrib 5.6.12, for PHP 5.5.1";

    protected $pdo;

    protected $gz;
    protected $stream;

    protected $dump_options = array(
        "add_drop_table" => true,
        "add_locks" => true,
        "comments" => true,
        "create_options" => true,
        "disable_keys" => true,
        "dump_date" => true, 
        "extended_insert" => true,
        "lock_tables" => true,
        "quick" => true, 
        "quote_names" => true,
        "set_charset" => true,
        "set_gtid_purged" => "AUTO",
        "triggers" => true,
        "tz_utc" => true, 
    );

    /**
     *
     */
    public function __construct(PDO $pdo, array $option_args = array())
    {
        $this->pdo = $pdo;

        // Figure out which databases to read
        $args = $this->parse_dump_options($option_args);
        if ($this->all_databases) {
            if ($args) {
                $this->dump_usage();
            }
            $stmt = $this->pdo->query("SHOW DATABASES WHERE `Database` NOT IN ('information_schema', 'performance_schema')");
            $databases = array();
            while ($db = $stmt->fetchColumn(0)) {
                $databases[] = $db;
            }
            $this->databases = $databases;
        } else {
            if ($args) {
                $this->databases = array_merge((array)$this->databases, array(array_shift($args)));
            }
            if ($args) {
                $this->tables = array_merge((array)$this->tables, $args);
            }
        }
    }

    /**
     *
     */
    public function dump($outputfile = 'php://stdout')
    {
        $this->get_mysql_config();
        $this->open_outputfile($outputfile);

        foreach ($this->databases as $db) {
	    $this->dump_header($db);
	    $this->dump_master_data();
	    $this->dump_database_current();
	    $this->dump_database_drop();
	    $this->dump_database_create();
	    $this->dump_tables();
	    $this->dump_events();
	    $this->dump_routines();
	    $this->dump_unlock();
        }

        $this->dump_footer();
	$this->close_outputfile();
    }

    /**
     *
     */
    protected function get_mysql_config()
    {
        $stmt = $this->pdo->query("SELECT @@hostname, @@version, @@net_buffer_length, @@log_bin");
        $this->mysql_config = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->net_buffer_length = $this->net_buffer_length ?: $this->mysql_config["@@net_buffer_length"];
	$this->default_character_set = $this->default_character_set ?: "utf8";
    }

    /**
     *
     */
    protected function dump_header($db)
    {
	if (!$this->compact) {
	    $version = self::VERSION;
	    $this->write(<<<"GO"
-- SQLScript {self::VERSION}
--
-- Host: {$this->mysql_config["@@hostname"]}    Database: $db
-- ------------------------------------------------------
-- Server version       {$this->mysql_config["@@version"]}


GO
	    );
	}

	if ($this->set_charset) {
	    $this->write(<<<"GO"
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES $this->default_character_set */;

GO
	    );
	}
	if ($this->tz_utc) {
	    $this->write(<<<"GO"
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;

GO
	    );
	}
	$this->write(<<<"GO"
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;


GO
	);
    }

    /**
     *
     */
    protected function dump_master_data()
    {
	if ($this->master_data) {
	    if (!$this->mysql_config["@@log_bin"]) {
		throw new Exception("mysqldump: Error: Binlogging on server not active");
	    }
	    $stmt = $this->pdo->query("SHOW MASTER STATUS");
	    $master = $stmt->fetch(PDO::FETCH_ASSOC);
	    $comment = $this->master_data == 2 ? "-- " : "";
	    $this->write(<<<"GO"
--
-- Position to start replication or point-in-time recovery from
--

{$comment}CHANGE MASTER TO MASTER_LOG_FILE='{$master["File"]}', MASTER_LOG_POS={$master["Position"]};


GO
	    );
	}
    }

    /**
     *
     */
    protected function dump_database_current()
    {
	$this->write(<<<"GO"
--
-- Current Database: `$db`
--


GO
	);
    }

    /**
     *
     */
    protected function dump_database_drop()
    {
	// Print DROP DATABASE
	if ($this->add_drop_database) {
	    $this->write(<<<"GO"
/*!40000 DROP DATABASE IF EXISTS `$db` */;

GO
	    );
	}
    }

    /**
     *
     */
    protected function dump_database_create()
    {
	// Print CREATE DATABASE
	if (!$this->no_create_db) {
	    $stmt = $this->pdo->query("SHOW CREATE DATABASE `$db`");
	    $create_db = $stmt->fetchColumn(1);
	    $create_db = str_replace("CREATE DATABASE", "CREATE DATABASE /*!32312 IF NOT EXISTS*/", $create_db);
	    $this->write(<<<"GO"
$create_db;

USE `$db`;

GO
	    );
	}
    }

    /**
     *
     */
    protected function dump_tables($db)
    {
	if (!$this->no_create_info || !$this->no_data) {
	    // Fetch list of tables for this database
	    $stmt = $this->pdo->query("SHOW FULL TABLES FROM `$db`");

	    // TODO: filter tables

	    $tables = $stmt->fetchAll(PDO::FETCH_NUM);

	    foreach ($tables as $tablerow) {
		$table = $tablerow[0];
		switch ($tablerow[1]) {
		case "BASE TABLE":
		    $this->dump_table($db, $table);
		    break;

	        case "VIEW":
		    $this->dump_view($db, $table);
		    break;

	        }
	    }
	}
    }

    /**
     *
     */
    protected function dump_table($db, $table)
    {
	$this->dump_table_drop_and_create($db, $table);
	$this->dump_table_data($db, $table);
	$this->dump_table_triggers($db, $table);
    }

    /**
     *
     */
    protected function dump_table_drop_and_create($db, $table)
    {
	if (!$this->no_create_info) {
	    $this->write(<<<"GO"

--
-- Table structure for table `$table`
--


GO
	    );
	    $stmt = $this->pdo->query("SHOW CREATE TABLE `$db`.`$table`");
	    $create_table = $stmt->fetchColumn(1);
	    if ($this->innodb_optimize_keys) {
		// @TODO Split out indexes to be created later, unless table is partitioned
	    }

	    // Print DROP TABLE
	    if ($this->add_drop_table) {
		$this->write(<<<"GO"
DROP TABLE IF EXISTS `$table`;

GO
		);
	    }
	    // Print CREATE TABLE
	    $this->write(<<<"GO"
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = {$this->default_character_set} */;
$create_table;
/*!40101 SET character_set_client = @saved_cs_client */;


GO
	    );
	}
    }

    /**
     *
     */
    protected function dump_table_data($db, $table)
    {

	// Dump data for table
	if (!$this->no_data) {
	    $this->write(<<<"GO"
--
-- Dumping data for table `$table`
--


GO
	    );

	    if ($this->add_locks) {
		$this->write(<<<"GO"
LOCK TABLES `$table` WRITE;

GO
		);
	    }
	    if ($this->disable_keys) {
		$this->write(<<<"GO"
/*!40000 ALTER TABLE `$table` DISABLE KEYS */;

GO
		);
	    }

	    // @TODO: read rows of data and write them out
	    $columns = array();
	    $stmt = $this->pdo->query("SHOW COLUMNS FROM `$db`.`$table`");
	    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
		$type = rtrim($row["Type"], "(0..9)");
		switch ($type) {
		case "tinyint":
		case "smallint":
		case "mediumint":
		case "int":
		case "bigint":
		case "float":
		case "double":
		case "decimal":
		case "numeric":
		case "year":
		    $select_list[] = "`{$row["Field"]}`";
		    break;
		default:
		    $select_list[] = "QUOTE(`{$row["Field"]}`)";
		    break;
		}
		$columns[] = "`{$row["Field"]}`";
	    }
	    $select_list = implode(",", $select_list);
	    $column_list = implode(",", $columns);

	    $sql = "SELECT $select_list FROM `$db`.`$table`";
	    if ($this->where) {
		$sql .= " WHERE {$this->where}";
	    }
	    if ($this->order_by_primary) {
		$stmt = $this->pdo->query("SELECT GROUP_CONCAT(CONCAT('`',column_name,'`') ORDER BY ORDINAL_POSITION) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE (TABLE_SCHEMA, TABLE_NAME, CONSTRAINT_NAME) = ('$db','$table','PRIMARY')");
		$primary_key_columns = $stmt->fetchColumn(0);
		$sql .= " ORDER BY $primary_key_columns";
	    }
	    if ($this->replace) {
		$command_init = "REPLACE";
	    } else if ($this->insert_ignore) {
		$command_init = "INSERT IGNORE";
	    } else {
		$command_init = "INSERT";
	    }
	    $command_init .= " INTO `$table`";
	    if ($this->complete_insert) {
		$command_init .= " ($column_list)";
	    }
	    $command_init .= " VALUES";
	    $len_init = strlen($command_init);
	    $len = $net_buffer_length;

	    $stmt = $this->pdo->query($sql);
	    $command = "";
	    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
		$tuple = "(" . implode(",", $row) . ")";
		$tuple_len = strlen($tuple);
		if ($len + $tuple_len > $net_buffer_length) {
		    if ($command) {
			$this->write(<<<"GO"
$command;

GO
			);
		    }
		    $command = "$command_init $tuple";
		    $len = $len_init + 1 + $tuple_len;
		} else {
		    $command .= ",$tuple";
		}
	    }
	    if ($command) {
		$this->write(<<<"GO"
$command;

GO
		);
	    }

	    $this->dump_table_enable_keys($db, $table);

	    $this->dump_table_optimize_keys($db, $table);

	}
    }

    /**
     *
     */
    protected function dump_table_enable_keys($db, $table)
    {
	if ($this->disable_keys) {
	    $this->write(<<<"GO"
/*!40000 ALTER TABLE `$table` ENABLE KEYS */;

GO
	    );
	}
    }

    /**
     *
     */
    protected function dump_table_optimize_keys($db, $table)
    {
	if (!$this->no_create_info && $this->innodb_optimize_keys) {
	    // @TODO: finally ALTER TABLE to create indexes on populated table
	    // @TODO: run ANALYZE TABLE because 5.6 enables persistent stats by default now
	}
    }

    /**
     *
     */
    protected function dump_table_triggers($db, $table)
    {
	if ($this->triggers) {
	    // @TODO: Fetch list of triggers for this table
	}
    }

    /**
     *
     */
    protected function dump_view($db, $view)
    {
	$stmt = $this->pdo->query("SHOW CREATE VIEW `$table`");
	$row = $stmt->fetch(PDO::FETCH_NUM);
	$create_view = $row[1];
	// @TODO: Print CREATE OR REPLACE VIEW
    }

    /**
     *
     */
    protected function dump_events()
    {
	if ($this->events) {
	    // @TODO: Fetch list of events for this database
	}
    }

    /**
     *
     */
    protected function dump_routines()
    {
	if ($this->routines) {
	    // @TODO: Fetch list of routines for this database
	}
    }

    /**
     *
     */
    protected function dump_unlock()
    {
	if (!$this->no_data && $this->add_locks) {
	    $this->write(<<<"GO"
UNLOCK TABLES;

GO
	    );
	}
    }

    /**
     *
     */
    protected function dump_footer()
    {
        // Output footer
        if (!$this->compact) {
            if ($this->tz_utc) {
                $this->write(<<<"GO"
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;


GO
                );
            }

            $this->write(<<<"GO"
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;

GO
            );

            if ($this->set_charset) {
                $this->write(<<<"GO"
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

GO
                );
            }
            $this->write(<<<"GO"
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;


GO
            );

            if ($this->dump_date) {
                $dump_completed = "on " . date("Y-m-d H:i:s");
            } else {
                $dump_completed = "";
            }
            $this->write(<<<"GO"
-- Dump completed $dump_completed

GO
            );
        }
    }

    /**
     *
     */
    protected function dump_usage()
    {
        throw new Exception(<<<"USAGE"
Usage: mysqldump [OPTIONS] database [tables]
OR     mysqldump [OPTIONS] --databases [OPTIONS] DB1 [DB2 DB3...]
OR     mysqldump [OPTIONS] --all-databases [OPTIONS]
For more options, use mysqldump --help
USAGE
        );
    }

    /**
     *
     */
    public function __get($option)
    {
        if (array_key_exists($option, $this->dump_options)) {
            return $this->dump_options[$option];
        } else {
            return null;
        }
    }

    /**
     *
     */
    public function __set($option, $value)
    {
        $this->dump_options[$option] = $value;
    }

    /**
     *
     */
    protected function parse_dump_options(array $option_args)
    {
        while ($option_args) {
            if ($option_args[0] == "--" || $option_args[0][0] != "-") {
                break;
            }

            $option_and_arg = explode("=", array_shift($option_args));
            $option = array_shift($option_and_arg);
            $arg    = array_shift($option_and_arg);

            $option = strtr(ltrim($option, "-"), "-", "_");

            // Single-character option aliases
            switch ($option) {
                case "#": $option = "debug"; break;
                case "?": $option = "help"; break;
                case "A": $option = "all_databases"; break;
                case "B": $option = "databases"; break;
                case "E": $option = "events"; break;
                case "F": $option = "flush_logs"; break;
                case "I": $option = "help"; break;
                case "K": $option = "disable_keys"; break;
                case "N": $option = "no_set_names"; break;
                case "P": $option = "port"; break;
                case "Q": $option = "quote_names"; break;
                case "R": $option = "routines"; break;
                case "S": $option = "socket"; break;
                case "T": $option = "tab"; break;
                case "V": $option = "version"; break;
                case "W": $option = "pipe"; break;
                case "X": $option = "xml"; break;
                case "Y": $option = "all_tablespaces"; break;
                case "c": $option = "complete_insert"; break;
                case "d": $option = "no_data"; break;
                case "e": $option = "extended_insert"; break;
                case "f": $option = "force"; break;
                case "h": $option = "host"; break;
                case "i": $option = "comments"; break;
                case "n": $option = "no_create_db"; break;
                case "p": $option = "password"; break;
                case "r": $option = "result_file"; break;
                case "t": $option = "no_create_info"; break;
                case "u": $option = "user"; break;
                case "x": $option = "lock_all_tables"; break;
                case "y": $option = "no_tablespaces"; break;
                case "no_set_names": $option = "skip_set_charset";
            }

            $group_setting = true;

            switch ($option) {

                // Option affects group of other options
                case "skip_opt":
                    $group_setting = false;
                case "opt":
                    $this->add_drop_table =
                    $this->add_locks =
                    $this->create_options =
                    $this->disable_keys =
                    $this->extended_insert =
                    $this->lock_tables =
                    $this->quick =
                    $this->set_charset = $group_setting;
                    break;

                // Option affects group of other options
                case "compact":
                    $group_setting = false;
                case "skip_compact":
                    $this->add_drop_table =
                    $this->add_locks =
                    $this->comments =
                    $this->disable_keys =
                    $this->set_charset = $group_setting;
                    $this->compact = ! $group_setting;
                    break;

                // Option affects group of other options
                case "xml":
                    $this->extended_insert = 
                    $this->add_drop_table = 
                    $this->add_locks = 
                    $this->disable_keys =
                    $this->autocommit = false;
                    $this->no_create_db = true;
                    break;

                // Options with optional arguments
                case "debug":
                    $this->debug = $arg ?: 'd:t:o,/tmp/mysqldump.trace';
                    break;
                case "master_data": // default 1
                    $this->master_data = $arg ?: 1;
                    break;

                // Options with mandatory arguments
                case "character_sets_dir":
                case "compatible":
                case "default_character_set":
                case "dump_slave":
                case "fields_terminated_by":
                case "fields_enclosed_by":
                case "fields_optionally_enclosed_by":
                case "fields_escaped_by":
                case "ignore_table":
                case "lines_terminated_by":
                case "log_error":
                case "result_file":
                case "set_gtid_purged":
                case "tab":
                case "where":
                    if (!isset($arg)) {
                        if (!isset($option_args[0]) || $option_args[0][0] == "-") {
                            throw new Exception("Option '$option' required an argument.");
                        }
                        $arg = array_shift($option_args);
                    }
                    $this->$option = $arg;
                    break;

                // Options with multiple arguments
                case "databases":
                case "tables":
                    if (!isset($arg)) {
                        if (!isset($option_args[0]) || $option_args[0][0] == "-") {
                            throw new Exception("Option '$option' required an argument.");
                        }
                        while ($option_args && $option_args[0][0] != "-") {
                            $item = array_shift($option_args);
                            $this->$option = array_merge((array)$this->$option, array($item));
                        }
                    }
                    break;

                // Negation of boolean options
                case "skip_add_drop_table":
                case "skip_add_locks":
                case "skip_comments":
                case "skip_create_options":
                case "skip_disable_keys":
                case "skip_dump_date":
                case "skip_extended_insert":
                case "skip_quick":
                case "skip_quote_names":
                case "skip_set_charset":
                case "skip_triggers":
                case "skip_tz_utc":
                    $skip_option = substr_replace($option, "", 2, 5);
                    $this->$skip_option = false;
                    break;

                // Boolean options
                case "add_drop_database":
                case "add_drop_table":
                case "add_drop_trigger":
                case "add_locks":
                case "all_databases":
                case "all_tablespaces":
                case "allow_keywords":
                case "apply_slave_statements":
                case "comments":
                case "complete_insert":
                case "create_options":
                case "debug_check":
                case "debug_info":
                case "delayed_insert":
                case "delete_master_logs":
                case "disable_keys":
                case "dump_date":
                case "events":
                case "extended_insert":
                case "flush_logs":
                case "flush_privileges":
                case "force":
                case "help":
                case "hex_blob":
                case "include_master_host_port":
                case "innodb_optimize_keys":
                case "insert_ignore":
                case "lock_all_tables":
                case "lock_tables":
                case "no_autocommit":
                case "no_create_db":
                case "no_create_info":
                case "no_data":
                case "no_tablespaces":
                case "order_by_primary":
                case "pipe":
                case "quick":
                case "quote_names":
                case "replace":
                case "routines":
                case "set_charset":
                case "single_transaction":
                case "triggers":
                case "tz_utc":
                case "verbose":
                case "version":
                case "xml":
                    $this->$option = true;
                    break;

                // Connection options, moot because PDO object is required
                case "bind_address":
                case "default_auth":
                case "defaults_file":
                case "defaults_group_suffix":
                case "host":
                case "password":
                case "plugin_dir":
                case "port":
                case "protocol":
                case "socket":
                case "ssl*":
                case "user":
                    array_shift($option_args);
                    break;

                default:
                    throw new Exception("Option '$option' not yet supported.");
            }
        }
        return $option_args;
    }

    /**
     *
     */
    protected function open_outputfile($outputfile)
    {
        // Open outputfile
/*
        $ext = pathinfo($outputfile);
        if ($this->gz = ($ext["extension"] == "gz")) {
            if (!extension_loaded("zlib")) {
                throw new Exception("Zlib extension not loaded.");
            }
            if (($this->stream = gzopen($outputfile, "wb")) === false) {
                throw new Exception("Could not open output file.");
            }
        } else
*/
	{
            if (($this->stream = fopen($outputfile, "w")) === false) {
                throw new Exception("Could not open output file.");
            }
        }
    }

    /**
     *
     */
    protected function write($string)
    {
        if ($this->gz) {
            gzwrite($this->stream, $string);
        } else {
            if (fwrite($this->stream, $string) === false) {
                throw new Exception("Can't write '$string'");
            }
        }
    }

    /**
     *
     */
    protected function close_outputfile()
    {
        // Close output file
        if ($this->gz) {
            gzclose($this->stream);
        } else {
            fclose($this->stream);
        }
    }

}
