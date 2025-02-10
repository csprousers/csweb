<?php

namespace AppBundle\CSPro\FileManager;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
// use Dotenv\Dotenv;


class Utils{
	
	
	public static function env($key, $default = null){
		return $_ENV[$key] ?? $default;
	}
		
	public static function normalize_path($path){
	    // ensure it does not end in a slash, but does start with one
	    return rtrim( Str::start(Utils::clean_path($path), '/'), '/');
	}
	
	public static function base_path($path = ''){
	    return dirname(__FILE__, 3) . Utils::normalize_path($path);
	}
	
/*
	public static function storage_path($path = ''){
	    $path_base = Utils::env('STORAGE_PATH', '/csfilesystem');
	    return Utils::base_path($path_base . Utils::normalize_path($path));
	}
	
*/
	
	public static function clean_path($path){
	    $path_array = explode('/', $path);
	    return implode('/', array_filter($path_array));
	}
	
	
		
	
}