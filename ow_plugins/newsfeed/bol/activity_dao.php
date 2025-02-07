<?php
/**
 * Data Access Object for `newsfeed_activity` table.
 *
 * @package ow_plugins.newsfeed.bol
 * @since 1.0
 */
class NEWSFEED_BOL_ActivityDao extends OW_BaseDao
{
    /**
     * Singleton instance.
     *
     * @var NEWSFEED_BOL_ActivityDao
     */
    private static $classInstance;

    /**
     * Returns an instance of class (singleton pattern implementation).
     *
     * @return NEWSFEED_BOL_ActivityDao
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
        return 'NEWSFEED_BOL_Activity';
    }

    /**
     * @see OW_BaseDao::getTableName()
     *
     */
    public function getTableName()
    {
        return OW_DB_PREFIX . 'newsfeed_activity';
    }

    public function deleteByActionIds( $actionIds )
    {
        if ( empty($actionIds) )
        {
            return array();
        }

        $example = new OW_Example();
        $example->andFieldInArray('actionId', $actionIds);

        return $this->deleteByExample($example);
    }

    public function deleteByUserId( $userId )
    {
        $example = new OW_Example();
        $example->andFieldEqual('userId', $userId);

        return $this->deleteByExample($example);
    }

    public function findIdListByActionIds( $actionIds )
    {
        if ( empty($actionIds) )
        {
            return array();
        }

        $example = new OW_Example();
        $example->andFieldInArray('actionId', $actionIds);

        return $this->findIdListByExample($example);
    }

    public function findByActionIds( $actionIds )
    {
        if ( empty($actionIds) )
        {
            return array();
        }

        $example = new OW_Example();
        $example->andFieldInArray('actionId', $actionIds);

        return $this->findListByExample($example);
    }

    private function getQueryParts( $conts )
    {
        $actionDao = NEWSFEED_BOL_ActionDao::getInstance();
        $or = array();
        $join = '';

        foreach ( $conts as $cond )
        {
            $action = array_filter($cond['action']);
            $activity = array_filter($cond['activity']);

            $where = array();

            if ( empty($activity['id']) )
            {
                if ( !empty($action['id']) )
                {
                    $activity['actionId'] = $action['id'];
                }
                else if ( !empty($action) )
                {
                    $join = 'INNER JOIN ' . $actionDao->getTableName() . ' action ON activity.actionId=action.id';

                    foreach ( $action as $k => $v )
                    {
                        $where[] = 'action.' . $k . "='" . $this->dbo->escapeValue($v) . "'";
                    }
                }
            }

            foreach ( $activity as $k => $v )
            {
                $where[] = 'activity.' . $k . "='" . $this->dbo->escapeValue($v) . "'";
            }

            $or[] = implode(' AND ', $where);
        }

        return array(
            'join' => $join,
            'where' => empty($or) ? '1' : '( ' . implode(' ) OR ( ', $or) . ' )'
        );
    }

    public function findActivity( $params )
    {
        $qp = $this->getQueryParts($params);

        $query = 'SELECT activity.* FROM ' . $this->getTableName() . ' activity ' . $qp['join'] . ' WHERE ' . $qp['where'];

        return $this->dbo->queryForObjectList($query, $this->getDtoClassName());
    }

    public function findActionsByFeedTypeAndFeedId( $feedTyped, $feedId )
    {
        $params = array('feedType' => $feedTyped, 'feedId' => $feedId);
        $query = 'SELECT DISTINCT `action` .* FROM ' . NEWSFEED_BOL_ActionDao::getInstance()->getTableName() .' AS `action` INNER JOIN '. $this->getTableName() .' AS `activity` 
        ON `activity`.actionId = `action`.id 
        AND `activity`.id IN ( SELECT `action_feed`.activityId FROM '. NEWSFEED_BOL_ActionFeedDao::getInstance()->getTableName().' AS `action_feed` WHERE `action_feed`.feedType=:feedType 
        AND `action_feed`.feedId=:feedId ) ';
        return $this->dbo->queryForList($query,$params);
    }


    public function deleteActivity( $params )
    {
        $qp = $this->getQueryParts($params);

        $query = 'DELETE activity FROM ' . $this->getTableName() . ' activity ' . $qp['join'] . ' WHERE ' . $qp['where'];

        return $this->dbo->query($query);
    }

    public function updateActivity( $params, $updateFields )
    {
        if ( empty($updateFields) )
        {
            return;
        }

        $set = array();
        foreach ( $updateFields as $k => $v )
        {
            $set[] = 'activity.`' . $k . "`='" . $this->dbo->escapeValue($v) . "'";
        }

        $qp = $this->getQueryParts($params);
        $query = 'UPDATE ' . $this->getTableName() . ' activity ' . $qp['join'] . ' SET ' . implode(', ', $set) . ' WHERE ' . $qp['where'];

        return $this->dbo->query($query);
    }

    /**
     *
     * @param string $activityType
     * @param int $activityId
     * @param int $actionId
     * @return NEWSFEED_BOL_Activity
     */
    public function findActivityItem( $activityType, $activityId, $actionId )
    {
        $example = new OW_Example();
        $example->andFieldEqual('activityType', $activityType);
        $example->andFieldEqual('activityId', $activityId);
        $example->andFieldEqual('actionId', $actionId);

        return $this->findObjectByExample($example);
    }

    public function findSiteFeedActivity( $actionIds )
    {
        $unionQueryList = array();

        $queryParts = BOL_UserDao::getInstance()->getUserQueryFilter("activity", "userId", array(
            "method" => "NEWSFEED_BOL_ActivityDao::findSiteFeedActivity"
        ));
        
        $unionQueryList[] = 'SELECT activity.* FROM ' . $this->getTableName() . ' activity ' . $queryParts["join"] . '
            WHERE ' . $queryParts["where"] . ' AND activity.actionId IN(' . implode(', ', $actionIds) . ')
                AND activity.activityType IN ("' . implode('", "', NEWSFEED_BOL_Service::getInstance()->SYSTEM_ACTIVITIES) . '")';

//        foreach ( $actionIds as $actionId ) {
//            $unionQueryList[] = 'SELECT a.* FROM (
//                SELECT activity.* FROM ' . $this->getTableName() . ' activity ' . $queryParts["join"] . ' WHERE ' . $queryParts["where"] . ' AND  activity.actionId = ' . $actionId . ' AND activity.status=:s AND activity.privacy=:peb AND activity.visibility & :v ORDER BY activity.timeStamp DESC, activity.id DESC LIMIT 100
//                        ) a';
//        }
//        $query = implode( ' UNION ', $unionQueryList ) . " ORDER BY 7 DESC, 1 DESC";
        $actionIdsString = '('.implode(',',$actionIds).')';
        $unionQueryList[] = 'SELECT a.* FROM (
                SELECT activity.* FROM ' . $this->getTableName() . ' activity ' . $queryParts["join"] . ' WHERE ' . $queryParts["where"] . ' AND  activity.actionId IN ' . $actionIdsString . ' AND activity.status=:s AND activity.privacy=:peb AND activity.visibility & :v ORDER BY activity.timeStamp DESC, activity.id DESC LIMIT 100
                        ) a';
        $query = implode( ' UNION ', $unionQueryList ) . " ORDER BY 7 DESC, 1 DESC ";
        return $this->dbo->queryForObjectList($query, $this->getDtoClassName(), array(
            'v' => NEWSFEED_BOL_Service::VISIBILITY_SITE,
            's' => NEWSFEED_BOL_Service::ACTION_STATUS_ACTIVE,
            'peb' => NEWSFEED_BOL_Service::PRIVACY_EVERYBODY
        ));
    }

    public function findUserFeedActivity( $userId, $actionIds )
    {
        /*
         * @author Farzan Mohammadi <farzan.mohamadii@gmail.com>
         * When access to an action is granted. It must access to all it's activities
         */
        /*
        $followDao = NEWSFEED_BOL_FollowDao::getInstance();
        $actionFeedDao = NEWSFEED_BOL_ActionFeedDao::getInstance();

        $unionQueryList = array();

        $queryParts = BOL_UserDao::getInstance()->getUserQueryFilter("activity", "userId", array(
            "method" => "NEWSFEED_BOL_ActivityDao::findUserFeedActivity"
        ));

        $unionQueryList[] = 'SELECT activity.* FROM ' . $this->getTableName() . ' activity 
            WHERE activity.actionId IN(' . implode(', ', $actionIds) . ') 
            AND activity.activityType IN ("' . implode('", "', NEWSFEED_BOL_Service::getInstance()->SYSTEM_ACTIVITIES) . '")';

        $actionIdsString = '('.implode(',',$actionIds).')';
        $unionQueryList[] = ' SELECT a.* FROM ( SELECT DISTINCT activity.* FROM ' . $this->getTableName() . ' activity
                ' . $queryParts["join"] . '

                LEFT JOIN ' . $actionFeedDao->getTableName() . ' action_feed ON activity.id=action_feed.activityId
                LEFT JOIN ' . $followDao->getTableName() . ' follow ON action_feed.feedId = follow.feedId AND action_feed.feedType = follow.feedType
                WHERE ' . $queryParts["where"] . ' AND activity.actionId IN ' . $actionIdsString . ' AND
                (
                    (activity.status=:s AND
                    (
                        ( follow.userId=:u AND activity.visibility & :vf AND ( activity.privacy=:peb OR activity.privacy=follow.permission ) )
                        OR
                        ( activity.userId=:u AND activity.visibility & :va )
                        OR
                        ( action_feed.feedId=:u AND action_feed.feedType="user" AND activity.visibility & :vfeed )
                    ))
                ) ORDER BY activity.timeStamp DESC, activity.id DESC LIMIT 100 ) a' ;
        $query = implode( ' UNION ', $unionQueryList ) . " ORDER BY 7 DESC, 1 DESC ";
        return $this->dbo->queryForObjectList($query, $this->getDtoClassName(), array(
            'u' => $userId,
            'va' => NEWSFEED_BOL_Service::VISIBILITY_AUTHOR,
            'vf' => NEWSFEED_BOL_Service::VISIBILITY_FOLLOW,
            'vfeed' => NEWSFEED_BOL_Service::VISIBILITY_FEED,
            's' => NEWSFEED_BOL_Service::ACTION_STATUS_ACTIVE,
            'peb' => NEWSFEED_BOL_Service::PRIVACY_EVERYBODY
        ));
        */
        $query = 'SELECT activity.* FROM ' . $this->getTableName() . ' activity 
            WHERE activity.actionId IN(' . implode(', ', $actionIds) . ')';
        return $this->dbo->queryForObjectList($query, $this->getDtoClassName());
    }

    public function findFeedActivity( $feedType, $feedId, $actionIds )
    {
        $actionFeedDao = NEWSFEED_BOL_ActionFeedDao::getInstance();

        $queryParts = BOL_UserDao::getInstance()->getUserQueryFilter("activity", "userId", array(
            "method" => "NEWSFEED_BOL_ActivityDao::findFeedActivity"
        ));

        $actionIdsString = '('.implode(',',$actionIds).')';

        $query = '
        SELECT * FROM ' . $this->getTableName() . ' 
            WHERE actionId IN(' . implode(', ', $actionIds) . ')
            AND activityType IN ("' . implode('", "', NEWSFEED_BOL_Service::getInstance()->SYSTEM_ACTIVITIES) . '")
        UNION
        SELECT * FROM ' . $this->getTableName() . '
            WHERE id IN 
            ( SELECT DISTINCT activity.id FROM ' . $this->getTableName() . ' activity
                ' . $queryParts["join"] . '
                INNER JOIN ' . $actionFeedDao->getTableName() . ' action_feed ON activity.id=action_feed.activityId
                WHERE ' . $queryParts["where"] . ' AND activity.actionId IN ' . $actionIdsString . ' AND
                (
                    activity.status=:s
                    AND activity.privacy=:peb
                    AND action_feed.feedType=:ft
                    AND action_feed.feedId=:fi
                    AND activity.visibility & :v
                )
            )    
        ORDER BY timeStamp DESC, id DESC LIMIT 100;';

        return $this->dbo->queryForObjectList($query, $this->getDtoClassName(), array(
            'ft' => $feedType,
            'fi' => $feedId,
            's' => NEWSFEED_BOL_Service::ACTION_STATUS_ACTIVE,
            'v' => NEWSFEED_BOL_Service::VISIBILITY_FEED,
            'peb' => NEWSFEED_BOL_Service::PRIVACY_EVERYBODY
        ));
    }

    public function saveOrUpdate( NEWSFEED_BOL_Activity $activity )
    {
        $dto = $this->findActivityItem($activity->activityType, $activity->activityId, $activity->actionId);
        if ( $dto !== null )
        {
            $activity->id = $dto->id;
        }
        
        $this->save($activity);
    }

    public function batchSaveOrUpdate( array $dtoList )
    {
        $this->dbo->batchInsertOrUpdateObjectList($this->getTableName(), $dtoList);
    }


    public function findByIds($ids)
    {
        if (empty($ids)) {
            return array();
        }
        $example = new OW_Example();
        $example->andFieldInArray('id', $ids);
        $example->setOrder('`id` DESC');
        return $this->findListByExample($example);

    }
}