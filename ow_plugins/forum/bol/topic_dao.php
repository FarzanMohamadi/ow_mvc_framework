<?php
/**
 * Data Access Object for `forum_topic` table.
 *
 * @author Farzan Mohammadi <farzan.mohamadii@gmail.com>
 * @package ow.ow_plugins.forum.bol
 * @since 1.0
 */
class FORUM_BOL_TopicDao extends OW_BaseDao
{
    const GROUP_ID = 'groupId';
    const STATUS = 'status';
    /**
     * Class constructor
     *
     */
    protected function __construct()
    {
        parent::__construct();
    }
    /**
     * Class instance
     *
     * @var FORUM_BOL_TopicDao
     */
    private static $classInstance;

    /**
     * Returns class instance
     *
     * @return FORUM_BOL_TopicDao
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
        return 'FORUM_BOL_Topic';
    }

    /**
     * @see OW_BaseDao::getTableName()
     *
     */
    public function getTableName()
    {
        return OW_DB_PREFIX . 'forum_topic';
    }

    /**
     * Find latest topics ids
     *
     * @param integer $first
     * @param integer $count
     * @return array
     */
    public function findLatestPublicTopicsIds($first, $count)
    {
        $query = "SELECT
            a.`id`
        FROM
            `" . $this->getTableName() . "` a
        INNER JOIN
            `" . FORUM_BOL_GroupDao::getInstance()->getTableName() . "` b
        ON
            a.`groupId` = b.`id`
                AND
            b.`isPrivate` = :private
        WHERE
            a.`status` = :status
        ORDER BY
            a.`id` DESC
        LIMIT :f, :c";

        return $this->dbo->queryForColumnList($query, array(
            'private' => 0,
            'status' => 'approved',
            'f' => (int) $first,
            'c' => (int) $count,
        ));
    }

    /**
     * Returns forum group's topic count
     * 
     * @param int 
     * @return int $groupId
     */
    public function findGroupTopicCount( $groupId )
    {
        if ( empty($groupId) )
        {
            return 0;
        }

        $example = new OW_Example();

        $example->andFieldEqual(self::GROUP_ID, (int)$groupId);
        $example->andFieldEqual(self::STATUS, FORUM_BOL_ForumService::STATUS_APPROVED);

        return $this->countByExample($example);
    }

    /**
     * Returns forum group's post count
     * 
     * @param int $groupId
     * @return int
     */
    public function findGroupPostCount( $groupId )
    {
        if ( empty($groupId) )
        {
            return 0;
        }

        $query = 'SELECT COUNT(`p`.`id`)
            FROM `' . $this->getTableName() . '` AS `t`
		        INNER JOIN `' . FORUM_BOL_PostDao::getInstance()->getTableName() . '` AS `p`
		            ON ( `t`.`id` = `p`.`topicId` )
		    WHERE `t`.`' . self::GROUP_ID . '` = :groupId AND `t`.`' . self::STATUS . '` = :status';

        return (int)$this->dbo->queryForColumn($query, array('groupId' => $groupId, 'status' => FORUM_BOL_ForumService::STATUS_APPROVED));
    }

    /**
     * Returns simple forum group's topic list
     *
     * @param int $groupId
     * @param int $first
     * @param int $count
     * @param integer $lastTopicId
     * @return array of FORUM_BOL_Topic
     */
    public function findSimpleGroupTopicList( $groupId, $first, $count, $lastTopicId = null )
    {
        $example = new OW_Example();

        $example->andFieldEqual('groupId', $groupId);

        if ( $lastTopicId )
        {
            $example->andFieldGreaterThan('id', $lastTopicId);
        }

        $example->setOrder('`id`');
        $example->setLimitClause($first, $count);

        return $this->findListByExample($example);
    }

    /**
     * Returns forum group's topic list
     * 
     * @param int $groupId
     * @param int $first
     * @param int $count
     * @param int $lastTopicId
     * @param array $excludeTopicIds
     * @param string $sortOrder
     * @param string$sortDirection
     * @return array 
     */
    public function findGroupTopicList( $groupId, $first, $count, array $excludeTopicIds = array(),$sortOrder=null,$sortDirection=null )
    {
        $topicExcludeFilter = null;

        if ( $excludeTopicIds )
        {
            $topicExcludeFilter = ' AND `t`.`id` NOT IN (' . $this->dbo->mergeInClause($excludeTopicIds) . ')';
        }

        $orderClause=' `postTimeStamp` ';
        $directionClause= 'DESC';
        if(isset($sortDirection))
        {
            $directionClause =$sortDirection;
        }
        switch ($sortOrder)
        {
            case 'topic':
                $orderClause=' `t2`.`title` ';
                break;
            case 'replies':
                $orderClause=' postCount ';
                break;
            case 'views':
                $orderClause=' `t2`.`viewCount` ';
                break;
        }
        $query = 'SELECT * from 
            (SELECT `t`.*, COUNT(`t`.id) AS postCount, max(`p`.`createStamp`) as `postTimeStamp` 
		    FROM `' . $this->getTableName() . '` AS `t`
		        INNER JOIN `' . FORUM_BOL_PostDao::getInstance()->getTableName() . '` AS `p`
		             ON (`t`.`id` = `p`.`topicId`)
		    WHERE `t`.`groupId` = ? AND `t`.`status` = ? ' . $topicExcludeFilter . ' 
		    GROUP BY `t`.`id`) AS `t2` ORDER BY `t2`.`sticky` DESC, '.$orderClause.$directionClause .' LIMIT ?, ?';

        $list = $this->dbo->queryForList($query, array($groupId, FORUM_BOL_ForumService::STATUS_APPROVED, (int)$first, (int)$count));

        if ( $list )
        {
            $topicIdList = array();
            foreach ( $list as $topic )
            {
                $topicIdList[] = $topic['id'];
            }

            $counters = $this->getPostCountForTopicIdList($topicIdList);
            foreach ( $list as &$topic )
            {
                $topic['postCount'] = $counters[$topic['id']];
            }
        }

        return $list;
    }

    public function findLastTopicList( $limit, $excludeGroupIdList = null )
    {
        $postDao = FORUM_BOL_PostDao::getInstance();
        $groupDao = FORUM_BOL_GroupDao::getInstance();
        $sectionDao = FORUM_BOL_SectionDao::getInstance();

        $excludeCond = $excludeGroupIdList ? ' AND `g`.`id` NOT IN ('.implode(',', $excludeGroupIdList).') = 1' : '';

        $query = 'SELECT `t`.*
            FROM `' . $this->getTableName() . '` AS `t`
                INNER JOIN `' . $groupDao->getTableName() . '` AS `g` ON (`t`.`groupId` = `g`.`id`)
                INNER JOIN `' . $sectionDao->getTableName() . '` AS `s` ON (`s`.`id` = `g`.`sectionId`)
                INNER JOIN `' . $postDao->getTableName() . '` AS `p` ON (`t`.`lastPostId` = `p`.`id`)
            WHERE `s`.`isHidden` = 0 AND `t`.`status` = :status ' . $excludeCond . '
            ORDER BY `p`.`createStamp` DESC
            LIMIT :limit';

        $list = $this->dbo->queryForList($query, array('status' => FORUM_BOL_ForumService::STATUS_APPROVED, 'limit' => (int)$limit));

        if ( $list )
        {
            $topicIdList = array();
            foreach ( $list as $topic )
            {
                $topicIdList[] = $topic['id'];
            }

            $counters = $this->getPostCountForTopicIdList($topicIdList);
            foreach ( $list as &$topic )
            {
                $topic['postCount'] = $counters[$topic['id']];
            }
        }

        return $list;
    }


    /**
     * @param $limit
     * @param null $excludeGroupIdList
     * @param $userId
     * @param bool $selectedGroupIds
     * @return array
     */
    public function findUserLastTopicGroupsList( $limit, $excludeGroupIdList = null, $userId,$selectedGroupIds=array() )
    {
        $postDao = FORUM_BOL_PostDao::getInstance();
        $groupDao = FORUM_BOL_GroupDao::getInstance();
        $sectionDao = FORUM_BOL_SectionDao::getInstance();
        $groupEntityDao = GROUPS_BOL_GroupDao::getInstance();
        $groupUsersDao = GROUPS_BOL_GroupUserDao::getInstance();

        $selectedGroupCond='';
        if(is_array($selectedGroupIds) && sizeof($selectedGroupIds) >0)
        {
            $selectedGroupCond = ' AND `ge`.`id`  IN ('.implode(',', $selectedGroupIds).') = 1 ';
        }
        $excludeCond = $excludeGroupIdList ? ' AND `g`.`id` NOT IN ('.implode(',', $excludeGroupIdList).') = 1 ' : '';

        $query = 'SELECT `t`.*, `ge`.`title` as `groupEntityTitle`, `ge`.`id` as `groupEntityId` 
            FROM `' . $this->getTableName() . '` AS `t`
                INNER JOIN `' . $groupDao->getTableName() . '` AS `g` ON (`t`.`groupId` = `g`.`id`)
                INNER JOIN `' . $sectionDao->getTableName() . '` AS `s` ON (`s`.`id` = `g`.`sectionId`)
                INNER JOIN `' . $postDao->getTableName() . '` AS `p` ON (`t`.`lastPostId` = `p`.`id`)
                INNER JOIN `' . $groupEntityDao->getTableName() . '` AS `ge` ON (`g`.`entityId` = `ge`.`id`)
                INNER JOIN `' . $groupUsersDao->getTableName() . '` AS `gu` ON (`gu`.`groupId` = `ge`.`id`)
            WHERE `s`.`isHidden` = 1 AND  `s`.`entity` = "groups" AND `t`.`status` = :status ' . $excludeCond .$selectedGroupCond. '
            AND `gu`.`userId`=:userId ORDER BY `p`.`createStamp` DESC
            LIMIT :limit';

        $list = $this->dbo->queryForList($query, array('userId'=>$userId,'status' => FORUM_BOL_ForumService::STATUS_APPROVED, 'limit' => (int)$limit));

        if ( $list )
        {
            $topicIdList = array();
            foreach ( $list as $topic )
            {
                $topicIdList[] = $topic['id'];
            }

            $counters = $this->getPostCountForTopicIdList($topicIdList);
            foreach ( $list as &$topic )
            {
                $topic['postCount'] = $counters[$topic['id']];
            }
        }

        return $list;
    }


    public function getPostCountForTopicIdList( $topicIdList )
    {
        $postDao = FORUM_BOL_PostDao::getInstance();

        $query = "SELECT `p`.`topicId`, COUNT(`p`.`id`) AS `postCount`
            FROM `".$postDao->getTableName()."` AS `p`
            WHERE `p`.`topicId` IN (".$this->dbo->mergeInClause($topicIdList).")
            GROUP BY `p`.`topicId`";

        $countList = $this->dbo->queryForList($query);

        $counters = array();
        foreach ( $countList as $count )
        {
            $counters[$count['topicId']] = $count['postCount'];
        }

        return $counters;
    }

    public function findUserTopicList( $userId )
    {
        $query = "
            SELECT * FROM `" . $this->getTableName() . "` WHERE `userId` = ?
        ";

        return $this->dbo->queryForList($query, array($userId));
    }

    /**
     * Returns forum topic info
     * 
     * @param int $topicId
     * @return array 
     */
    public function findTopicInfo( $topicId )
    {
        $query = "
		SELECT `t`.*, `g`.`id` AS `groupId`, `g`.`name` AS `groupName`, `s`.`name` AS `sectionName`, `s`.`id` AS `sectionId` 
		FROM `" . $this->getTableName() . "` AS `t`
		LEFT JOIN `" . FORUM_BOL_GroupDao::getInstance()->getTableName() . "` AS `g` 
		ON (`t`.`groupId` = `g`.`id`)
		LEFT JOIN `" . FORUM_BOL_SectionDao::getInstance()->getTableName() . "` AS `s`
		ON (`g`.`sectionId` = `s`.`id`)
		WHERE `t`.`id` = ?
		";

        return $this->dbo->queryForRow($query, array($topicId));
    }

    /**
     * Returns topic list by ids
     * 
     * @param array $topicIds
     * @return array 
     */
    public function findListByTopicIds( array $topicIds )
    {
        if ( !$topicIds )
        {
            return array();
        }

        $topicsIn = $this->dbo->mergeInClause($topicIds);
        $query = "
		SELECT `t`.*, `g`.`id` AS `groupId`, `g`.`name` AS `groupName`, `s`.`name` AS `sectionName`, `s`.`id` AS `sectionId` 
		FROM `" . $this->getTableName() . "` AS `t`
		INNER JOIN `" . FORUM_BOL_GroupDao::getInstance()->getTableName() . "` AS `g` 
		ON (`t`.`groupId` = `g`.`id`)
		INNER JOIN `" . FORUM_BOL_SectionDao::getInstance()->getTableName() . "` AS `s`
		ON (`g`.`sectionId` = `s`.`id`)
		WHERE t.id IN (" . $topicsIn .") ORDER BY FIELD(t.id, " . $topicsIn . ")";

        $list = $this->dbo->queryForList($query);

        if ( $list )
        {
            $topicIdList = array();
            foreach ( $list as $topic )
            {
                $topicIdList[] = $topic['id'];
            }

            $counters = $this->getPostCountForTopicIdList($topicIdList);
            foreach ( $list as &$topic )
            {
                $topic['postCount'] = !empty($counters[$topic['id']]) ? $counters[$topic['id']] : 0;
            }
        }

        return $list;
    }

    /**
     * Returns topic id list
     * 
     * @param array $groupIds
     * @return array 
     */
    public function findIdListByGroupIds( $groupIds )
    {
        $example = new OW_Example();
        $example->andFieldInArray('groupId', $groupIds);

        $query = "
    	SELECT `id` FROM `" . $this->getTableName() . "`
    	" . $example;

        return $this->dbo->queryForColumnList($query);
    }

    public function getTopicIdListForDelete( $limit )
    {
        $example = new OW_Example();
        $example->setOrder('`id` ASC');
        $example->setLimitClause(0, $limit);

        return $this->findIdListByExample($example);
    }
    
    public function findTemporaryTopicList( $limit )
    {
        $postDao = FORUM_BOL_PostDao::getInstance();
        
        $query = "SELECT `t`.* FROM `".$this->getTableName()."` AS `t`
            LEFT JOIN `".$postDao->getTableName()."` AS `p` ON (`t`.`lastPostId`=`p`.`id`)
            WHERE `t`.`temp` = 1 AND `p`.`createStamp` < :ts";
        
        return $this->dbo->queryForList($query, array('ts' => time() - 3600 * 24 * 5));
    }
}