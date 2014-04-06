<?php
/**
 * This file is designed to be the new 'server' of sites using StaticPublisher.
 * to use this, you need to modify your .htaccess to point all requests to
 * static-main.php, rather than main.php. This file also allows for using
 * static publisher with the subsites module.
 *
 * If you are using StaticPublisher+Subsites, set the following in _config.php:
 *   FilesystemPublisher::$domain_based_caching = true;
 * and added main site host mapping in subsites/host-map.php after everytime a new subsite is created or modified
 *
 * If you are not using subsites, the host-map.php file will not exist (it is
 * automatically generated by the Subsites module) and the cache will default
 * to no subdirectory.
 */

// Configuration
define('CACHE_ENABLED', true);
define('CACHE_DEBUG', false);
define('CACHE_BASE_DIR', '../cache/'); // Should point to the same folder as FilesystemPublisher->destFolder

define('CACHE_CLIENTSIDE_EXPIRY', 5); // How long the client should be allowed to cache for before re-checking

// Optional settings for FilesystemPublisher::$domain_based_mapping=TRUE
define('CACHE_HOSTMAP_LOCATION', '../subsites/host-map.php');
define('CACHE_HOMEPAGE_MAP_LOCATION', '../assets/_homepage-map.php');

// Calculated constants
if(!defined('BASE_PATH')) {
	// Assuming that this file is sapphire/static-main.php we can then determine the base path
	define('BASE_PATH', rtrim(dirname(dirname(__FILE__))), DIRECTORY_SEPARATOR);
}

if (CACHE_ENABLED
    // Allow skipping static cache via cookie
    && empty($_COOKIE['bypassStaticCache'])
    // No GET params other than cache relevant config is passed (e.g. "?stage=Stage"),
    // which would mean that we have to bypass the cache
    && count(array_diff(array_keys($_GET), array('url', 'cacheSubdir'))) == 0
    // Request is not POST (which would have to be handled dynamically)
    && count($_POST) == 0
) {
	// Define system paths (copied from Core.php)
	if(!defined('BASE_URL')) {
		// Determine the base URL by comparing SCRIPT_NAME to SCRIPT_FILENAME and getting the common elements
		if(substr($_SERVER['SCRIPT_FILENAME'],0,strlen(BASE_PATH)) == BASE_PATH) {
			$urlSegmentToRemove = substr($_SERVER['SCRIPT_FILENAME'],strlen(BASE_PATH));
			if(substr($_SERVER['SCRIPT_NAME'],-strlen($urlSegmentToRemove)) == $urlSegmentToRemove) {
				$baseURL = substr($_SERVER['SCRIPT_NAME'], 0, -strlen($urlSegmentToRemove));
				define('BASE_URL', rtrim($baseURL, DIRECTORY_SEPARATOR));
			}
		}
	}

	$url = $_GET['url'];
	// Remove base folders from the URL if webroot is hosted in a subfolder
	if (substr(strtolower($url), 0, strlen(BASE_URL)) == strtolower(BASE_URL)) {
		$url = substr($url, strlen(BASE_URL));
	}

	$host = str_replace('www.', '', $_SERVER['HTTP_HOST']);

	if (isset($_GET['cacheSubdir']) && !preg_match('/[^a-zA-Z0-9\-_]/', $_GET['cacheSubdir'])) {
		// Custom cache dir for debugging purposes
		$cacheDir = $_GET['cacheSubdir'].'/';
	} elseif (file_exists(CACHE_HOSTMAP_LOCATION)) {
		// Custom mapping through PHP file (assumed FilesystemPublisher::$domain_based_mapping=TRUE)
		include_once CACHE_HOSTMAP_LOCATION;
		$subsiteHostmap['default'] = isset($subsiteHostmap['default']) ? $subsiteHostmap['default'] : '';
		$cacheDir = (isset($subsiteHostmap[$host]) ? $subsiteHostmap[$host] : $subsiteHostmap['default']) . '/';
	} else {
		// No subfolder (for FilesystemPublisher::$domain_based_mapping=FALSE)
		$cacheDir = '';
	}

	// Look for the file in the cachedir
	$file = trim($url, '/');
	$file = $file ? $file : 'index';

	// Route to the 'correct' index file (if applicable)
	if ($file == 'index' && file_exists(CACHE_HOMEPAGE_MAP_LOCATION)) {
		include_once CACHE_HOMEPAGE_MAP_LOCATION;
		$file = isset($homepageMap[$_SERVER['HTTP_HOST']]) ? $homepageMap[$_SERVER['HTTP_HOST']] : $file;
	}

	// Find file by extension (either *.html or *.php)
	$file = preg_replace('/[^a-zA-Z0-9\/\-_]/si', '-', $file);
	$path = CACHE_BASE_DIR . $cacheDir . $file;

	$respondWith = null;

	if (file_exists($path.'.html')) {
		$respondWith = array('html', $path.'.html');
	} elseif (file_exists(strtolower($path).'.html')) {
		$respondWith = array('stale.html', strtolower($path).'.html');
	} elseif (file_exists($path.'.stale.html')) {
		$respondWith = array('stale.html', $path.'.stale.html');
	} elseif (file_exists(strtolower($path).'.stale.html')) {
		$respondWith = array('stale.html', strtolower($path).'.stale.html');
	} elseif (file_exists($path.'.php')) {
		$respondWith = array('php', $path.'.php');
	}

	if ($respondWith) {
		// Cache hit. Spit out some headers and then the cache file
		header('X-SilverStripe-Cache: hit at '.@date('r').' returning '.$file.'.'.$respondWith[0]);

		header('Expires: '.gmdate('D, d M Y H:i:s', time() + CACHE_CLIENTSIDE_EXPIRY).' GMT');
		header('Cache-Control: max-age='.CACHE_CLIENTSIDE_EXPIRY.", must-revalidate");
		header('Pragma:');

		if ($respondWith[0] == 'php') include_once($respondWith[1]);
		else readfile($respondWith[1]);

		if (CACHE_DEBUG) echo "<h1>File WAS cached</h1>";
	} else {
		// No cache hit... fallback to dynamic routing
		header('X-SilverStripe-Cache: miss at '.@date('r').' on '.$cacheDir.$file);
		include BASE_PATH.DIRECTORY_SEPARATOR.'framework/main.php';
		if (CACHE_DEBUG) echo "<h1>File was NOT cached</h1>";
	}
} else {
	// Fall back to dynamic generation via normal routing if caching has been explicitly disabled
	include BASE_PATH.DIRECTORY_SEPARATOR.'framework/main.php';
}

?>
