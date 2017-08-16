<?php
/**
 * KalturaIframe support
 */
	
// Setup the kalturaIframe
global $wgKalturaIframe;
$wgKalturaIframe = new kalturaIframe();

// Do kalturaIframe video output:

// Start output buffering to 'catch errors' and override output
if( ! ob_start("ob_gzhandler") ) ob_start();

$wgKalturaIframe->outputIFrame();
// Check if we are wrapping the iframe output in a callback
if( isset( $_REQUEST['callback']  )) {
	// get the output buffer:
	$out = ob_get_contents();
	ob_end_clean();
	// Re-start the output buffer: 
	if( ! ob_start("ob_gzhandler") ) ob_start();
	
	header('Content-type: text/javascript' );
	echo htmlspecialchars( $_REQUEST['callback'] ) . '(' . 
		json_encode( array( 'content' => $out ) ) . ');';
} 
// flush the buffer.
ob_end_flush();

/**
 * Kaltura iFrame class:
 */
class kalturaIframe {
	var $resultObject = null; // lazy init 
	var $debug = false;
	var $error = false;
	var $playerError = false;
	
	// A list of kaltura plugins and associated includes	
	public static $iframePluginMap = array(
		'ageGate' => 'iframePlugins/AgeGate.php'
	);
	// Plugins used in $this context
	var $plugins = array();
	
	function getIframeId(){
		if( isset( $_GET['playerId'] ) ){
			return htmlspecialchars( $_GET[ 'playerId' ] );
		}
		return 'iframeVid';
	}
	/**
	 * The result object grabber, caches a local result object for easy access
	 * to result object properties. 
	 */
	function getResultObject(){
		global $wgMwEmbedVersion;
		if( ! $this->resultObject ){
			require_once( dirname( __FILE__ ) .  '/KalturaResultObject.php' );
			try{
				// Init a new result object with the client tag: 
				$this->resultObject = new KalturaResultObject( 'html5iframe:' . $wgMwEmbedVersion );;
			} catch ( Exception $e ){
				$this->fatalError( $e->getMessage() );
			}
		}
		return $this->resultObject;
	}

	function getPlayEventUrl() {
		$param = array(
			'action' => 'collect',
			'apiVersion' => '3.0',
			'clientTag' => 'html5',
			'expiry' => '86400',
			'format' => 9, // 9 = JSONP format
			'ignoreNull' => 1,
			'ks' => $this->getResultObject()->getKS()
		);

		$eventSet = array(
			'eventType' =>	3, // PLAY Event
			'clientVer' => 0.1,
			'currentPoint' => 	0,
			'duration' =>	0,
			'eventTimestamp' => time(),
			'isFirstInSession' => 'false',
			'objectType' => 'KalturaStatsEvent',
			'partnerId' =>	$this->getResultObject()->getPartnerId(),
			'sessionId' =>	$this->getResultObject()->getKS(),
			'uiconfId' => 0,
			'seek'	 =>  'false',
			'entryId'   =>   $this->getResultObject()->getEntryId(),
		);
		foreach( $eventSet as $key=> $val){
			$param[ 'event:' . $key ] = $val;
		}
		ksort( $param );
		
		// Get the signature:
		$sigString = '';
		foreach( $param as $key => $val ){
			$sigString.= $key . $val;
		}
		$param['kalsig'] = md5( $sigString );
		$requestString =  http_build_query( $param );

		return $this->getResultObject()->getServiceConfig('ServiceUrl') .
			 	$this->getResultObject()->getServiceConfig('ServiceBase' ) . 
			 	'stats&' . $requestString;
	}

	// Returns a simple image with a direct link to the asset
	private function getFileLinkHTML(){
		try {
			$sources =  $this->getResultObject()->getSources();
			// If no sources are found use the error video source: 
			if( count( $sources ) == 0 ){
				$sources = $this->getResultObject()->getErrorVideoSources();
			}
			$flavorUrl = $this->getResultObject()->getSourceForUserAgent( $sources );
		} catch ( Exception $e ){
			$this->fatalError( $e->getMessage() );
		}
		// The outer container:
		$o='<div id="directFileLinkContainer">';
			// TODO once we hook up with the kaltura client output the thumb here:
			// ( for now we use javascript to append it in there )
			$o.='<div id="directFileLinkThumb" ></div>';
			$o.='<a href="' . $flavorUrl . '" id="directFileLinkButton" target="_new"></a>';
		$o.='</div>';

		return $o;
	}
	private function getPlayerSizeCss() {
		// Set defaults
		$width = 400;
		$height = 300;
		// check if we have iframeSize paramater:
		if( isset( $_GET[ 'iframeSize' ] ) ){
			list( $width, $height ) = explode( 'x',  $_GET[ 'iframeSize' ]);
			$width = intval( $width );
			$height = intval( $height );
		}		
		return "width:{$width}px;height:{$height}px;";
	}
	private function getPlaylistPlayerSizeCss(){
		$width = 400;
		$height = 300;
		// check if we have iframeSize paramater: 
		if( isset( $_GET[ 'iframeSize' ] ) ){
			list( $iframeWidth, $iframeHeight ) = explode( 'x',  $_GET[ 'iframeSize' ]);
			$iframeWidth = intval( $iframeWidth );
			$iframeHeight = intval( $iframeHeight );
			
			$xml = $this->getResultObject()->getUiConfXML();
			// check for playlist.includeInLayout property and set to full size:
			$result = $xml->xpath("//*[@key='playlist.includeInLayout']" );
			if( isset( $result[0] ) ){
				foreach ( $result[0]->attributes() as $key => $value ) {
					if( $key == 'value' && $value == "false" ){
						$width = $iframeWidth;
						$height = 	$iframeHeight;						
					}
				}
			} else {
				$result = $xml->xpath("//*[@id='playlistHolder']");
				if( isset( $result[0] ) ){
					foreach ( $result[0]->attributes() as $key => $value ) {
						if( $key == 'width' && $value != '100%' ){
							$width = $iframeWidth - intval( $value );
							$height = $iframeHeight;
						}
						if( $key == 'height' && $value != '100%' ){
							$height = $iframeHeight - intval( $value );
							$width = $iframeWidth;
						}
					}
				}
			}
		}
		return "width:{$width}px;height:{$height}px;";
	}
	// outputs the playlist wrapper 
	private function getPlaylistWraper( $videoHtml ){
		// XXX this hard codes some layout assumptions ( but no good way around that for now )
		return '<div id="playlistContainer" style="width:100%;height:100%">
					<span class="media-rss-video-player-container" style="float:left;' . 
					$this->getPlaylistPlayerSizeCss() . '">' . 
					'<div class="media-rss-video-player" style="position:relative">' . 
						$videoHtml .
					'</div>' . 
				'</span>
			</div>';
	}
	private function getVideoHTML( $playerSize = ''  ){
		$videoTagMap = array(
			'entry_id' => 'kentryid',
			'uiconf_id' => 'kuiconfid',
			'wid' => 'kwidgetid',
			'autoplay' => 'autoplay',
		);
		// Check if we have flashvar: loadThumbnailWithKs, if so load the thumbnail with KS
		$ksParam = '';
		if( isset( $_REQUEST['flashvars'] ) && is_array( $_REQUEST['flashvars'] ) && 
			isset( $_REQUEST['flashvars']['loadThumbnailWithKs']) ) 
		{
			$ksParam = '?ks=' . $this->getResultObject()->getKS();
		}
	
		// See if we have access control restrictions
		// Check access control and throw an exception if not allowed: 
		$acStatus = $this->getResultObject()->isAccessControlAllowed( $resultObject );
		if( $acStatus !== true ){
			$this->playerError = $acStatus;
			$sources = $this->getResultObject()->getBlackVideoSources();
		} else {	
			try {
				// We should grab the thumbnail url from our entry to get the latest version of the thumbnail
				if( $this->getResultObject()->getThumbnailUrl() ){
					$posterUrl = $this->getResultObject()->getThumbnailUrl() . '/height/480' . $ksParam;
				} else {
					$posterUrl = $this->getResultObject()->getBlackPoster();
				}
				// get Player sources: 
				$sources = $this->getResultObject()->getSources();
				// If we have an error, show it
				if( $this->getResultObject()->getError() ) {
					$this->playerError = $this->getResultObject()->getError();
					$sources = $this->getResultObject()->getBlackVideoSources();
				}
			} catch ( Exception $e ){
				// xxx log an empty entry id lookup!
				$this->fatalError( $e->getMessage() );
			}
		}

		// NOTE: special persistentNativePlayer class will prevent the video from being swapped
		// so that overlays work on the iPad.
		$o = "\n\n\t" .'<video class="persistentNativePlayer" ';
		// output the poster if set: 
		if( $posterUrl ){
			$o.='poster="' . htmlspecialchars( $posterUrl ) . '" ';
		}
		$o.='id="' . htmlspecialchars( $this->getIframeId() ) . '" ' .
			'style="' . $playerSize . '" ';

		$urlParams = $this->getResultObject()->getUrlParameters();
		
		// Add any additional attributes:
		foreach( $urlParams as $key => $val ){
			if( isset( $videoTagMap[ $key ] ) && $val != null ) {
				if( $videoTagMap[ $key ] == $val ) {
					$o.= ' ' . $videoTagMap[ $key ];
				} else {
					$o.= ' ' . $videoTagMap[ $key ] . '="' . htmlentities( $val ) . '"';
				}
			}
		}
		if( $this->playerError  !== false ){
			// TODO should move this to i8ln keys instead of raw msgs
			$o.= ' data-playerError="' . htmlentities( $this->playerError ) . '" ';
		}
		// Close the open video tag attribute set
		$o.='>';

		// Output each source as a child element ( for javascript off browsers to have a chance
		// to playback the content
		foreach( $sources as $source ){
			// Android has issues with type attribute on source element
			$o.= "\n\t\t" .'<source ' .
					'type="' . htmlspecialchars( $source['type'] ) . '" ' . 
					'src="' . $source['src'] . '" '.
					'data-flavorid="' . htmlspecialchars( $source['data-flavorid'] ) . '" '.
				'></source>';
		}

		// To be on the safe side include the flash player and
		// direct file link as a child of the video tag
		// ( if javascript is "off" and they don't have video tag support for example )
		$o.= "\n\t\t\t" . $this->getFlashEmbedHTML(
			$this->getFileLinkHTML(), 
			'kaltura_player_iframe_no_rewrite'
		);

		$o.= "\n" . "</video>\n";
		// Wrap in a videoContainer
		return  '<div id="videoContainer" > ' . $o . '</div>';
	}
	/**
	 * Get Flash embed code with default flashvars:
	 * @param childHtml Html string to set as child of object embed
	 */	
	private function getFlashEmbedHTML( $childHTML = '', $idOverride = false ){		
		
		$playerId = ( $idOverride )? $idOverride :  $this->getIframeId();
		
		$o = '<object id="' . htmlspecialchars( $playerId ) . '" name="' . $playerId . '" ' .
				'type="application/x-shockwave-flash" allowFullScreen="true" '.
				'allowNetworking="all" allowScriptAccess="always" height="100%" width="100%" style="height:100%;width:100%" '.
				'xmlns:dc="http://purl.org/dc/terms/" '.
				'xmlns:media="http://search.yahoo.com/searchmonkey/media/" '.
				'rel="media:video" '.
				'resource="' . htmlspecialchars( $this->getSwfUrl() ) . '" '.
				'data="' . htmlspecialchars( $this->getSwfUrl() ) . '"> '.
				'<param name="wmode" value="opaque" />' .
				'<param name="allowFullScreen" value="true" /><param name="allowNetworking" value="all" />' .
				'<param name="allowScriptAccess" value="always" /><param name="bgcolor" value="#000000" />'.
				'<param name="flashVars" value="';
		
		$o.= $this->getFlashVarsString() ;
		// close the object tag add the movie param and childHTML: 
		$o.='" /><param name="movie" value="' . htmlspecialchars( $this->getSwfUrl() ) . '" />'.
				$childHTML .
			'</object>';
		return $o;
	}
	private function getFlashVarsString(){
		// output the escaped flash vars from get arguments
		$s = 'externalInterfaceDisabled=false';
		if( isset( $_REQUEST['flashvars'] ) && is_array( $_REQUEST['flashvars'] ) ){
			foreach( $_REQUEST['flashvars'] as $key=>$val ){
				$s.= '&' . htmlspecialchars( $key ) . '=' . urlencode( $val );
			}
		}
		return $s;
	}
	/**
	 * Get custom player includes for css and javascript
	 */
	private function getCustomPlayerIncludesJSON(){
		if( ! $this->getResultObject()->getUiConf() ){
			return false;
		}
		
		// Try to get uiConf
		$xml = $this->getResultObject()->getUiConfXML();
		$resourceIncludes = array();
		$playerConfig =  $this->getResultObject()->playerConfig;
		
		// vars
		foreach( $playerConfig['vars'] as $key => $value ){
			// Check for valid plugin types: 
			$resource = array();
			if( strpos( $key, 'IframeCustomPluginJs' ) === 0 ){
				$resource['type'] = 'js';
			} else if( strpos( $key, 'IframeCustomPluginCss' ) === 0 ){
				$resource['type'] = 'css';
			} else{
				continue;
			}
			// we have a valid type key add src:
			$resource['src']= htmlspecialchars( $value );
			
			// Add the resource	
			$resourceIncludes[] = $resource;
		}		
		// plugins
		foreach( $playerConfig['plugins'] as $pluginId => $plugin ){
			foreach( $plugin as $attr => $value ){
				$resource = array();
				if( strpos( $attr, 'iframeHTML5Js' ) === 0 ){
					$resource['type'] = 'js';
				} else if( strpos( $attr, 'iframeHTML5Css' ) === 0 ){
					$resource['type'] = 'css';
				} else {
					continue;
				}
				// we have a valid type key add src:
				$resource['src']= htmlspecialchars( $value );
				// Add the resource	
				$resourceIncludes[] = $resource;
			}
		}
		// return the resource array in JSON: 
		return json_encode( $resourceIncludes );
	}
	/** 
	 * Gets a series of mw.setConfig calls set via the uiConf of the kaltura player 
	 * */
	private function getCustomPlayerConfig(){
		if( ! $this->getResultObject()->getUiConf() ){
			return '';
		}
		$o = '';
		$xml = $this->getResultObject()->getUiConfXML();
		foreach ( $xml->uiVars->var as $var ){
			if( isset( $var['key'] ) && isset( $var['value'] ) 
				&& $var['key'] != 'Mw.CustomResourceIncludes' 
			){
				$o.= "mw.setConfig('" . htmlspecialchars( addslashes( $var['key'] ) ) . "', ";
				// check for boolean attributes: 
				if( $var['value'] == 'false' || $var['value'] == 'true' ){
					$o.=  $var['value'];
				} else if( substr($var['value'][0], 0, 1 ) == '{' 
					&&  substr($var['value'], -1, 1 ) == '}' 
					&& json_decode( $var['value'] ) !== null
				){ // check for json valuse
					$o.= $var['value'];
				} else { //escape string values:
					$o.= "'" . htmlspecialchars( addslashes( $var['value'] ) ) . "'";
				}
				$o.= ");\n";
			}
		}
		return $o;
	}
	private function checkIframePlugins(){
		try{
			$xml = $this->getResultObject()->getUiConfXML();
		} catch ( Exception $e ){
			//$this->fatalError( $e->getMessage() );
			return ;
		}
		if( isset( $xml->HBox ) && isset( $xml->HBox->Canvas ) && isset( $xml->HBox->Canvas->Plugin ) ){
			foreach ($xml->HBox->Canvas->Plugin as $plugin ){
				$attributes = $plugin->attributes();
				$pluginId = (string) $attributes['id'];
				if( in_array( $pluginId, array_keys ( self::$iframePluginMap ) ) ){
					require_once( self::$iframePluginMap[ $pluginId] );
					$this->plugins[$pluginId] = new $pluginId( $this );
					$this->plugins[$pluginId ]->run();
				}
			}
		}
	}
	private function getSwfUrl(){
		$swfUrl = $this->getResultObject()->getServiceConfig('ServiceUrl') . '/index.php/kwidget';
		// pass along player attributes to the swf:
		$urlParams = $this->getResultObject()->getUrlParameters();
		foreach($urlParams as $key => $val ){
			if( $val != null && $key != 'flashvars' ){
				$swfUrl.='/' . $key . '/' . $val;
			}
		}
		return $swfUrl;
	}
	
	/**
	 * Void function to set iframe content headers
	 */
	private function setIFrameHeaders(){
		global $wgKalturaUiConfCacheTime;

		// Set relevent expire headers:
		if( $this->getResultObject()->isCachedOutput() ){
			$time = $this->getResultObject()->getFileCacheTime();
			header( 'Pragma: public' );
			// Cache for $wgKalturaUiConfCacheTime
			header( "Cache-Control: public, max-age=$wgKalturaUiConfCacheTime, max-stale=0");
			header( "Last-Modified: " . gmdate( "D, d M Y H:i:s", $time) . "GMT");
			header( "Expires: " . gmdate( "D, d M Y H:i:s", $time + $wgKalturaUiConfCacheTime ) . " GM" );
		} else {
			header("Cache-Control: no-cache, must-revalidate"); // HTTP/1.1
			header("Pragma: no-cache");
			header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Date in the past
		}
	}
	
	/**
	 * Get the location of the mwEmbed library
	 */
	private function getMwEmbedLoaderLocation(){
		global $wgResourceLoaderUrl;
		$loaderPath = str_replace( 'ResourceLoader.php', 'mwEmbedLoader.php', $wgResourceLoaderUrl );
		$versionParam = '?';
		$urlParam = $this->getResultObject()->getUrlParameters();
		if( isset( $urlParam['urid'] ) ){
			$versionParam .= '&urid=' . htmlspecialchars( $urlParam['urid'] );
		}
		if( isset( $ulrParam['debug'] ) ){
			$versionParam .= '&debug=true';
		}
		
		$xml = $this->getResultObject()->getUiConfXML();
		if( $xml && isset( $xml->layout ) && isset( $xml->layout[0] ) ){
			foreach($xml->layout[0]->attributes() as $name => $value) {
				if( $name == 'html5_url' ){
					if( $value[0] == '/' ){
						$loaderPath = $this->getResultObject()->getServiceConfig( 'CdnUrl' ) . $value;
					} else if( substr( $value,0, 4 ) == 'http' ) {
						$loaderPath = $value;
					}
				}
			}
		}
		return $loaderPath . $versionParam;
	}
	
	/**
	 * Get the iframe css
	 */
	private function outputIframeHeadCss(){
		global $wgResourceLoaderUrl;
		$path = str_replace( 'ResourceLoader.php', '', $wgResourceLoaderUrl );
		?>
		<meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1" />
		<title>Kaltura Embed Player iFrame</title>
		<style type="text/css">
			body {
				margin:0;
				position:fixed;
				top:0px;
				left:0px;
				bottom:0px;
				right:0px;
				width: 100%;
				height: 100%;
				overflow:hidden;
				background: #000;
				color: #fff;
			}
		<?php 
		if( $this->isError() ){
			?>
				.error {
					position:absolute;
					top: 37%;
					left: 50%;
					margin: 0 0 0 -140px;
					width: 280px;
					border: 1px solid #eee;
					-webkit-border-radius: 4px;
					-moz-border-radius: 4px;
					border-radius: 4px;
					text-align: center;
					background: #fff;
					padding-bottom: 10px;
					color: #000;
				}
				.error h2 {
					font-size: 14px;
				}
			<?php 
		} else {
			?>
			.loadingSpinner {
					background: url( '<?php echo $path ?>skins/common/images/loading_ani.gif');
					position: absolute;
					top: 50%; left: 50%;
					width:32px;
					height:32px;
					display:block;
					padding:0px;
					margin: -16px -16px;
				}
				#videoContainer {
					position: absolute;
					width: 100%;
					height: 100%;
				}
				#directFileLinkContainer{
					position:abolute;
					top:0px;
					left:0px;
					height:100%;
					width:100%
				}
				/* Should allow this to be overided */
				#directFileLinkButton {
					background: url( '<?php echo $path ?>skins/common/images/player_big_play_button.png');
					width: 70px;
					height: 53px;
					position: absolute;
					top:50%;
					left:50%;
					margin: -26px 0 0 -35px;
				}
				#directFileLinkThumb{
					position: absolute;
					top:0px;
					left:0px;
					width: 100%;
					height: 100%;
				}
			<?php
		}
		?>
			</style>
		<?php
	}
	function outputIFrame( ){
		//die( '<pre>' . htmlspecialchars($this->getVideoHTML()) );
		global $wgResourceLoaderUrl;
		$path = str_replace( 'ResourceLoader.php', '', $wgResourceLoaderUrl );
		
		// Check for plugins ( can overide output) 
		$this->checkIframePlugins();
		
		$this->setIFrameHeaders();
?>
<!DOCTYPE html>
<html>
	<head>
		<?php echo $this->outputIframeHeadCss(); ?>
	</head>
	<body>	
		<?php 
		// Check if the object should be writen by javascript ( instead of outputing video tag and player pay load )
		if( $this->getResultObject()->isJavascriptRewriteObject() ) {
			echo $this->getFlashEmbedHTML();
		} else {
			if( $this->getResultObject()->isPlaylist() ){ 
				echo $this->getPlaylistWraper( 
					// Get video html with a default playlist video size ( we can adjust it later in js )
					$this->getVideoHTML( $this->getPlaylistPlayerSizeCss() ) 
				);
			} else {
				// For the actual video tag we need to use a document.write since android dies 
				// on some video tag properties
				?>
				<script type="text/javascript">
					var videoTagHTML = <?php echo json_encode( $this->getVideoHTML() ) ?>;
					// Android can't handle position:absolute style on video tags and requires an absolute size: 
					if( navigator.userAgent.indexOf('Android' ) !== -1 ){
						// Also android does not like "type" on source tags
						videoTagHTML= videoTagHTML.replace(/type=\"[^\"]*\"/g, '');
						styleValue = '<?php echo $this->getPlayerSizeCss(); ?>';
					} else {
						// iOS and other OSs are fine with 100% size and position:abolute;
						styleValue = 'position:absolute;width:100%;height:100%';
					}
					videoTagHTML = videoTagHTML.replace(/style=\"\"/, 'style="' + styleValue + '"');
					document.write( videoTagHTML );
				</script>
				<?php
			} 
		}
		?>
		<script type="text/javascript">
			// In same page iframe mode the script loading happens inline and not all the settings get set in time
			// its critical that at least EmbedPlayer.IsIframeServer is set early on. 
			window.preMwEmbedConfig = {};
			window.preMwEmbedConfig['EmbedPlayer.IsIframeServer'] = true;
		</script>
		<!--  Add the mwEmbedLoader.php -->
		<script src="<?php echo $this->getMwEmbedLoaderLocation() ?>" type="text/javascript"></script>
		<!-- Add the kaltura ui logic as inline script: --> 
		<script type="text/javascript"><?php
			$uiConfJ = new mweApiUiConfJs();
			echo $uiConfJ->getUserAgentPlayerRules();
		?></script>
		<script type="text/javascript" >
			// Insert JSON support if in missing ( IE 7, 8 )
			if( typeof JSON == 'undefined' ){ 
				document.write(unescape("%3Cscript src='<?php echo $path ?>/libraries/json/json2.js' type='text/javascript'%3E%3C/script%3E"));
			}
		</script>
		<script type="text/javascript">
			// IE has out of order stuff execution... we have a pooling funciton to make sure mw is ready before we procceed. 
			var waitForMwCount = 0;
			var waitforMw = function( callback ){
				if( window['mw'] ){
					callback();
					return ;
				}
				setTimeout(function(){ 
					waitForMwCount++;
					if(  waitForMwCount < 1000 ){
						waitforMw( callback );
					} else {
						console.log("Error in loading mwEmbedLodaer");
					}
				}, 10 );
			};
			waitforMw( function(){
				<?php 
					global $wgAllowCustomResourceIncludes;
					if( $wgAllowCustomResourceIncludes ){
						echo 'mw.setConfig( \'Mw.CustomResourceIncludes\', '. $this->getCustomPlayerIncludesJSON() .' );';
					}
				?>
				
				var hashString = document.location.hash;
				// Parse any configuration options passed in via hash url:
				if( hashString ){
					var hashObj = JSON.parse(
						unescape( hashString.replace( /^#/, '' ) )
					);
					if( hashObj.mwConfig ){
						mw.setConfig( hashObj.mwConfig );
					}
				} else 	if( window['parent'] && window['parent']['preMwEmbedConfig'] ){ 
					// Grab config from parent frame:
					mw.setConfig( window['parent']['preMwEmbedConfig'] );
					// Set the "iframeServer" to the current domain: 
					mw.setConfig( 'EmbedPlayer.IframeParentUrl', document.URL ); 
				}
	
				// Get the flashvars object:
				var flashVarsString = '<?php echo $this->getFlashVarsString() ?>';
				var fvparts = flashVarsString.split('&');
				var flashvarsObject = {};
				for(var i=0;i<fvparts.length;i++){
					var kv = fvparts[i].split('=');
					if( kv[0] && kv[1] ){
						flashvarsObject[ unescape( kv[0] ) ] = unescape( kv[1] );
					}
				}
				mw.setConfig( 'KalturaSupport.IFramePresetFlashvars', flashvarsObject );
	
				// We should first read the config for the hashObj and after that overwrite with our own settings
				// The entire block below must be after mw.setConfig( hashObj.mwConfig );
	
				// Don't do an iframe rewrite inside an iframe
				mw.setConfig('Kaltura.IframeRewrite', false );
	
				// Set a prepend flag so its easy to see whats happening on client vs server side of the iframe:
				mw.setConfig('Mw.LogPrepend', 'iframe:');
	
				// Don't rewrite the video tag from the loader ( if html5 is supported it will be
				// invoked bellow and respect the persistant video tag option for iPad overlays )
				mw.setConfig( 'Kaltura.LoadScriptForVideoTags', false );
	
				// Don't wait for player metada for size layout and duration Won't be needed since
				// we add durationHint and size attributes to the video tag
				mw.setConfig( 'EmbedPlayer.WaitForMeta', false );
	
				// Add Packaging Kaltura Player Data ( JSON Encoded )
				mw.setConfig( 'KalturaSupport.IFramePresetPlayerData', <?php echo $this->getResultObject()->getJSON(); ?>);

				mw.setConfig('EmbedPlayer.IframeParentPlayerId', '<?php echo $this->getIframeId()?>' );			
				
				// Set uiConf global vars for this player ( overides iframe based hash url config )
				<?php 
					echo $this->getCustomPlayerConfig();
				?>
				// Remove the fullscreen option if we are in an iframe: 
				if( mw.getConfig('EmbedPlayer.IsFullscreenIframe') ){
					mw.setConfig('EmbedPlayer.EnableFullscreen', false );
				} else {
					// If we don't get a 'EmbedPlayer.IframeParentUrl' update fullscreen to pop-up new 
					// window. ( we won't have the iframe api to resize the iframe ) 
					if( mw.getConfig('EmbedPlayer.IframeParentUrl') === null ){
						mw.setConfig( "EmbedPlayer.NewWindowFullscreen", true ); 
					}
				}
				// For testing limited capacity browsers
				//var kIsHTML5FallForward = function(){ return false };
				//var kSupportsFlash = function(){ return false	 };
	
				<?php
					if( ! $this->getResultObject()->isJavascriptRewriteObject() ) {
						echo $this->javascriptPlayerLogic();
					}
				?>
				// Because IE has out of order execution issues, we don't check the dom until we get here: 
				setTimeout(function(){
					kRunMwDomReady( 'endOfIframeJs' );
				},0);
			});
		</script>
	</body>
</html>
<?php
	}
	private function javaScriptPlayerLogic(){
		?>
		var isHTML5 = kIsHTML5FallForward();
		if( window.kUserAgentPlayerRules ) {
			var playerAction = window.checkUserAgentPlayerRules( window.kUserAgentPlayerRules[ '<?php echo $this->getResultObject()->getUiConfId() ?>' ] );
			if( playerAction.mode == 'leadWithHTML5' ){
				isHTML5 = true;
			}
		}
		if( isHTML5){
				// remove the no_rewrite flash object ( never used in rewrite )
				var obj = document.getElementById('kaltura_player_iframe_no_rewrite');
				if( obj ){
					try {
						document.getElementById('<?php echo $this->getIframeId()?>').removeChild( obj );
					} catch( e ){
						// could not remove node
					}
				}
				
				// Load the mwEmbed resource library and add resize binding
				mw.ready(function(){
				
					// try again to remove the flash player if not already removed: 
					$('#kaltura_player_iframe_no_rewrite').remove();
					
					var embedPlayer = $( '#<?php echo htmlspecialchars( $this->getIframeId() )?>' ).get(0);
					// Try to seek to the IframeSeekOffset time:
					if( mw.getConfig( 'EmbedPlayer.IframeCurrentTime' ) ){
						embedPlayer.currentTime = mw.getConfig( 'EmbedPlayer.IframeCurrentTime' );					
					}
					// Maintain play state for html5 browsers
					if( mw.getConfig('EmbedPlayer.IframeIsPlaying') ){
						embedPlayer.play();
					}
					function doResizePlayer(){
						$( '#<?php echo htmlspecialchars( $this->getIframeId() )?>' )
							.get(0).resizePlayer({
								'width' : $(window).width(),
								'height' : $(window).height()
							});
					}
					// Bind window resize to reize the player:
					$( window ).resize( doResizePlayer );
					// Resize the player per player on ready
					if( mw.getConfig('EmbedPlayer.IsFullscreenIframe') ){
						doResizePlayer();
					}
				});
		} else {
			// Remove the video tag and output a clean "object" or file link
			// ( if javascript is off the child of the video tag so would be played,
			//  but rewriting gives us flexiblity in in selection criteria as
			// part of the javascript check kIsHTML5FallForward )
			if( document.getElementById( 'videoContainer' ) ){
				try{
					document.getElementById( 'videoContainer' ).innerHTML = "";
				}catch(e){
					// failed to empty video tag
				}
			}
			
			if( kSupportsFlash() ||  mw.getConfig( 'Kaltura.ForceFlashOnDesktop' ) ){				
				// Write out the embed object
				document.write('<?php echo $this->getFlashEmbedHTML() ?>' );
				
				// Load server side bindings for kdpServer
				kLoadJsRequestSet( ['window.jQuery', 'mwEmbed', 'mw.style.mwCommon', '$j.postMessage', 'kdpServerIFrame', 'JSON' ] );
			} else {
				
				// Last resort just provide an image with a link to the file
				// NOTE we need to do some platform checks to see if the device can
				// "actually" play back the file and or switch to 3gp version if nessesary.
				// also we need to see if the entryId supports direct download links
				document.write('<?php echo $this->getFileLinkHTML()?>');

				var thumbSrc = kGetEntryThumbUrl({
					'entry_id' : '<?php echo $this->getResultObject()->getEntryId() ?>',
					'partner_id' : '<?php echo $this->getResultObject()->getPartnerId() ?>',
					'height' : ( document.body.clientHeight )? document.body.clientHeight : '300',
					'width' : ( document.body.clientHeight )? document.body.clientHeight : '400'
				});
				document.getElementById( 'directFileLinkThumb' ).innerHTML =
					'<img style="width:100%;height:100%" src="' + thumbSrc + '" >';

				window.kCollectCallback = function(){ return ; }; // callback for jsonp

				document.getElementById('directFileLinkButton').onclick = function() {
					kAppendScriptUrl( '<?php echo $this->getPlayEventUrl() ?>' + '&callback=kCollectCallback' );
					return true;
				};
			}
		}
		<?php 
	}
	/**
	 * Very simple error handling for now: 
	 */
	private function setError( $errorTitle ){
		$this->error = true;
	}
	private function isError( ){
		return $this->error;
	}
	/**
	 * Output a fatal error and exit with error code 1
	 */
	private function fatalError( $errorTitle, $errorMsg = false ){
		// check for multi line errorTitle array: 
		if( strpos( $errorTitle, "\n" ) !== false ){
			list( $errorTitle, $errorMsg) = explode( "\n", $errorTitle);
		};
		$this->setError( $errorTitle );
		
		// clear the buffer
		$pageInProgress = ob_end_clean();
		
		// Re-start the output buffer: 
		if( ! ob_start("ob_gzhandler") ) ob_start();
		
		// Optional errorTitle: 
		if( $errorMsg === false ){
			$errorMsg = $errorTitle;
			$errorTitle = false;
		}
		?>
<!DOCTYPE html>
<html>
	<head>
		<?php echo $this->outputIframeHeadCss(); ?>
	</head>
	<body>
		<div class="error"><?php
			if( $errorTitle ){
				echo '<h2>' . htmlspecialchars( $errorTitle ) . '</h2>';
			}
			// Presently errors can have html foramting ( not ideal )
			// TODO refactor to have error title and error message arguments
			echo htmlspecialchars( $errorMsg );
		?></div>
	</body>
</html><?php
		// TODO clean up flow ( should not have two checks for callback )
		if( isset( $_REQUEST['callback']  )) {
			// get the output buffer:
			$out = ob_get_contents();
			ob_end_clean();
			// Re-start the output buffer: 
			if( ! ob_start("ob_gzhandler") ) ob_start();
			header('Content-type: text/javascript' );
			echo htmlspecialchars( $_REQUEST['callback'] ) . '(' . 
				json_encode( array( 'content' => $out ) ) . ');';
		} 

		ob_end_flush();
		// Iframe error exit
		exit( 1 );
	}
}
