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
	protected static $_expireTime = 3600; // default: 1h
	protected static $_salt = '';
	protected static $_dirOk = false;

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
	* returns if is right configured
	* @return	bool	plugin ok
	*/
	public static function isActive()
	{
		return self::$_dirOk;
	}
	
	/**
	* Catches header, creates symlinks an redirects
	*/
	public static function rewrite()
	{
		// not active or header already sent
		if (!self::isActive() || headers_sent())
			return false;
		
		$sendfile = false;
		$filename = '';
		foreach(headers_list() as $header)
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
		if ($sendfile === false)
			return;
		
		// now remove no longer needed headers
		header_remove('X-Sendfile');
		header_remove('X-Accel-Redirect');
		header_remove('X-Lighttpd-Sendfile');
		header_remove('Content-Disposition');
		
		// break the loop if available secret dir found
		while(true)
		{
			$secret = sha1($sendfile . rand() . self::$_salt); // secret hash for symlink directory
			$secretDir = self::$_linkDir . $secret . '/'; // path to secret directory
			if (!is_dir($secretDir))
			{
				// not a absolute path
				if (substr($sendfile, 0, 1) != DIRECTORY_SEPARATOR)
				{
					// fixes for windows-os
					if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')
					{
						// need to add the drive letter
						$sendfile = substr(__FILE__, 0, 1) . ':' . $sendfile;
					}
					else
					{
						// convert to absolute from $sendfile as seen from calling script-dir
						$sendfile = dirname(getenv('SCRIPT_FILENAME')). '/' . $sendfile;
					}
				}
				
				// if no name, get filename
				if (empty($filename))
					$filename = basename($sendfile);
				
				mkdir($secretDir);
				symlink($sendfile, $secretDir . utf8_decode($filename));
				
				// create web path
				if (!empty(self::$_linkDirUri))
				{
					$weburi = self::$_linkDirUri . $secret . '/' . $filename;
				}
				
				header('Location: ' . $weburi);
				break; // end the loop
			}
		}
	}
	
	/**
	* Register the rewrite-method as shutdown function
	*/
	public static function register()
	{
		register_shutdown_function(function() { $class = __CLASS__; $class::rewrite(); });
	}
	
	/**
	* Runs the garbage collector
	* @return	bool	Returns if directories have been deleted
	**/
	public static function runGBC()
	{
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
			rmdir($linkdir);
			$result = true;
		}
		
		return $result;
	}
	
	/* Wrapper for unlink and array_walk */
	protected static function unlink($file, $key = '')
	{
		return unlink($file);
	}
}
?>