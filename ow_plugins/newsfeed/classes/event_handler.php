<?php
/**
 *
 * @package ow_plugins.newsfeed.classes
 * @since 1.0
 */
class NEWSFEED_CLASS_EventHandler
{
    /**
     * Singleton instance.
     *
     * @var NEWSFEED_CLASS_EventHandler
     */
    private static $classInstance;

    /**
     * Returns an instance of class (singleton pattern implementation).
     *
     * @return NEWSFEED_CLASS_EventHandler
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
     *
     * @var NEWSFEED_BOL_Service
     */
    private $service;

    private function __construct()
    {
        $this->service = NEWSFEED_BOL_Service::getInstance();
    }

    private function validateParams( $params, $requiredList, $orRequiredList = array() )
    {
        $fails = array();

        foreach ( $requiredList as $required )
        {
            if ( empty($params[$required]) )
            {
                $fails[] = $required;
            }
        }

        if ( !empty($fails) )
        {
            if ( !empty($orRequiredList) )
            {
                $this->validateParams($params, $orRequiredList);

                return;
            }

            throw new InvalidArgumentException('Next params are required: ' . implode(', ', $fails));
        }
    }

    private function extractEventParams( OW_Event $event )
    {
        $defaultParams = array(
            'visibility' => NEWSFEED_BOL_Service::VISIBILITY_FULL,
            'replace' => false,
            'merge' => false
        );

        $params = $event->getParams();
        $data = $event->getData();

        if ( empty($params['userId']) )
        {
            $params['userId'] = OW::getUser()->getId();
        }

        if ( isset($data['time']) )
        {
            $params['time'] = $data['time'];
        }

        if ( isset($data['params']) && is_array($data['params']) )
        {
            $params = array_merge($params, $data['params']);
        }

        return array_merge($defaultParams, $params);
    }

    private function extractEventData( OW_Event $event )
    {
        $data = $event->getData();
        unset($data['params']);

        return $data;
    }

    public function action( OW_Event $originalEvent )
    {
        $params = $this->extractEventParams($originalEvent);
        $this->validateParams($params, array('entityType', 'entityId'));

        $data = $originalEvent->getData();
        $actionDto = null;

        $mergeTo = null;

        if ( is_array($params['merge']) )
        {
            $actionDto = $data['actionDto'] = $this->service->findAction($params['merge']['entityType'], $params['merge']['entityId']);
            $mergeTo = $params['merge'];
        }
        else
        {
            $actionDto = $data['actionDto'] = $this->service->findAction($params['entityType'], $params['entityId']);
        }

        if(isset($_POST['pin'])){
            $params['pin'] = $_POST['pin'];
        }

        $event = new OW_Event('feed.on_entity_action', $params, $data);
        OW::getEventManager()->trigger($event);

        $params = $this->extractEventParams($event);
        $data = $this->extractEventData($event);

        $event = new OW_Event('feed.entity_id_specified', $params);
        OW::getEventManager()->trigger($event);

        if ( $mergeTo !== null && ( $mergeTo["entityType"] != $params['merge']["entityType"] || $mergeTo["entityId"] != $params['merge']["entityId"] ) )
        {
            $actionDto = $data['actionDto'] = $this->service->findAction($params['merge']['entityType'], $params['merge']['entityId']);
            $mergeTo = $params['merge'];
        }

        $actionDto = $data['actionDto'] = empty($data['actionDto']) ? $actionDto : $data['actionDto'];

        $activityParams = null;
        if ( $actionDto !== null )
        {
            $action = $actionDto;

            $action->entityType = $params['entityType'];
            $action->entityId = $params['entityId'];

            $params['pluginKey'] = empty($params['pluginKey']) ? $action->pluginKey : $params['pluginKey'];
            $actionData = json_decode($action->data, true);
            $data = array_merge($actionData, $data);

            $event = new OW_Event('feed.on_entity_update', $params, $data);
            OW::getEventManager()->trigger($event);

            unset($data['actionDto']);

            $params = $this->extractEventParams($event);
            $data = $this->extractEventData($event);

            if ( $params['replace'] )
            {
                $this->service->removeAction($action->entityType, $action->entityId);
                $action->id = null;
            }

            if(isset($data['actionDto']) && isset($data['actionDto']->data)) {
                //preventing unnecessary data to be stored
                $oldData = json_decode($data['actionDto']->data,true);
                if(isset($oldData['actionDto']))
                    unset($oldData['actionDto']);
                $data['actionDto']->data = json_encode($oldData);
                //unset($data['actionDto']->data);
            }
            $action->data = json_encode($data);

            if ( empty($data["content"]) )
            {
                $action->format = NEWSFEED_CLASS_FormatManager::FORMAT_EMPTY;
            }
            else if ( !empty($data["content"]["format"]) )
            {
                $action->format = trim($data["content"]["format"]);
            }

            $this->service->saveAction($action);

            $activityParams = array(
                'pluginKey' => $params['pluginKey'],
                'entityType' => $params['entityType'],
                'entityId' => $params['entityId'],
                'activityType' => NEWSFEED_BOL_Service::SYSTEM_ACTIVITY_CREATE,
                'actionId' => $action->id
            );

            if ( isset($params['visibility']) )
            {
                $activityParams['visibility'] = $params['visibility'];
            }

            if ( isset($params['time']) )
            {
                $activityParams['time'] = $params['time'];
            }

            if ( !empty($params['privacy']) )
            {
                $activityParams['privacy'] = $params['privacy'];
            }

            if ( !empty( $params['feedType']) && !empty($params['feedId']) )
            {
                $activityParams['feedType'] = $params['feedType'];
                $activityParams['feedId'] = $params['feedId'];
            }

            $temp = empty($data['ownerId']) ? $params['userId'] : $data['ownerId'];
            $userIds = !is_array($temp) ? array($temp) : $temp;

            foreach ( $userIds as $userId )
            {
                $activityParams['userId'] = (int) $userId;
                $activityParams['activityId'] = (int) $userId;

                $activityEvent = new OW_Event('feed.activity', $activityParams);
                $this->activity($activityEvent);
            }
        }
        else
        {
            $_authorIdList = is_array($params['userId']) ? $params['userId'] : array($params['userId']);
            $authorIdList = array();

            foreach ( $_authorIdList as $uid )
            {
                $activityKey = "create.{$params['entityId']}:{$params['entityType']}.{$params['entityId']}:{$uid}";
                if ( $this->testActivity($activityKey) )
                {
                    $authorIdList[] = $uid;
                }
            }

            if ( empty($authorIdList) && $params['entityType'] != "groups-status")
            {
                return;
            }

            $params["userId"] = count($authorIdList) == 1 ? $authorIdList[0] : $authorIdList;

            $event = new OW_Event('feed.on_entity_add', $params, $data);
            OW::getEventManager()->trigger($event);

            $params = $this->extractEventParams($event);
            $data = $this->extractEventData($event);

            if ( empty($data['content']) && empty($data['string']) && empty($data['line']) )
            {
                return;
            }

            if ( is_array($params['replace']) )
            {
                $this->service->removeAction($params['replace']['entityType'], $params['replace']['entityId']);
            }

            $action = new NEWSFEED_BOL_Action();
            $action->entityType = $params['entityType'];
            $action->entityId = $params['entityId'];
            $action->pluginKey = $params['pluginKey'];

            if ( empty($data["content"]) )
            {
                $action->format = NEWSFEED_CLASS_FormatManager::FORMAT_EMPTY;
            }
            else if ( !empty($data["content"]["format"]) )
            {
                $action->format = trim($data["content"]["format"]);
            }

            $action->data = json_encode($data);

            $this->service->saveAction($action);
            $actionDto = $action;

            OW::getEventManager()->trigger(new OW_Event(NEWSFEED_BOL_Service::EVENT_AFTER_ACTION_ADD, array(
                "actionId" => $action->id,
                "entityType" => $action->entityType,
                "entityId" => $action->entityId
            )));

            $activityParams = array(
                'pluginKey' => $params['pluginKey'],
                'entityType' => $params['entityType'],
                'entityId' => $params['entityId'],
                'activityType' => NEWSFEED_BOL_Service::SYSTEM_ACTIVITY_CREATE,
                'visibility' => (int) $params['visibility'],
                'actionId' => $action->id,
                'subscribe' => isset($params['subscribe']) ? (bool) $params['subscribe'] : true,
                'time' => empty($params['time']) ? time() : $params['time']
            );

            if ( !empty($params['privacy']) )
            {
                $activityParams['privacy'] = $params['privacy'];
            }

            if ( !empty( $params['feedType']) && !empty($params['feedId']) )
            {
                $activityParams['feedType'] = $params['feedType'];
                $activityParams['feedId'] = $params['feedId'];
            }

            $temp = empty($data['ownerId']) ? $params['userId'] : $data['ownerId'];
            $userIds = !is_array($temp) ? array($temp) : $temp;

            foreach ( $userIds as $userId )
            {
                $activityParams['userId'] = (int) $userId;
                $activityParams['activityId'] = (int) $userId;
                $activityParams['action_loaded'] = $action;

                $activityEvent = new OW_Event('feed.activity', $activityParams);
                $this->activity($activityEvent);
            }
        }

        OW::getEventManager()->trigger( new OW_Event('after.feed.action', array_merge($params, $activityParams), array('action' => $actionDto)) );
    }

    public function activity( OW_Event $activityEvent )
    {
        $params = $activityEvent->getParams();
        $data = $activityEvent->getData();
        $data = empty($data) ? array() : $data;
        $actionLoaded = null;
        if (isset($params['action_loaded'])) {
            $actionLoaded = $params['action_loaded'];
        }

        $this->validateParams($params,
            array('activityType', 'activityId', 'entityType', 'entityId', 'userId', 'pluginKey'),
            array('activityType', 'activityId', 'actionId', 'userId', 'pluginKey'));

        $activityKey = "{$params['activityType']}.{$params['activityId']}:{$params['entityType']}.{$params['entityId']}:{$params['userId']}";
        if ( !$this->testActivity($activityKey) && $params['entityType'] != "groups-status" )
        {
            return;
        }

        $actionId = empty($params['actionId']) ? null : $params['actionId'];

        $onEvent = new OW_Event('feed.on_activity', $activityEvent->getParams(), array( 'data' => $data ));
        OW::getEventManager()->trigger($onEvent);
        $onData = $onEvent->getData();
        $data = $onData['data'];

        if ( !empty($onData['params']) && is_array($onData['params']) )
        {
            $params = array_merge($params, $onData['params']);
        }

        if ( !in_array($params['activityType'], NEWSFEED_BOL_Service::getInstance()->SYSTEM_ACTIVITIES) && empty($data['string']) )
        {
            return;
        }

        if ( empty($actionId) )
        {
            $actionDto = $actionLoaded;
            if ($actionDto == null) {
                $actionDto = $this->service->findAction($params['entityType'], $params['entityId']);
            }

            if ( $actionDto === null )
            {
                $paramUserId=$params['userId'];
                $frmEventActionData = OW::getEventManager()->trigger(new OW_Event(FRMEventManager::CHECK_OWNER_OF_ACTION_ID,array('pluginKey'=>$params['pluginKey'],'entityId'=>$params['entityId'])));
                if(isset($frmEventActionData->getData()['ownerId'])){
                    $paramUserId=$frmEventActionData->getData()['ownerId'];
                }
                $actionEvent = new OW_Event('feed.action', array(
                    'pluginKey' => $params['pluginKey'],
                    'userId' => $paramUserId,
                    'entityType' => $params['entityType'],
                    'entityId' => $params['entityId']
                ), array(
                    'data' => $data
                ));

                $this->action($actionEvent);
                $actionDto = $this->service->findAction($params['entityType'], $params['entityId']);
            }

            if ( $actionDto === null )
            {
                return;
            }

            $actionId = (int) $actionDto->id;
        }

        $activity = $this->service->findActivityItem($params['activityType'], $params['activityId'], $actionId);

        if ( $activity === null )
        {
            $privacy = empty($params['privacy']) ? NEWSFEED_BOL_Service::PRIVACY_EVERYBODY : $params['privacy'];
            $activity = new NEWSFEED_BOL_Activity();
            $activity->activityType = $params['activityType'];
            $activity->activityId = $params['activityId'];
            $activity->actionId = $actionId;
            $activity->privacy = $privacy;
            $activity->userId = $params['userId'];
            $activity->visibility = empty($params['visibility']) ? NEWSFEED_BOL_Service::VISIBILITY_FULL : $params['visibility'];
            $activity->timeStamp = empty($params['time']) ? time() : $params['time'];
            $activity->data = json_encode($data);
        }
        else
        {
            $activity->privacy = empty($params['privacy']) ? $activity->privacy : $params['privacy'];
            $activity->timeStamp = empty($params['time']) ? $activity->timeStamp : $params['time'];
            $activity->visibility = empty($params['visibility']) ? $activity->visibility : $params['visibility'];
            $_data = array_merge( json_decode($activity->data, true), $data );
            $activity->data = json_encode($_data);
        }

        $this->service->saveActivity($activity, $actionLoaded);

        if ( isset($params['subscribe']) && $params['subscribe'] )
        {
            $subscribe = new NEWSFEED_BOL_Activity();
            $subscribe->actionId = $actionId;
            $subscribe->userId = $params['userId'];
            $subscribe->visibility = NEWSFEED_BOL_Service::VISIBILITY_FULL;
            $subscribe->privacy = NEWSFEED_BOL_Service::PRIVACY_EVERYBODY;
            $subscribe->timeStamp = empty($params['time']) ? time() : $params['time'];
            $subscribe->activityType = NEWSFEED_BOL_Service::SYSTEM_ACTIVITY_SUBSCRIBE;
            $subscribe->activityId = $params['userId'];
            $subscribe->data = json_encode(array());

            $this->service->saveActivity($subscribe, $actionLoaded);
        }

        if ( isset($params['subscribe']) && !$params['subscribe'] )
        {
            $this->service->removeActivity("subscribe.{$params['userId']}:$actionId");
        }

        if ( !empty($params['feedType']) && !empty($params['feedId']) )
        {
            $this->service->addActivityToFeed($activity, $params['feedType'], $params['feedId'], $actionLoaded);
        }

        $params = $activityEvent->getParams();
        $params['actionId'] = $actionId;
        $params['action'] = $actionLoaded;

        $onEvent = new OW_Event('feed.after_activity', $params, array( 'data' => $data ));
        OW::getEventManager()->trigger($onEvent);
    }

    public function afterActivity( OW_Event $e )
    {
        $params = $e->getParams();
        $data = $e->getData();

        $this->service->clearUserFeedCahce($params['userId']);
    }

    public function onActivity( OW_Event $e )
    {
        $params = $e->getParams();
        $data = $e->getData();

        if ( empty($params['privacy']) )
        {
            $activityKey = "{$params['activityType']}.{$params['activityId']}:{$params['entityType']}.{$params['entityId']}:{$params['userId']}";
            $action = $this->service->getPrivacyActionByActivityKey($activityKey);

            $privacy = NEWSFEED_BOL_Service::PRIVACY_EVERYBODY;

            if ( !empty($action) )
            {
                $t = OW::getEventManager()->call('plugin.privacy.get_privacy', array(
                    'ownerId' => $params['userId'],
                    'action' => $action
                ));

                $privacy = empty($t) ? $privacy : $t;
            }

            $data['params']['privacy'] = $privacy;

            $e->setData($data);
        }
    }

    private function testActivity( $activityKey )
    {
        $disbledActivity = NEWSFEED_BOL_CustomizationService::getInstance()->getDisabledEntityTypes();

        if ( empty($disbledActivity) )
        {
            return true;
        }

        return !$this->service->testActivityKey($activityKey, $disbledActivity);
    }

    public function addComment( OW_Event $e )
    {
        $this->onCommentAdd($e);

        if ( !OW::getConfig()->getValue('newsfeed', 'allow_comments') )
        {
            return;
        }

        $params = $e->getParams();

        $action = null;
        if (isset($params['action'])) {
            $action = $params['action'];
        }

        $eventParams = array(
            'entityType' => $params['entityType'],
            'entityId' => $params['entityId'],
            'userId' => $params['userId'],
            'action' => $action,
            'pluginKey' => $params['pluginKey'],
            'commentId' => $params['commentId']
        );

        $comment = BOL_CommentService::getInstance()->findComment($params['commentId']);
        $attachment = json_decode($comment->getAttachment(), true);
        if(isset($attachment["url"])) {
            $stringRenderer = OW::getEventManager()->trigger(new OW_Event(FRMEventManager::ON_BEFORE_NEWSFEED_STATUS_STRING_WRITE, array('string' => $attachment["url"])));
            if (isset($stringRenderer->getData()['string'])) {
                $attachment["url"] = $stringRenderer->getData()['string'];
            }
        }
        if(isset($attachment["href"])) {
            $stringRenderer = OW::getEventManager()->trigger(new OW_Event(FRMEventManager::ON_BEFORE_NEWSFEED_STATUS_STRING_WRITE, array('string' => $attachment["href"])));
            if (isset($stringRenderer->getData()['string'])) {
                $attachment["href"] = $stringRenderer->getData()['string'];
            }
        }
        $eventData = array(
            'message' => $comment->getMessage(),
            'attachment' => $attachment
        );

        OW::getEventManager()->trigger(new OW_Event('feed.after_comment_add', $eventParams, $eventData));
    }

    public function afterComment( OW_Event $e )
    {
        $params = $e->getParams();
        $data = $e->getData();

        $eventParams = array(
            'activityType' => 'comment',
            'activityId' => $params['commentId'],
            'entityId' => $params['entityId'],
            'entityType' => $params['entityType'],
            'userId' => $params['userId'],
            'pluginKey' => $params['pluginKey'],
            'subscribe' => true
        );

        $eventData = array(
            'commentId' => $params['commentId']
        );

        switch ( $params['entityType'] )
        {
            case 'user-status':

                $action = NEWSFEED_BOL_Service::getInstance()->findAction($params['entityType'], $params['entityId']);

                if ( empty($action) )
                {
                    return;
                }

                $actionData = json_decode($action->data, true);

                if ( empty($actionData['data']['userId']) )
                {
                    $cActivities = $this->service->findActivity( NEWSFEED_BOL_Service::SYSTEM_ACTIVITY_CREATE . ':' . $action->id);
                    $cActivity = reset($cActivities);

                    if ( empty($cActivity) )
                    {
                        return;
                    }

                    $userId = $cActivity->userId;
                }
                else
                {
                    $userId = $actionData['data']['userId'];
                }

                $eventData['string'] = array("key" => 'newsfeed+activity_string_status_comment', "vars" => array(
                    'comment' => $data['message']
                ));

                break;

            default:
                return;
        }

        OW::getEventManager()->trigger(new OW_Event('feed.activity', $eventParams, $eventData));
    }

    public function onCommentAdd( OW_Event $e )
    {
        $params = $e->getParams();

        if ( $params['entityType'] != 'base_profile_wall' )
        {
            return;
        }

        $event = new OW_Event('feed.action', $params);
        OW::getEventManager()->trigger($event);
    }

    public function deleteComment( OW_Event $e )
    {
        $params = $e->getParams();
        $commentId = $params['commentId'];

        $event = new OW_Event('feed.delete_activity', array(
            'entityType' => $params['entityType'],
            'entityId' => $params['entityId'],
            'activityType' => 'comment',
            'activityId' => $commentId
        ));
        OW::getEventManager()->trigger($event);

        if ( empty($params['entityType']) || ($params['entityType'] !== 'user-status' && $params['entityType'] !== 'groups-status') )
            return;

        OW::getEventManager()->call('notifications.remove', array(
            'entityType' => 'status_comment',
            'entityId' => $commentId
        ));
        OW::getEventManager()->call('notifications.remove', array(
            'entityType' => 'base_profile_wall',
            'entityId' => $commentId
        ));
    }

    public function addLike( OW_Event $e )
    {
        $params = $e->getParams();

        if ( $params['entityType'] != 'user-status' &&  $params['entityType'] != 'groups-status' )
        {
            return;
        }
        if (!isset($params['vote']) || ($params['vote']!=1 && $params['vote']!=-1) ){
            return;
        }
        $data = $e->getData();

        $action = NEWSFEED_BOL_Service::getInstance()->findAction($params['entityType'], $params['entityId']);

        if ( empty($action) )
        {
            return;
        }

        $actionData = json_decode($action->data, true);

        if ( empty($actionData['data']['userId']) )
        {
            $cActivities = $this->service->findActivity( NEWSFEED_BOL_Service::SYSTEM_ACTIVITY_CREATE . ':' . $action->id);
            $cActivity = reset($cActivities);

            if ( empty($cActivity) )
            {
                return;
            }

            $userId = $cActivity->userId;
        }
        else
        {
            $userId = $actionData['data']['userId'];
        }

        $eventData = $data;

        if ( $params['vote']==1 ){
            $eventData['string'] = array("key" => 'newsfeed+activity_string_status_like');
        }
        else{
            $eventData['string'] = array("key" => 'newsfeed+activity_string_status_dislike');
        }
        OW::getEventManager()->trigger(new OW_Event('feed.activity', array(
            'activityType' => 'like',
            'activityId' => $params['userId'],
            'entityId' => $params['entityId'],
            'entityType' => $params['entityType'],
            'userId' => $params['userId'],
            'pluginKey' => 'newsfeed'
        ), $eventData ));
    }

    public function removeLike( OW_Event $e )
    {
        $params = $e->getParams();

        $event = new OW_Event('feed.delete_activity', array(
            'entityType' => $params['entityType'],
            'entityId' => $params['entityId'],
            'activityType' => 'like',
            'activityId' => $params['userId']
        ));
        OW::getEventManager()->trigger($event);
    }

    public function removeActivity( OW_Event $e )
    {
        $params = $e->getParams();

        if ( isset($params['activityKey']) )
        {
            $activityKey = $params['activityKey'];
        }
        else
        {
            $keyData = array();

            foreach ( array('activityType', 'activityId', 'entityType', 'entityId', 'userId') as $item )
            {
                $keyData[$item] = empty($params[$item]) ? '*' : $params[$item];
            }

            $actionKey = empty($params['actionUniqId']) ? $keyData['entityType'] . '.' . $keyData['entityId'] : $params['actionUniqId'];
            $_activityKey = empty($params['activityUniqId']) ? $keyData['activityType'] . '.' . $keyData['activityId'] : $params['activityUniqId'];

            $activityKey = "$_activityKey:$actionKey:{$keyData['userId']}";
        }

        $this->service->removeActivity($activityKey);
    }

    public function addFollow( OW_Event $e )
    {
        $params = $e->getParams();

        $this->validateParams($params, array('feedType', 'feedId', 'userId'));

        if ( !empty($params["permission"]) )
        {
            $this->service->addFollow($params['userId'], $params['feedType'], $params['feedId'], $params["permission"]);

            return;
        }

        $event = new BASE_CLASS_EventCollector('feed.collect_follow_permissions', $params);
        OW::getEventManager()->trigger($event);

        $data = $event->getData();
        $data[] = NEWSFEED_BOL_Service::PRIVACY_EVERYBODY;

        foreach ( array_unique($data) as $permission )
        {
            $this->service->addFollow($params['userId'], $params['feedType'], $params['feedId'], $permission);
        }
    }

    public function removeFollow( OW_Event $e )
    {
        $params = $e->getParams();
        $this->validateParams($params, array('feedType', 'feedId', 'userId'));

        $permission = empty($params['permission']) ? null : $params['permission'];
        $this->service->removeFollow($params['userId'], $params['feedType'], $params['feedId'], $permission);
    }

    public function isFollow( OW_Event $e )
    {
        $params = $e->getParams();
        $this->validateParams($params, array('feedType', 'feedId', 'userId'));

        $permission = empty($params['permission']) ? NEWSFEED_BOL_Service::PRIVACY_EVERYBODY : $params['permission'];
        $result = $this->service->isFollow($params['userId'], $params['feedType'], $params['feedId'], $permission);
        $e->setData($result);

        return $result;
    }

    public function isFollowList( OW_Event $e )
    {
        $params = $e->getParams();
        $this->validateParams($params, array('feedList', 'userId'));

        $permission = empty($params['permission']) ? null : $params['permission'];
        $result = $this->service->isFollowList($params['userId'], $params['feedList'], $permission);
        $e->setData($result);

        return $result;
    }

    public function getAllFollows( OW_Event $e )
    {
        $params = $e->getParams();

        $this->validateParams($params, array('feedType', 'feedId'));

        $permission = empty($params['permission']) ? null : $params['permission'];
        $list = $this->service->findFollowList($params['feedType'], $params['feedId'], $permission);
        $out = array();

        foreach ( $list as $item )
        {
            $out[] = array(
                'userId' => $item->userId,
                'permission' => $item->permission
            );
        }

        $e->setData($out);

        return $out;
    }

    public function statusUpdate( OW_Event $e )
    {
        $params = $e->getParams();
        $data = $e->getData();

        $eventParams = array(
            'pluginKey' => 'newsfeed',
            'entityType' => $params['feedType'] . '-status',
            'entityId' => $data['statusId'],
            'feedType' => $params['feedType'],
            'feedId' => $params['feedId']
        );


        $status = nl2br($data['status']);

        /*
         * @uthor Mohammad Agha Abbasloo
         * Status of a newsfeed was written in 3 places for data column of action table
         * the value of newsfeed must be read from only one place
         */
        $content = array(
            "format" => "text",
            "vars" => array(
                //"status" => $status
            )
        );

        $contentImage = null;

        if ( !empty($data['content']) )
        {
            $data['content'] = array_merge(array(
                "title" => null,
                "description" => null,
                "thumbnail_url" => null,
                "html" => null
            ), $data['content']);

            $content["vars"]["title"] = $data['content']["title"];
            $content["vars"]["description"] = $data['content']["description"];

            $contentHref = empty($data['content']["href"]) ? null : $data['content']["href"];
            $content["vars"]["url"] = empty($data['content']["url"]) ? $contentHref : $data['content']["url"];
            if($data['content']['thumbnail_url']==null && isset($data['content']['type']) && $data['content']['type']=='link'){
                $thumbnailDefualt=OW::getEventManager()->trigger(new OW_Event('newsfeed_defualt_link_icon_renderer',array('data'=>$data)));
                if(isset($thumbnailDefualt)){
                    $thumbnailDefualtData=$thumbnailDefualt->getData();
                    $data['content']["thumbnail_url"]=$thumbnailDefualtData['data']['data']['content']['thumbnail_url'];
                };
            }
            if(!isset($data['content']['type']))
                $data['content']["type"] = 'text';
            switch ( $data['content']["type"] )
            {
                case "photo":
                    $content["format"] = "image";
                    $content["vars"]["image"] = $data['content']["url"];

                    $content["vars"]["url"] = null;

                    $contentImage = $data['content']["url"];
                    break;

                case "video":
                    $content["format"] = "video";
                    $content["vars"]["image"] = $data['content']["thumbnail_url"];
                    $content["vars"]["embed"] = $data['content']["html"];

                    $contentImage = $data['content']["thumbnail_url"];
                    break;

                case "link":
                    if ( empty($data['content']["thumbnail_url"]) )
                    {
                        $content["format"] = "content";
                    }
                    else
                    {
                        $content["format"] = "image_content";
                        $content["vars"]["image"] = $data['content']["thumbnail_url"];
                        $content["vars"]["thumbnail"] = $data['content']["thumbnail_url"];

                        $contentImage = $data['content']["thumbnail_url"];
                    }

                    break;
            }
        }

        $eventData = array_merge($data, array(
            'content' => $content,
            'time'=>time(),
            'contentImage' => $contentImage,
            'view' => array(
                'iconClass' => 'ow_ic_comment'
            ),
            'data' => array(
                'userId' => $params['userId'],
                'status' => $status
            )
        ));

        if ( $params['feedType'] == 'user' && $params['feedId'] != $params['userId'] )
        {
            $eventData['context'] = array(
                'label' => BOL_UserService::getInstance()->getDisplayName($params['feedId']),
                'url' => BOL_UserService::getInstance()->getUserUrl($params['feedId'])
            );
        }

        if ( !empty($params['visibility']) )
        {
            $eventParams['visibility'] = (int) $params['visibility'];
        }

        if ( !empty($params['userId']) )
        {
            $eventParams['userId'] = (int) $params['userId'];
        }

        if ( !empty($data['visibility']) )
        {
            $eventParams['visibility'] = (int) $data['visibility'];
        }

        OW::getEventManager()->trigger( new OW_Event('feed.action', $eventParams, $eventData) );
    }

    public function installWidget( OW_Event $e )
    {
        $params = $e->getParams();

        $widgetService = BOL_ComponentAdminService::getInstance();

        try
        {
            $widget = $widgetService->addWidget('NEWSFEED_CMP_EntityFeedWidget', false);
            $widgetPlace = $widgetService->addWidgetToPlace($widget, $params['place']);
            $widgetService->addWidgetToPosition($widgetPlace, $params['section'], $params['order']);
        }
        catch ( Exception $event )
        {
            $e->setData(false);
        }

        $e->setData($widgetPlace->uniqName);
    }

    public function deleteAction( OW_Event $e )
    {
        $params = $e->getParams();
        $this->validateParams($params, array('entityType', 'entityId'));

        $data = array(
            'entityType' => $params['entityType'],
            'entityId' => $params['entityId'],
        );
        $valid = FRMSecurityProvider::sendUsingRabbitMQ($data, 'remove_feed');
        //todo:must reverted to checking mode.
        $valid = false;
        if (!$valid) {
            $this->service->removeAction($params['entityType'], $params['entityId']);
        }
    }

    public function deleteActivity( OW_Event $e )
    {
        $params = $e->getParams();
        $this->validateParams($params, array('entityType', 'entityId', 'activityType, activityId'));

        $this->service->findActivity($params['entityType'], $params['entityId']);
    }

    public function onPluginDeactivate( OW_Event $e )
    {
        $params = $e->getParams();

        if ( $params['pluginKey'] == 'newsfeed' )
        {
            return;
        }

        $this->service->setActionStatusByPluginKey($params['pluginKey'], NEWSFEED_BOL_Service::ACTION_STATUS_INACTIVE);
    }

    public function onPluginActivate( OW_Event $e )
    {
        $params = $e->getParams();

        if ( $params['pluginKey'] == 'newsfeed' )
        {
            return;
        }

        $this->service->setActionStatusByPluginKey($params['pluginKey'], NEWSFEED_BOL_Service::ACTION_STATUS_ACTIVE);
    }

    public function onPluginUninstall( OW_Event $e )
    {
        $params = $e->getParams();

        if ( $params['pluginKey'] == 'newsfeed' )
        {
            return;
        }

        $this->service->removeActionListByPluginKey($params['pluginKey']);
    }

    public function getUserStatus( OW_Event $e )
    {
        $params = $e->getParams();

        $event = new OW_Event('feed.get_status', array(
            'feedType' => 'user',
            'feedId' => $params['userId']
        ));

        $this->getStatus($event);

        $e->setData($event->getData());
    }

    public function getStatus( OW_Event $e )
    {
        $params = $e->getParams();

        $status = $this->service->getStatus($params['feedType'], $params['feedId']);

        $e->setData($status);
    }

    public function entityAdd( OW_Event $e )
    {
        $params = $e->getParams();
        $data = $e->getData();

        if ( $params['entityType'] != 'base_profile_wall' )
        {
            return;
        }

        $comment = BOL_CommentService::getInstance()->findComment($params['commentId']);

        $attachment = empty($comment->attachment) ? null : json_decode($comment->attachment, true);

        if ( empty($attachment) )
        {
            $data['attachment'] = null;
        }
        else
        {
            $data["attachmentId"] = empty($attachment['uid']) ? null : $attachment['uid'];

            $data['attachment'] = array(
                'oembed' => $attachment,
                'attachmentId' => $data["attachmentId"],

                'url' => empty($attachment['url'])
                    ? null
                    : $attachment['url']
            );
        }

        $data['content'] = '[ph:attachment]';
        $data['string'] = strip_tags($comment->getMessage());
        $data['string'] = UTIL_HtmlTag::autoLink($data['string']);

        $data['context'] = array(
            'label' => BOL_UserService::getInstance()->getDisplayName($params['entityId']),
            'url' => BOL_UserService::getInstance()->getUserUrl($params['entityId'])
        );

        $data['params']['feedType'] = 'user';
        $data['params']['feedId'] = $params['entityId'];

        $data['params']['entityType'] = 'user-comment';
        $data['params']['entityId'] = $params['commentId'];

        $data['view'] = array(
            'iconClass' => 'ow_ic_most_discussed'
        );

        $data['features'] = array();

        $e->setData($data);
    }

    public function desktopItemRender( OW_Event $e )
    {
        $params = $e->getParams();
        $data = $e->getData();

        if ( !empty($data['attachment']) && !empty($data['attachment']['oembed']) )
        {
            $oembed = array_filter($data['attachment']['oembed']);

            if ( !empty($oembed) )
            {
                //$canDelete = $params['createActivity']['userId'] == OW::getUser()->getId();
                $canDelete = false;

                $oembedCmp = new BASE_CMP_OembedAttachment($data['attachment']['oembed'], $canDelete);
                $oembedCmp->setContainerClass('newsfeed_attachment');
                $oembedCmp->setDeleteBtnClass('newsfeed_attachment_remove');
                $data['assign']['attachment'] = array('template'=>'attachment', 'vars' => array(
                    'content' => $oembedCmp->render()
                ));
            }
        }
        if(isset($data["string"]["key"])) {
            switch ($data["string"]["key"]) {
                case "birthdays+feed_activity_birthday_string_like":
                case "birthdays+feed_activity_birthday_string":
                    if (OW::getPluginManager()->isPluginActive('birthdays')&& isset($data["userData"]["userId"])) {
                        // update userName and userUrl
                        $userName = BOL_UserService::getInstance()->getDisplayName($data["userData"]["userId"]);
                        $userUrl = BOL_UserService::getInstance()->getUserUrl($data["userData"]["userId"]);
                        $data["string"]["vars"]["user"] = '<a href="' . $userUrl . '">' . $userName . '</a>';
                    }
                    break;
                case "newsfeed+activity_string_status_comment":
                    {
                        // update userName and userUrl
                        if (isset($data["data"]["userId"])) {
                            $userName = BOL_UserService::getInstance()->getDisplayName($data["data"]["userId"]);
                            $userUrl = BOL_UserService::getInstance()->getUserUrl($data["data"]["userId"]);
                            $data["string"]["vars"]["user"] = '<a href="' . $userUrl . '">' . $userName . '</a>';
                        }
                    }
                    break;
            }
        }
        if(
            (isset($params["action"]["entityType"]) && $params["action"]["entityType"]=="groups-status") &&
            (isset($data["content"]["format"]) && $data["content"]["format"]=="text") &&
            (isset($data["contextFeedType"]) && $data["contextFeedType"]=="groups") &&
            OW::getPluginManager()->isPluginActive('groups')
        )
        {
            $groupService = GROUPS_BOL_Service::getInstance();
            $group = null;
            if (isset($params['group'])) {
                $group = $params['group'];
            }
            if ($group == null || $group->id != $data["contextFeedId"]) {
                if (isset($params['cache']['groups'][$data["contextFeedId"]])) {
                    $group = $params['cache']['groups'][$data["contextFeedId"]];
                }
                if ($group == null) {
                    $group = $groupService->findGroupById($data["contextFeedId"]);
                }
            }
            if(isset($group)) {
                $data["context"]["url"] = $groupService->getGroupUrl($group);
                $data["context"]["label"] = $group->title;
            }
        }
        elseif(isset($params["action"]["entityType"]) && $params["action"]["entityType"]=="user-status"
            && isset($data["content"]["format"]) && $data["content"]["format"]=="text" && isset($data["ReceiverId"]) )
        {
            $userName = BOL_UserService::getInstance()->getDisplayName($data["ReceiverId"]);
            $userUrl = BOL_UserService::getInstance()->getUserUrl($data["ReceiverId"]);
            $data["context"]["label"] = $userName;
            $data["context"]["url"] = $userUrl;
            $data["context"]["id"] = $data["ReceiverId"];
        }

        $e->setData($data);
    }

    public function genericItemRender( OW_Event $e )
    {
        $params = $e->getParams();
        $data = $e->getData();

        if ( in_array($params['action']['entityType'], array('user-comment', 'user-status')) && $params['feedType'] == 'user' && $params['createActivity']->userId != $params['feedId'] )
        {
            $data['context'] = null;
        }
        /*
         * most of users thought this button is supposed to kick out someone from the group but what this button really does is to delete the user account.
         * because of this confusion this feature is disabled
        $actionUserId = $userId = (int) $data['action']['userId'];
        if ( in_array($params['feedType'], array('site', 'my')) 
                && $actionUserId != OW::getUser()->getId() 
                && !BOL_AuthorizationService::getInstance()->isSuperModerator($actionUserId)
                && OW::getUser()->isAuthorized('base') )
        {
            $callbackUrl = OW_URL_HOME . OW::getRequest()->getRequestUri();
            $code='';
            $frmSecuritymanagerEvent= OW::getEventManager()->trigger(new OW_Event('frmsecurityessentials.on.generate.request.manager',
                array('senderId'=>OW::getUser()->getId(),'receiverId'=>$userId,'isPermanent'=>true,'activityType'=>'userDelete_core')));
            if(isset($frmSecuritymanagerEvent->getData()['code'])){
                $code = $frmSecuritymanagerEvent->getData()['code'];
            }
            array_unshift($data['contextMenu'], array(
                'label' => OW::getLanguage()->text('newsfeed', 'delete_feed_item_user_label'),
                'attributes' => array(
                    'onclick' => UTIL_JsGenerator::composeJsString('if ( confirm($(this).data(\'confirm-msg\')) ) OW.Users.deleteUser({$userId}, \'' . $code . '\', \'' . $callbackUrl . '\', true);', array(
                        'userId' => $actionUserId
                    )),
                    "data-confirm-msg" => OW::getLanguage()->text('base', 'are_you_sure')
                ),
                "class" => "owm_red_btn"
            ));
        }
        */
        $isFeedOwner = $params['feedType'] == "user" && $params["feedId"] == OW::getUser()->getId();
        $isStatus = in_array($params['action']['entityType'], array('user-comment', 'user-status'));

        $canRemove = OW::getUser()->isAuthenticated()
                && (
                    $params['action']['userId'] == OW::getUser()->getId()
                    || OW::getUser()->isAuthorized('newsfeed')
                    || ( $isFeedOwner && $isStatus && $params['action']['onOriginalFeed'] )
                );

        if ( $canRemove && in_array($params['feedType'], array('site', 'my', 'user', 'all')) )
        {
            array_unshift($data['contextMenu'], array(
                'label' => OW::getLanguage()->text('newsfeed', 'feed_delete_item_label'),
                'attributes' => array(
                    'data-confirm-msg' => OW::getLanguage()->text('base', 'are_you_sure')
                ),
                "class" => "newsfeed_remove_btn owm_red_btn"
            ));
        }

        $event = new OW_Event('newsfeed.generic_item_render',$params,$data);
        OW_EventManager::getInstance()->trigger($event);
        $data = $event->getData();

        $e->setData($data);

    }

    public function feedItemRenderFlagBtn( OW_Event $e )
    {
        $params = $e->getParams();
        $data = $e->getData();
        if (in_array(OW::getLanguage()->text('base', 'flag'), array_column($data['contextMenu'],'label'))) {
            return;
        }

        $userId = OW::getUser()->getId();

        if ( empty($userId) || $params['action']['userId'] == $userId )
        {
            return;
        }

        $contentType = BOL_ContentService::getInstance()->getContentTypeByEntityType($params['action']['entityType']);
        $flagsAllowed = !empty($contentType) && in_array(BOL_ContentService::MODERATION_TOOL_FLAG, $contentType["moderation"]);

        if ( !$flagsAllowed )
        {
            return;
        }

        array_unshift($data['contextMenu'], array(
            'label' => OW::getLanguage()->text('base', 'flag'),
            'attributes' => array(
                'onclick' => 'OW.flagContent($(this).data().etype, $(this).data().eid)',
                "data-etype" => $params['action']['entityType'],
                "data-eid" => $params['action']['entityId'],
                "class" => "newsfeed_flag"
            )
        ));

        $e->setData($data);
    }

    public function onFeedItemRenderContext( OW_Event $event )
    {
        $params = $event->getParams();
        $data = $event->getData();

        if ( empty($data['contextFeedType']) || $data['contextFeedType'] == $params['feedType'] )
        {
            return;
        }

        if ( $data['contextFeedType'] != "user" )
        {
            return;
        }

        $userId = (int)$data['contextFeedId'];

        $data['context'] = array(
            'label' => BOL_UserService::getInstance()->getDisplayName($userId),
            'url' => BOL_UserService::getInstance()->getUserUrl($userId)
        );

        $event->setData($data);
    }

    public function userUnregister( OW_Event $e )
    {
        $params = $e->getParams();

        if ( !isset($params['deleteContent']) || !$params['deleteContent'] )
        {
            return;
        }

        $userId = (int) $params['userId'];

        $actions = $this->service->findActionsByUserId($userId);

        foreach ( $actions as $action )
        {
            OW::getEventManager()->trigger(new OW_Event('feed.delete_item', array('entityType' => $action->entityType, 'entityId' => $action->entityId)));
        }

        BOL_VoteService::getInstance()->deleteUserVotes($userId);
        $this->service->removeActivityByUserId($userId);
    }

    public function onPrivacyChange( OW_Event $e )
    {
        $params = $e->getParams();

        $userId = (int) $params['userId'];
        $actionList = $params['actionList'];
        $actionList = is_array($actionList) ? $actionList : array();

        $privacyList = array();

        foreach ( $actionList as $action => $privacy )
        {
            $a = $this->service->getActivityKeysByPrivacyAction($action);
            foreach ( $a as $item )
            {
                $privacyList[$privacy][] = $item;
            }
        }

        foreach ( $privacyList as $privacy => $activityKeys )
        {
            $key = implode(',', array_filter($activityKeys));
            $this->service->setActivityPrivacy($key, $privacy, $userId);
        }
    }

    public function afterAppInit()
    {
        $this->service->collectPrivacy();
    }

    public function clearCache( OW_Event $e )
    {
        $params = $e->getParams();
        $this->validateParams($params, array('userId'));

        $this->service->clearUserFeedCahce($params['userId']);
    }

    public function userBlocked( OW_Event $e )
    {
        $params = $e->getParams();

        $event = new OW_Event('feed.remove_follow', array(
            'feedType' => 'user',
            'feedId' => $params['userId'],
            'userId' => $params['blockedUserId']
        ));
        OW::getEventManager()->trigger($event);

        $event = new OW_Event('feed.remove_follow', array(
            'feedType' => 'user',
            'feedId' => $params['blockedUserId'],
            'userId' => $params['userId']
        ));
        OW::getEventManager()->trigger($event);
    }

    public function onCommentNotification( OW_Event $event )
    {
        $params = $event->getParams();

        if ( $params['entityType'] != 'user-status'  && $params['entityType']!='groups-status')
        {
            return;
        }

        $userId = $params['userId'];
        $commentId = $params['commentId'];

        $userService = BOL_UserService::getInstance();

        $action = null;
        if (isset($params['action'])) {
            $action = $params['action'];
        }
        if ($action == null) {
            $action = NEWSFEED_BOL_Service::getInstance()->findAction($params['entityType'], $params['entityId']);
        }

        if ( empty($action) )
        {
            return;
        }

        $actionData = json_decode($action->data, true);
        $status = empty($actionData['data']['status'])
            ? empty($actionData['string']) ? null : $actionData['string']
            : $actionData['data']['status'];

        if ( empty($actionData['data']['userId']) )
        {
            $cActivities = $this->service->findActivity( NEWSFEED_BOL_Service::SYSTEM_ACTIVITY_CREATE . ':' . $action->id);
            $cActivity = reset($cActivities);

            if ( empty($cActivity) )
            {
                return;
            }

            $ownerId = $cActivity->userId;
        }
        else
        {
            $ownerId = $actionData['data']['userId'];
        }

        $comment = BOL_CommentService::getInstance()->findComment($commentId);

        $contentImage = null;

        if ( !empty($comment->attachment) )
        {
            $attachment = json_decode($comment->attachment, true);

            if ( !empty($attachment["thumbnail_url"]) )
            {
                $contentImage = $attachment["thumbnail_url"];
            }
            if ( $attachment["type"] == "photo" )
            {
                $contentImage = $attachment["url"];
            }
        }

        $url = OW::getRouter()->urlForRoute('newsfeed_view_item', array('actionId' => $action->id));

        if ( $ownerId != $userId )
        {
            $avatar = BOL_AvatarService::getInstance()->getDataForUserAvatars(array($userId), true, true, true, false);

            $stringKey = empty($status)
                ? 'newsfeed+email_notifications_empty_status_comment'
                : 'newsfeed+email_notifications_status_comment';

            $event = new OW_Event('notifications.add', array(
                'pluginKey' => 'newsfeed',
                'entityType' => 'status_comment',
                'entityId' => $commentId,
                'userId' => $ownerId,
                'action' => 'newsfeed-status_comment'
            ), array(
                'format' => "text",
                'avatar' => $avatar[$userId],
                'string' => array(
                    'key' => $stringKey,
                    'vars' => array(
                        'userName' => $userService->getDisplayName($userId),
                        'userUrl' => $userService->getUserUrl($userId),
                        'status' => UTIL_String::truncate(UTIL_HtmlTag::stripTags($status), 60, '...'),
                        'url' => $url,
                        'comment' => UTIL_String::truncate($comment->getMessage(), 120, '...')
                    )
                ),
                'content' => $comment->getMessage(),
                'contentImage' => $contentImage,
                'url' => $url
            ));

            OW::getEventManager()->trigger($event);
        }
    }

    public function onLikeNotification( OW_Event $event )
    {
        $params = $event->getParams();
        $data = $event->getData();

        if ( $params['entityType'] != 'user-status' )
        {
            return;
        }

        $userId = $params['userId'];
        $userService = BOL_UserService::getInstance();

        $action = NEWSFEED_BOL_Service::getInstance()->findAction($params['entityType'], $params['entityId']);

        if ( empty($action) )
        {
            return;
        }

        $actionData = json_decode($action->data, true);
        $status = null;
        if(!empty($actionData['data']['status'])){
            $status = $actionData['data']['status'];
        }else if(!empty($actionData['string'])) {
            $status = $actionData['string'];
        }
        $contentImage = empty($actionData['contentImage']) ? null : $actionData['contentImage'];

        if ( empty($actionData['data']['userId']) )
        {
            $cActivities = $this->service->findActivity( NEWSFEED_BOL_Service::SYSTEM_ACTIVITY_CREATE . ':' . $action->id);
            $cActivity = reset($cActivities);

            if ( empty($cActivity) )
            {
                return;
            }

            $ownerId = $cActivity->userId;
        }
        else
        {
            $ownerId = $actionData['data']['userId'];
        }

        $url = OW::getRouter()->urlForRoute('newsfeed_view_item', array('actionId' => $action->id));

        if ( $ownerId != $userId )
        {
            $avatar = BOL_AvatarService::getInstance()->getDataForUserAvatars(array($userId), true, true, true, false);
            if($params['vote']==1) {
                $stringKey = empty($status)
                    ? 'newsfeed+email_notifications_empty_status_like'
                    : 'newsfeed+email_notifications_status_like';
            }
            else if($params['vote']==-1){
                $stringKey = empty($status)
                    ? 'newsfeed+email_notifications_empty_status_dislike'
                    : 'newsfeed+email_notifications_status_dislike';
            }
            $event = new OW_Event('notifications.add', array(
                'pluginKey' => 'newsfeed',
                'action' => 'newsfeed-status_like',
                'entityType' => 'status_like',
                'entityId' => $data['likeId'],
                'userId' => $ownerId
            ), array(
                'format' => "text",
                'avatar' => $avatar[$userId],
                'string' => array(
                    'key' => $stringKey,
                    'vars' => array(
                        'userName' => $userService->getDisplayName($userId),
                        'userUrl' => $userService->getUserUrl($userId),
                        'url' => $url
                    )
                ),
                'url' => $url
            ));

            OW::getEventManager()->trigger($event);
        }
    }


    public function sendFeedUpdaterUsingSocket(OW_Event $event)
    {
        if (!FRMSecurityProvider::isSocketEnable())
        {
            return;
        }
        $params = $event->getParams();
        $feedType = $params['feedType'];
        $feedId =  $params['feedId'];
        $visibility = (int) $params['visibility'];
        $userId = $params['userId'];
        $userIdList = array();
        switch ($feedType){
            case 'groups':
                if (FRMSecurityProvider::checkPluginActive('groups', true)) {
                    $userIdList = GROUPS_BOL_Service::getInstance()->findGroupUserIdList($feedId);
                }
                break;
            case 'user':
                break;
            case 'event':
                break;
            case 'site':
                break;
            case 'my':
                break;
        }

        foreach ($userIdList as $receiver)
        {
            $data = array('type'=>'newsFeedUpdater');
            OW::getEventManager()->trigger(new OW_Event('base.send_data_using_socket', array('data' => $data, 'userId' => (int) $receiver)));
        }
    }
    public function userFeedStatusUpdate( OW_Event $event )
    {
        $params = $event->getParams();
        $data = $event->getData();

        if ( $params['feedType'] != 'user' )
        {
            return;
        }
        if (isset($data['status']) && isset($data['statusId']) && isset($_POST['reply_to'])) {
            NEWSFEED_BOL_Service::getInstance()->replyNotification($data['status'],$data['statusId']);
        }

        $recipientId = (int) $params['feedId'];
        $userId = (int) $params['userId'];

        if ( $recipientId == $userId )
        {
            return;
        }

        $action = NEWSFEED_BOL_Service::getInstance()->findAction('user-status', $data['statusId']);

        if ( empty($action) )
        {
            return;
        }

        $url = OW::getRouter()->urlForRoute('newsfeed_view_item', array('actionId' => $action->id));
        $actionData = json_decode($action->data, true);
        $contentImage = empty($actionData['contentImage']) ? null : $actionData['contentImage'];

        $avatarData = BOL_AvatarService::getInstance()->getDataForUserAvatars(array($userId), true, true, true, false);
        $avatar = $avatarData[$userId];

        $stringKey = 'newsfeed+notifications_user_status';

        $event = new OW_Event('notifications.add', array(
            'pluginKey' => 'newsfeed',
            'action' => 'newsfeed-user_status',
            'entityType' => 'user_status',
            'entityId' => $data['statusId'],
            'userId' => $recipientId
        ), array(
            'format' => "text",
            'avatar' => $avatar,
            'string' => array(
                'key' => $stringKey,
                'vars' => array(
                    'userName' => $avatar['title'],
                    'userUrl' => $avatar['url'],
                    'status' => UTIL_String::truncate($data['status'], 120, '...')
                )
            ),
            'content' => UTIL_String::truncate($data['status'], 100, '...'),
            'url' => $url,
            "contentImage" => $contentImage
        ));

        OW::getEventManager()->trigger($event);
    }



    public function collectNotificationActions( BASE_CLASS_EventCollector $event )
    {
        $event->add(array(
            'section' => 'newsfeed',
            'action' => 'newsfeed-status_comment',
            'sectionIcon' => 'ow_ic_clock',
            'sectionLabel' => OW::getLanguage()->text('newsfeed', 'email_notifications_section_label'),
            'description' => OW::getLanguage()->text('newsfeed', 'email_notifications_setting_status_comment'),
            'selected' => true
        ));

        $event->add(array(
            'section' => 'newsfeed',
            'action' => 'newsfeed-status_like',
            'sectionIcon' => 'ow_ic_clock',
            'sectionLabel' => OW::getLanguage()->text('newsfeed', 'email_notifications_section_label'),
            'description' => OW::getLanguage()->text('newsfeed', 'email_notifications_setting_status_like'),
            'selected' => true
        ));
        $newsfeedComponentEvent=OW_EventManager::getInstance()->trigger(new OW_Event('on.render.newsfeed.user.profile'));
        if(isset($newsfeedComponentEvent->getData()['disable']) && $newsfeedComponentEvent->getData()['disable']){
            //Do nothing
        }
        else {
            $event->add(array(
                'section' => 'newsfeed',
                'action' => 'newsfeed-user_status',
                'sectionIcon' => 'ow_ic_clock',
                'sectionLabel' => OW::getLanguage()->text('newsfeed', 'email_notifications_section_label'),
                'description' => OW::getLanguage()->text('newsfeed', 'email_notifications_setting_user_status'),
                'selected' => true
            ));
        }
        $event->add(array(
            'section' => 'newsfeed',
            'action' => 'reply-to-status',
            'description' => OW::getLanguage()->text('newsfeed', 'email_notifications_reply_to_status'),
            'selected' => true,
            'sectionLabel' => OW::getLanguage()->text('newsfeed', 'email_notifications_section_label'),
            'sectionIcon' => 'ow_ic_write'
        ));
    }

    public function getActionPermalink( OW_Event $event )
    {
        $params = $event->getParams();
        $actionId = empty($params['actionId']) ? null : $params['actionId'];

        if ( empty($actionId) && !empty($params['entityType']) && !empty($params['entityId']) )
        {
            $action = $this->service->findAction($params['entityType'], $params['entityId']);
            if ( empty($action) )
            {
                return null;
            }

            $actionId = $action->id;
        }

        if ( empty($actionId) )
        {
            return null;
        }

        $url = $this->service->getActionPermalink($actionId);
        $event->setData($url);

        return $url;
    }

    public function onCollectProfileActions( BASE_CLASS_EventCollector $event )
    {
        $params = $event->getParams();
        $userId = $params['userId'];

        if ( !OW::getUser()->isAuthenticated() || OW::getUser()->getId() == $userId )
        {
            return;
        }

        $urlParams = array(
            'userId' => $userId,
            'backUri' => OW::getRouter()->getUri()
        );

        $linkId = 'follow' . rand(10, 1000000);

        $isFollowing = NEWSFEED_BOL_Service::getInstance()->isFollow(OW::getUser()->getId(), 'user', $userId);

        $followUrl = OW::getRouter()->urlFor('NEWSFEED_CTRL_Feed', 'follow');
        $frmSecuritymanagerEvent= OW::getEventManager()->trigger(new OW_Event('frmsecurityessentials.on.generate.request.manager',
            array('senderId'=>OW::getUser()->getId(),'receiverId'=>$userId,'isPermanent'=>true,'activityType'=>'followProfile_newsfeed')));
        if(isset($frmSecuritymanagerEvent->getData()['code'])){
            $code = $frmSecuritymanagerEvent->getData()['code'];
            $urlParams['followCode']=$code;
        }
        $followUrl = OW::getRequest()->buildUrlQueryString($followUrl, $urlParams);
        $followLabel = OW::getLanguage()->text('newsfeed', 'follow_button');
        $followClass = "follow_user";

        $unfollowUrl = OW::getRouter()->urlFor('NEWSFEED_CTRL_Feed', 'unFollow');
        $frmSecuritymanagerEvent= OW::getEventManager()->trigger(new OW_Event('frmsecurityessentials.on.generate.request.manager',
            array('senderId'=>OW::getUser()->getId(),'receiverId'=>$userId,'isPermanent'=>true,'activityType'=>'unFollowProfile_newsfeed')));
        if(isset($frmSecuritymanagerEvent->getData()['code'])){
            $code = $frmSecuritymanagerEvent->getData()['code'];
            $urlParams['unFollowCode']=$code;
        }
        $unfollowUrl = OW::getRequest()->buildUrlQueryString($unfollowUrl, $urlParams);
        $unfollowLabel = OW::getLanguage()->text('newsfeed', 'unfollow_button');
        $unfollowClass = "unfollow_user";

        $script = UTIL_JsGenerator::composeJsString('
            var isFollowing = {$isFollowing};

            $("#' . $linkId . '").click(function()
            {
                if ( !isFollowing && {$isBlocked} )
                {
                    OW.error({$blockError});
                    return;
                }

                $.getJSON(isFollowing ? {$unfollowUrl} : {$followUrl}, function( r ) {
                    OW.info(r.message);
                });

                isFollowing = !isFollowing;
                $(this).text(isFollowing ? {$unfollowLabel} : {$followLabel});
                $(this).addClass(isFollowing ? {$unfollowClass} : {$followClass});
                $(this).removeClass(isFollowing ? {$followClass} : {$unfollowClass});
            });

        ', array(
            'isFollowing' => $isFollowing,
            'unfollowUrl' => $unfollowUrl,
            'followUrl' => $followUrl,
            'followLabel' => $followLabel,
            'unfollowLabel' => $unfollowLabel,
            'followClass' => $followClass,
            'unfollowClass' => $unfollowClass,
            'isBlocked' => BOL_UserService::getInstance()->isBlocked(OW::getUser()->getId(), $userId),
            'blockError' => OW::getLanguage()->text('base', 'user_block_message')
        ));

        OW::getDocument()->addOnloadScript($script);

        $event->add(array(
            BASE_CMP_ProfileActionToolbar::DATA_KEY_LABEL => $isFollowing ? $unfollowLabel : $followLabel,
            BASE_CMP_ProfileActionToolbar::DATA_KEY_LINK_HREF => 'javascript://',
            BASE_CMP_ProfileActionToolbar::DATA_KEY_LINK_ID => $linkId,
            BASE_CMP_ProfileActionToolbar::DATA_KEY_ITEM_KEY => "newsfeed.follow",
            BASE_CMP_ProfileActionToolbar::DATA_KEY_LINK_ORDER => 1,
            BASE_CMP_ProfileActionToolbar::DATA_KEY_LINK_CLASS => $isFollowing ? $unfollowClass : $followClass
        ));
    }

    function isFeedInited()
    {
        return true;
    }

    public function onCollectAuthLabels( BASE_CLASS_EventCollector $event )
    {
        $language = OW::getLanguage();
        $event->add(
            array(
                'newsfeed' => array(
                    'label' => $language->text('newsfeed', 'auth_group_label'),
                    'actions' => array(
                        'add_comment' => $language->text('newsfeed', 'auth_action_label_add_comment'),
                        'allow_status_update' => $language->text('newsfeed', 'auth_action_label_allow_status_update')
                    )
                )
            )
        );
    }

    public function onPrivacyCollectActions( BASE_CLASS_EventCollector $event )
    {
        $language = OW::getLanguage();

        $action = array(
            'key' => NEWSFEED_BOL_Service::PRIVACY_ACTION_VIEW_MY_FEED,
            'pluginKey' => 'newsfeed',
            'label' => $language->text('newsfeed', 'privacy_action_view_my_feed'),
            'description' => '',
            'defaultValue' => NEWSFEED_BOL_Service::PRIVACY_EVERYBODY,
            'sortOrder' => 1001
        );

//        $event->add($action);
    }

    private function deleteActionSet(BASE_CLASS_EventCollector $event){
        NEWSFEED_BOL_Service::getInstance()->deleteActionSetByTimestamp(time() - (60 * 60));
    }

    public function getEditedDataNotification(OW_Event $event)
    {
        $params = $event->getParams();
        $notificationData = $event->getData();
        if ($params['pluginKey'] != 'newsfeed')
            return;

        $entityType = $params['entityType'];
        $entityId =  $params['entityId'];
        if ($entityType == 'status_comment') {
            $commentService = BOL_CommentService::getInstance();
            $comment = $commentService->findComment($entityId);
            if (isset($comment)) {
                $commentEntityId = $comment->commentEntityId;
                $commentEntity = $commentService->findCommentEntityById($commentEntityId);
                if (isset($commentEntity)) {
                    $action = NEWSFEED_BOL_Service::getInstance()->findAction($commentEntity->entityType, $commentEntity->entityId);
                    if (isset($action)) {
                        $actionData = json_decode($action->data, true);
                        if (isset($actionData['status'])) {
                            $notificationData["string"]["vars"]['status'] = UTIL_String::truncate($actionData['status'], 60, '...');
                        }
                    }
                }
                $notificationData["string"]["vars"]["comment"] = UTIL_String::truncate($comment->getMessage(), 120, '...');
            }
        }
        elseif ($entityType == 'user_status'){
            $action = NEWSFEED_BOL_Service::getInstance()->findAction('user-status', $entityId);
            if (isset($action)) {
                $actionData = json_decode($action->data, true);
                $notificationData["string"]["vars"]["status"] =  UTIL_String::truncate( $actionData['status'], 120, '...' );
            }
        }

        $event->setData($notificationData);
    }

    public function genericInit()
    {
        $eventHandler = $this;

        OW::getEventManager()->bind('feed.action', array($eventHandler, 'action'));
        OW::getEventManager()->bind('feed.activity', array($eventHandler, 'activity'));
        OW::getEventManager()->bind('feed.delete_activity', array($eventHandler, 'removeActivity'));
        OW::getEventManager()->bind('feed.get_all_follows', array($eventHandler, 'getAllFollows'));
        OW::getEventManager()->bind('feed.install_widget', array($eventHandler, 'installWidget'));
        OW::getEventManager()->bind('feed.delete_item', array($eventHandler, 'deleteAction'));
        OW::getEventManager()->bind('feed.get_status', array($eventHandler, 'getStatus'));
        OW::getEventManager()->bind('feed.remove_follow', array($eventHandler, 'removeFollow'));
        OW::getEventManager()->bind('feed.is_follow', array($eventHandler, 'isFollow'));
        OW::getEventManager()->bind('feed.after_status_update', array($eventHandler, 'statusUpdate'));
        OW::getEventManager()->bind('feed.after_status_update', array($eventHandler, 'userFeedStatusUpdate'));
        OW::getEventManager()->bind('feed.after_status_update', array($eventHandler, 'sendFeedUpdaterUsingSocket'));
        OW::getEventManager()->bind('feed.after_like_added', array($eventHandler, 'addLike'));
        OW::getEventManager()->bind('feed.after_like_removed', array($eventHandler, 'removeLike'));
        OW::getEventManager()->bind('feed.add_follow', array($eventHandler, 'addFollow'));
        OW::getEventManager()->bind('feed.on_entity_add', array($eventHandler, 'entityAdd'));
        OW::getEventManager()->bind('feed.on_activity', array($eventHandler, 'onActivity'));
        OW::getEventManager()->bind('feed.after_activity', array($eventHandler, 'afterActivity'));
        OW::getEventManager()->bind('feed.get_item_permalink', array($eventHandler, 'getActionPermalink'));
        OW::getEventManager()->bind('feed.clear_cache', array($eventHandler, 'deleteActionSet'));
        OW::getEventManager()->bind('feed.after_comment_add', array($eventHandler, 'afterComment'));
        OW::getEventManager()->bind('feed.is_inited', array($eventHandler, 'isFeedInited'));
        OW::getEventManager()->bind('admin.add_auth_labels', array($eventHandler, 'onCollectAuthLabels'));
        OW::getEventManager()->bind('plugin.privacy.get_action_list', array($eventHandler, 'onPrivacyCollectActions'));
        OW::getEventManager()->bind('plugin.privacy.on_change_action_privacy', array($eventHandler, 'onPrivacyChange'));
        OW::getEventManager()->bind('base_add_comment', array($eventHandler, 'addComment'));
        OW::getEventManager()->bind('base_delete_comment', array($eventHandler, 'deleteComment'));
        OW::getEventManager()->bind(OW_EventManager::ON_USER_UNREGISTER, array($eventHandler, 'userUnregister'));
        OW::getEventManager()->bind(OW_EventManager::ON_USER_BLOCK, array($eventHandler, 'userBlocked'));
        OW::getEventManager()->bind(OW_EventManager::ON_PLUGINS_INIT, array($eventHandler, 'afterAppInit'));
        OW::getEventManager()->bind(FRMEventManager::ON_AFTER_RABITMQ_QUEUE_RELEASE, array(NEWSFEED_BOL_Service::getInstance(), 'onRabbitMQLogRelease'));
        //OW::getEventManager()->bind('base.on_get_user_status', array($eventHandler, 'getUserStatus'));
        OW::getEventManager()->bind('base_add_comment', array($eventHandler, 'onCommentNotification'));
        OW::getEventManager()->bind('feed.after_like_added', array($eventHandler, 'onLikeNotification'));
        OW::getEventManager()->bind('notifications.collect_actions', array($eventHandler, 'collectNotificationActions'));
        OW::getEventManager()->bind('feed.on_item_render', array($eventHandler, 'genericItemRender'));

        OW::getEventManager()->bind('feed.on_item_render', array($eventHandler, 'onFeedItemRenderContext'));

        OW::getEventManager()->bind('newsfeed.activity.visibility', array(NEWSFEED_BOL_CustomizationService::getInstance(), 'activityVisibility'));

        OW::getEventManager()->bind('update.group.feeds.privacy', array(NEWSFEED_BOL_Service::getInstance(), 'updateGroupFeedsPrivacy'));
        OW::getEventManager()->bind('groups.unread_count.group_user', array(NEWSFEED_BOL_Service::getInstance(), 'unreadCountForGroup'));
        OW::getEventManager()->bind('newsfeed.check.chat.form', array(NEWSFEED_BOL_Service::getInstance(), 'checkShowChatFormActive'));
        OW::getEventManager()->bind('newsfeed.check.change.hear.name', array(NEWSFEED_BOL_Service::getInstance(), 'checkChangeHeaderName'));
        OW::getEventManager()->bind('frmgroupsplus.on.channel.load', array(NEWSFEED_BOL_Service::getInstance(), 'checkShowChatFormActive'));
        OW::getEventManager()->bind('on.render.newsfeed.user.profile', array(NEWSFEED_BOL_Service::getInstance(), 'onRenderNewsfeedUserProfile'));
        OW::getEventManager()->bind('on.before.profile.view.widget.render', array(NEWSFEED_BOL_Service::getInstance(), 'addFollowersAndFollowingsEvent'));
        OW::getEventManager()->bind('notification.get_edited_data', array($this, 'getEditedDataNotification'));

        NEWSFEED_CLASS_ContentProvider::getInstance()->init();
    }
}