<?php
// emulate a existing mod_xsendfile module in apache
if (!function_exists('apache_get_modules'))
{
	function apache_get_modules()
	{
		return array('mod_xsendfile');
	}
}