<?php
// emulate a existing mod_xsendfile module in apache
if (!function_exists('apache_get_modules'))
{
	
	function apache_get_modules()
	{
		$checkMethod = 'mulu\xsendfile::isActive';
		$modules = array();
		if (is_callable($checkMethod) && call_user_func($checkMethod))
		{
			$modules[] = 'mod_xsendfile';
		}
		return $modules;
	}
}