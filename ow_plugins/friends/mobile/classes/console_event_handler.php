<?php
/**
 * @author Farzan Mohammadi <farzan.mohamadii@gmail.com>
 * @package ow.ow_plugins.friends.mobile.classes
 * @since 1.6.0
 */
class FRIENDS_MCLASS_ConsoleEventHandler
{
    /**
     * Class instance
     *
     * @var FRIENDS_MCLASS_ConsoleEventHandler
     */
    private static $classInstance;

    const CONSOLE_PAGE_KEY = 'notifications';
    const CONSOLE_SECTION_KEY = 'friends';

    /**
     * Returns class instance
     *
     * @return FRIENDS_MCLASS_ConsoleEventHandler
     */
    public static function getInstance()
    {
        if ( !isset(self::$classInstance) )
        {
            self::$classInstance = new self();
        }

        return self::$classInstance;
    }

    public function collectSections( BASE_CLASS_EventCollector $event )
    {
        $params = $event->getParams();

        if ( $params['page'] == self::CONSOLE_PAGE_KEY )
        {
            $event->add(array(
                'key' => self::CONSOLE_SECTION_KEY,
                'component' => new FRIENDS_MCMP_ConsoleSection(),
                'order' => 1
            ));
        }
    }

    public function countNewItems( BASE_CLASS_EventCollector $event )
    {
        $params = $event->getParams();

        if ( $params['page'] == self::CONSOLE_PAGE_KEY )
        {
            $service = FRIENDS_BOL_Service::getInstance();
            $event->add(
                array(self::CONSOLE_SECTION_KEY => $service->count(null, OW::getUser()->getId(), FRIENDS_BOL_Service::STATUS_PENDING, null, false))
            );
        }
    }

    public function getNewItems( BASE_CLASS_EventCollector $event )
    {
        $params = $event->getParams();

        if ( $params['page'] == self::CONSOLE_PAGE_KEY )
        {
            $event->add(array(
                self::CONSOLE_SECTION_KEY => new FRIENDS_MCMP_ConsoleNewItems($params['timestamp'])
            ));
        }
    } 

    public function onMobileNotificationsRender( OW_Event $event )
    {
        $params = $event->getParams();
        if( $params['entityType'] != 'friends-accept' &&  $params['entityType'] != 'friendship')
        {
            return;
        }
        if($params['entityType'] == 'friendship'){
            $data = $params['data'];
            $event->setData($data);
        }
        else if( $params['entityType'] == 'friends-accept')
        {
            $data = $params['data'];
            if (isset($data['avatar']['urlInfo'])) {
                $url = OW::getRouter()->urlForRoute($data['avatar']['urlInfo']['routeName'], $data['avatar']['urlInfo']['vars']);
                $displayName = $data['avatar']['title'];
                $data['string']['vars']['receiver'] = '<a href="' . $url . '">' . $displayName . '</a>';
                $event->setData($data);
            }
        }
    }


    public function onActionToolbarAddFriendActionTool( BASE_CLASS_EventCollector $event )
    {
        $params = $event->getParams();

        if ( !OW::getUser()->isAuthenticated() )
        {
            return;
        }

        if ( empty($params['userId']) )
        {
            return;
        }

        if ( $params['userId'] == OW::getUser()->getId() )
        {
            return;
        }

        $userId = (int) $params['userId'];

        $language = OW::getLanguage();
        $router = OW::getRouter();
        $dto = FRIENDS_BOL_Service::getInstance()->findFriendship($userId, OW::getUser()->getId());
        $linkId = 'friendship' . rand(10, 1000000);
        
        if ( $dto === null )
        {
            if( !OW::getUser()->isAuthorized('friends', 'add_friend') && !OW::getUser()->isAdmin() )
            {
                $status = BOL_AuthorizationService::getInstance()->getActionStatus('friends', 'add_friend');
            
                if ( $status['status'] == BOL_AuthorizationService::STATUS_PROMOTED )
                {
                    $href = 'javascript://';
                    $script = '$({$link}).click(function(){
                        OWM.ajaxFloatBox(\'FRIENDS_MCMP_Notification\', [{$message}], {});
                    });';

                    $script = UTIL_JsGenerator::composeJsString($script, array('link' => '#'.$linkId, 'message' => $status['msg'] ));
                    OW::getDocument()->addOnloadScript($script);
                }
                else if ( $status['status'] != BOL_AuthorizationService::STATUS_AVAILABLE )
                {
                    return;
                }
            }
            else if ( BOL_UserService::getInstance()->isBlocked(OW::getUser()->getId(), $userId) )
            {
                $href = 'javascript://';
                $script = '$({$link}).click(function(){
                    OWM.ajaxFloatBox(\'FRIENDS_MCMP_Notification\', [{$message}], {});
                });';
                
                $script = UTIL_JsGenerator::composeJsString($script, array('link' => '#'.$linkId, 'message' => OW::getLanguage()->text('base', 'user_block_message') ));
                OW::getDocument()->addOnloadScript($script);
            }
            else
            {
                $href = $router->urlFor('FRIENDS_MCTRL_Action', 'request', array('id' => $userId));
                $frmSecuritymanagerEvent= OW::getEventManager()->trigger(new OW_Event('frmsecurityessentials.on.generate.request.manager',
                    array('senderId'=>OW::getUser()->getId(),'receiverId'=>$userId,'isPermanent'=>true,'activityType'=>'request_friends')));
                if(isset($frmSecuritymanagerEvent->getData()['code'])){
                    $code = $frmSecuritymanagerEvent->getData()['code'];
                    $href = $router->urlFor('FRIENDS_MCTRL_Action', 'request', array('id' => $userId,'code'=>$code));
                }
            }

            $label = OW::getLanguage()->text('friends', 'add_to_friends');
        }
        else
        {
            switch ( $dto->getStatus() )
            {
                case FRIENDS_BOL_Service::STATUS_ACTIVE:
                    $label = $language->text('friends', 'remove_from_friends');
                    $href = $router->urlFor('FRIENDS_MCTRL_Action', 'cancel', array('id' => $userId, 'redirect'=>true));
                    $frmSecuritymanagerEvent= OW::getEventManager()->trigger(new OW_Event('frmsecurityessentials.on.generate.request.manager',
                        array('senderId'=>OW::getUser()->getId(),'receiverId'=>$userId,'isPermanent'=>true,'activityType'=>'cancel_friends')));
                    if(isset($frmSecuritymanagerEvent->getData()['code'])){
                        $code = $frmSecuritymanagerEvent->getData()['code'];
                        $href = $router->urlFor('FRIENDS_MCTRL_Action', 'cancel', array('id' => $userId, 'redirect'=>true,'code'=>$code));
                    }
                    break;

                case FRIENDS_BOL_Service::STATUS_PENDING:

                    if ( $dto->getUserId() == OW::getUser()->getId() )
                    {
                        $label = $language->text('friends', 'remove_friendship_request');
                        $href = $router->urlFor('FRIENDS_MCTRL_Action', 'cancel', array('id' => $userId, 'redirect'=>true));
                        $frmSecuritymanagerEvent= OW::getEventManager()->trigger(new OW_Event('frmsecurityessentials.on.generate.request.manager',
                            array('senderId'=>OW::getUser()->getId(),'receiverId'=>$userId,'isPermanent'=>true,'activityType'=>'cancel_friends')));
                        if(isset($frmSecuritymanagerEvent->getData()['code'])){
                            $code = $frmSecuritymanagerEvent->getData()['code'];
                            $href = $router->urlFor('FRIENDS_MCTRL_Action', 'cancel', array('id' => $userId, 'redirect'=>true,'code'=>$code));
                        }
                    }
                    else
                    {
                        $label = $language->text('friends', 'accept_friendship_request');
                        $href = $router->urlFor('FRIENDS_MCTRL_Action', 'accept', array('id' => $userId));
                        $frmSecuritymanagerEvent= OW::getEventManager()->trigger(new OW_Event('frmsecurityessentials.on.generate.request.manager',
                            array('senderId'=>OW::getUser()->getId(),'receiverId'=>$userId,'isPermanent'=>true,'activityType'=>'accept_friends')));
                        if(isset($frmSecuritymanagerEvent->getData()['code'])){
                            $code = $frmSecuritymanagerEvent->getData()['code'];
                            $href = $router->urlFor('FRIENDS_MCTRL_Action', 'accept', array('id' => $userId,'code'=>$code));
                        }
                    }
                    break;

                case FRIENDS_BOL_Service::STATUS_IGNORED:

                    if ( $dto->getUserId() == OW::getUser()->getId() )
                    {
                        $label = $language->text('friends', 'remove_friendship_request');
                        $href = $router->urlFor('FRIENDS_MCTRL_Action', 'cancel', array('id' => $userId));
                        $frmSecuritymanagerEvent= OW::getEventManager()->trigger(new OW_Event('frmsecurityessentials.on.generate.request.manager',
                            array('senderId'=>OW::getUser()->getId(),'receiverId'=>$userId,'isPermanent'=>true,'activityType'=>'cancel_friends')));
                        if(isset($frmSecuritymanagerEvent->getData()['code'])){
                            $code = $frmSecuritymanagerEvent->getData()['code'];
                            $href = $router->urlFor('FRIENDS_MCTRL_Action', 'cancel', array('id' => $userId,'code'=>$code));
                        }
                    }
                    else
                    {
                        $label = $language->text('friends', 'add_to_friends');
                        $href = $router->urlFor('FRIENDS_MCTRL_Action', 'activate', array('id' => $userId));
                    }
            }

        }

        $resultArray = array(
            'label' => $label,
            'href' => 'javascript://',
            'id' => $linkId,
            'key' => 'friends.action',
            'order' => 1,
            'attributes' => [
                'onclick' => "confirm_redirect('".OW::getLanguage()->text('admin','are_you_sure')."', '".$href."')"
            ]
        );

        $event->add($resultArray);
//
//        $uniqId = uniqid("block-");
//        $isBlocked = BOL_UserService::getInstance()->isBlocked($userId, OW::getUser()->getId());
//
//        $resultArray["label"] = $isBlocked ? OW::getLanguage()->text('base', 'user_unblock_btn_lbl') : OW::getLanguage()->text('base', 'user_block_btn_lbl');
//
//        $toggleText = !$isBlocked ? OW::getLanguage()->text('base', 'user_unblock_btn_lbl') : OW::getLanguage()->text('base', 'user_block_btn_lbl');
//
//        $toggleClass = !$isBlocked ? 'owm_context_action_list_item' : 'owm_context_action_list_item owm_red_btn';
//
//        $resultArray["attributes"] = array();
//        $resultArray["attributes"]["data-command"] = $isBlocked ? "unblock" : "block";
//
//        $toggleCommand = !$isBlocked ? "unblock" : "block";
//
//        $resultArray["href"] = 'javascript://';
//        $resultArray["id"] = $uniqId;
//
//        $js = UTIL_JsGenerator::newInstance();
//        $js->jQueryEvent("#" . $uniqId, "click",
//            'var toggle = false; if ( $(this).attr("data-command") == "block" && confirm(e.data.msg) ) { OWM.Users.blockUser(e.data.userId); toggle = true; };
//            if ( $(this).attr("data-command") != "block") { OWM.Users.unBlockUser(e.data.userId); toggle =true;}
//            toggle && OWM.Utils.toggleText($("span:eq(0)", this), e.data.toggleText);
//            toggle && OWM.Utils.toggleAttr(this, "class", e.data.toggleClass);
//            toggle && OWM.Utils.toggleAttr(this, "data-command", e.data.toggleCommand);',
//            array("e"), array(
//                "userId" => $userId,
//                "toggleText" => $toggleText,
//                "toggleCommand" => $toggleCommand,
//                "toggleClass" => $toggleClass,
//                "msg" => strip_tags(OW::getLanguage()->text("base", "user_block_confirm_message"))
//            ));
//
//        OW::getDocument()->addOnloadScript($js);
//
//        $resultArray["key"] = "base.block_user";
//        $resultArray["group"] = "addition";
//
//        $resultArray["class"] = $isBlocked ? '' : 'owm_red_btn';
//        $resultArray["order"] = 3;
//
//        $event->add($resultArray);
    }
    
    public function init()
    {
        $em = OW::getEventManager();
        $em->bind(
            MBOL_ConsoleService::EVENT_COLLECT_CONSOLE_PAGE_SECTIONS,
            array($this, 'collectSections')
        );

        $em->bind(
            MBOL_ConsoleService::EVENT_COUNT_CONSOLE_PAGE_NEW_ITEMS,
            array($this, 'countNewItems')
        );

        $em->bind(
            MBOL_ConsoleService::EVENT_COLLECT_CONSOLE_PAGE_NEW_ITEMS,
            array($this, 'getNewItems')
        );

        $em->bind('mobile.notifications.on_item_render', array($this, 'onMobileNotificationsRender'));
        $em->bind(BASE_MCMP_ProfileActionToolbar::EVENT_NAME, array($this, 'onActionToolbarAddFriendActionTool'));

        OW::getRequestHandler()->addCatchAllRequestsExclude('base.wait_for_approval', 'FRIENDS_MCTRL_Action', 'accept');
        OW::getRequestHandler()->addCatchAllRequestsExclude('base.wait_for_approval', 'FRIENDS_MCTRL_Action', 'ignore');
        OW::getRequestHandler()->addCatchAllRequestsExclude('base.suspended_user', 'FRIENDS_MCTRL_Action', 'accept');
        OW::getRequestHandler()->addCatchAllRequestsExclude('base.suspended_user', 'FRIENDS_MCTRL_Action', 'ignore');
    }
}