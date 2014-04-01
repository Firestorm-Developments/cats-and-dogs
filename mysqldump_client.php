<?php
/**
 * 
 * This is used for testing mysql_dump.php.
 *
 * Copyright (c) 2014, Bill Karwin
 * All rights reserved.
 * 
 * Redistribution and use in source and binary forms, with or without 
 * modification, are permitted provided that the following conditions are met:
 * 
 * 1. Redistributions of source code must retain the above copyright notice, this 
 * list of conditions and the following disclaimer.
 * 
 * 2. Redistributions in binary form must reproduce the above copyright notice, 
 * this list of conditions and the following disclaimer in the documentation 
 * and/or other materials provided with the distribution.
 * 
 * 3. Neither the name of the copyright holder nor the names of its contributors 
 * may be used to endorse or promote products derived from this software without 
 * specific prior written permission.
 * 
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND 
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED 
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE 
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE 
 * FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL 
 * DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR 
 * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER 
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, 
 * OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE 
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 */

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
    if (!file_exists($file)) {
	continue;
    }
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
    $dsn = "host=".$connect_opts["host"];
    if (isset($connect_opts["port"])) { $dsn .= ";port=".$connect_opts["port"]; }
    if (isset($connect_opts["socket"])) { $dsn .= ";unix_socket=".$connect_opts["socket"]; }
    if (isset($connect_opts["charset"])) { $dsn .= ";charset=".$connect_opts["charset"]; }
    $pdo = new \PDO("mysql:$dsn", $connect_opts["user"], $connect_opts["password"]);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
} catch(PDOException $e) {
    die($e->getMessage()."\n");
}

require_once "mysql_dump.php";

$program = basename(array_shift($argv));
$option_args = array_merge(array("--default-character-set", $connect_opts["charset"]), $argv);

try {
    $script = new MySQL\Dump($pdo, $option_args);
    $script->dump();
} catch (Exception $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    exit(2);
}

exit(0);
