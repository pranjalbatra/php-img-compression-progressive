<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

	class ImageLib {

		private $msg;
		private $isPNG = FALSE;

		private function isImageCapable(){

			if (!extension_loaded('gd')){
				$this->msg = "PHP GD library not installed or not enabled";
				return 0; 
			}

			$gd = gd_info();

			if(!$gd["GIF Read Support"]) { $this->msg = "GIF read support is not available"; return 0; }
			if(!$gd["GIF Create Support"]) { $this->msg = "GIF create support is not available"; return 0; }
			if(!$gd["JPEG Support"]) { $this->msg = "JPEG support is not available"; return 0; }
			if(!$gd["PNG Support"]) { $this->msg = "PNG support is not available"; return 0; }

			return 1; // all required GD support are available
		}

		private function png2jpg($originalFile, $quality) {

			$dest = $originalFile . "-conv-png.png";
		    $image = imagecreatefrompng($originalFile);
			$bg = imagecreatetruecolor(imagesx($image), imagesy($image));
			imagefill($bg, 0, 0, imagecolorallocate($bg, 229, 229, 229));
			imagealphablending($bg, TRUE);
			imagecopy($bg, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
			imagedestroy($image);
			imagejpeg($bg, $dest, $quality);
			imagedestroy($bg);

		    @unlink($originalFile);

		    return $dest;
		}

		private function makeProgressive($source){

			$dest = $source . "-progressive.jpg";
			$im = imagecreatefromjpeg($source);
			imageinterlace($im, 1);
			imagejpeg($im, $dest, 100);
			imagedestroy($im);

			@unlink($source);

			return $dest;
		}

		private function is_animatedGIF($filename) {

			if(exif_imagetype($filename) != IMAGETYPE_GIF )
				return -2;
		    if(!($fh = @fopen($filename, 'rb')))
		        return -1;

		    $count = 0;
		    //an animated gif contains multiple "frames", with each frame having a
		    //header made up of:
		    // * a static 4-byte sequence (\x00\x21\xF9\x04)
		    // * 4 variable bytes
		    // * a static 2-byte sequence (\x00\x2C)

		    // We read through the file til we reach the end of the file, or we've found
		    // at least 2 frame headers
		    while(!feof($fh) && $count < 2) {
		        $chunk = fread($fh, 1024 * 100); //read 100kb at a time
		        $count += preg_match_all('#\x00\x21\xF9\x04.{4}\x00[\x2C\x21]#s', $chunk, $matches);
		    }

		    fclose($fh);

		    if($count>1) return 1;
		    else return 0;
		}

		public function getMsg(){
			return $this->msg;
		}

		public function compress($src, $quality){

			if($this->isImageCapable() == 0){
				return; // server is not able to handle image processing, most likely due to GD
			}
			//get image type
			switch(exif_imagetype($src)) {
				case IMAGETYPE_GIF:{
					if($this->is_animatedGIF($src) == 1){
						$this->msg ="Animated GIF image can not be compressed";
						return;
					}else
						$src_img = imagecreatefromgif($src);

					$this->isPNG = FALSE;
				}
				break;

				case IMAGETYPE_JPEG:
					$src_img = imagecreatefromjpeg($src);
					$this->isPNG = FALSE;
					break;

				case IMAGETYPE_PNG:
					$src_img = imagecreatefrompng($src);
					$this->isPNG = TRUE;
					break;

				case IMAGETYPE_BMP:
					$src_img = imagecreatefromwbmp($src);
					$this->isPNG = FALSE;
					break;

				default:
					$this->msg = 'Invalid file type, only GIF, PNG, JPG or JPEG are allowed';
					$this->isPNG = FALSE;
		        	return;
		        	break;

			}

			if(!$src_img){
				$this->msg = "Failed to read image file";
				return;
			}

			//if image is PNG, save it's transparency
			if($this->isPNG == TRUE){
				$bg = imagecreatetruecolor(imagesx($src_img), imagesy($src_img));

				imagefill($bg, 0, 0, imagecolorallocate($bg, 229, 229, 229));
				imagealphablending($bg, TRUE);
				imagecopy($bg, $src_img, 0, 0, 0, 0, imagesx($src_img), imagesy($src_img));
				imagedestroy($src_img);

				@unlink($src);
				imageinterlace($bg, 1);
				imagejpeg($bg, $src, $quality);
				imagedestroy($bg);

			}else{
				@unlink($src);
				imageinterlace($src_img, 1);
				imagejpeg($src_img, $src, $quality);
				imagedestroy($src_img);
			}

		}

		public function crop($src, $dst, $JSONdata, $minW, $minH, $maxW, $maxH, $quality) {

			if($this->isImageCapable() == 0){
				return; // server is not able to handle image processing, most likely due to GD
			}

		    if (!empty($src) && !empty($dst) && !empty($JSONdata)) {

		      switch (exif_imagetype($src)) {
		        case IMAGETYPE_GIF:{
		        	if($this->is_animatedGIF($src) == 1){
		        		// GIF is animated, don't crop it
		        		copy($src, $dst);
		        		@unlink($src);
		        		$this->msg = "success";
		        		return;
		        	}else
		        		$src_img = imagecreatefromgif($src);
		      	  }
		          break;

		        case IMAGETYPE_JPEG:
		          $src_img = imagecreatefromjpeg($src);
		          break;

		        case IMAGETYPE_PNG:
		          $src_img = imagecreatefrompng($src);
		          break;

		        default:
		        	$this->msg = 'Invalid file type, only GIF, PNG, JPG or JPEG are allowed';
		        	return;
		        	break;
		      }

		      $data = json_decode(stripslashes($JSONdata));

		      //If image size is low than minimum size provided, then show error
		      if( abs($data -> width) < abs($minW) || abs($data -> height) < abs($minH) ){
		      	$this->msg = "Selected image/area is smaller than minimum required size.<br>Minimum width: " . $minW . " and height: " . $minH;
		      	return;
		      }

		      if (!$src_img) {
		        $this -> msg = "Failed to read the image file.";
		        return;
		      }

		      $size = getimagesize($src);
		      $size_w = $size[0]; // natural width
		      $size_h = $size[1]; // natural height

		      $src_img_w = $size_w;
		      $src_img_h = $size_h;

		      $degrees = $data -> rotate;

		      // Rotate the source image
		      if (is_numeric($degrees) && $degrees != 0) {
		        // PHP's degrees is opposite to CSS's degrees
		        $new_img = imagerotate( $src_img, -$degrees, imagecolorallocatealpha($src_img, 229, 229, 229, 127) );

		        imagedestroy($src_img);
		        $src_img = $new_img;

		        $deg = abs($degrees) % 180;
		        $arc = ($deg > 90 ? (180 - $deg) : $deg) * M_PI / 180;

		        $src_img_w = $size_w * cos($arc) + $size_h * sin($arc);
		        $src_img_h = $size_w * sin($arc) + $size_h * cos($arc);

		        // Fix rotated image miss 1px issue when degrees < 0
		        $src_img_w -= 1;
		        $src_img_h -= 1;
		      }

		      $tmp_img_w = $data -> width;
		      $tmp_img_h = $data -> height;

		      $dst_img_w = 0;
		      $dst_img_h = 0;

		      //check if selected image size is larger than max image size provided, then round it up
		      if( abs($data -> width) > abs($maxW) || abs($data -> height) > abs($maxH)){
		      	$dst_img_w = abs($maxW);
		      	$dst_img_h = abs($maxH);
		      }else{
		        $dst_img_w = $data -> width;
		        $dst_img_h = $data -> height;
		  	  }

		      $src_x = $data -> x;
		      $src_y = $data -> y;

		      if ($src_x <= -$tmp_img_w || $src_x > $src_img_w) {
		        $src_x = $src_w = $dst_x = $dst_w = 0;
		      } else if ($src_x <= 0) {
		        $dst_x = -$src_x;
		        $src_x = 0;
		        $src_w = $dst_w = min($src_img_w, $tmp_img_w + $src_x);
		      } else if ($src_x <= $src_img_w) {
		        $dst_x = 0;
		        $src_w = $dst_w = min($tmp_img_w, $src_img_w - $src_x);
		      }

		      if ($src_w <= 0 || $src_y <= -$tmp_img_h || $src_y > $src_img_h) {
		        $src_y = $src_h = $dst_y = $dst_h = 0;
		      } else if ($src_y <= 0) {
		        $dst_y = -$src_y;
		        $src_y = 0;
		        $src_h = $dst_h = min($src_img_h, $tmp_img_h + $src_y);
		      } else if ($src_y <= $src_img_h) {
		        $dst_y = 0;
		        $src_h = $dst_h = min($tmp_img_h, $src_img_h - $src_y);
		      }

		      // Scale to destination position and size
		      $ratio = $tmp_img_w / $dst_img_w;
		      $dst_x /= $ratio;
		      $dst_y /= $ratio;
		      $dst_w /= $ratio;
		      $dst_h /= $ratio;

		      $dst_img = imagecreatetruecolor($dst_img_w, $dst_img_h);

		      // Add transparent background to destination image
		      imagefill($dst_img, 0, 0, imagecolorallocatealpha($dst_img, 229, 229, 229, 127));
		      imagesavealpha($dst_img, true);

		      $result = imagecopyresampled($dst_img, $src_img, $dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h);

		      if ($result) {
		        if (!imagepng($dst_img, $dst)) {
		          $this -> msg = "Failed to save the cropped image file";
		        }else{
		        	// image is saved as PNG, now convert it to progressive JPEG
		        	$temp = $this->png2jpg($dst, $quality);
		        	$temp = $this->makeProgressive($temp);

		        	//safe measures, rename back the file to provided file path
		        	@unlink($dst);
		        	rename($temp, $dst);
		        	$this->msg = "success";
		        }
		      } else {
		        $this -> msg = "Failed to crop the image file";
		      }

		      imagedestroy($src_img);
		      imagedestroy($dst_img);

		      @unlink($src);
		    }else{
		    	$this->msg = "Invalid details provided";
		    }
		}
	}
?>