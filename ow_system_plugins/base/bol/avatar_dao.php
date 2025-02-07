<?php
/**
 * Data Access Object for `base_avatar` table.
 *
 * @author Farzan Mohammadi <farzan.mohamadii@gmail.com>
 * @package ow.ow_system_plugins.base.bol
 * @since 1.0
 */
class BOL_AvatarDao extends OW_BaseDao
{

    /**
     * Constructor.
     *
     */
    protected function __construct()
    {
        parent::__construct();
    }

    /**
     * Singleton instance.
     *
     * @var BOL_AvatarDao
     */
    private static $classInstance;

    /**
     * Returns an instance of class (singleton pattern implementation).
     *
     * @return BOL_AvatarDao
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
     * @see OW_BaseDao::getDtoClassName()
     *
     */
    public function getDtoClassName()
    {
        return 'BOL_Avatar';
    }

    /**
     * @see OW_BaseDao::getTableName()
     *
     */
    public function getTableName()
    {
        return OW_DB_PREFIX . 'base_avatar';
    }

    protected $cachedItems = array();

    public function clearCahche( $userId )
    {
        unset($this->cachedItems[$userId]);
    }
    
    /**
     * Finds user avatar by userId
     *
     * @param int $userId
     * @param bool $checkCache
     * @return BOL_Avatar
     */
    public function findByUserId( $userId, $checkCache = true )
    {
        $userId = intval($userId);

        if ( !$checkCache || empty($this->cachedItems[$userId]) )
        {
            $example = new OW_Example();
            $example->andFieldEqual('userId', $userId);
            $example->setLimitClause(0, 1);

            $this->cachedItems[$userId] = $this->findObjectByExample($example);
        }

        return $this->cachedItems[$userId];
    }

    /**
     * Get list of avatars
     *
     * @param $idList
     * @return array of BOL_Avatar
     */
    public function getAvatarsList( $idList )
    {
        if ( empty($idList) )
        {
            return array();
        }

        $idList = array_unique(array_map('intval', $idList));

        $idsToRequire = array();
        $result = array();

        foreach ( $idList as $id )
        {
            if ( empty($this->cachedItems[$id]) )
            {
                $idsToRequire[] = $id;
            }
            else
            {
                $result[] = $this->cachedItems[$id];
            }
        }

        $items = array();

        if ( !empty($idsToRequire) )
        {
            $example = new OW_Example();
            $example->andFieldInArray('userId', $idsToRequire);

            $items = $this->findListByExample($example);
        }

        foreach ( $items as $item )
        {
            $result[] = $item;
            $this->cachedItems[(int) $item->userId] = $item;
        }

        return $result;
    }

}