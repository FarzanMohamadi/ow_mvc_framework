<?php
/**
 * @package ow_plugins.friends.bol
 * @since 1.0
 */
class FRIENDS_BOL_FriendshipDao extends OW_BaseDao
{
    const USER_ID = 'userId';
    const FRIEND_ID = 'friendId';
    const STATUS = 'status';

    const VAL_STATUS_ACTIVE = 'active';
    const VAL_STATUS_PENDING = 'pending';
    const VAL_STATUS_IGNORED = 'ignored';

    const CACHE_TAG_FRIENDS_COUNT = 'friends.count';
    const CACHE_TAG_FRIEND_ID_LIST = 'friends.friend_id_list';
    const CACHE_LIFE_TIME = 86400; //24 hour

    /**
     * Class instance
     *
     * @var FRIENDS_BOL_FriendshipDao
     */
    private static $classInstance;

    /**
     * Class constructor
     *
     */
    protected function __construct()
    {
        parent::__construct();
    }

    /**
     * Returns class instance
     *
     * @return FRIENDS_BOL_FriendshipDao
     */
    public static function getInstance()
    {
        if ( self::$classInstance === null )
        {
            self::$classInstance = new self();
        }

        return self::$classInstance;
    }

    public function getTableName()
    {
        return OW_DB_PREFIX . 'friends_friendship';
    }

    public function getDtoClassName()
    {
        return 'FRIENDS_BOL_Friendship';
    }

    /**
     * Save new friendship request
     *
     * @param integer $requesterId
     * @param integer $userId
     */
    public function request( $requesterId, $userId )
    {
        $ex = new OW_Example();
        $ex->andFieldEqual('userId', $userId)
           ->andFieldEqual('friendId', $requesterId);

        $dto = $this->findObjectByExample($ex);

        $itWasIgnoredByRequester = $dto !== null;

        if ( $itWasIgnoredByRequester )
        {
            $this->save(
                $dto->setStatus('active')
            );

            return;
        }

        $dto = new FRIENDS_BOL_Friendship();

        $dto->setUserId($requesterId)->setFriendId($userId)->setStatus(FRIENDS_BOL_Service::STATUS_PENDING);

        $dto->timeStamp = time();

        $this->save($dto);
    }

    /**
     * Accept new friendship request
     *
     * @param integer $userId
     * @param integer $requesterId
     *
     * @return FRIENDS_BOL_Friendship
     */
    public function accept( $userId, $requesterId )
    {
        $ex = new OW_Example();
        $ex->andFieldEqual('friendId', $userId)
            ->andFieldEqual('userId', $requesterId)
            ->andFieldEqual('status', FRIENDS_BOL_Service::STATUS_PENDING);

        /**
         * @var FRIENDS_BOL_Friendship $dto
         */
        $dto = $this->findObjectByExample($ex);

        if ( empty($dto) )
        {
            return;
        }

        $dto->setStatus(FRIENDS_BOL_Service::STATUS_ACTIVE);

        $this->save($dto);

        return $dto;
    }

    /**
     * Cancel friendship
     *
     * @param integer $requesterId
     * @param integer $userId
     */
    public function cancel( $requesterId, $userId )
    {
        $ex = new OW_Example();

        $ex->andFieldInArray('userId', array($userId, $requesterId))
            ->andFieldInArray('friendId', array($userId, $requesterId));
        
        $this->deleteByExample($ex);   
        
    }
    
    /**
     * getDto friendship
     *
     * @param integer $requesterId
     * @param integer $userId
     */
    public function findByRequesterIdAndUserId( $requesterId, $userId )
    {
        $ex = new OW_Example();
        $ex->andFieldInArray('friendId', array($userId, $requesterId))
            ->andFieldInArray('userId', array($userId, $requesterId));

         $dto = $this->findObjectByExample($ex);

        if ( empty($dto) )
        {
            return;
        }
        
        return $dto;
        
    }

    /**
     * Ignore new friendship request
     *
     * @param integer $userId
     * @param integer $requesterId
     */
    public function ignore( $userId, $requesterId )
    {
        $ex = new OW_Example();

        $ex->andFieldEqual('userId', $userId)
            ->andFieldEqual('friendId', $requesterId);
        $this->cancel($requesterId, $userId);

        /**
        * @var FRIENDS_BOL_Friendship $dto
        */
//        $dto = $this->findObjectByExample($ex);

//        $dto->setStatus('ignored');

//        $this->save($dto);
    }

    /**
     *
     * @param integer $requesterId
     * @param integer $userId
     */
    public function activate( $requesterId, $userId )
    {
        $query = "UPDATE `{$this->getTableName()}` SET `status`='active' WHERE `userId` IN (:userId, :user2Id) AND `friendId` IN (:userId, :user2Id)";

        $this->dbo->query($query, array(':userId' => (int) $userId, ':user2Id' => (int) $requesterId));
        $this->clearCache();
    }

    public function findFriendship( $userId, $user2Id )
    {
        $query = "SELECT * FROM `{$this->getTableName()}` WHERE ( userId = :userId AND friendId = :user2Id ) OR (userId = :user2Id AND friendId = :userId ) LIMIT 1";

        $cacheLifeTime = self::CACHE_LIFE_TIME;
        $tags = array( self::CACHE_TAG_FRIEND_ID_LIST );

        return $this->dbo->queryForObject($query, $this->getDtoClassName(), array('userId' => $userId, 'user2Id' => $user2Id), $cacheLifeTime, $tags);
    }

    public function findFriendships( $userIds, $userId = null)
    {
        if(!is_array($userIds) || empty($userIds)) {
            return array();
        }

        $userIdUserQuery = "";
        $userIdFriendQuery = "";
        if ($userId != null) {
            $userIdUserQuery = " and friendId = " . $userId . " ";
            $userIdFriendQuery = " and userId = " . $userId . " ";
        }


        $query = "SELECT * FROM `{$this->getTableName()}` WHERE ( userId in (" . $this->dbo->mergeInClause($userIds) .") " . $userIdUserQuery . " ) OR (friendId in (" . $this->dbo->mergeInClause($userIds) .") " . $userIdFriendQuery . " )";
        return $this->dbo->queryForObjectList($query, $this->getDtoClassName());
    }

    public function findFriendshipById($friendshipId)
    {
        $query = "SELECT * FROM `{$this->getTableName()}` WHERE `id` = :id LIMIT 1";

        return $this->dbo->queryForObject($query, $this->getDtoClassName(), array('id' => $friendshipId));
    }

    public function findFriendIdList( $userId, $first, $count, $userIdList = null)
    {
        $queryParts1 = BOL_UserDao::getInstance()->getUserQueryFilter("friends_friendship", "userId", array(
            "method" => "FRIENDS_BOL_FriendshipDao::findFriendIdList1"
        ));

        $queryParts2 = BOL_UserDao::getInstance()->getUserQueryFilter("friends_friendship", "friendId", array(
            "method" => "FRIENDS_BOL_FriendshipDao::findFriendIdList2"
        ));
        if(OW::getConfig()->getValue('base', 'confirm_email')){
            $usersAccess="`emailVerify` != 0";
        }else{
            $usersAccess="1";
        }
        if(OW::getConfig()->getValue('base', 'mandatory_user_approve')){
            $disapprovedUsers = " AND `id` NOT IN (SELECT `userId` FROM `" . BOL_UserApproveDao::getInstance()->getTableName() ."` AS `userId`) ";
        }else{
            $disapprovedUsers=null;
        }
        $query =
            "
            SELECT `id` FROM ".BOL_UserDao::getInstance()->getTableName()."
            WHERE `id` IN
            ( 
                SELECT `friends_friendship`.`userId` FROM `" . $this->getTableName() . "` AS `friends_friendship`
                " . $queryParts1['join'] . "
                WHERE " . $queryParts1['where'] . " AND `friends_friendship`.`status` = :status1 AND `friends_friendship`.`friendId` = :userId1
                " . (empty($userIdList) ? '' : " AND `friends_friendship`.`userId` IN ( " . $this->dbo->mergeInClause($userIdList) . " )") . "
            UNION
                SELECT `friends_friendship`.`friendId` AS `userId` FROM `" . $this->getTableName() . "` AS `friends_friendship`
                " . $queryParts2['join'] . "
                WHERE " . $queryParts2['where'] . " AND `friends_friendship`.`status` = :status2 AND `friends_friendship`.`userId` = :userId2
                " . (empty($userIdList) ? '' : " AND `friends_friendship`.`friendId` IN ( " . $this->dbo->mergeInClause($userIdList) . " )") . " 
            ) AND "  . $usersAccess . $disapprovedUsers . " 
            ORDER BY `activityStamp` DESC
            LIMIT :first, :count
            ";

        $cacheLifeTime = self::CACHE_LIFE_TIME;
        $tags = array( self::CACHE_TAG_FRIEND_ID_LIST );

        return $this->dbo->queryForColumnList($query,
            array(
                'userId1' => $userId,
                'userId2' => $userId,
                'status1' => self::VAL_STATUS_ACTIVE,
                'status2' => self::VAL_STATUS_ACTIVE,
                'first' => $first,
                'count' => $count),
            $cacheLifeTime,
            $tags
        );
    }

    public function findFriendsIdOfUsersList( $userId, $first = 0, $count = 9)
    {
        $query = "SELECT userId FROM (";
        $query .= "SELECT friendId AS userId FROM `" . $this->getTableName() . "` ff WHERE friendId != :userId and userId IN (SELECT friendId FROM `" . $this->getTableName() . "` ff WHERE userId = :userId AND ff.status = 'active')";
        $query .= " union ";
        $query .= "SELECT userId as userId FROM  `" . $this->getTableName() . "` ff WHERE userId != :userId and friendId IN (SELECT friendId FROM `" . $this->getTableName() . "` ff WHERE userId = :userId AND ff.status = 'active')";
        $query .= " union ";
        $query .= "SELECT friendId as userId FROM `" . $this->getTableName() . "` ff WHERE friendId != :userId and userId IN (SELECT userId FROM `" . $this->getTableName() . "` ff WHERE friendId = :userId AND ff.status = 'active')";
        $query .= " union ";
        $query .= "SELECT userId as userId FROM `" . $this->getTableName() . "` ff WHERE userId != :userId and friendId IN (SELECT userId FROM `" . $this->getTableName() . "` ff WHERE friendId = :userId AND ff.status = 'active')";
        $query .= ") AS users WHERE userId NOT IN (SELECT userId FROM `" . BOL_UserBlockDao::getInstance()->getTableName() . "` WHERE blockedUserId = :userId ) AND userId NOT IN (SELECT blockedUserId FROM `" . BOL_UserBlockDao::getInstance()->getTableName() . "` WHERE userId = :userId )";
        $query .= " and userId NOT IN (SELECT userId FROM `" . $this->getTableName() . "` ff WHERE ff.friendId = :userId) ";
        $query .= " and userId NOT IN (SELECT friendId FROM `" . $this->getTableName() . "` ff WHERE ff.userId = :userId) ";
        $query .= " limit " . $first . ", " . $count;
        return $this->dbo->queryForColumnList($query, array(
            'status' => self::VAL_STATUS_ACTIVE,
            'userId' => $userId,
        ));
    }

    public function findUserFriendsCount( $userId, $userIdList = null )
    {
        $queryParts1 = BOL_UserDao::getInstance()->getUserQueryFilter("friends_friendship", "userId", array(
            "method" => "FRIENDS_BOL_FriendshipDao::findFriendIdList1"
        ));

        $queryParts2 = BOL_UserDao::getInstance()->getUserQueryFilter("friends_friendship", "friendId", array(
            "method" => "FRIENDS_BOL_FriendshipDao::findFriendIdList2"
        ));

        $query = "SELECT SUM(`count`) AS `count` FROM (
            ( SELECT COUNT(*) AS `count` FROM `" . $this->getTableName() . "` AS `friends_friendship`
            ".$queryParts1['join']."
            WHERE ".$queryParts1['where']." AND `friends_friendship`.`status` = :status1 AND `friends_friendship`.`friendId` = :userId1
            " . ( empty($userIdList) ? '' : " AND `userId` IN ( " . $this->dbo->mergeInClause($userIdList) . " )" ) . " )
            UNION ALL
            ( SELECT COUNT(*) AS `count` FROM `" . $this->getTableName() . "` AS `friends_friendship`
            ".$queryParts2['join']."
            WHERE ".$queryParts2['where']." AND `friends_friendship`.`status` = :status2 AND `friends_friendship`.`userId` = :userId2
            " . ( empty($userIdList) ? '' : " AND `friendId` IN ( " . $this->dbo->mergeInClause($userIdList) . " )" ) . " )
            ) AS `temp`";

        $cacheLifeTime = self::CACHE_LIFE_TIME;
        $tags = array( self::CACHE_TAG_FRIENDS_COUNT );

        return (int)$this->dbo->queryForColumn($query,
            array('userId1' => $userId,
                'userId2' => $userId,
                    'status1' => self::VAL_STATUS_ACTIVE,
                    'status2' => self::VAL_STATUS_ACTIVE
                ),
            $cacheLifeTime,
            $tags
        );
    }

    public function findRequestedUserIdList( $userId, $first, $count )
    {
        $queryParts = BOL_UserDao::getInstance()->getUserQueryFilter("friends_friendship", "friendId", array(
            "method" => "FRIENDS_BOL_FriendshipDao::findRequestedUserIdList"
        ));

        $query = "SELECT `friends_friendship`.`friendId` FROM `{$this->getTableName()}` `friends_friendship`
        " . $queryParts["join"] ."
        WHERE " . $queryParts['where'] . " AND  `friends_friendship`.`userId` = ? AND `status` != ? LIMIT ?, ?";

        return $this->dbo->queryForColumnList($query, array($userId, FRIENDS_BOL_Service::STATUS_ACTIVE, $first, $count));
    }

    public function findRequesterUserIdList( $userId, $first, $count )
    {
        $queryParts = BOL_UserDao::getInstance()->getUserQueryFilter("friends_friendship", "userId", array(
            "method" => "FRIENDS_BOL_FriendshipDao::findRequesterUserIdList"
        ));

        $query = "SELECT `friends_friendship`.`userId` FROM `{$this->getTableName()}` `friends_friendship`
        " . $queryParts["join"] ."
        WHERE " . $queryParts['where'] . " AND `friends_friendship`.`friendId` = ? AND `status`= ? LIMIT ?, ?";

        return $this->dbo->queryForColumnList($query, array($userId, FRIENDS_BOL_Service::STATUS_PENDING, $first, $count));
    }


    public function count( $userId=null, $friendId=null, $status=null, $orStatus=null, $viewed = null )
    {
        $queryParts = array();

        if ( $userId !== null )
        {
            $queryParts = BOL_UserDao::getInstance()->getUserQueryFilter("friends_friendship", "friendId", array(
                "method" => "FRIENDS_BOL_FriendshipDao::count"
            ));

            $queryParts['where'] .= " AND `friends_friendship`.`userId`=".$userId;
        }

        if ( $friendId !== null )
        {
            $queryParts = BOL_UserDao::getInstance()->getUserQueryFilter("friends_friendship", "userId", array(
                "method" => "FRIENDS_BOL_FriendshipDao::count"
            ));

            $queryParts['where'] .= " AND `friends_friendship`.`friendId`=".$friendId;
        }

        if ( $status !== null )
        {
            if ( $orStatus !== null )
            {
                $statusArray = $this->dbo->mergeInClause(array($status, $orStatus));

                $queryParts['where'] .= " AND `friends_friendship`.`status` IN (" . $statusArray . ")";
            }
            else
            {
                $queryParts['where'] .= " AND `friends_friendship`.`status` = '" . $status . "'";
            }
        }

        if ( $viewed !== null )
        {
            $queryParts['where'] .= " AND `friends_friendship`.`viewed` = '" .  (int) (bool) $viewed . "'";
        }

        $cacheLifeTime = self::CACHE_LIFE_TIME;
        $tags = array( self::CACHE_TAG_FRIENDS_COUNT );

        $query = "SELECT COUNT(*) FROM `{$this->getTableName()}` `friends_friendship`
        " . $queryParts["join"] ."
        WHERE " . $queryParts['where'];

        return $this->dbo->queryForColumn($query, array(), $cacheLifeTime, $tags);
    }

    public function deleteUserFriendships( $userId )
    {
        $query = "DELETE FROM `{$this->getTableName()}` WHERE `userId` = ? OR `friendId` = ?";

        $this->dbo->delete($query, array($userId, $userId));

        $this->clearCache();
    }

    public function findAllActiveFriendships()
    {
        $ex = new OW_Example();

        $ex->andFieldEqual('status', FRIENDS_BOL_Service::STATUS_ACTIVE);

        return $this->findListByExample($ex);
    }

    public function findActiveFriendships( $first, $count )
    {
        $ex = new OW_Example();

        $ex->andFieldEqual('status', FRIENDS_BOL_Service::STATUS_ACTIVE);
        $ex->setOrder('`id` ASC');
        $ex->setLimitClause($first, $count);

        return $this->findListByExample($ex);
    }

    public function findFriendshipListByUserId( $userId, $userIdList = array() )
    {
         $query = "( SELECT `fr`.`id`, `fr`.`userId`, `fr`.`friendId`, `fr`.`status` FROM `" . $this->getTableName() . "` AS `fr`
            LEFT JOIN `" . BOL_UserSuspendDao::getInstance()->getTableName() . "` AS `us` ON ( `fr`.`" . self::USER_ID . "` = `us`.`userId` )
            WHERE `fr`.`" . self::STATUS . "` = :status1 AND `us`.`userId` IS NULL AND `fr`.`" . self::FRIEND_ID . "` = :userId1
            " . ( empty($userIdList) ? '' : " AND `fr`.`" . self::USER_ID . "` IN ( " . $this->dbo->mergeInClause($userIdList) . " )" ) . " )
            UNION
            ( SELECT `fr`.`id`, `fr`.`userId`, `fr`.`friendId`, `fr`.`status` FROM `" . $this->getTableName() . "` AS `fr`
            LEFT JOIN `" . BOL_UserSuspendDao::getInstance()->getTableName() . "` AS `us` ON ( `fr`.`" . self::FRIEND_ID . "` = `us`.`userId` )
            WHERE `fr`.`" . self::STATUS . "` = :status2 AND `us`.`userId` IS NULL AND `fr`.`" . self::USER_ID . "` = :userId2
            " . ( empty($userIdList) ? '' : " AND `fr`.`" . self::FRIEND_ID . "` IN ( " . $this->dbo->mergeInClause($userIdList) . " )" ) . " ) ";

        return $this->dbo->queryForObjectList($query,
            $this->getDtoClassName(),
            array(
                'userId1' => $userId,
                'userId2' => $userId,
                'status1' => self::VAL_STATUS_ACTIVE,
                'status2' => self::VAL_STATUS_ACTIVE,
                )
        );
    }

    public function findRequestList($userId, $beforeStamp, $offset, $count, $exclude = null)
    {
        $queryParts = BOL_UserDao::getInstance()->getUserQueryFilter("friends_friendship", "userId", array(
            "method" => "FRIENDS_BOL_FriendshipDao::findRequestList"
        ));

        $excludeCondition = "";
        if($exclude!=null){
            $excludeCondition = " and `friends_friendship`.`id` not in (". OW::getDbo()->mergeInClause($exclude) .")";
        }

        $query = "SELECT `friends_friendship`.* FROM `{$this->getTableName()}` `friends_friendship`
        " . $queryParts["join"] ."
        WHERE " . $queryParts['where']." AND `friends_friendship`.`friendId` = ".$userId." AND `friends_friendship`.`status`='".FRIENDS_BOL_Service::STATUS_PENDING."'
        AND `friends_friendship`.`timeStamp`<=".$beforeStamp. " " . $excludeCondition . " ORDER BY `friends_friendship`.`viewed`, `friends_friendship`.`timeStamp` DESC LIMIT ".$offset.", ".$count;

        return $this->dbo->queryForObjectList($query, $this->getDtoClassName(), array());

    }

    public function findNewRequestList( $userId, $afterStamp )
    {
        $queryParts = BOL_UserDao::getInstance()->getUserQueryFilter("friends_friendship", "userId", array(
            "method" => "FRIENDS_BOL_FriendshipDao::findRequestList"
        ));

        $query = "SELECT `friends_friendship`.* FROM `{$this->getTableName()}` `friends_friendship`
        " . $queryParts["join"] ."
        WHERE " . $queryParts['where']." AND `friends_friendship`.`friendId` = ".$userId." AND `friends_friendship`.`status`='".FRIENDS_BOL_Service::STATUS_PENDING."'
        AND `friends_friendship`.`timeStamp`>".$afterStamp." ORDER BY `friends_friendship`.`viewed`, `friends_friendship`.`timeStamp` DESC";

        return $this->dbo->queryForObjectList($query, $this->getDtoClassName(), array());
    }

    public function markViewedByIds( array $ids, $viewed = true )
    {
        if ( empty($ids) )
        {
            return;
        }

        $in = implode(',', $ids);

        $query = "UPDATE " . $this->getTableName() . " SET `viewed`=:viewed WHERE id IN ( " . $in . " )";

        $this->dbo->query($query, array(
            'viewed' => $viewed ? 1 : 0
        ));

        $this->clearCache();
    }

    public function markAllViewedByUserId( $userId, $viewed = true )
    {
        if ( !$userId )
        {
            return;
        }

        $query = "UPDATE " . $this->getTableName() . " SET `viewed`=:viewed WHERE `friendId` = :userId";

        $this->dbo->query($query, array('viewed' => $viewed ? 1 : 0, 'userId' => $userId));

        $this->clearCache();
    }

    public function findUnreadFriendRequestsForUserIdList($userIdList)
    {
        $queryParts = BOL_UserDao::getInstance()->getUserQueryFilter("friends_friendship", "userId", array(
            "method" => "FRIENDS_BOL_FriendshipDao::findUnreadFriendRequestsForUserIdList"
        ));

        $query = "SELECT `friends_friendship`.* FROM `{$this->getTableName()}` `friends_friendship`
        " . $queryParts["join"] ."
        WHERE " . $queryParts['where']." AND `friends_friendship`.`friendId` IN ( ".$this->dbo->mergeInClause($userIdList)." ) AND `friends_friendship`.`status`='".FRIENDS_BOL_Service::STATUS_PENDING."'
        AND `friends_friendship`.`notificationSent`=0 AND `viewed`=0 ORDER BY `friends_friendship`.`timeStamp` DESC";

        return $this->dbo->queryForObjectList($query, $this->getDtoClassName(), array());
    }

    protected function clearCache()
    {
        OW::getCacheManager()->clean( array( FRIENDS_BOL_FriendshipDao::CACHE_TAG_FRIENDS_COUNT, FRIENDS_BOL_FriendshipDao::CACHE_TAG_FRIEND_ID_LIST ));
    }

    public function findFriendIdListByDisplayName( $userId, $search, $first, $count, $userIdList = null )
    {
        $ids = $this->findFriendIdList($userId, 0, 10000, $userIdList);
        if ($ids == null || sizeof($ids) == 0) {
            return array();
        }
        $queryParts = BOL_UserDao::getInstance()->getUserQueryFilter('u', 'id', array(
            'method' => 'MAILBOX_BOL_AjaxService::findFriendIdListByDisplayName'
        ));
        $questionName = OW::getConfig()->getValue('base', 'display_name_question');
        $params = array('search' => '%' . $search . '%');

        if ( $questionName === 'username' )
        {
            $order = ' group by `u`.`id` ORDER BY `u`.`username`';
            $queryParts['where'] .= ' AND `u`.`username` LIKE :search';
        }
        else
        {
            $order = ' group by `u`.`id` ORDER BY `qd`.`textValue`';
            $params['questionName'] = $questionName;
            $queryParts['where'] .= ' AND qd.questionName=:questionName AND (qd.textValue LIKE :search or base_user_table_alias.username LIKE :search)';
            $queryParts['join'] .= ' INNER JOIN `' . BOL_QuestionDataDao::getInstance()->getTableName() . '` AS `qd` ON(`u`.`id` = `qd`.`userId`) ';
        }

        $query = 'SELECT `u`.`id`, `qd`.`textValue`, `u`.`username`
            FROM `' . BOL_UserDao::getInstance()->getTableName() . '` AS `u`
                ' . $queryParts['join'] . '
            WHERE`u`.`id` IN(' . $this->dbo->mergeInClause($ids) . ') AND ' . $queryParts['where'] . '
            ' . $order . '
            LIMIT :first, :count';

        return $this->dbo->queryForColumnList($query, array_merge(
            array(
                'first' => $first,
                'count' => $count
            ),
            $params
        ));
    }
}