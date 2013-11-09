<?php
/**
 * 
 * This is used for testing sqlscript.php.
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

$log = fopen("err-mysqldump.php", "w");

// defaults
$host = "localhost";
$user = "root";
$pass = "root";

$opts = getopt("h:u:p::", array("defaults-file:", "defaults-extra-file:", "login-path:",
    "defaults-group-suffix:", "no-defaults", "host:", "user:", "password::"));

$defaults_files = array(
    "/etc/my.cnf",
);
if ($home = getenv("HOME")) {
    $defaults_files[] = "$home/.my.cnf";
}
$suffix = "";
foreach ($opts as $opt => $value) {
    switch ($opt) {
    case "defaults-file":
        $defaults_files = array($value);
        break;
    case "defaults-extra-file":
    case "login-path":
        $defaults_files[] = $value;
        break;
    case "defaults-group-suffix":
	$defaults_suffix = $value;
        break;
    case "no-defaults":
	$defaults_files = array();
        break;
    }
}

$connect_opts = array(
    "user" => trim(`whoami`),
    "password" => "",
    "host" => "localhost",
    "port" => 3306,
    "charset" => "utf8"
);

foreach ($defaults_files as $file) {
    $ini = parse_ini_file($file, true);
    if (array_key_exists("client$suffix", $ini)) {
	$connect_opts = array_merge($connect_opts, $ini["client$suffix"]);
    }
    if (array_key_exists("mysqldump$suffix", $ini)) {
	$connect_opts = array_merge($connect_opts, $ini["mysqldump$suffix"]);
    }
}

foreach ($opts as $opt => $value) {
    switch ($opt) {
    case "h": case "host":
        $connect_opts["host"] = $value;
        break;
    case "u": case "user":
        $connect_opts["user"] = $value;
        break;
    case "p": case "password":
        if ($value === false) {
            echo "Password: ";
            system('stty -echo');
            $value = trim(fgets(STDIN));
            system('stty echo');
            // add a new line since the users CR didn't echo
            echo "\n";
        }
        $connect_opts["password"] = $value;
        break;
    case "P": case "port":
        $connect_opts["port"] = $value;
        break;
    case "S": case "socket":
        $connect_opts["socket"] = $value;
        break;
    case "default-character-set":
        $connect_opts["charset"] = $value;
        break;
    }
}

try {
    $dsn = "host=".$connect_opts["host"]
     .";port=".$connect_opts["port"]
     .";unix_socket=".$connect_opts["socket"]
     .";charset=".$connect_opts["charset"];
    $pdo = new PDO("mysql:$dsn", $connect_opts["user"], $connect_opts["password"]);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
} catch(PDOException $e) {
    die($e->getMessage()."\n");
}

require_once "/home/billkarwin/bin/mysql-cats.php";

$program = basename(array_shift($argv));
$option_args = array_merge(array("--default-character-set", $connect_opts["charset"]), $argv);

try {
    $script = new DumpScript($pdo, $option_args);
    $script->dump();
} catch (Exception $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    exit(2);
}

exit(0);
