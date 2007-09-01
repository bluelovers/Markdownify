<?php
header('Content-Type:text/plain; charset=utf-8');

define('COL_WIDTH', 180);
define('DIFF_FGCOLOR_D', 'white');
define('DIFF_BGCOLOR_D', 'red');
define('DIFF_FGCOLOR_A', 'white');
define('DIFF_BGCOLOR_A', 'green');
define('DIFF_FGCOLOR_C', 'white');
define('DIFF_BGCOLOR_C', 'brown');

error_reporting(E_ALL);
ini_set('html_errors', false);

chdir(dirname(__FILE__).'/..');

require_once('parsehtml.php');
require_once('markdownify.php');
require_once('test/folder.php');
require_once('test/functions.php');
require_once('test/test.class.php');

$test = new test;

if ($tc = param('test')) {
	if (!file_exists('MDTest/Markdown.mdtest/'.$tc.'.html')) {
		trigger_error('Testcase '.$tc.' could not be found!', E_USER_ERROR);
	}
	$test->run($tc, 'MDTest/Markdown.mdtest/'.$tc);
	die();
}
$testCases = new folder('MDTest/Markdown.mdtest');

while ($testCases->read()) {
	if (substr($testCases->file, -5) != '.html') {
		continue;
	}
	$test->run(substr($testCases->file, 0, -5), substr($testCases->path, 0, -5));
}