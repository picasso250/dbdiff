<?php
/**
 * Created by PhpStorm.
 * User: xiaochi
 * Date: 2018/9/29
 * Time: 下午1:40
 */

$file = ".env";
$config = parse_ini_file($file, true);
$pdo = null;

// diff a => b (a want to be b)

if ($argc != 3) {
    echo "USAGE: $argv[0] from to\n";
    exit;
}

$a = db_def($argv[1]);
$b = db_def($argv[2]);

$c = array_diff_key($b, $a);
foreach ($c as $table_name => $def) {
    echo "create table $table_name\n";
}

$it = array_intersect_key($a, $b);
foreach ($it as $table_name => $_) {
    echo "-- # $table_name\n";
    $ta = $a[$table_name];
    $tb = $b[$table_name];

    if($ta['pkey']!==$tb['pkey']) {
        echo "pkey change from $ta[pkey] to $tb[pkey]\n";
    }
    if($ta['comment']!==$tb['comment']) {
        echo "comment change from $ta[comment] to $tb[comment]\n";
    }

    $col_add = array_diff_key($tb['column'], $ta['column']);
    foreach ($col_add  as $ca => $_) {
        echo "-- add col $ca {$tb['column'][$ca]}\n";
        echo "alter table `$table_name` add column `$col` {$tb['column'][$ca]};\n";
    }

    $_it = array_intersect_key($ta['column'], $tb['column']);
    foreach ($_it as $col => $_) {
        if ($ta['column'][$col]!=$tb['column'][$col]) {
            echo "-- col change from {$ta['column'][$col]} to {$tb['column'][$col]}\n";
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
    return [
        'pkey' => $pkey,
        'column' => $columns,
        'index' => $indexs,
        'comment' => preg_replace('/ AUTO_INCREMENT=\d+/', '', trim($c)),
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