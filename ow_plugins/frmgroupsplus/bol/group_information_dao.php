<?php
/**
 * Data Access Object for `frmgroupsplus_information` table.
 *
 * @author Farzan Mohammadi <farzan.mohamadii@gmail.com>
 * @package ow_plugins.frmgroupsplus.bol
 * @since 1.0
 */
class FRMGROUPSPLUS_BOL_GroupInformationDao extends OW_BaseDao
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
     * @var FRMGROUPSPLUS_BOL_GroupInformationDao
     */
    private static $classInstance;

    /**
     * Returns an instance of class (singleton pattern implementation).
     *
     * @return FRMGROUPSPLUS_BOL_GroupInformationDao
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
        return 'FRMGROUPSPLUS_BOL_GroupInformation';
    }

    /**
     * @see OW_BaseDao::getTableName()
     *
     */
    public function getTableName()
    {
        return OW_DB_PREFIX . 'frmgroupsplus_group_information';
    }

    public function getGroupInformationByCategoryId($categoryId)
    {
        $example = new OW_Example();
        $example->andFieldEqual('categoryId', $categoryId);
        return $this->findListByExample($example);
    }
    public function addCategoryToGroup($groupId,$categoryId)
    {
        $groupInfo = new FRMGROUPSPLUS_BOL_GroupInformation();
        $this->deleteByGroupId($groupId);
        if($categoryId!=null) {
            $groupInfo->setCategoryId($categoryId);
            $groupInfo->setGroupId($groupId);
            $this->save($groupInfo);
        }
    }

    public function deleteByCategoryId( $categoryId )
    {
        $ex = new OW_Example();
        $ex->andFieldEqual('categoryId', $categoryId);
        return $this->deleteByExample($ex);
    }

    public function deleteByGroupId( $groupId )
    {
        $ex = new OW_Example();
        $ex->andFieldEqual('groupId', $groupId);
        return $this->deleteByExample($ex);
    }

    public function getGroupInformationByGroupId($groupId)
    {
        $ex = new OW_Example();
        $ex->andFieldEqual('groupId', $groupId);
        return $this->findObjectByExample($ex);
    }

    public function getGroupInformationByGroupIds($groupIds)
    {
        if (!is_array($groupIds) || empty($groupIds)) {
            return array();
        }
        $groupIdsString = $this->dbo->mergeInClause($groupIds);
        $sql = "SELECT * FROM `".$this->getTableName()."` gi, `" . FRMGROUPSPLUS_BOL_CategoryDao::getInstance()->getTableName() . "` c WHERE c.id = gi.categoryId AND `groupId` IN ({$groupIdsString})";

        $list = $this->dbo->queryForList($sql);
        $groupsInformation = array();
        foreach ($list as $item) {
            $groupsInformation[$item['groupId']] = $item;
        }
        return $groupsInformation;
    }
}