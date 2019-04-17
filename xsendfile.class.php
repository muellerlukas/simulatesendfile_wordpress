<?php
/*
sendfile emulator - emulates xsendfile with symlinks

2017 by Lukas Mueller

    This program is free software: you can redistribute it and/or modify
    it under the terms of the Lesser GNU General Public License as published by
    the Free Software Foundation, either version 2.1 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
    Lesser GNU General Public License for more details.

    You should have received a copy of the Lesser GNU General Public License
    along with this program. If not, see http://www.gnu.org/licenses/.
*/

namespace mulu;

class xsendfile
{
	protected static $_linkDir = '';
	protected static $_linkDirUri = '';
	protected static $_expireTime = 60; // default: 1 minute. After the download started it will be finished, wheter the file is "deleted" or not.
	protected static $_salt = '';
	protected static $_dirOk = false;
	protected static $_disallowedExt = [];
	protected static $_callbacks = [];
	protected static $_headerWorker = [__CLASS__, 'headerWorker'];
	protected static $_externalUrlSymlink = false; // used, when the sendfile is external

	/**
	* Defines the directory for symlinks
	* @param	string	the directory name
	* @param	bool	create directory if not exist
	* @return	bool	directory successfully set
	*/
	public static function setLinkDir($dir, $create = true)
	{
		// non existing dir
		if (!is_dir($dir))
		{
			
			if ($create)
			{
				// try to create given directory
				if (!mkdir($dir))
				{
					trigger_error('Could not create directory', E_ERROR);
					return false;
				}
			}
			else
			{
				trigger_error('Directory does not exist', E_ERROR);
				return false;
			}
		}		
		self::$_linkDir = $dir;

		self::$_dirOk = is_writable($dir);
		return self::$_dirOk;
	}
	
	/**
	* Sets disallowed extensions
	* @param	array	the extensions
	*/
	public static function setDisallowedExtensions($extensions)
	{
		self::$_disallowedExt = $extensions;
		return true;
	}
	
	/**
	* Add callback function
	* @param	string		callback name
	* @param	callable	a callable function
	*/
	public static function registerCallback($type, $function)
	{
		if (is_callable($function))
		{
			self::$_callbacks[$type] = $function;
		}
		return false;
	}
	
	/**
	* Sets the uri to the symlinks
	* So you can use CDN-plugins in Wordpress or so
	* @param	string	the uri of the symlink-folder
	*/
	public static function setLinkDirUri($uri)
	{
		self::$_linkDirUri = $uri;
		return true;
	}
	
	/**
	* Sets the salt to use
	* @param	string	the salt
	*/
	public static function setSalt($salt)
	{
		self::$_salt = $salt;
		return true;
	}
	
	/**
	* Sets the expire time of a symlink
	* @param	integer	expire in seconds
	* @return	bool	expire successfully set
	*/
	public static function setExpireTime($seconds)
	{
		if (is_numeric($seconds))
		{
			self::$_expireTime = $seconds;
			return true;
		}
		return false;
	}
	
	/**
	* Sets the symlink-path when external link
	* @param	string	 the path (variables: %host%, %path%)
	* @return	bool	
	*/
	public static function setExtSymlinkPath($symlink)
	{

		self::$_externalUrlSymlink = $symlink;
	}
	
	/**
	* returns true if is correct configured
	* @return	bool	plugin ok
	*/
	public static function isActive()
	{
		$result = true;

		// if dir is not set successfully
		if (!self::$_dirOk)
		{
			$result = false;
		}
		// or the apache module is activated
		elseif (php_sapi_name() == 'apache2handler')
		{
			$result = false;
		}
		return $result;
	}
	
	/**
	* Catches header, creates symlinks an redirects
	* @param	callable	a function that works with the header-data (for use with zend, shopware, etc)
	*/
	public static function rewrite($headerWorker = null)
	{

		if (is_null($headerWorker))
			$headerWorker = self::$_headerWorker;
		
		error_reporting(E_ALL);

		

		// not active or header already sent
		if (!self::isActive())
			return false;

		$headerWorker('del', 'content-disposition');
		$headerWorker('set', 'Content-Type', 'text/plain');
		
		$sendfile = false;
		$filename = '';
		$headers = $headerWorker('getall');

		foreach($headers as $header)
		{
			// explode key and value
			$data = explode(': ', $header);
			switch($data[0])
			{
				case 'X-Sendfile': // Apache version
				case 'X-Accel-Redirect': // nginx version
				case 'X-Lighttpd-Sendfile': // lighty version
					$sendfile = $data[1];
				break;
				case 'Content-Disposition': // includes the filename
					if(preg_match('~filename=(.*)$~is', $data[1], $matchFn))
						$filename = trim($matchFn[1], '";');
				break;
			}
		}
		
		// check for external link
		$sendfileInfo = parse_url($sendfile);
		if (!empty($sendfileInfo['scheme']))
		{
			// if not set quit
			if (empty(self::$_externalUrlSymlink))
				return false;
			
			$sendfile = self::$_externalUrlSymlink;
			$sendfile = str_replace('%host%', $sendfileInfo['host'], $sendfile);
			$sendfile = str_replace('%path%', $sendfileInfo['path'], $sendfile);
		}
		
		if ($sendfile === false)
			return;
		
		// now remove no longer needed headers
		$headerWorker('del', 'X-Sendfile');
		$headerWorker('del', 'X-Accel-Redirect');
		$headerWorker('del', 'X-Lighttpd-Sendfile');
		$headerWorker('del', 'Content-Disposition');
		
		// debug things, let it in, doesnt matter when we forward and makes debugging easier
		$headerWorker('set', 'Content-Type', 'text/plain');

		// break the loop if available secret dir found
		while(true)
		{
			$secret = sha1($sendfile . rand() . self::$_salt); // secret hash for symlink directory
			$secretDir = self::$_linkDir . $secret . '/'; // path to secret directory
			if (!is_dir($secretDir))
			{
				$sendfile = self::makeAbsPath($sendfile);
				$secretDir = self::makeAbsPath($secretDir);

				// if no name, get filename
				if (empty($filename))
					$filename = basename($sendfile);
				
				// check extension and forbid
				if (in_array(pathinfo($filename, PATHINFO_EXTENSION), self::$_disallowedExt))
				{
					$noAuth = true;
					
					// call callback to check if redirect is allowed
					if (isset(self::$_callbacks['forbiddenExtension']))
					{
						$result = call_user_func(self::$_callbacks['forbiddenExtension'], pathinfo($filename, PATHINFO_EXTENSION));
						if ($result === true)
							$noAuth = false;
					}
					
					if ($noAuth)
					{
						$headerWorker('set', 'HTTP/1.0 403 Forbidden');
						return;
					}
				}
				
				mkdir($secretDir);
				symlink(urldecode($sendfile), $secretDir . utf8_decode(urldecode($filename)));
				// create web path
				if (!empty(self::$_linkDirUri))
				{
					$weburi = self::$_linkDirUri . $secret . '/' . $filename;
				}
				$headerWorker('set', 'Location', $weburi, 1);
				return; // end the loop
			}
		}
	}
	
	/**
	*	Makes a relative path absolute
	*	@param	str	relative path
	*	@return		absolute path
	*/
	public static function makeAbsPath($path)
	{
		
		// On windows symlinks must have absolute paths
		if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')
		{
			$matchWinAbsolute = '~^([a-z]{1}):(\\/|\\\\)~i'; // regex for valid absolute path on windows
			// no unc-path and no absolute path
			if (substr($path, 0, 2) != '\\\\' && !preg_match($matchWinAbsolute, $path, $arMatches))
			{
				// is a absolute path but without the drive letter
				if (substr($path, 0, 1) == '/')
					$path = substr(getenv('SCRIPT_FILENAME'), 0, 1) . ':' . $path;
				else // relative path
					$path = dirname(getenv('SCRIPT_FILENAME')) . '/' . $path;
			}

		}
		// Linux, Mac
		// without trailing slash -> relative
		elseif (substr($path, 0, 1) !== '/') 
		{
			$path = dirname(getenv('SCRIPT_FILENAME')) . '/' . $path;
		}
		return $path;
	}
	/*
	*	Implements the default-functions for header-handling
	*	@param	str	action to run
	*	@param	str name of the header
	*	@param	str	value of the header
	*	@return	list of headers of result of the action
	*/
	protected static function headerWorker($action = 'get', $name = '', $value = '')
	{
		if (headers_sent())
			return ($action == 'getall') ? [] : '';
		switch($action)
		{
			// returns all already set header
			case 'getall':
				return headers_list();
			break;
			
			// removes a header
			case 'del';
				return header_remove($name);
			break;
			
			// sets a header and overwrites it
			case 'set':
				if (substr($name, 0, 5) == 'HTTP/')
					return header($name);
				else
					return header($name . ': ' . $value, true);
			break;
		}
	}
	
	/**
	* Register the rewrite-method as shutdown function
	*/
	public static function register()
	{
		register_shutdown_function(function() 
		{ 
			$class = __CLASS__;
			$class::rewrite(); 		
		});
	}
	
	/**
	* Runs the garbage collector
	* @return	bool	Returns if directories have been deleted
	**/
	public static function runGBC()
	{
		if (!self::isActive())
			return false;
		
		// save old value and disable error reporting
		$oldER = error_reporting();
		error_reporting(E_ERROR);
		
		$result = false;
		$linkdirs = glob(self::$_linkDir . '*');
		foreach($linkdirs as $linkdir)
		{
			// check creation date
			if (filemtime($linkdir) > (time() - self::$_expireTime))
				continue;
			
			// delete all files in dir
			array_walk(glob($linkdir . '/*'), array(__CLASS__, 'unlink'));
			
			// and the dir itself
			if (rmdir($linkdir))
				$result = true;
		}
		
		// reset error reporting
		error_reporting($oldER);
		return $result;
	}
	
	/* Wrapper for unlink and array_walk */
	protected static function unlink($file, $key = '')
	{
		return unlink($file);
	}
}
?>