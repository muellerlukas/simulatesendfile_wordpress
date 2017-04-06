<?php
/*
* Plugin Name: Simulate X-Sendfile
* Description: This plugin simulates the X-Sendfile and x-accel-redirect with symlinks
* Version: 0.1
* Author: Lukas Müller
* Author URI: https://muellerlukas.de
*/
namespace mulu;
use mulu\xsendfile;
require 'xsendfile.class.php';

class simulatesendfile
{
	protected static $_cronName = 'simulatesendfile_gbc';
	public static function init()
	{
		// add wordpress options
		add_option('simulatesendfile_dir', 'symlinks');
		add_option('simulatesendfile_expire', 3600);
		
		xsendfile::setLinkDir(WP_CONTENT_DIR . '/' . get_option('simulatesendfile_dir', 'symlinks') . '/');
		xsendfile::setLinkDirUri(content_url() . '/' . get_option('simulatesendfile_dir', 'symlinks') . '/');
		xsendfile::setExpireTime(get_option('simulatesendfile_expire', 3600));
		xsendfile::register();
		
		// register wp_cron
		add_action(self::$_cronName, array(__CLASS__, 'runGBC'));

		// shedule if not already sheduled
		if (!wp_next_scheduled(self::$_cronName)) 
		{
			wp_schedule_event(time(), 'hourly', self::$_cronName);
		}
		
		register_deactivation_hook(__FILE__, array(__CLASS__, 'disable'));
	}
	

	
	public static function runGBC()
	{
		xsendfile::runGBC();
	}
	
	public static function disable()
	{
	   $timestamp = wp_next_scheduled(self::$_cronName);
	   wp_unschedule_event( $timestamp, self::$_cronName);
	}
}
add_action( 'init', array('mulu\simulatesendfile', 'init'));

?>