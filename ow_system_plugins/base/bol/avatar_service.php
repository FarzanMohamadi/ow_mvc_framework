<?php
/**
 * Avatar service class
 *
 * @author Farzan Mohammadi <farzan.mohamadii@gmail.com>
 * @package ow.ow_system_plugins.base.bol
 * @since 1.0
 */
class BOL_AvatarService
{
    /**
     * @var BOL_AvatarDao
     */
    private $avatarDao;


    const AVATAR_PREFIX = 'avatar_';

    const AVATAR_BIG_PREFIX = 'avatar_big_';

    const AVATAR_ORIGINAL_PREFIX = 'avatar_original_';

    const AVATAR_CHANGE_GALLERY_LIMIT = 12;

    const AVATAR_CHANGE_SESSION_KEY = 'base.avatar_change_key';

    const USER_AVATAR_IMAGE_NAME = 'default_user_avatar.svg';

    const USER_AVATAR_BIG_IMAGE_NAME = 'no-avatar-big.png';

    const GROUPS_AVATAR_BIG_IMAGE_NAME = 'default_group_image.svg';

    const NEWS_AVATAR_BIG_IMAGE_NAME = 'default_news_image.svg';

    const EVENTS_AVATAR_BIG_IMAGE_NAME = 'default_event_image.svg';

    const AVATAR_RANGE_COLOR_COUNT = 9;

    /**
     * @var BOL_AvatarService
     */
    private static $classInstance;

    /**
     * Class constructor
     */
    private function __construct()
    {
        $this->avatarDao = BOL_AvatarDao::getInstance();
    }

    /**
     * Singleton instance.
     *
     * @return BOL_AvatarService
     */
    public static function getInstance()
    {
        if ( self::$classInstance === null )
        {
            self::$classInstance = new self();
        }

        return self::$classInstance;
    }

    /**
     * Find avatar object by userId
     *
     * @param int $userId
     * @return BOL_Avatar
     */
    public function findByUserId( $userId, $checkCache = true )
    {
        return $this->avatarDao->findByUserId($userId, $checkCache);
    }

    public function findAvatarByIdList( $idList )
    {
        return $this->avatarDao->findByIdList($idList);
    }

    /**
     * Find avatar object by userId list
     *
     * @param int $userId
     * @return BOL_Avatar
     */
    public function findByUserIdList( $userIdList )
    {
        return $this->avatarDao->getAvatarsList($userIdList);
    }

    public function findAvatarById( $id )
    {
        return $this->avatarDao->findById($id);
    }

    /**
     * Updates avatar object
     *
     * @param BOL_Avatar $avatar
     * @return int
     */
    public function updateAvatar( BOL_Avatar $avatar )
    {
        $this->clearCahche($avatar->userId);
        $this->avatarDao->save($avatar);

        return $avatar->id;
    }

    public function clearCahche( $userId )
    {
        $this->avatarDao->clearCahche($userId);
    }

    /**
     * Removes avatar image file
     *
     * @param string $path
     */
    public function removeAvatarImage( $path )
    {
        $storage = OW::getStorage();

        if ( $storage->fileExists($path) )
        {
            $storage->removeFile($path);
        }
    }

    /**
     * Removes user avatar
     *
     * @param int $userId
     * @return boolean
     */
    public function deleteUserAvatar( $userId )
    {
        if ( !$userId )
        {
            return false;
        }

        if ( !$this->userHasAvatar($userId) )
        {
            return true;
        }

        $avatar = $this->findByUserId($userId);

        $event = new OW_Event('base.before_user_avatar_delete', array('avatarId' => $avatar->id ));
        OW::getEventManager()->trigger($event);

        if ( $avatar )
        {
            return $this->deleteAvatar($avatar);
        }

        return false;
    }

    private function deleteAvatar( BOL_Avatar $avatar )
    {
        if ( empty($avatar) )
        {
            return false;
        }

        $this->avatarDao->deleteById($avatar->id);

        // avatar image
        $avatarPath = $this->getAvatarPath($avatar->userId, 1, $avatar->hash);
        $this->removeAvatarImage($avatarPath);

        // avatar big image
        $bigAvatarPath = $this->getAvatarPath($avatar->userId, 2, $avatar->hash);
        $this->removeAvatarImage($bigAvatarPath);

        // avatar original image
        $origAvatarPath = $this->getAvatarPath($avatar->userId, 3, $avatar->hash);
        $this->removeAvatarImage($origAvatarPath);

        return true;
    }

    public function deleteAvatarById( $id )
    {
        if ( !$id )
        {
            return false;
        }

        $avatar = $this->avatarDao->findById($id);

        if ( $avatar )
        {
            return $this->deleteAvatar($avatar);
        }

        return false;
    }

    /**
     * Crops user avatar using coordinates
     *
     * @param int $userId
     * @param $path
     * @param array $coords
     * @param int $viewSize
     * @return bool
     */
    public function cropAvatar( $userId, $path, $coords, $viewSize, array $editionalParams = array() )
    {
        $this->deleteUserAvatar($userId);

        $avatar = new BOL_Avatar();
        $avatar->userId = $userId;
        $avatar->hash = time();

        $this->updateAvatar($avatar);

        $params = array(
            'avatarId' => $avatar->id,
            'userId' => $userId,
            'trackAction' => isset($editionalParams['trackAction'])  ? $editionalParams['trackAction'] : true
        );

        $event = new OW_Event('base.after_avatar_update', array_merge($editionalParams, $params));
        OW::getEventManager()->trigger($event);

        // destination path
        $avatarPath = $this->getAvatarPath($userId, 1, $avatar->hash);
        $avatarBigPath = $this->getAvatarPath($userId, 2, $avatar->hash);
        $avatarOriginalPath = $this->getAvatarPath($userId, 3, $avatar->hash);

        // pluginfiles tmp path
        $avatarPFPath = $this->getAvatarPluginFilesPath($userId, 1, $avatar->hash);
        $avatarPFBigPath = $this->getAvatarPluginFilesPath($userId, 2, $avatar->hash);
        $avatarPFOriginalPath = $this->getAvatarPluginFilesPath($userId, 3, $avatar->hash);

        if ( !OW::getStorage()->isWritable(dirname($avatarPFPath)) )
        {
            $this->deleteUserAvatar($userId);

            return false;
        }

        $storage = OW::getStorage();

        if ( !empty($editionalParams['isLocalFile']) )
        {
            $toFilePath = $path;
        }
        else
        {
            $toFilePath = OW::getPluginManager()->getPlugin('base')->getPluginFilesDir() . FRMSecurityProvider::generateUniqueId(md5( rand(0,9999999999) )) . '.' .UTIL_File::getExtension($path);

            $storage->copyFileToLocalFS($path, $toFilePath);
        }

        $result = true;
        try
        {
            $image = new UTIL_Image($toFilePath);

            $width = $image->getWidth();
            $k = $width / $viewSize;

            $config = OW::getConfig();
            $avatarSize = (int) $config->getValue('base', 'avatar_size');
            $bigAvatarSize = (int) $config->getValue('base', 'avatar_big_size');

            $image->saveImage($avatarPFOriginalPath)
                ->cropImage($coords['x'] * $k, $coords['y'] * $k, $coords['w'] * $k, $coords['h'] * $k)
                ->resizeImage($bigAvatarSize, $bigAvatarSize, true)
                ->saveImage($avatarPFBigPath)
                ->resizeImage($avatarSize, $avatarSize, true)
                ->saveImage($avatarPFPath);

            $storage->copyFile($avatarPFOriginalPath, $avatarOriginalPath);
            $storage->copyFile($avatarPFBigPath, $avatarBigPath);
            $storage->copyFile($avatarPFPath, $avatarPath);
        }
        catch (Exception $ex)
        {
            $result = false;
        }

        OW::getStorage()->removeFile($avatarPFPath, true);
        OW::getStorage()->removeFile($avatarPFBigPath, true);
        OW::getStorage()->removeFile($avatarPFOriginalPath, true);
        OW::getStorage()->removeFile($toFilePath, true);

        return $result;
    }

    public function cropTempAvatar( $key, $coords, $viewSize )
    {
        $originalPath = $this->getTempAvatarPath($key, 3);
        $bigAvatarPath = $this->getTempAvatarPath($key, 2);
        $avatarPath = $this->getTempAvatarPath($key, 1);

        $image = new UTIL_Image($originalPath);

        $width = $image->getWidth();

        $k = $width / $viewSize;

        $config = OW::getConfig();
        $avatarSize = (int) $config->getValue('base', 'avatar_size');
        $bigAvatarSize = (int) $config->getValue('base', 'avatar_big_size');

        $image->cropImage($coords['x'] * $k, $coords['y'] * $k, $coords['w'] * $k, $coords['h'] * $k)
            ->resizeImage($bigAvatarSize, $bigAvatarSize, true)
            ->saveImage($bigAvatarPath)
            ->resizeImage($avatarSize, $avatarSize, true)
            ->saveImage($avatarPath);

        return true;
    }

    public function setUserAvatar( $userId, $uploadedFileName, array $editionalParams = array() )
    {
        $avatar = $this->findByUserId($userId);

        if ( !$avatar )
        {
            $avatar = new BOL_Avatar();
            $avatar->userId = $userId;
        }
        else
        {
            $oldHash = $avatar->hash;
        }

        $avatar->hash = time();

        // destination path
        $avatarPath = $this->getAvatarPath($userId, 1, $avatar->hash);
        $avatarBigPath = $this->getAvatarPath($userId, 2, $avatar->hash);
        $avatarOriginalPath = $this->getAvatarPath($userId, 3, $avatar->hash);

        // pluginfiles tmp path
        $avatarPFPath = $this->getAvatarPluginFilesPath($userId, 1, $avatar->hash);
        $avatarPFBigPath = $this->getAvatarPluginFilesPath($userId, 2, $avatar->hash);
        $avatarPFOriginalPath = $this->getAvatarPluginFilesPath($userId, 3, $avatar->hash);

        if ( !OW::getStorage()->isWritable(dirname($avatarPFPath)) )
        {
            return false;
        }

        try
        {
            $image = new UTIL_Image($uploadedFileName);

            $config = OW::getConfig();

            $configAvatarSize = $config->getValue('base', 'avatar_size');
            $configBigAvatarSize = $config->getValue('base', 'avatar_big_size');

            $image->saveImage($avatarPFOriginalPath)
                ->resizeImage($configBigAvatarSize, $configBigAvatarSize, true)
                ->saveImage($avatarPFBigPath)
                ->resizeImage($configAvatarSize, $configAvatarSize, true)
                ->saveImage($avatarPFPath);

            $this->updateAvatar($avatar);

            $params = array(
                'avatarId' => $avatar->id,
                'userId' => $userId,
                'trackAction' => isset($editionalParams['trackAction'])  ? $editionalParams['trackAction'] : true
            );

            $event = new OW_Event('base.after_avatar_update', array_merge( $editionalParams, $params) );
            OW::getEventManager()->trigger($event);

            // remove old images
            if ( isset($oldHash) )
            {
                $oldAvatarPath = $this->getAvatarPath($userId, 1, $oldHash);
                $oldAvatarBigPath = $this->getAvatarPath($userId, 2, $oldHash);
                $oldAvatarOriginalPath = $this->getAvatarPath($userId, 3, $oldHash);

                $this->removeAvatarImage($oldAvatarPath);
                $this->removeAvatarImage($oldAvatarBigPath);
                $this->removeAvatarImage($oldAvatarOriginalPath);
            }

            $storage = OW::getStorage();

            $storage->copyFile($avatarPFOriginalPath, $avatarOriginalPath);
            $storage->copyFile($avatarPFBigPath, $avatarBigPath);
            $storage->copyFile($avatarPFPath, $avatarPath);

            OW::getStorage()->removeFile($avatarPFPath, true);
            OW::getStorage()->removeFile($avatarPFBigPath, true);
            OW::getStorage()->removeFile($avatarPFOriginalPath, true);

            return true;
        }
        catch ( Exception $e )
        {
            return false;
        }
    }

    public function uploadUserTempAvatar( $key, $uploadedFileName )
    {
        $path = $this->getTempAvatarPath($key, 3);

        if ( !OW::getStorage()->isWritable(dirname($path)) )
        {
            OW::getStorage()->removeFile($uploadedFileName, true);

            return false;
        }

        if ( OW::getStorage()->moveFile($uploadedFileName, $path) )
        {
            OW::getStorage()->removeFile($uploadedFileName, true);

            return true;
        }

        OW::getStorage()->removeFile($uploadedFileName, true);

        return false;
    }

    public function deleteUserTempAvatar( $key, $size = null )
    {
        if ( !$key )
        {
            return false;
        }

        if ( $size === null )
        {
            OW::getStorage()->removeFile($this->getTempAvatarPath($key, 1), true);
            OW::getStorage()->removeFile($this->getTempAvatarPath($key, 2), true);
            OW::getStorage()->removeFile($this->getTempAvatarPath($key, 3), true);

            return true;
        }

        $path = $this->getTempAvatarPath($key, $size);

        if ( OW::getStorage()->fileExists($path) )
        {
            OW::getStorage()->removeFile($path, true);
        }

        return true;
    }

    public function deleteTempAvatars( )
    {
        $path = OW::getPluginManager()->getPlugin('base')->getUserFilesDir() . 'avatars' . DS . 'tmp' . DS;

        if ( $handle = opendir($path) )
        {
            while ( false !== ($file = readdir($handle)) )
            {
                if ( !OW::getStorage()->isFile($path.$file) )
                {
                    continue;
                }

                if ( time() - filemtime($path.$file) >= 60*60*24 )
                {
                    if ( !preg_match('/\.jpg$/i', $file) )
                    {
                        continue;
                    }

                    OW::getStorage()->removeFile($path.$file, true);
                }
            }
        }
    }

    /**
     * Give avatar original new name after hash is changed
     *
     * @param int $userId
     * @param int $oldHash
     * @param int $newHash
     */
    public function renameAvatarOriginal( $userId, $oldHash, $newHash )
    {
        $originalPath = $this->getAvatarPath($userId, 3, $oldHash);
        $newPath = $this->getAvatarPath($userId, 3, $newHash);

        OW::getStorage()->renameFile($originalPath, $newPath);
    }

    /**
     * Get url to access avatar image
     *
     * @param int $userId
     * @param int $size
     * @param null $hash
     * @param bool $checkCache
     * @param bool $getDefaultOnNull
     * @return string
     */
    public function getAvatarUrl( $userId, $size = 1, $hash = null, $getDefaultOnNull=true, $checkModerationStatus = true)
    {
        $event = new OW_Event("base.avatars.get_list", array(
            "userIds" => array($userId),
            "size" => $size,
            "checkModerationStatus" => $checkModerationStatus
        ));

        $eventAvatars = OW::getEventManager()->trigger($event)->getData();

        if ( isset($eventAvatars[$userId]) )
        {
            return $eventAvatars[$userId];
        }

        $avatar = $this->avatarDao->findByUserId($userId, false);

        if ($avatar) {
            return $this->getAvatarUrlByAvatarDto($avatar, $size, $hash, $checkModerationStatus);
        } else if ($getDefaultOnNull) {
            return $this->getDefaultAvatarUrl($size);
        }

        return null;
    }

    /**
     * Returns default avatar URL
     *
     * @param int $size
     * @return string
     */
    public function getDefaultAvatarUrl( $size = 1 )
    {
        $custom = self::getCustomDefaultAvatarUrl($size);

        if ( $custom != null )
        {
            return $custom;
        }

        return OW::getPluginManager()->getPlugin('base')->getStaticUrl(). 'css/images/' . self::USER_AVATAR_IMAGE_NAME;
    }

    public function getUserColor($id) {
        if ($id == null) {
            return '#DDEBF7';
        }
        $digit = $id % self::AVATAR_RANGE_COLOR_COUNT;
        switch ( $digit )
        {
            case 0:
                return '#34ABE3';
            case 1:
                return '#0BA069';
            case 2:
                return '#72584B';
            case 3:
                return '#92D050';
            case 4:
                return '#FA9E1F';
            case 5:
                return '#69ABE5';
            case 6:
                return '#D12028';
            case 7:
                return '#F7B48A';
            case 8:
                return '#C9227A';
            default:
                return '#C9227A';
        }
    }

    public function getAvatarInfo($id, $avatarUrl = null, $type = 'user') {
        $hasAvatar = false;
        if ($avatarUrl != null) {
            $avatarUrlExploded = explode('/', $avatarUrl);
            $lastPartAvatarUrlExploded = $avatarUrlExploded[sizeof($avatarUrlExploded) - 1];
            if ($lastPartAvatarUrlExploded != self::USER_AVATAR_BIG_IMAGE_NAME
                && $lastPartAvatarUrlExploded != self::USER_AVATAR_IMAGE_NAME
                && $lastPartAvatarUrlExploded != self::GROUPS_AVATAR_BIG_IMAGE_NAME
                && $lastPartAvatarUrlExploded != self::NEWS_AVATAR_BIG_IMAGE_NAME
                && $lastPartAvatarUrlExploded != self::EVENTS_AVATAR_BIG_IMAGE_NAME) {
                $hasAvatar = true;
            }
        }
        return array(
            'color' => $this->getUserColor($id),
            'empty' => !$hasAvatar,
            'digit' => $id % self::AVATAR_RANGE_COLOR_COUNT,
            'type' => $type,
        );
    }

    private function getCustomDefaultAvatarUrl( $size = 1 )
    {
        if ( !in_array($size, array(1, 2)) )
        {
            return null;
        }

        $conf = json_decode(OW::getConfig()->getValue('base', 'default_avatar'), true);

        if ( isset($conf[$size]) )
        {
            $path = OW::getPluginManager()->getPlugin('base')->getUserFilesDir() . 'avatars' . DS . $conf[$size];

            return OW::getStorage()->getFileUrl($path);
        }

        return null;
    }

    public function setCustomDefaultAvatar( $size, $file )
    {
        $conf = json_decode(OW::getConfig()->getValue('base', 'default_avatar'), true);

        $dir = OW::getPluginManager()->getPlugin('base')->getUserFilesDir() . 'avatars' . DS;

        $ext = UTIL_File::getExtension($file['name']);
        $prefix = 'default_' . ($size == 1 ? self::AVATAR_PREFIX : self::AVATAR_BIG_PREFIX);

        $fileName = $prefix . FRMSecurityProvider::generateUniqueId() . '.' . $ext;

        if ( is_uploaded_file($file['tmp_name']) )
        {
            $storage = OW::getStorage();

            if ( $storage->copyFile($file['tmp_name'], $dir . $fileName) )
            {
                if ( isset($conf[$size]) )
                {
                    $storage->removeFile($dir . $conf[$size]);
                }

                $conf[$size] = $fileName;
                OW::getConfig()->saveConfig('base', 'default_avatar', json_encode($conf));

                return true;
            }
        }

        return false;
    }

    public function deleteCustomDefaultAvatar( $size )
    {
        $conf = json_decode(OW::getConfig()->getValue('base', 'default_avatar'), true);

        if ( !isset($conf[$size]) )
        {
            return false;
        }

        $dir = OW::getPluginManager()->getPlugin('base')->getUserFilesDir() . 'avatars' . DS;

        $storage = OW::getStorage();
        $storage->removeFile($dir . $conf[$size]);

        unset($conf[$size]);
        OW::getConfig()->saveConfig('base', 'default_avatar', json_encode($conf));

        return true;
    }

    /**
     * Returns list of users' avatars
     *
     * @param array $userIds
     * @param int $size
     * @return array
     */
    public function getAvatarsUrlList( array $userIds, $size = 1 )
    {
        if ( empty($userIds) || !is_array($userIds) )
        {
            return array();
        }

        $event = new OW_Event("base.avatars.get_list", array(
            "userIds" => $userIds,
            "size" => $size,
            "checkModerationStatus" => true
        ));

        $eventAvatars = OW::getEventManager()->trigger($event)->getData();

        if ( !empty($eventAvatars) )
        {
            return $eventAvatars;
        }

        $urlsList = array_fill(0, count($userIds), $this->getDefaultAvatarUrl($size));
        $urlsList = array_combine($userIds, $urlsList);

        $avatars = $this->avatarDao->getAvatarsList($userIds);

        foreach ( $avatars as $avatar )
        {
            $urlsList[$avatar->userId] =  $this->getAvatarUrlByAvatarDto($avatar, $size);
        }

        return $urlsList;
    }

    /**
     * Returns avatar file name for given avatar dto and size
     *
     * @param BOL_Avatar $avatar
     * @param int $size
     * @param string|null $hash
     * @param bool|true $checkModerationStatus
     *
     * @return null|string
     */
    public function getAvatarUrlByAvatarDto( $avatar, $size = 1, $hash = null, $checkModerationStatus = true )
    {
        $dir = OW::getPluginManager()->getPlugin('base')->getUserFilesDir() . 'avatars' . DS;

        if ( $checkModerationStatus && $avatar->getStatus() != BOL_ContentService::STATUS_ACTIVE )
        {
            return $this->getDefaultAvatarUrl($size);
        }

        $hash = isset($hash) ? $hash : $avatar->getHash();
        $avatarFile = $this->getAvatarFileName($avatar->userId, $hash, $size);

        if ( empty($avatarFile) )
        {
            return null;
        }
            return OW::getStorage()->getFileUrl($dir . $avatarFile);
    }


    /**
     * Composes avatar file name
     *
     * @param int $userId
     * @param int $size
     * @param null $hash
     * @return null|string
     */
    public function getAvatarFileName( $userId, $hash, $size = 1 )
    {
        switch ( $size )
        {
            case 1:
                return self::AVATAR_PREFIX . $userId . '_' . $hash . '.jpg';

            case 2:
                return self::AVATAR_BIG_PREFIX . $userId . '_' . $hash . '.jpg';

            case 3:
                return self::AVATAR_ORIGINAL_PREFIX . $userId . '_' . $hash . '.jpg';
        }

        return null;
    }

    /**
     * Get avatar path in filesystem
     *
     * @param int $userId
     * @param int $size
     * @param int $hash
     * @return string
     */
    public function getAvatarPath( $userId, $size = 1, $hash = null )
    {
        $avatar = $this->avatarDao->findByUserId($userId);

        $dir = $this->getAvatarsDir();

        if ( $avatar )
        {
            $hash = isset($hash) ? $hash : $avatar->getHash();
        }

        $fileName = $this->getAvatarFileName($userId, $hash, $size);

        return $fileName ? $dir . $fileName : null;
    }

    public function getAvatarPluginFilesPath( $userId, $size = 1, $hash = null )
    {
        $avatar = $this->avatarDao->findByUserId($userId);

        $dir = $this->getAvatarsPluginFilesDir();

        if ( $avatar )
        {
            $hash = isset($hash) ? $hash : $avatar->getHash();
        }

        $fileName = $this->getAvatarFileName($userId, $hash, $size);

        return $fileName ? $dir . $fileName : null;
    }

    public function getTempAvatarPath( $key, $size = 1 )
    {
        $dir = $this->getAvatarsDir() . 'tmp' . DS;

        switch ( $size )
        {
            case 1:
                return $dir . self::AVATAR_PREFIX . $key . '.jpg';

            case 2:
                return $dir . self::AVATAR_BIG_PREFIX . $key . '.jpg';

            case 3:
                return $dir . self::AVATAR_ORIGINAL_PREFIX . $key . '.jpg';
        }

        return null;
    }

    public function getTempAvatarUrl( $key, $size = 1 )
    {
        $url = OW::getPluginManager()->getPlugin('base')->getUserFilesUrl() . 'avatars/tmp/';

        switch ( $size )
        {
            case 1:
                return $url . self::AVATAR_PREFIX . $key . '.jpg';

            case 2:
                return $url . self::AVATAR_BIG_PREFIX . $key . '.jpg';

            case 3:
                return $url . self::AVATAR_ORIGINAL_PREFIX . $key . '.jpg';
        }

        return null;
    }

    public function getAvatarsDir()
    {
        return OW::getPluginManager()->getPlugin('base')->getUserFilesDir() . 'avatars' . DS;
    }

    public function getAvatarsPluginFilesDir()
    {
        return OW::getPluginManager()->getPlugin('base')->getPluginFilesDir() . 'avatars' . DS;
    }

    /**
     * Checks if user has avatar
     *
     * @param int $userId
     * @return boolean
     */
    public function userHasAvatar( $userId )
    {
        $avatar = $this->avatarDao->findByUserId($userId);

        return $avatar != null;
    }

    public function trackAvatarChangeActivity( $userId, $avatarId )
    {
        // Newsfeed
        $event = new OW_Event('feed.action', array(
            'pluginKey' => 'base',
            'entityType' => 'avatar-change',
            'entityId' => $avatarId,
            'userId' => $userId,
            'replace' => true
        ), array(
            'string' => array('key' => 'base+avatar_feed_string'),
            /* 'content' => '<img src="' . $this->getAvatarUrl($userId) . '" />', */
            'view' => array(
                'iconClass' => 'ow_ic_picture'
            )
        ));
        OW::getEventManager()->trigger($event);
    }

    public function getDataForUserAvatars( $userIdList, $src = true, $url = true, $dispName = true, $role = true, $showUsernameForEmptyDisplayName = false)
    {
        if ( !count($userIdList) )
        {
            return array();
        }

        $data = array();

        if ( $src )
        {
            $srcArr = $this->getAvatarsUrlList($userIdList);
        }

        $userService = BOL_UserService::getInstance();

        if ( $url )
        {
            $usernameList = BOL_UserService::getInstance()->getUserNamesForList($userIdList);
            $urlArr = $userService->getUserUrlsListForUsernames($usernameList);

            if ( $urlArr )
            {
                foreach ( $urlArr as $userId => $userUrl )
                {
                    $data[$userId]['urlInfo'] = array(
                        'routeName' => 'base_user_profile',
                        'vars' => array('username' => $usernameList[$userId])
                    );
                }
            }
        }

        if ( $dispName )
        {
            $dnArr = BOL_UserService::getInstance()->getDisplayNamesForList($userIdList);
        }

        if ( $role )
        {
            $roleArr = BOL_AuthorizationService::getInstance()->getLastDisplayLabelRoleOfIdList($userIdList);
        }

        foreach ( $userIdList as $userId )
        {
            $data[$userId]["userId"] = $userId;

            if ( $src )
            {
                $data[$userId]['src'] = !empty($srcArr[$userId]) ? $srcArr[$userId] : '_AVATAR_SRC_';
                $data[$userId]['imageInfo'] = $this->getAvatarInfo($userId, $data[$userId]['src']);
            }
            if ( $url )
            {
                $data[$userId]['url'] = !empty($urlArr[$userId]) ? $urlArr[$userId] : '#_USER_URL_';
            }
            if ( $dispName )
            {
                $data[$userId]['title'] = !empty($dnArr[$userId]) ? $dnArr[$userId] : null;
                if ($showUsernameForEmptyDisplayName && $data[$userId]['title'] == null && isset($usernameList[$userId])) {
                    $data[$userId]['title'] = $usernameList[$userId];
                }
            }
            if ( $role )
            {
                $data[$userId]['label'] = !empty($roleArr[$userId]) ? $roleArr[$userId]['label'] : null;
                $data[$userId]['labelColor'] = !empty($roleArr[$userId]) ? $roleArr[$userId]['custom'] : null;
            }
        }

        return $data;
    }

    public function collectAvatarChangeSections()
    {
        $event = new BASE_CLASS_EventCollector(
            'base.avatar_change_collect_sections',
            array('limit' => self::AVATAR_CHANGE_GALLERY_LIMIT)
        );

        OW::getEventManager()->trigger($event);

        $data = $event->getData();

        return $data;
    }

    public function getAvatarChangeSection( $entityType, $entityId, $offset )
    {
        $params = array('entityType' => $entityType, 'entityId' => $entityId, 'offset' => $offset, 'limit' => self::AVATAR_CHANGE_GALLERY_LIMIT);
        $event = new BASE_CLASS_EventCollector('base.avatar_change_get_section', $params);

        OW::getEventManager()->trigger($event);

        $data = $event->getData();

        if ( !empty($data[0]) && count($data[0]) )
        {
            foreach ( $data[0]['list'] as &$image )
            {
                $image['entityType'] = $entityType;
                $image['entityId'] = $entityId;
            }

            return $data[0];
        }

        return $data;
    }

    public function getAvatarChangeGalleryItem( $entityType, $entityId, $itemId )
    {
        if ( !$entityType || !$itemId )
        {
            return null;
        }

        $params = array('entityType' => $entityType, 'entityId' => $entityId, 'id' => $itemId);
        $event = new OW_Event('base.avatar_change_get_item', $params);

        OW::getEventManager()->trigger($event);

        $data = $event->getData();

        return $data;
    }

    public function getAvatarChangeSessionKey()
    {
        $key = OW::getSession()->get(self::AVATAR_CHANGE_SESSION_KEY);

        return $key;
    }

    public function setAvatarChangeSessionKey()
    {
        $key = OW::getSession()->get(self::AVATAR_CHANGE_SESSION_KEY);

        if ( !strlen($key) )
        {
            $key = FRMSecurityProvider::generateUniqueId();
            OW::getSession()->set(self::AVATAR_CHANGE_SESSION_KEY, $key);
        }
    }

    public function createAvatar( $userId, $isModerable = true, $trackAction = true)
    {
        $key = $this->getAvatarChangeSessionKey();
        $path = $this->getTempAvatarPath($key, 2);

        if ( !OW::getStorage()->fileExists($path) )
        {
            return false;
        }

        if ( !UTIL_File::validateImage($path) )
        {
            return false;
        }

        $avatarSet = $this->setUserAvatar($userId, $path, array('isModerable' => $isModerable, 'trackAction' => $trackAction ));

        if ( $avatarSet )
        {
            $this->deleteUserTempAvatar($key);
        }

        return $avatarSet;
    }
}