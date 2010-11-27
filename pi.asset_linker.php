<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * This plugin is free for both personal and commercial use.
 * You are free to modify and redistribute it as long as it retains this notice.
 * Copyright (c) 2010, Joseph Wensley
 */

$plugin_info = array(
  'pi_name' => 'Asset Linker',
  'pi_version' =>'1.2',
  'pi_author' =>'Joseph Wensley',
  'pi_author_url' => 'http://josephwensley.com/',
  'pi_description' => 'Links and caches CSS and JS files',
  'pi_usage' => Asset_linker::usage()
  );

/**
 * Links CSS and Javascript files together and provides minification and gzipping
 *
 * @package default
 * @author Joseph Wensley
 * @copyright Copyright (c) 2010, Joseph Wensley
 */

class Asset_linker {
	
	var $return_data = "";
	
	function Asset_linker()
	{
		$this->EE =& get_instance();
		
		$assets		= $this->EE->TMPL->fetch_param('assets');
		$asset_dir	= $this->EE->TMPL->fetch_param('asset_dir');
		$cache_name	= $this->EE->TMPL->fetch_param('cache_name');
		$expires	= $this->EE->TMPL->fetch_param('expires');
		
		switch(strtolower($this->EE->TMPL->fetch_param('minify'))){
			case 'off':
			case 'no':
				$minify = FALSE;
				break;
			case 'yes':
			case 'on':
			default:
				$minify = TRUE;
				break;
		}
		
		switch(strtolower($this->EE->TMPL->fetch_param('gzip'))){
			case 'yes':
			case 'on':
				$gzip = TRUE;
				break;
			case 'no':
			case 'off':
			default:
				$gzip = FALSE;
				break;
		}
		
		switch ($asset_type = strtolower($this->EE->TMPL->fetch_param('type'))) {
			case 'css':
			case 'js':
				break;
			default:
				$asset_type = 'css';
				break;
		}
		
		switch ($output = strtolower($this->EE->TMPL->fetch_param('output'))) {
			case 'code':
				$gzip = FALSE;
				break;
			case 'tag':
			case 'disable':
				break;
			default:
				$output = 'tag';
				break;
		}
		
		// Check if expire time is actually a number
		if(!is_numeric($expires)){
			$expires = 24;
		}
		
		if(!$asset_dir){
			$this->EE->TMPL->log_item("Asset Linker: No asset directory specified");
			$this->return_data = '';
			return;
		}else{
			if(substr($asset_dir, 0, 1) == '/'){$asset_dir = substr($asset_dir, 1);}
			if(substr($asset_dir, -1) != '/'){$asset_dir .= '/';} // Add the trailing slash if it isn't there
			
			$asset_dir_url = '/'.$asset_dir;
			$asset_dir = FCPATH.$asset_dir;
			
			if(!is_dir($asset_dir)){
				$this->EE->TMPL->log_item("Asset Linker: Asset directory does not exist");
				$this->return_data = '';
				return;
			}else{
				$cache_dir = $asset_dir.'cache/';
				$cache_dir_url = $asset_dir_url.'cache/';
				if(!is_dir($cache_dir) && !mkdir($cache_dir, DIR_READ_MODE)){
					$cache_dir = $asset_dir;
					$cache_dir_url = $asset_dir_url;
				}
				
				if(is_dir($cache_dir) && !is_writable($cache_dir)){
					chmod($cache_dir, DIR_WRITE_MODE);
				}
			}
		}
		
		if(!$assets){
			$this->EE->TMPL->log_item("Asset Linker: No asset files specified");
			$this->return_data = '';
			return;
		}else{
			$asset_names_array = explode('|', $assets);
			foreach($asset_names_array as $key => $name){
				$filepath = $asset_dir.$name.'.'.$asset_type;
				if(file_exists($filepath)){
					$assets_array[$key]['filename'] = $name.'.'.$asset_type;
					$assets_array[$key]['filepath'] = $asset_dir.$name.'.'.$asset_type;
					$assets_array[$key]['url'] = $asset_dir_url.$name.'.'.$asset_type;	
				}
			}
		}
		
		if($output == 'disable'){
			foreach($assets_array AS $asset){
				if($asset_type == 'css'){
					$tag = '<link rel="stylesheet" href="%s" type="text/css" media="screen" charset="utf-8" />'.NL;
					$this->return_data .= sprintf($tag, $asset['url']);
				}elseif($asset_type == 'js'){
					$tag = '<script src="%s" type="text/javascript" charset="utf-8"></script>'.NL;
					$this->return_data .= sprintf($tag, $asset['url']);
				}
			}
			return;
		}
		
		if(!$cache_name){
			$cache_name = md5($assets);
		}
		
		if($gzip){
			$cache_file =  $cache_dir.$cache_name.'.php';
			$cache_file_url =  $cache_dir_url.$cache_name.'.php';
		}else{
			$cache_file =  $cache_dir.$cache_name.'.'.$asset_type;
			$cache_file_url =  $cache_dir_url.$cache_name.'.'.$asset_type;
		}
		
		$cache_status = $this->_check_cache($assets_array, $cache_file);
		
		if($cache_status === FALSE){
			$this->_rebuild_cache($assets_array, $cache_file, $asset_type, $minify, $gzip, $expires);
		}
		
		// Build the html tag
		if($output == 'tag'){
			$cache_mtime = filemtime($cache_file);
			
			if($asset_type == 'css'){
				$tag = '<link rel="stylesheet" href="%s?t=%s" type="text/css" media="screen" charset="utf-8" />';
				$this->return_data = sprintf($tag, $cache_file_url, $cache_mtime);
			}elseif($asset_type == 'js'){
				$tag = '<script src="%s?t=%s" type="text/javascript" charset="utf-8"></script>';
				$this->return_data = sprintf($tag, $cache_file_url, $cache_mtime);
			}
		}else{
			$this->return_data = file_get_contents($cache_file);
		}
	}
	
	/**
	 * Check the cache to see if it is still good
	 * Returns true if the cache is still good, false otherwise
	 *
	 * @return bool
	 * @author Joseph Wensley
	 */
	function _check_cache($assets_array, $cache_file)
	{	
		if(!file_exists($cache_file)){
			return FALSE;
		}else{
			$cache_time = filemtime($cache_file);
		}
		
		foreach ($assets_array as $key => $asset){
			if(file_exists($asset['filepath'])){
				if(filemtime($asset['filepath']) > $cache_time){
					return FALSE;
				}
			}
		}
		
		return TRUE;
	}
	
	/**
	 * Rebuild the cache file
	 *
	 * @param string $assets_array 
	 * @param string $cache_file 
	 * @param string $asset_type 
	 * @param string $minify 
	 * @param string $gzip 
	 * @return void
	 * @author Joseph Wensley
	 */
	function _rebuild_cache($assets_array, $cache_file, $asset_type, $minify, $gzip, $expires)
	{
		$modified = gmdate('r');
		$cache_handle = fopen($cache_file, 'a');
		flock($cache_handle, LOCK_EX);
		ftruncate($cache_handle, 0); // Erase everything in the file
		
		$first = TRUE;
		if($gzip){
			$header = "<?php
				if(isset(\$_SERVER['HTTP_IF_MODIFIED_SINCE']) && strtotime(\$_SERVER['HTTP_IF_MODIFIED_SINCE']) == %s){
					header('HTTP/1.1 304 Not Modified');
					exit;
				}
				
				\$expires = 60 * 60 * %s;
				
				header('Cache-Control: must-revalidate');
				header('Last-Modified: %s');
				header('Expires: ' . gmdate('r', time() + \$expires));
				header('Content-type: text/%s; charset=UTF-8');
				ob_start(\"ob_gzhandler\");
			?>";
			
			if($asset_type === 'css'){
				$header = sprintf($header, time(), $expires, $modified, 'css');
			}elseif($asset_type === 'js'){
				$header = sprintf($header, time(), $expires, $modified, 'javascript');
			}
			
			fwrite($cache_handle, $header); // Write the header to the file
		}
		
		foreach ($assets_array as $asset) {
			if($data = file_get_contents($asset['filepath'])){
				if($minify === TRUE){
					$data = $this->_minify_data($data, $asset_type);
				}

				if($first){
					$data = "/*********** {$asset['filename']} **********/\n".$data;
					$first = FALSE;
				}else{
					$data = "\n\n/*********** {$asset['filename']} **********/\n".$data;
				}

				fwrite($cache_handle, $data); // Write the last file
			}
		}
		
		flock($cache_handle, LOCK_UN); // Unlock the file
		fclose($cache_handle);
		chmod($cache_file, FILE_READ_MODE);
	}
	
	/**
	 * Minify CSS and Javascript data
	 *
	 * @param string $data 
	 * @param string $asset_type 
	 * @return string
	 * @author Joseph Wensley
	 */
	function _minify_data($data, $asset_type){
		if($asset_type == 'css'){
			$comment_pattern = "/\/\*(?:\r|\n|\r\n|.)*?\*\//i";
			$data = preg_replace($comment_pattern, '', $data);
			$data = preg_replace("/(?:\r|\n|\r\n)*/", '', $data);
		}elseif($asset_type == 'js'){
			if(@include_once 'jspacker.php'){
				$packer = new JavaScriptPacker($data);
				$data = $packer->pack();
			}
		}
		
		return $data;
	}
	
	function usage()
	{
		ob_start();
		?>
			------------------------------
			- Requirements
			------------------------------
			ExpressionEngine 2.x
			PHP 5+
			JavaScriptPacker (included) - Needed for minification of Javascript files.
			------------------------------
			- Parameters
			------------------------------
			type = js/css (defaults to css)
			assets(required) = '|' delimited list of asset file names
			asset_dir(required) = relative path from the root to the directory where your assets are
			cache_name = a name for the cached file
			output = tag/code/disable (defaults to tag) Tag outputs a link/script tag to the cache file, code outputs the combined and minified code and disable outputs tags linking to the original files
			minify = on/off (defaults to on)
			gzip = on/off (defaults to off)
			expires = The number of hours to set the 'Expires' header to
			
			------------------------------
			- Example Usage
			------------------------------
			There are 2 ways to use this plugin
			
			The first way is to put the template tag into your <head> like:
			{exp:asset_linker type="js" assets="jquery|cufon|Calibri.font|slideshow|common" asset_dir="/assets/js" cache_name="scripts"}
			{exp:asset_linker type="css" assets="reset|960|text|master" asset_dir="/assets/css" cache_name="home"}
			
			-- Example 2 --
			
			The second option is to put the tags into a template which you then link to:

			in your template
			<link rel="stylesheet" type="text/css" media="all" href="/index.php/site/styles/" />
			
			in 'site/styles' CSS template
			{exp:asset_linker type="css" assets="reset|960|text|master" asset_dir="/assets/css" cache_name="home" output="code"}
			
			-- Notes --
			
			The second option lets you use ExpressionEngine's output system to have everything gzipped and use the page cache but experienced lower performance in my test and is not recommended for most cases.
			
			------------------------------
			- Changelog
			------------------------------
			1.2 - Added expires parameter
				- Do some extra checks to make sure the cache dir is writable
			1.1 - Added output="disable" option (Thanks to Kevin Smith)
			    - Don't add the gzip php code if output="code"
			1.0 - Initial Release
		<?php
		$buffer = ob_get_contents();
		ob_end_clean(); 
		return $buffer;
	  
	}
}

/* End of file pi.asset_linker.php */ 
/* Location: ./system/expressionengine/third_party/asset_linker/pi.asset_linker.php */