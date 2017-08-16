<?php 
/**
 * This file stores default settings for Kaltura html5 client library "mwEmbed".
 * 
 *  DO NOT MODIFY THIS FILE. Instead modify LocalSettings.php in the parent mwEmbd directory. 
 * 
 */

// The default cache directory
$wgScriptCacheDirectory = realpath( dirname( __FILE__ ) ) . '/cache';

// The absolute or relative path to mwEmbed install folder.
// by default its the entry point minus the entry point name:
$wgMwEmbedPathUrl = str_replace( 
	// List entry points: 
	array( 'mwEmbedFrame.php', 'ResourceLoader.php',  'mwEmbedLoader.php'),
	'', 
	$_SERVER['SCRIPT_NAME']
);

// The version of the library ( should match the mwEmbedLoader KALTURA_LOADER_VERSION
$wgMwEmbedVersion = '1.4b27';

// Url to the resource loader php script: 
$wgResourceLoaderUrl = $wgMwEmbedPathUrl . 'ResourceLoader.php';

// The list of enabled modules 
$wgMwEmbedEnabledModules = array();

// By default we enable every module in the "modules" folder
$d = dir( realpath( dirname( __FILE__ ) )  . '/../modules' );	
while (false !== ($entry = $d->read())) {
	if( substr( $entry, 0, 1 ) != '.' ){
		$wgMwEmbedEnabledModules[] = $entry;
	}
}

/*********************************************************
 * Default Kaltura Configuration: 
 * TODO move kaltura configuration to KalturaSupport module ( part of ResourceLoader update ) 
 ********************************************************/

// To include signed headers with user IPs for IP restriction lookups, input a salt string for 
// $wgKalturaRemoteAddressSalt configuration option. 
$wgKalturaRemoteAddressSalt = false;

// Default debug mode
$wgEnableScriptDebug = false;

// Default HTTP protocol
$wgHTTPProtocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') ? "https" : "http";

// The default Kaltura service url:
$wgKalturaServiceUrl = $wgHTTPProtocol . '://cdnapi.kaltura.com';

// Default Kaltura CDN url: 
$wgKalturaCDNUrl = $wgHTTPProtocol . '://cdnsecakmi.kaltura.com';

// Default Kaltura service url:
$wgKalturaServiceBase = '/api_v3/index.php?service=';

// Default api request timeout in seconds 
$wgKalturaServiceTimeout = 20;

// If we should include the cue points request
$wgKalturaEnableCuePointsRequest = false;

// If the iframe will accept 3rd party domain remote service requests 
// should be left "off" in production. 
$wgKalturaAllowIframeRemoteService = false;

// Default expire time for ui conf api queries in seconds 
$wgKalturaUiConfCacheTime = 60*10; // 10 min

// By default enable the iframe rewrite
$wgKalturaIframeRewrite = true;

// If the iframe embed should include the kaltura javascript api: 
$wgEnableIframeApi = true;

$wgEnableIpadHTMLControls = true;

$wgKalturaUseManifestUrls = true;

// By default do not allow custom resource includes. 
$wgAllowCustomResourceIncludes = false;

// An array of partner ids for which apple adaptive should be disabled. 
$wgKalturaPartnerDisableAppleAdaptive = array();

// By default use apple adaptive if we have the ability
$wgKalturaUseAppleAdaptive = true;

/*********************************************************
 * Include local settings override:
 ********************************************************/
$wgLocalSettingsFile = realpath( dirname( __FILE__ ) ) . '/../LocalSettings.php';

if( is_file( $wgLocalSettingsFile ) ){
	require_once( $wgLocalSettingsFile );
}

/*
if( class_exists('kConf') ) {
	$kConf = new kConf();

	$wgKalturaVersion = basename(getcwd()); // Gets the version by the folder name
	$wgKalturaServiceUrl = $wgHTTPProtocol . '://' . $kConf->get('www_host');
	$wgKalturaCDNUrl = $wgHTTPProtocol. '://' . $kConf->get('cdn_host_https');
	$wgScriptCacheDirectory = $kConf->get('cache_root_path') . 'html5/' . $wgKalturaVersion;
	$wgResourceLoaderUrl = $wgKalturaServiceUrl . '/html5/html5lib/' . $wgKalturaVersion . '/ResourceLoader.php';
	$wgKalturaAllowIframeRemoteService = true;
}
 */

// Check if we have local setting for Stats url
$wgKalturaStatsServiceUrl = isset($wgKalturaStatsServiceUrl) ? $wgKalturaStatsServiceUrl : $wgKalturaServiceUrl;

?>
