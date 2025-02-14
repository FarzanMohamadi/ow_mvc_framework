<?php
/**
 * @author Farzan Mohammadi <farzan.mohamadii@gmail.com>
 * @package ow.ow_plugins.event.mobile.classes
 * @since 1.6.0
 */
class EVENT_MCLASS_EventHandler
{
    /**
     * Class instance
     *
     * @var EVENT_MCLASS_EventHandler
     */
    private static $classInstance;

    /**
     * Returns class instance
     *
     * @return EVENT_MCLASS_EventHandler
     */
    public static function getInstance()
    {
        if ( !isset(self::$classInstance) )
        {
            self::$classInstance = new self();
        }

        return self::$classInstance;
    }

    public function setInvitationData( OW_Event $event )
    {
        $params = $event->getParams();
        if ( $params['entityType'] == 'event-join' )
        {
            $data = $params['data'];
            $data['string']['vars']['event'] = strip_tags($data['string']['vars']['event']);
            $data['acceptCommand'] = 'events.accept';
            $data['declineCommand'] = 'events.ignore';
            $event->setData($data);
        }
    }

    public function onCommand( OW_Event $event )
    {
        if ( !OW::getUser()->isAuthenticated() )
        {
            return;
        }

        $params = $event->getParams();

        if ( !in_array($params['command'], array('events.accept', 'events.ignore')) )
        {
            return;
        }

        $eventId = $params['data'];
        $eventDto = EVENT_BOL_EventService::getInstance()->findEvent($eventId);

        $userId = OW::getUser()->getId();
        $eventService = EVENT_BOL_EventService::getInstance();

        if ( empty($eventDto) )
        {
            BOL_InvitationService::getInstance()->deleteInvitation(
                EVENT_CLASS_InvitationHandler::INVITATION_JOIN, $eventId, $userId
            );

            return;
        }

        $lang = OW::getLanguage();
        $result = array('result' => false);

        if ( $params['command'] == 'events.accept' )
        {
            $exit = false;
            $attendedStatus = 1;

            if ( $eventService->canUserView($eventId, $userId) )
            {
                $eventDto = $eventService->findEvent($eventId);


                $eventUser = $eventService->findEventUser($eventId, $userId);

                if ( $eventUser !== null && (int) $eventUser->getStatus() === (int) $attendedStatus )
                {
                    $result['msg'] = $lang->text('event', 'user_status_not_changed_error');
                    $exit = true;
                }

                if ( $eventDto->getUserId() == OW::getUser()->getId() && (int) $attendedStatus == EVENT_BOL_EventService::USER_STATUS_NO )
                {
                    $result['msg'] = $lang->text('event', 'user_status_author_cant_leave_error');
                    $exit = true;
                }

                if ( !$exit )
                {
                    $eventUserDto = EVENT_BOL_EventService::getInstance()->addEventUser($userId, $eventId, $attendedStatus);

                    if ( !empty( $eventUserDto ) )
                    {
                        $e = new OW_Event(
                            EVENT_BOL_EventService::EVENT_ON_CHANGE_USER_STATUS,
                            array('eventId' => $eventDto->id, 'userId' => $eventUserDto->userId)
                        );
                        OW::getEventManager()->trigger($e);

                        $eventService->deleteUserEventInvites((int)$eventId, $userId);
                        $result = array('result' => true, 'msg' => $lang->text('event', 'user_status_updated'));
                        BOL_InvitationService::getInstance()->deleteInvitation(
                            EVENT_CLASS_InvitationHandler::INVITATION_JOIN, $eventId, $userId
                        );
                        OW::getEventManager()->call('notifications.remove', array(
                            'entityType' =>'event_invitation' ,
                            'entityId' => (int)$eventId
                        ));
                    }
                    else
                    {
                        $result['msg'] = $lang->text('event', 'user_status_update_error');
                    }
                }
            }
            else
            {
                $result['msg'] = $lang->text('event', 'user_status_update_error');
            }
        }
        else if ( $params['command'] == 'events.ignore' )
        {
            $eventService->deleteUserEventInvites((int)$eventId, $userId);
            $result = array('result' => true, 'msg' => $lang->text('event', 'user_status_updated'));
            BOL_InvitationService::getInstance()->deleteInvitation(
                EVENT_CLASS_InvitationHandler::INVITATION_JOIN, $eventId, $userId
            );
            OW::getEventManager()->call('notifications.remove', array(
                'entityType' =>'event_invitation' ,
                'entityId' => (int)$eventId
            ));
        }

        $event->setData($result);
    }

    public function init()
    {
        $em = OW::getEventManager();
        $em->bind('mobile.invitations.on_item_render', array($this, 'setInvitationData'));
        $em->bind('invitations.on_command', array($this, 'onCommand'));
        $em->bind('feed.on_item_render', array($this, 'onFeedItemRenderDisableActions'));
        OW::getEventManager()->bind('mobile.notifications.on_item_render', array($this, 'onNotificationRender'));
        $em->bind('base.mobile_top_menu_add_options', array($this, 'onMobileTopMenuAddLink'));
        EVENT_CLASS_InvitationHandler::getInstance()->init();
    }

    public function onFeedItemRenderDisableActions( OW_Event $event )
    {
        $params = $event->getParams();

        if ( $params["action"]["entityType"] != 'event' )
        {
            return;
        }

        $data = $event->getData();

        $data["disabled"] = false;

        $event->setData($data);
    }
    public function onMobileTopMenuAddLink( BASE_CLASS_EventCollector $event )
    {
        if ( OW::getUser()->isAuthenticated() && (OW::getUser()->isAuthorized('event', 'add_event'))){
            $id = FRMSecurityProvider::generateUniqueId('event_add');
            $status = BOL_AuthorizationService::getInstance()->getActionStatus('event', 'add_event');
            OW::getDocument()->addScriptDeclaration(
                UTIL_JsGenerator::composeJsString(
                    ';$("#" + {$btn}).on("click", function()
                {
                    OWM.showContent();
                    OWM.authorizationLimitedFloatbox({$msg});
                });',
                    array(
                        'btn' => $id,
                        'msg' => $status['msg'],
                    )
                )
            );
            $event->add(array(
                'prefix' => 'event',
                'key' => 'event_mobile',
                'id' => $id,
                'url' => OW::getRequest()->buildUrlQueryString(OW::getRouter()->urlForRoute('event.add'))
            ));
        }
    }

    public function onNotificationRender( OW_Event $e )
    {
        $params = $e->getParams();
        if ( $params['pluginKey'] != 'event' || $params['entityType'] != 'event' )
        {
            return;
        }

        $userId = $params["data"]["avatar"]["userId"];

        $userService = BOL_UserService::getInstance();
        $commentId = $params['entityId'];
        $comment = BOL_CommentService::getInstance()->findComment($commentId);
        if ( !$comment )
        {
            return;
        }
        $commEntity = BOL_CommentService::getInstance()->findCommentEntityById($comment->commentEntityId);
        if ( !$commEntity )
        {
            return;
        }
        $eventDto = EVENT_BOL_EventService::getInstance()->findEvent($commEntity->entityId);
        if ($eventDto) {
            $eventUrl = OW::getRouter()->urlForRoute('event.view', array('eventId' => $eventDto->getId()));
            if (OW::getUser()->getId() != $eventDto->userId) {
                $data = $params['data'];
                $e->setData($data);
            } else {
                $langVars = array(
                    'userName' => $userService->getDisplayName($userId),
                    'userUrl' => $userService->getUserUrl($userId),
                    'url' => $eventUrl,
                    'title' => UTIL_String::truncate( strip_tags($eventDto->getTitle()), 60, '...' ),
                    'comment' => UTIL_String::truncate( $comment->getMessage(), 120, '...' )
                );

                $data['string'] = array('key' => 'event+email_notification_comment', 'vars' => $langVars);

                //Notification on click logic is set here
                $event = new OW_Event('mobile.notification.data.received', array('pluginKey' => $params['pluginKey'],
                    'entityType' => $params['entityType'],
                    'data' => $data));
                OW::getEventManager()->trigger($event);
                if (isset($event->getData()['url'])) {
                    $data['url'] = $event->getData()['url'];
                }

                $e->setData($data);
            }
        }
    }
}