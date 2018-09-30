<?php
/**
 * Created by PhpStorm.
 * User: xiaochi
 * Date: 2018/9/29
 * Time: 下午1:40
 */

// Create new Colors class
$colors = new Colors();

$file = ".env";
$config = parse_ini_file($file, true);
$pdo = null;

// diff a => b (a want to be b)

if ($argc != 3) {
    echo "USAGE: $argv[0] from to\n";
    exit;
}

$ignore_tables = [];
if(getenv('IGNORE')) {
    $ignore_tables = explode(",", getenv('IGNORE'));
}

$a = db_def($argv[1]);
$b = db_def($argv[2]);

$c = array_diff_key($b, $a);
foreach ($c as $table_name => $def) {
    echo "create table $table_name\n";
}

$it = array_intersect_key($a, $b);
foreach ($it as $table_name => $_) {
    if(in_array($table_name, $ignore_tables)) continue;
    // echo "-- # $table_name\n";
    $ta = $a[$table_name];
    $tb = $b[$table_name];

    if($ta['pkey']!==$tb['pkey']) {
        echo "pkey change from $ta[pkey] to $tb[pkey]\n";
    }
    if($ta['comment']!==$tb['comment']) {
        echo "-- comment change from ".$colors->getColoredString($ta['comment'],'red')." to ".$colors->getColoredString($tb['comment'],'green')."\n";
        echo "ALTER TABLE `$table_name` {$tb['comment']};\n";
    }

    if($ta['modifier']!==$tb['modifier']) {
        echo "modifier change from $ta[modifier] to $tb[modifier]\n";
    }

    $col_add = array_diff_key($tb['column'], $ta['column']);
    foreach ($col_add  as $ca => $_) {
        echo "-- add column $ca ".$colors->getColoredString($tb['column'][$ca],"green")."\n";
        echo "ALTER TABLE `$table_name` add column `$col` {$tb['column'][$ca]};\n";
    }

    $_it = array_intersect_key($ta['column'], $tb['column']);
    foreach ($_it as $col => $_) {
        if ($ta['column'][$col]!=$tb['column'][$col]) {
            echo "-- column change ".$colors->getColoredString($ta['column'][$col],'red')." => ".$colors->getColoredString($tb['column'][$col], "green") . "\n";
            echo "ALTER TABLE `$table_name` CHANGE COLUMN `$col` `$col` {$tb['column'][$col]};\n";
        }
    }

    $col_add = array_diff_key($tb['index'], $ta['index']);
    foreach ($col_add  as $ca => $_) {
        echo "add index $ca {$tb['index'][$ca]}\n";
    }

    $_it = array_intersect_key($ta['index'], $tb['index']);
    foreach ($_it as $k => $_) {
        if ($ta['index'][$k]!=$tb['index'][$k]) {
            echo "index change from {$ta['index'][$k]} to {$tb['index'][$k]}\n";
        }
    }
}

function db_def($env) {

    global $config;
    global $pdo;
    global $ssh;

    $conf = $config[$env];

    if (isset($conf['ssh'])) {
        $ssh = $conf['ssh'];
        echo "-- $ssh\n";
    } else {
        $ssh = '';
        echo "-- $conf[dsn]\n";
        $pdo = new PDO($conf['dsn'], $conf['username'], $conf['password']);
    }

    $db_struct = [];

    $a = fetchAll("show tables");

    foreach ($a as $aa) {
        $table_name = $aa[0];
        $b = fetch("show create table `$table_name`");
        $create = $b[1];
        if (!preg_match('/^CREATE TABLE `([\w_]+)` \((.+)\)(.+?)$/s', $create, $m)) {
            echo "no sql create table $create\n";
            exit(1);
        }
        $db_struct[$table_name] = table_def($m[2], $m[3]);
    }

    return $db_struct;
}

function table_def($cols_s, $c) {
    $pkey = [];
    $columns = [];
    $indexs = [];
    $cols = array_filter(array_map('trim', preg_split("/\n/", $cols_s)));
    foreach ($cols as $col_line) {
        $col_line = trim($col_line);
        $col_line = rtrim($col_line, ",");
        if (preg_match('/^(?P<mod>(UNIQUE|FULLTEXT|SPATIAL) )?KEY `(?P<name>[\w_]+)` (?P<key>.+)$/', $col_line, $m)) {
            $indexs[$m['name']] = index_def($m);
        } elseif (preg_match('/^PRIMARY KEY (.+?)$/', $col_line, $m)) {
            $pkey = pkey_def($m[1]);
        } elseif (preg_match('/^`([\w_]+)` (.+)$/', $col_line, $m)) {
            $columns[$m[1]] = $m[2];
        } else {
            echo "unknown $col_line\n";
            exit(1);
        }
    }
    if(!preg_match('/^(.+?)( COMMENT=\'.+\')?$/', $c, $m)) {
        die("$c parse error");
    }
    return [
        'pkey' => $pkey,
        'column' => $columns,
        'index' => $indexs,
        'modifier' => preg_replace('/ AUTO_INCREMENT=\d+/', '', trim($m[1])),
        'comment' => isset($m[2]) ? $m[2] :'',
    ];
}

function names_parse($s) {
    return array_map(function ($name) {
        return trim($name, '` ');
    }, explode(',', $s));
}
function index_def($m) {
    $modifier = '';
    if (isset($m['mod'])) {
        $modifier = trim($m['mod']);
    }
    return [
        'modifier' => $modifier,
        'cols' => $m['key'],
    ];
}
function pkey_def($s) {
    return $s;
}

function execute($sql, $vars=[]) {
    global $pdo;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($vars);
    return $stmt;
}
function fetchAll($sql, $vars = []) {
    global $ssh;
    if ($ssh) {
        $cmd = "echo ".escapeshellarg($sql)." | $ssh";
//        echo "$cmd\n";
        $ret = shell_exec($cmd);
        if (!$ret) return [];
        $lines = explode("\n", $ret);
        $a = array_map(function ($l) {
            return array_map(function ($s) {
                return str_replace('\\n', "\n", $s);
            }, explode("\t", trim($l)));
        }, $lines);
        return array_slice($a, 1, count($lines) - 2);
    } else {
        $stmt = execute($sql, $vars);
        $a = $stmt->fetchAll();
        return $a ?: [];
    }
}
function fetch($sql, $vars=[]) {
    $a = fetchAll($sql, $vars);
    return $a ? $a[0] : $a;
}

class Colors {
    private $foreground_colors = array();
    private $background_colors = array();

    public function __construct() {
        // Set up shell colors
        $this->foreground_colors['black'] = '0;30';
        $this->foreground_colors['dark_gray'] = '1;30';
        $this->foreground_colors['blue'] = '0;34';
        $this->foreground_colors['light_blue'] = '1;34';
        $this->foreground_colors['green'] = '0;32';
        $this->foreground_colors['light_green'] = '1;32';
        $this->foreground_colors['cyan'] = '0;36';
        $this->foreground_colors['light_cyan'] = '1;36';
        $this->foreground_colors['red'] = '0;31';
        $this->foreground_colors['light_red'] = '1;31';
        $this->foreground_colors['purple'] = '0;35';
        $this->foreground_colors['light_purple'] = '1;35';
        $this->foreground_colors['brown'] = '0;33';
        $this->foreground_colors['yellow'] = '1;33';
        $this->foreground_colors['light_gray'] = '0;37';
        $this->foreground_colors['white'] = '1;37';

        $this->background_colors['black'] = '40';
        $this->background_colors['red'] = '41';
        $this->background_colors['green'] = '42';
        $this->background_colors['yellow'] = '43';
        $this->background_colors['blue'] = '44';
        $this->background_colors['magenta'] = '45';
        $this->background_colors['cyan'] = '46';
        $this->background_colors['light_gray'] = '47';
    }

    // Returns colored string
    public function getColoredString($string, $foreground_color = null, $background_color = null) {
        $colored_string = "";

        // Check if given foreground color found
        if (isset($this->foreground_colors[$foreground_color])) {
            $colored_string .= "\033[" . $this->foreground_colors[$foreground_color] . "m";
        }
        // Check if given background color found
        if (isset($this->background_colors[$background_color])) {
            $colored_string .= "\033[" . $this->background_colors[$background_color] . "m";
        }

        // Add string and end coloring
        $colored_string .=  $string . "\033[0m";

        return $colored_string;
    }

    // Returns all foreground color names
    public function getForegroundColors() {
        return array_keys($this->foreground_colors);
    }

    // Returns all background color names
    public function getBackgroundColors() {
        return array_keys($this->background_colors);
    }
}