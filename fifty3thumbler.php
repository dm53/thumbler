<?php
/**
 * This plugin scales/resizes loaded images to width and height set in images properties when you save content page.
 *
 * @author Andrey Loskutnikov <andrey@loskutnikoff.ru>
 * @copyright Copyright © 2016 Andrey Loskutnikov. All rights reserved.
 * @license GNU General Public License version 3 https://www.gnu.org/licenses/gpl-3.0.html
 * @link http://fifty3.ru
 * @version 1.0
 */

// no direct access
defined( '_JEXEC' ) or die( 'Restricted access' );

class plgContentFifty3Thumbler extends JPlugin
{
    private $bounds = array(0, 0);
    public function __construct()
    {
        $this->initBounds();

        parent::__construct();
    }

    /**
     * Content BeforeSave Handler
     *
     * @see JPlugin::onContentBeforeSave
     * @return bool
     */
    public function onContentBeforeSave($context, $article, $isNew)
    {
        //DEBUG
        if ($_SERVER['REMOTE_ADDR'] != '85.93.138.67')
            return false;

        $this->resize($article->introtext);
        //$this->resize($article->fulltext);

        return true;
    }

    /**
     * Checks text text for images that need to resize
     *
     * @param string $text text where to find and fix images
     * @return bool
     */
    private function resize(&$text)
    {
        //find all images stored local
        $text = preg_replace_callback('/(?<tag><img[^>]*?src="(?!([a-z]*:)?\/\/)[^"][^"]+".*?>)/i', function ($matches)
        {
            $dom = new DOMDocument();
            $dom->loadHTML($matches['tag']);
            $img = $dom->documentElement->firstChild->firstChild;

            $style = $img->getAttribute('style');

            list($width, $height) = $this->getSize($style);

            $src = $img->getAttribute('src');

            $file = JPATH_ROOT.'/'.$src;

            if (!file_exists($file))
                return false;

            $timeout = (int) $this->params->get('image_timeout');
            if ($timeout < 0)
                $timeout = 0;

            $new_file = $file;
            $new_src = $src;

            if (microtime(true) - filemtime($file) > $timeout) {
                $pi = pathinfo($src);
                $pi['filename'] .= '.'.$width.'x'.$height;
                $new_src = $pi['dirname'].'/'.$pi['filename'].'.'.$pi['extension'];
                $new_file = JPATH_ROOT.'/'.$new_src;
                unset($pi);
            }

            if (!empty($this->params->get('convert_to_jpeg'))) {
                $pi = pathinfo($new_src);
                $new_src = $pi['dirname'].'/'.$pi['filename'].'.jpg';
                $new_file = JPATH_ROOT.'/'.$new_src;
                unset($pi);
            }

            if (file_exists($new_file)) {
                $img->setAttribute('src', $new_file);
                return $dom->saveHTML($img);
            }

            try {
                $image = new Imagick();
                $image->readImage($file);
                $orig_size = $size = $image->getSize();

                if (!empty($width) || !empty($height)) { //image width or height set
                    $image->scaleImage($width, $height);
                    $size = $image->getSize();
                }

                if (($bounds = $this->respectBounds($size)) != $size) { //fit image size to boubds
                    $image->scaleImage($bounds[0], $bounds[1]);
                    $size = $image->getSize();
                }

                if (!empty($this->params->get('convert_to_jpeg')) && !preg_match('/^jpe?g$/i', $image->getImageFormat())) {
                    $image->setImageFormat('jpg');
                    unlink($file);
                } elseif ($orig_size == $size) { //if image size doesn't change and we don't want to make it jpeg, return original image
                    $image->destroy();
                    throw new Exception();
                }

                $image->writeImage($new_file);
                $image->destroy();
            } catch (Exception $e) {
                return false;
            }

            if ($new_src == $src)
                return false;

            $img->setAttribute('src', $new_src);

            return $dom->saveHTML($img);
        }, $text);
    }

    /**
     * Gets width and height params form tag's style value. Only px values accepted
     *
     * @param string $style Tag's style value
     * @return array Array of width and height. Values set to 0 if not found
     */
    private function getSize($style)
    {
        $width = $height = '0';
        if (preg_match('/width:\s?(\d+)\s?px\s?;/', $style, $matches))
            $width = $matches[1];
        if (preg_match('/height:\s?(\d+)\s?px\s?;/', $style, $matches))
            $height = $matches[1];

        return array($width, $height);
    }

    /**
     * Initialize image bounds
     */
    private function initBounds()
    {
        $width = (int) $this->params->get('max_width');
        $height = (int) $this->params->get('max_height');

        $this->bounds = array($width, $height);
    }

    /**
     * Fits width && height to bounds from plugin config.
     * <b>Zero values is not allowed!</b>
     *
     * @param array $size width and height
     * @return array Width and height after bounds fit
     */
    private function respectBounds(array $size)
    {
        list($width, $height) = $size;
        list($max_width, $max_height) = $this->bounds;

        if ($max_width == 0 && $max_height == 0
            || $width == 0 || $height == 0)
            return array($width, $height);

        $ar = $width / $height;

        if ($max_width == 0) { //max height set
            if ($height > $max_height) {
                $height = $max_height;
                $width = $height * $ar;
            }
        } elseif ($max_height == 0) { //max width set
            if ($width > $max_width) {
                $width = $max_width;
                $height = $width / $ar;
            }
        } else { //max width && height set
            if ($height > $max_height) {
                $height = $max_height;
                $width = $height * $ar;
            }
            if ($width > $max_width) {
                $width = $max_width;
                $height = $width / $ar;
            }
        }

        $width = ceil($width);
        $height = ceil($height);

        return array($width, $height);
    }
}

