<?php 



/**
 * Slideshow Service Class.
 *
 * @author Farzan Mohammadi <farzan.mohamadii@gmail.com>
 * @package ow.plugin.slideshow.bol
 * @since 1.4.0
 */
final class SLIDESHOW_BOL_Service
{
    /**
     * @var SLIDESHOW_BOL_SlideDao
     */
    private $slideDao;
    
    /**
     * Class instance
     *
     * @var SLIDESHOW_BOL_Service
     */
    private static $classInstance;
    
    const SLIDE_IMAGE_PREFIX = 'slide_image_';

    const EVENT_UNINSTALL_IN_PROGRESS = 'slideshow.uninstall_in_progress';
    
    /**
     * Class constructor
     *
     */
    private function __construct()
    {
        $this->slideDao = SLIDESHOW_BOL_SlideDao::getInstance();
    }
    
    public static function getInstance()
    {
        if ( null === self::$classInstance )
        {
            self::$classInstance = new self();
        }
    
        return self::$classInstance;
    }
    
    /**
     * Finds slide by ID
     * 
     * @param $slideId
     */
    public function findSlideById( $slideId )
    {
        return $this->slideDao->findById($slideId);
    }
    
    /**
     * Updates slide
     * 
     * @param $slide
     */
    public function updateSlide( SLIDESHOW_BOL_Slide $slide )
    {
        $this->slideDao->save($slide);
    }
    
    /**
     * Finds all active slides for a slideshow
     * 
     * @param $uniqName
     */
    public function getSlideList( $uniqName )
    {
        if ( !mb_strlen($uniqName) )
        {
            return false;
        }
        
        $list = $this->slideDao->findListByUniqueName($uniqName);
        
        $result = array();
        if ( $list )
        {
            foreach ( $list as $slide )
            {
                $result[$slide->id]['dto'] = $slide;
                $result[$slide->id]['imageUrl'] = $this->getImageUrl($slide->id, $uniqName, $slide->addStamp, $slide->ext);
            }
        }
        
        return $result;
    }
    
    /**
     * Returns all slides
     */
    public function getAllSlideList( $uniqName = null )
    {
    	if ( $uniqName )
    	{
    		return $this->slideDao->findAllByUniqueName($uniqName);
    	}
    	else 
    	{
            return $this->slideDao->findAll();
    	}
    }
    
    /**
     * Adds slide and stores temporary slide image 
     * 
     * @param string $uniqName
     * @param array $file
     */
    public function addTmpSlide( $uniqName, array $file )
    {
    	if ( !$uniqName )
    	{
            return false;
    	}
    	
        $ext = UTIL_File::getExtension($file['name']);
        $image = new UTIL_Image($file['tmp_name']);
        
        $slide = new SLIDESHOW_BOL_Slide();
        $slide->widgetId = $uniqName;
        $slide->width = $image->getWidth();
        $slide->height = $image->getHeight();
        $slide->ext = $ext;
        $slide->order = $this->slideDao->getNextOrder($uniqName);
        $slide->addStamp = time();
        
        $this->slideDao->save($slide);
        
        $path = $this->getImageTmpDir($slide->id, $uniqName, $slide->addStamp, $ext);
        $image->saveImage($path);
       /* try {
            $image->resizeImage(1000, null)
                ->saveImage($path);
                
            if ( $image->imageResized() )
            {
                $image = new UTIL_Image($path);
                $slide->width = $image->getWidth();
                $slide->height = $image->getHeight();
                
                $this->slideDao->save($slide);
            }
        }
        catch ( Exception $e ) 
        {
            $this->slideDao->deleteById($slide->id);
            
            return false;
        }*/
        
        return $slide->id;
    }
    
    /**
     * Copies slide to destination folder and activates slide
     * 
     * @param $slideId
     * @param $title
     * @param $url
     */
    public function addSlide( $slideId, $title, $url )
    {
        if ( !$slideId )
        {
            return false;
        }
        
        $slide = $this->slideDao->findById($slideId);
        
        if ( !$slide )
        {
            return false;
        }
        
        $tmpPath = $this->getImageTmpDir($slide->id, $slide->widgetId, $slide->addStamp, $slide->ext);
        $destPath = $this->getImageDir($slide->id, $slide->widgetId, $slide->addStamp, $slide->ext);
        
        $storage = OW::getStorage();
        
        if ( $storage->copyFile($tmpPath, $destPath) )
        {
            OW::getStorage()->removeFile($tmpPath, true);
            
            $slide->label = $title;
            $slide->url = $url;
            $slide->status = 'active';
            
            $this->slideDao->save($slide);
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Replaces slide image with a new one 
     * 
     * @param $slideId
     * @param $file
     */
    public function updateSlideImage( $slideId, $file )
    {
        if ( !$slideId )
        {
            return false;
        }
        
        $storage = OW::getStorage();
        
        $slide = $this->findSlideById($slideId);
        $oldPath = $this->getImageDir($slide->id, $slide->widgetId, $slide->addStamp, $slide->ext);
        
        $ext = UTIL_File::getExtension($file['name']);
        
        $newAddStamp = time();
        $tmpPath = $this->getImageTmpDir($slide->id, $slide->widgetId, $newAddStamp, $slide->ext);
        $destPath = $this->getImageDir($slide->id, $slide->widgetId, $newAddStamp, $slide->ext);
        
        if ( OW::getStorage()->moveFile($file['tmp_name'], $tmpPath) )
        {
        	$image = new UTIL_Image($tmpPath);
        	$image->resizeImage(1000, null)
        	   ->saveImage($tmpPath);
        	
        	$slide->width = $image->getWidth();
	        $slide->height = $image->getHeight();
	        $slide->ext = $ext;
	        $slide->addStamp = $newAddStamp;
	        
	        $this->slideDao->save($slide);
        	
            $storage->removeFile($oldPath);
            
            $storage->copyFile($tmpPath, $destPath);
            OW::getStorage()->removeFile($tmpPath, true);
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Adds slide to delete queue 
     * to be deleted later by a cron process
     * 
     * @param $id
     */
    public function addSlideToDeleteQueue( $id )
    {
        $slide = $this->findSlideById($id);
        
        if ( !$slide )
        {
            return false;
        }
        
        $slide->status = 'delete';
        $this->slideDao->save($slide);
        
        return true;
    }
    
    /**
     * Returns slide list marked for removal 
     * @param int $limit
     */
    public function getDeleteQueueList( $limit )
    {
        return $this->slideDao->getListForRemoval( $limit );
    }
    
    /**
     * Removes slide and its image
     * 
     * @param $slideId
     */
    public function deleteSlideById( $slideId )
    {
        if ( !$slideId )
        {
            return false;
        }
        
        $slide = $this->findSlideById($slideId);
        
        if ( !$slide )
        {
            return false;
        }
        
        $storage = OW::getStorage();
        
        $path = $this->getImageDir($slide->id, $slide->widgetId, $slide->addStamp, $slide->ext);
        if ( $storage->fileExists($path) )
        {
            $storage->removeFile($path);
        }

        $this->slideDao->deleteById($slide->id);
        
        return true;
    }
    
    /**
     * Returns image url
     * 
     * @param int $slideId
     * @param string $uniqName
     * @param int $addStamp
     * @param string $ext
     */
    public function getImageUrl( $slideId, $uniqName, $addStamp, $ext )
    {
    	$dir = OW::getPluginManager()->getPlugin('slideshow')->getUserFilesDir();

        $storage = OW::getStorage();

        return $storage->getFileUrl($dir . self::SLIDE_IMAGE_PREFIX . $slideId . '_' . $uniqName . '_' . $addStamp . '.'. $ext);
    }
    
    /**
     * Returns image temporary path in pluginfiles dir 
     * 
     * @param $slideId
     * @param $uniqName
     * @param $addStamp
     * @param $ext
     */
 	public function getImageTmpDir( $slideId, $uniqName, $addStamp, $ext )
    {
    	$dir = OW::getPluginManager()->getPlugin('slideshow')->getPluginFilesDir();
    	
        return $dir . self::SLIDE_IMAGE_PREFIX . $slideId . '_' . $uniqName . '_' . $addStamp . '.' . $ext;
    }
    
    /**
     * Returns image path in userfiles dir
     * 
     * @param $slideId
     * @param $uniqName
     * @param $addStamp
     * @param $ext
     */
    public function getImageDir( $slideId, $uniqName, $addStamp, $ext )
    {
        $dir = OW::getPluginManager()->getPlugin('slideshow')->getUserFilesDir();
        
        return $dir . self::SLIDE_IMAGE_PREFIX . $slideId . '_' . $uniqName . '_' . $addStamp . '.' . $ext;
    }
    
    /**
     * Returns array of slide image sizes
     * 
     * @param array $slides
     */
    public function getSizes( $slides )
    {
        if ( !$slides )
        {
            return null;
        }
        
        $res = array();
        foreach ( $slides as $slide )
        {
            $dto = $slide['dto'];
            $res[$dto->id] = array('width' => $dto->width, 'height' => $dto->height);
        }
        
        return $res;
    }
}