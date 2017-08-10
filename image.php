<?php 

/**
 *  подставлять в url картинки путь до скрипта + параметры
 * <img src="/image.php?w=600&h=360">
 * доступные параметры:
 * w - ширина;
 * h - высота;
 * f - формат: resize (сохраняет соотношение по мин. стороне) или crop (обрезает от центра)
 * t - тип картинки, см. $types или названия папок в /sources
 * ph - добавить на изображение текст с размерами катинки
 */

// в сессии будем хранить список файлов доступных для отображения 
// в течении установленного времени кеша
session_start();

// время кеширования списка файлов в директориях
$cache_minutes = 5; 
$width_default = $height_default = 200;
$base_dir = __DIR__ . '/sources/';
/**
 * Параметры по умолчанию 200x200, crop, abstract 
 * Либо если передан один из параметров w или h, то тот параметр который 
 * не передан будет auto.
 */
$formats = ['resize', 'crop'];
// список папок с изображениями
$types = ['abstract', 'faces', 'auto', 'peoples', 'nature', 'food', 'bussines'];

$width = empty($_GET['w']) ? $width_default: intval($_GET['w']);
$height = empty($_GET['h']) ? $height_default : intval($_GET['h']);

$format = (!empty($_GET['f']) && (($f_key = array_search(mb_strtolower($_GET['f']), $formats)) !== false)) ? $formats[$f_key] : 'crop';
$type = (!empty($_GET['t']) && (($t_key = array_search(mb_strtolower($_GET['t']), $types)) !== false)) ? $types[$t_key] : 'abstract';

$placeholder = (empty($_GET['ph'])) ? false : (string) $_GET['ph'];

//

// массив с файлами кешируется на N минут в сессию пользователя. Надо это учитывать если добавлять новые файлы в папку.

if(isset($_SESSION['filelist_time']) && ((intval($_SESSION['filelist_time'])) > time() - 60 * $cache_minutes) 
	&& isset($_SESSION['filelist']) && ($filelist = unserialize($_SESSION['filelist'])) && is_array($filelist)) {
	// да впринципе нечего не делаем, просто проверили :)	
} else {
	//
	$filelist = [];	

	$directory = opendir($base_dir);
	while ($dirname=readdir($directory)) {
		if ($dirname!='.' && $dirname!='..') {				
			if (is_dir($base_dir . $dirname)) {				
				$sub_directory = opendir($base_dir . $dirname);
				while ($filename = readdir($sub_directory)) {
					if (($filename != '.') && ($filename != '..') 
						&& ($filename != '.gitignore') 
						&& (is_file($base_dir . $dirname . '/' . $filename))) {											
							$filelist[$dirname][] = $filename;												
					}
				}
				closedir ($sub_directory);
				//			
			}	
		}
	}
	closedir ($directory);
	
	// кешируе в сессию
	$_SESSION['filelist'] = serialize($filelist);
	$_SESSION['filelist_time'] = time(); 
}
// end readdir and cache


// 
//imagecreatefromjpeg()

//@todo: требуется рефакторинг - разнести по функциям вывод создание изображения и наложение текста
if (count($filelist[$type])) {
	//
	$rand = array_rand($filelist[$type]);
	$img_filename = $base_dir . $type . '/' . $filelist[$type][$rand];	
	$img_type = exif_imagetype($img_filename);
	
	// создаем изображение
	switch ($img_type) {
		// остальные типы от лукавого! :)
		case IMAGETYPE_JPEG:
			$image = imagecreatefromjpeg($img_filename);
			break;
		case IMAGETYPE_PNG:
			$image = imagecreatefrompng($img_filename);
			break;
		case IMAGETYPE_GIF:
			$image = imagecreatefromgif($img_filename);
			break;
		default:			
			get_error();
			break;
	}
	
	// ресайз или кроп
	switch ($format) {
		case 'resize':
			$image = imageResize($image, $width, $height);				
			break;
		case 'crop':
		default:
			$image = imageCut($image, $width, $height);			
			break;
	}
	
	// нанесение текста
	if ($placeholder) {		
		imagestring($image, 6, 15, 15, "{$width}x{$height}", imagecolorallocate($image, 0, 0, 255));
	}
	
	// выводим в поток	
	header('Content-type: ' . image_type_to_mime_type($img_type));
	//header("Content-Disposition: attachment; filename=\"i.".image_type_to_extension($img_type)."\"");
	header('Cache-Control: max-age=0');	
	imagejpeg($image);
	imagedestroy($image);	
	//
	//echo $filelist[$type][$rand]; 
} else {
	get_error();
}

function get_error() {
	header('HTTP/1.0 404 Not Found');
	exit();
}



function imageCut($image, $width, $height) {		
	//
	$width_old = imagesx($image);
	$height_old = imagesy($image);
		
	$original_aspect = $width_old / $height_old;
	$new_aspect = $width / $height;
	
	if ($original_aspect >= $new_aspect) {		
		$new_height = $height;
		$new_width = $width_old / ($height_old / $height);
	} else {		
		$new_width = $width;
		$new_height = $height_old / ($width_old / $width);
	}

	$top = 0 - ($new_width - $width) / 2;
	$left = 0 - ($new_height - $height) / 2;
	
	$new_image = imagecreatetruecolor($width, $height);
	imagecopyresampled($new_image,$image,$top, $left, 0, 0, $new_width, $new_height, $width_old, $height_old);	
	
	imageDestroy($image);
	//
	return $new_image;
}

function imageResize($image, $width, $height) {
	//
	$width_old = imagesx($image);
	$height_old = imagesy($image);
	
	if (($width < $width_old) || ($height < $height_old)) {
		if (($width_old/$height_old)>($width/$height)) 
			$height = round($height_old*$width/$width_old);
		else 
			$width = round($width_old * $height / $height_old);
	} else {		
		$width = $width_old;
		$height = $height_old;
	}
	
	//var_dump($width, $height, $width_old, $height_old); die();
	$new_image = imagecreatetruecolor($width, $height);
	imagecopyresampled($new_image, $image, 0, 0, 0, 0, $width, $height, $width_old, $height_old);
	imageDestroy($image);
	//
	return $new_image;	
}