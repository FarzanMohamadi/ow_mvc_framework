<?php
/**
 * Data Access Object for `base_authorization_group` table.
 *
 * @package ow_system_plugins.base.bol
 * @since 1.0
 */
class BOL_AuthorizationGroupDao extends OW_BaseDao
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
     * @var BOL_AuthorizationGroupDao
     */
    private static $classInstance;

    /**
     * Returns an instance of class (singleton pattern implementation).
     *
     * @return BOL_AuthorizationGroupDao
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
        return 'BOL_AuthorizationGroup';
    }

    /**
     * @see OW_BaseDao::getTableName()
     *
     */
    public function getTableName()
    {
        return OW_DB_PREFIX . 'base_authorization_group';
    }

    public function getIdByName( $name )
    {
        if ( empty($name) )
        {
            return null;
        }

        $ex = new OW_Example();
        $ex->andFieldEqual('name', $name);

        return $this->findIdByExample($ex);
    }

    /**
     *
     * @param string $name
     * @return BOL_AuthorizationGroup
     */
    public function findByName( $name )
    {
        if ( empty($name) )
        {
            return null;
        }

        $ex = new OW_Example();
        $ex->andFieldEqual('name', $name);

        return $this->findObjectByExample($ex);
    }

    protected function clearCache()
    {
        OW::getCacheManager()->clean(array(BOL_AuthorizationActionDao::CACHE_TAG_AUTHORIZATION));
    }

    public function findAll( $cacheLifeTime = 0, $tags = array() )
    {
        return parent::findAll(3600 * 24, array(BOL_AuthorizationActionDao::CACHE_TAG_AUTHORIZATION, OW_CacheManager::TAG_OPTION_INSTANT_LOAD));
    }
}