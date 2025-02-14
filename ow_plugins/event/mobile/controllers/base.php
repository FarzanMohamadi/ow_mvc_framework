<?php
/**
 *
 * @package ow_plugins.event.controllers
 * @since 1.0
 */
class EVENT_MCTRL_Base extends OW_MobileActionController
{
    /**
     * @var EVENT_BOL_EventService
     */
    private $eventService;

    public function __construct()
    {
        parent::__construct();
        $this->eventService = EVENT_BOL_EventService::getInstance();
    }

    public function index()
    {
        $frmEventPlus = OW::getEventManager()->trigger(new OW_Event('eventplus.check.if.active', array('checkActive' =>true)));
        if(isset($frmEventPlus->getData()['list'])) {
            $this->redirect(OW::getRouter()->urlForRoute('event.view_event_list', array('list' => $frmEventPlus->getData()['list'])));
        }else{
            $this->redirect(OW::getRouter()->urlForRoute('event.view_event_list', array('list' =>'latest')));
        }
    }
    /**
     * View event controller
     * 
     * @param array $params
     */
    public function view( $params )
    {
        $event = $this->getEventForParams($params);

        $cmpId = UTIL_HtmlTag::generateAutoId('cmp');

        $this->assign('contId', $cmpId);

        if (FRMSecurityProvider::checkPluginActive('frmmenu', true))
            $this->assign("frmmenu_active", true);

        if ( !OW::getUser()->isAuthorized('event', 'view_event') && $event->getUserId() != OW::getUser()->getId() )
        {
            $status = BOL_AuthorizationService::getInstance()->getActionStatus('event', 'view_event');
            throw new AuthorizationException($status['msg']);
        }
        
        if ( $event->status != 1 && !OW::getUser()->isAuthorized('event') && $event->getUserId() != OW::getUser()->getId()  )
        {
            throw new Redirect404Exception();
        }

        // guest gan't view private events
        if ( (int) $event->getWhoCanView() === EVENT_BOL_EventService::CAN_VIEW_INVITATION_ONLY && !OW::getUser()->isAuthenticated() )
        {
            throw new Redirect404Exception();
        }

        $eventInvite = $this->eventService->findEventInvite($event->getId(), OW::getUser()->getId());
        $eventUser = $this->eventService->findEventUser($event->getId(), OW::getUser()->getId());

        // check if user can view event
        if ( (int) $event->getWhoCanView() === EVENT_BOL_EventService::CAN_VIEW_INVITATION_ONLY && $eventUser === null && !OW::getUser()->isAuthorized('event') )
        {
            if( $eventInvite === null ) {
                throw new Redirect404Exception();
            }else{
                $this->redirect(OW::getRouter()->urlForRoute('event.view_event_list', array('list' => 'invited')));
            }
        }

        $buttons = array();
        $toolbar = array();
        if ( OW::getUser()->isAuthorized('event') || OW::getUser()->getId() == $event->getUserId() )
        {
            $code='';
            $frmSecuritymanagerEvent= OW::getEventManager()->trigger(new OW_Event('frmsecurityessentials.on.generate.request.manager',
                array('senderId'=>OW::getUser()->getId(),'receiverId'=>$event->id,'isPermanent'=>true,'activityType'=>'delete_event')));
            if(isset($frmSecuritymanagerEvent->getData()['code'])){
                $code = $frmSecuritymanagerEvent->getData()['code'];
            }
            $buttons = array(
                'edit' => array('url' => OW::getRouter()->urlForRoute('event.edit', array('eventId' => $event->getId())), 'label' => OW::getLanguage()->text('event', 'edit_button_label')),
                'delete' =>
                    array(
                        'url' => OW::getRequest()->buildUrlQueryString(OW::getRouter()->urlForRoute('event.delete',
                            array('eventId' => $event->getId())),array('code'=>$code)),
                        'label' => OW::getLanguage()->text('event', 'delete_button_label'),
                        'confirmMessage' => OW::getLanguage()->text('event', 'delete_confirm_message')
                    )
            );
        }

        $resultsEvent = OW::getEventManager()->trigger(new OW_Event(FRMEventManager::ADD_LEAVE_BUTTON, array('eventId' => $event->getId(), 'creatorId' => $event->getUserId())));
        if(isset($resultsEvent->getData()['leaveButton'])) {
            $this->assign('leaveArray', $resultsEvent->getData()['leaveButton']);
        }
        $this->assign('editArray', $buttons);
        
        $moderationStatus = '';
        
        if ( $event->status == 2  )
        {
            $moderationStatus = " <span class='ow_remark ow_small'>(".OW::getLanguage()->text('event', 'moderation_status_pending_approval').")</span>";
        }
        
        $this->setPageHeading($event->getTitle(). $moderationStatus);
//        $this->setPageTitle(OW::getLanguage()->text('event', 'event_view_page_heading', array('event_title' => $event->getTitle())));
        $this->setPageHeadingIconClass('ow_ic_calendar');
//        OW::getDocument()->setDescription(UTIL_String::truncate(strip_tags($event->getDescription()), 200, '...'));

        $desc = $event->getDescription();
        $stringRenderer = OW::getEventManager()->trigger(new OW_Event(FRMEventManager::ON_BEFORE_RENDER_STRING, array('string' => $desc)));
        if(isset($stringRenderer->getData()['string'])){
            $desc = ($stringRenderer->getData()['string']);
        }

        $creatorName = null;
        if (OW::getConfig()->getValue('event', 'showEventCreator')) {
            $creatorName = BOL_UserService::getInstance()->getDisplayName($event->getUserId());
        }

        $infoArray = array(
            'id' => $event->getId(),
            'image' => ( $event->getImage() ? $this->eventService->generateImageUrl($event->getImage(), false) : null ),
            'date' => UTIL_DateTime::formatSimpleDate($event->getStartTimeStamp(), $event->getStartTimeDisable()),
            'endDate' => $event->getEndTimeStamp() === null || !$event->getEndDateFlag() ? null : (UTIL_DateTime::formatSimpleDate($event->getEndTimeDisable() ? strtotime("-1 day", $event->getEndTimeStamp()) : $event->getEndTimeStamp(),$event->getEndTimeDisable())),
            'location' => $event->getLocation(),
            'desc' => UTIL_HtmlTag::autoLink($desc),
            'title' => $event->getTitle(),
            'creatorName' => $creatorName,
            'creatorLink' => BOL_UserService::getInstance()->getUserUrl($event->getUserId()),
            'moderationStatus' => $event->status
        );

        $resultsEvent = OW::getEventManager()->trigger(new OW_Event(FRMEventManager::GET_EVENT_SELECTED_CATEGORY_LABEL, array('eventId' => $event->id)));
        if(isset($resultsEvent->getData()['categoryLabel'])) {
            $infoArray['categoryLabel']=$resultsEvent->getData()['categoryLabel'];
        }
        $this->assign('info', $infoArray);

        // event attend form
        if ( OW::getUser()->isAuthenticated() && $event->getEndTimeStamp() > time() )
        {
            if ( $eventUser !== null )
            {
                $this->assign('currentStatus', OW::getLanguage()->text('event', 'user_status_label_' . $eventUser->getStatus()));
            }
            $this->addForm(new AttendMobileForm($event->getId(), $cmpId));

            $onloadJs = "
                var \$context = $('#" . $cmpId . "');
                $('#event_attend_yes_btn').click(
                    function(){
                        $('input[name=attend_status]', \$context).val(" . EVENT_BOL_EventService::USER_STATUS_YES . ");
                    }
                );
                $('#event_attend_maybe_btn').click(
                    function(){
                        $('input[name=attend_status]', \$context).val(" . EVENT_BOL_EventService::USER_STATUS_MAYBE . ");
                    }
                );
                $('#event_attend_no_btn').click(
                    function(){
                        $('input[name=attend_status]', \$context).val(" . EVENT_BOL_EventService::USER_STATUS_NO . ");
                    }
                );

                $('.current_status a', \$context).click(
                    function(){
                        $('.attend_buttons .buttons', \$context).fadeIn(500);
                    }
                );
            ";

            OW::getDocument()->addOnloadScript($onloadJs);
        }
        else
        {
            $this->assign('no_attend_form', true);
        }
        
        if ($event->status == EVENT_BOL_EventService::MODERATION_STATUS_ACTIVE && (((int) $event->getUserId() === OW::getUser()->getId() || ( (int) $event->getWhoCanInvite() === EVENT_BOL_EventService::CAN_INVITE_PARTICIPANT && $eventUser !== null) ) ) )
        {
            $params = array(
                $event->id
            );

            $this->assign('inviteLink', true);
            OW::getDocument()->addOnloadScript("
                var eventFloatBox;
                $('#inviteLink').click(
                    function(){
                        eventFloatBox = OW.ajaxFloatBox('EVENT_CMP_InviteUserListSelect', " . json_encode($params) . ", {width:600, iconClass: 'ow_ic_user', title: " . json_encode(OW::getLanguage()->text('event', 'friends_invite_button_label')) . "});
                    }
                );
                OW.bind('base.avatar_user_list_select',
                    function(list){
                        eventFloatBox.close();
                        $.ajax({
                            type: 'POST',
                            url: " . json_encode(OW::getRouter()->urlFor('EVENT_MCTRL_Base', 'inviteResponder')) . ",
                            data: 'eventId=" . json_encode($event->getId()) . "&userIdList='+JSON.stringify(list),
                            dataType: 'json',
                            success : function(data){
                                if( data.messageType == 'error' ){
                                    OW.error(data.message);
                                }
                                else{
                                    OW.info(data.message);
                                }
                            },
                            error : function( XMLHttpRequest, textStatus, errorThrown ){
                                OW.error(textStatus);
                            }
                        });
                    }
                );
            ");
        }

        if ( $event->status == EVENT_BOL_EventService::MODERATION_STATUS_ACTIVE )
        {
            $cmntParams = new BASE_CommentsParams('event', 'event');
            $cmntParams->setEntityId($event->getId());
            $cmntParams->setOwnerId($event->getUserId());
            $this->addComponent('comments', new BASE_MCMP_Comments($cmntParams));
        }
        
        
        if(isset($_SERVER['HTTP_REFERER']) && $_SERVER['HTTP_REFERER']!=null){
            $this->assign('backUrl',$_SERVER['HTTP_REFERER']);
        }else {
            $this->assign('backUrl', (OW::getRouter()->urlForRoute('event.main_menu_route')));
        }

        $ev = new BASE_CLASS_EventCollector(EVENT_BOL_EventService::EVENT_COLLECT_TOOLBAR, array(
            "event" => $event
        ));

        OW::getEventManager()->trigger($ev);
        
        $this->assign("toolbar", $ev->getData());
        /*
         * add file widget
         */
        $eventIisEventPlusFiles = new OW_Event('frmeventplus.add.file.widget', array('controller' => $this, 'eventId' => $event->id));
        OW::getEventManager()->trigger($eventIisEventPlusFiles);

        $decodedString=$event->getDescription();
        $stringDecode = OW::getEventManager()->trigger(new OW_Event(FRMEventManager::ON_AFTER_NEWSFEED_STATUS_STRING_READ,array('toDecode' => $decodedString)));
        if(isset($stringDecode->getData()['decodedString'])){
            $decodedString = $stringDecode->getData()['decodedString'];
        }
        $params = array(
            "sectionKey" => "event",
            "entityKey" => "eventView",
            "title" => "event+meta_title_event_view",
            "description" => "event+meta_desc_event_view",
            "keywords" => "event+meta_keywords_event_view",
            "vars" => array( "event_title" => $event->getTitle(), "event_description" => $decodedString )
        );

        OW::getEventManager()->trigger(new OW_Event("base.provide_page_meta_info", $params));

        //set JSON-LD
        $this->eventService->addJSONLD($event);

        OW::getEventManager()->trigger(new OW_Event(EVENT_BOL_EventService::ON_BEFORE_EVENT_VIEW_RENDER));

        $this->assign("urlForBack",OW::getRouter()->urlForRoute("event.main_menu_route"));
    }
//-----------------------------------------------------------------------------------------------------

    public function getContentMenu()
    {
        $menuItems = array();

        if ( OW::getUser()->isAuthenticated() )
        {
            $listNames = array(
                'invited' => array('iconClass' => 'ow_ic_bookmark'),
                'joined' => array('iconClass' => 'ow_ic_friends'),
                'past' => array('iconClass' => 'ow_ic_reply'),
                'latest' => array('iconClass' => 'ow_ic_calendar')
            );
        }
        else
        {
            $listNames = array(
                'past' => array('iconClass' => 'ow_ic_reply'),
                'latest' => array('iconClass' => 'ow_ic_calendar')
            );
        }

        foreach ( $listNames as $listKey => $listArr )
        {
            $menuItem = new BASE_MenuItem();
            $menuItem->setKey($listKey);
            $menuItem->setUrl(OW::getRouter()->urlForRoute('event.view_event_list', array('list' => $listKey)));
            $menuItem->setLabel(OW::getLanguage()->text('event', 'common_list_type_' . $listKey . '_label'));
            $menuItem->setIconClass($listArr['iconClass']);
            $menuItems[] = $menuItem;
        }

        $event = new BASE_CLASS_EventCollector('event.add_content_menu_item');
        OW::getEventManager()->getInstance()->trigger($event);

        $data = $event->getData();

        if ( !empty($data) && is_array($data) )
        {
            $menuItems = array_merge($menuItems, $data);
        }
        $validListsEvent = OW::getEventManager()->trigger(new OW_Event(FRMEventManager::ADD_LIST_TYPE_TO_EVENT,array('menuItems' => $menuItems)));
        if(isset($validListsEvent->getData()['menuItems'])){
            $menuItems = $validListsEvent->getData()['menuItems'];
        }
        return new BASE_MCMP_ContentMenu($menuItems);
    }


    /**
     * Add new event controller
     */
    public function add()
    {
        $language = OW::getLanguage();
        $this->setPageTitle($language->text('event', 'add_page_title'));
        $this->setPageHeading($language->text('event', 'add_page_heading'));
        $this->setPageHeadingIconClass('ow_ic_add');

        OW::getDocument()->setDescription(OW::getLanguage()->text('event', 'add_event_meta_description'));

        OW::getNavigation()->activateMenuItem(OW_Navigation::MAIN, 'event', 'main_menu_item');

        // check permissions for this page
        if ( !OW::getUser()->isAuthenticated() || (!OW::getUser()->isAuthorized('event', 'add_event') && !OW::getUser()->isAuthorized('event') && !OW::getUser()->isAdmin()))
        {
            $status = BOL_AuthorizationService::getInstance()->getActionStatus('event', 'add_event');
            throw new AuthorizationException($status['msg']);
        }

        $form = new EventAddForm('event_add');

        $resultsEvent = OW::getEventManager()->trigger(new OW_Event(FRMEventManager::ADD_CATEGORY_FILTER_ELEMENT,
            array('form' =>$form)));
        if(isset($resultsEvent->getData()['form'])) {
            $this->assign('hasCategoryFilter',true);
            $form = $resultsEvent->getData()['form'];
        }
        if ( date('n', time()) == 12 && date('j', time()) == 31 )
        {
            $defaultDate = (date('Y', time()) + 1) . '/1/1';
        }
        else if ( ( date('j', time()) + 1 ) > date('t') )
        {
            $defaultDate = date('Y', time()) . '/' . ( date('n', time()) + 1 ) . '/1';
        }
        else
        {
            $defaultDate = date('Y', time()) . '/' . date('n', time()) . '/' . ( date('j', time()) + 1 );
        }

        $form->getElement('start_date')->setValue($defaultDate);
        $form->getElement('end_date')->setValue($defaultDate);
        $form->getElement('start_time')->setValue('all_day');
        $form->getElement('end_time')->setValue('all_day');

        $checkboxId = UTIL_HtmlTag::generateAutoId('chk');
        $tdId = UTIL_HtmlTag::generateAutoId('td');
        $this->assign('tdId', $tdId);
        $this->assign('chId', $checkboxId);

        $this->assign('backUrl', (OW::getRouter()->urlForRoute('event.main_menu_route')));

        OW::getDocument()->addScript(OW::getPluginManager()->getPlugin("event")->getStaticJsUrl() . 'mobile.js');
        OW::getDocument()->addOnloadScript("new eventAddForm(". json_encode(array('checkbox_id' => $checkboxId, 'end_date_id' => $form->getElement('end_date')->getId(), 'tdId' => $tdId )) .")");

        if ( OW::getRequest()->isPost() )
        {
            if ( !empty($_POST['endDateFlag']) )
            {
                $this->assign('endDateFlag', true);
            }

            if ( $form->isValid($_POST) )
            {
                $data = $form->getValues();

                $serviceEvent = new OW_Event(EVENT_BOL_EventService::EVENT_BEFORE_EVENT_CREATE, array(), $data);
                OW::getEventManager()->trigger($serviceEvent);
                $data = $serviceEvent->getData();

                $dateArray = explode('/', $data['start_date']);
                $startStamp = mktime(0, 0, 0, $dateArray[1], $dateArray[2], $dateArray[0]);
                if ( $data['start_time'] != 'all_day' )
                {
                    $startStamp = mktime($data['start_time']['hour'], $data['start_time']['minute'], 0, $dateArray[1], $dateArray[2], $dateArray[0]);
                }

                if ( !empty($_POST['endDateFlag']) && !empty($data['end_date']) )
                {
                    $dateArray = explode('/', $data['end_date']);
                    $endStamp = mktime(0, 0, 0, $dateArray[1], $dateArray[2], $dateArray[0]);

                    $endStamp = strtotime("+1 day", $endStamp);

                    if ( $data['end_time'] != 'all_day' )
                    {
                        $hour = 0;
                        $min = 0;

                        if( $data['end_time'] != 'all_day' )
                        {
                            $hour = $data['end_time']['hour'];
                            $min = $data['end_time']['minute'];
                        }
                        $dateArray = explode('/', $data['end_date']);
                        $endStamp = mktime($hour, $min, 0, $dateArray[1], $dateArray[2], $dateArray[0]);
                    }
                }

                $imageValid = true;
                $datesAreValid = true;
                $imagePosted = false;

                if ( !empty($_FILES['image']['name']) )
                {
                    if ( (int) $_FILES['image']['error'] !== 0 || !is_uploaded_file($_FILES['image']['tmp_name']) || !UTIL_File::validateImage($_FILES['image']['name']) )
                    {
                        $imageValid = false;
                        OW::getFeedback()->error($language->text('base', 'not_valid_image'));
                    }
                    else
                    {
                        $imagePosted = true;
                    }
                }

                if ( empty($endStamp) )
                {
                    $endStamp = strtotime("+1 day", $startStamp);
                    $endStamp = mktime(0, 0, 0, date('n',$endStamp), date('j',$endStamp), date('Y',$endStamp));
                }

                if ( !empty($endStamp) && $endStamp < $startStamp )
                {
                    $datesAreValid = false;
                    OW::getFeedback()->error($language->text('event', 'add_form_invalid_end_date_error_message'));
                }

                if ( $imageValid && $datesAreValid )
                {
                    $event = EVENT_BOL_EventService::getInstance()->createEvent($data, OW::getUser()->getId(), $startStamp, $endStamp, $imagePosted, !empty($_POST['endDateFlag']), $_FILES['image']['tmp_name']);
                    OW::getFeedback()->info($language->text('event', 'add_form_success_message'));
                    $this->redirect(OW::getRouter()->urlForRoute('event.view', array('eventId' => $event->getId())));
                }
            }
        }

        if( empty($_POST['endDateFlag']) )
        {
            //$form->getElement('start_time')->addAttribute('disabled', 'disabled');
            //$form->getElement('start_time')->addAttribute('style', 'display:none;');

            $form->getElement('end_date')->addAttribute('disabled', 'disabled');
            $form->getElement('end_date')->addAttribute('style', 'display:none;');

            $form->getElement('end_time')->addAttribute('disabled', 'disabled');
            $form->getElement('end_time')->addAttribute('style', 'display:none;');
        }

        $this->addForm($form);
        OW::getEventManager()->trigger(new OW_Event(EVENT_BOL_EventService::ON_BEFORE_EVENT_VIEW_RENDER));
    }

    /**
     * Update event controller
     *
     * @param array $params
     */
    public function edit( $params )
    {
        if( !OW::getUser()->isAuthenticated() )
        {
            throw new Redirect404Exception();
        }

        $userId = OW::getUser()->getId();
        $isModerator = OW::getUser()->isAuthorized('event');
        $event = $this->getEventForParams($params);

        if( $userId != $event->userId && !OW::getUser()->isAdmin() && !$isModerator)
        {
            throw new Redirect404Exception();
        }
        $this->assign('backUrl', OW::getRouter()->urlForRoute('event.view', array('eventId' => $event->getId())));

        $language = OW::getLanguage();
        $form = new EventAddForm('event_edit');
        $resultsEvent = OW::getEventManager()->trigger(new OW_Event(FRMEventManager::ADD_CATEGORY_FILTER_ELEMENT,
            array('eventId' => $event->getId(),'form' =>$form)));
        if(isset($resultsEvent->getData()['form'])) {
            $this->assign('hasCategoryFilter',true);
            $form = $resultsEvent->getData()['form'];
        }
        $form->getElement('title')->setValue($event->getTitle());
        $form->getElement('desc')->setValue($event->getDescription());
        $form->getElement('location')->setValue($event->getLocation());
        $form->getElement('who_can_view')->setValue($event->getWhoCanView());
        $form->getElement('who_can_invite')->setValue($event->getWhoCanInvite());
        $form->getElement('who_can_invite')->setValue($event->getWhoCanInvite());

        $startTimeArray = array('hour' => date('G', $event->getStartTimeStamp()), 'minute' => date('i', $event->getStartTimeStamp()));
        $form->getElement('start_time')->setValue($startTimeArray);

        $startDate = date('Y', $event->getStartTimeStamp()) . '/' . date('n', $event->getStartTimeStamp()) . '/' . date('j', $event->getStartTimeStamp());
        $form->getElement('start_date')->setValue($startDate);

        if ( $event->getEndTimeStamp() !== null )
        {
            $endTimeArray = array('hour' => date('G', $event->getEndTimeStamp()), 'minute' => date('i', $event->getEndTimeStamp()));
            $form->getElement('end_time')->setValue($endTimeArray);


            $endTimeStamp = $event->getEndTimeStamp();
            if ( $event->getEndTimeDisable() )
            {
                $endTimeStamp = strtotime("-1 day", $endTimeStamp);
            }

            $endDate = date('Y', $endTimeStamp) . '/' . date('n', $endTimeStamp) . '/' . date('j', $endTimeStamp);
            $form->getElement('end_date')->setValue($endDate);
        }

        if ( $event->getStartTimeDisable() )
        {
            $form->getElement('start_time')->setValue('all_day');
        }

        if ( $event->getEndTimeDisable() )
        {
            $form->getElement('end_time')->setValue('all_day');
        }

        $form->getSubmitElement('submit')->setValue(OW::getLanguage()->text('event', 'edit_form_submit_label'));

        $checkboxId = UTIL_HtmlTag::generateAutoId('chk');
        $tdId = UTIL_HtmlTag::generateAutoId('td');
        $this->assign('tdId', $tdId);
        $this->assign('chId', $checkboxId);

        OW::getDocument()->addScript(OW::getPluginManager()->getPlugin("event")->getStaticJsUrl() . 'mobile.js');
        OW::getDocument()->addOnloadScript("new eventAddForm(". json_encode(array('checkbox_id' => $checkboxId, 'end_date_id' => $form->getElement('end_date')->getId(), 'tdId' => $tdId )) .")");

        if ( $event->getImage() )
        {
            $this->assign('imgsrc', $this->eventService->generateImageUrl($event->getImage(), true));
        }

        $endDateFlag = $event->getEndDateFlag();

        if ( OW::getRequest()->isPost() )
        {
            $endDateFlag = !empty($_POST['endDateFlag']);

            $this->assign('endDateFlag', !empty($_POST['endDateFlag']));

            if ( $form->isValid($_POST) )
            {
                $data = $form->getValues();
                OW::getEventManager()->trigger(new OW_Event(FRMEventManager::ADD_CATEGORY_TO_EVENT, array('eventId' => $event->id, 'categoryId' => $data['categoryStatus'])));
                $serviceEvent = new OW_Event(EVENT_BOL_EventService::EVENT_BEFORE_EVENT_EDIT, array('eventId' => $event->id), $data);
                OW::getEventManager()->trigger($serviceEvent);
                $data = $serviceEvent->getData();

                $dateArray = explode('/', $data['start_date']);

                $startStamp = mktime(0, 0, 0, $dateArray[1], $dateArray[2], $dateArray[0]);

                if ( $data['start_time'] != 'all_day' )
                {
                    $startStamp = mktime($data['start_time']['hour'], $data['start_time']['minute'], 0, $dateArray[1], $dateArray[2], $dateArray[0]);
                }

                if ( !empty($_POST['endDateFlag']) && !empty($data['end_date']) )
                {
                    $dateArray = explode('/', $data['end_date']);
                    $endStamp = mktime(0, 0, 0, $dateArray[1], $dateArray[2], $dateArray[0]);
                    $endStamp = strtotime("+1 day", $endStamp);

                    if ( $data['end_time'] != 'all_day' )
                    {
                        $hour = 0;
                        $min = 0;

                        if( $data['end_time'] != 'all_day' )
                        {
                            $hour = $data['end_time']['hour'];
                            $min = $data['end_time']['minute'];
                        }
                        $dateArray = explode('/', $data['end_date']);
                        $endStamp = mktime($hour, $min, 0, $dateArray[1], $dateArray[2], $dateArray[0]);
                    }
                }

                $event->setStartTimeStamp($startStamp);

                if ( empty($endStamp) )
                {
                    $endStamp = strtotime("+1 day", $startStamp);
                    $endStamp = mktime(0, 0, 0, date('n',$endStamp), date('j',$endStamp), date('Y',$endStamp));
                }

                if ( $startStamp > $endStamp )
                {
                    OW::getFeedback()->error($language->text('event', 'add_form_invalid_end_date_error_message'));
                    $this->redirect();
                }
                else
                {
                    $event->setEndTimeStamp($endStamp);

                    if($data['deleteEventImage']==1 && empty($data['image']))
                    {
                        if ( !empty($event->image) )
                        {
                            $storage = OW::getStorage();
                            $storage->removeFile(EVENT_BOL_EventService::getInstance()->generateImagePath($event->image));
                            $storage->removeFile(EVENT_BOL_EventService::getInstance()->generateImagePath($event->image, false));
                            $event->setImage(null);
                        }
                    }
                    if ( !empty($_FILES['image']['name']) )
                    {
                        if ( (int) $_FILES['image']['error'] !== 0 || !is_uploaded_file($_FILES['image']['tmp_name']) || !UTIL_File::validateImage($_FILES['image']['name']) )
                        {
                            OW::getFeedback()->error($language->text('base', 'not_valid_image'));
                            $this->redirect();
                        }
                        else
                        {
                            $event->setImage(FRMSecurityProvider::generateUniqueId());
                            $this->eventService->saveEventImage($_FILES['image']['tmp_name'], $event->getImage());

                        }
                    }

                    $event->setTitle(UTIL_HtmlTag::stripTagsAndJs($data['title']));
                    $event->setLocation(UTIL_HtmlTag::autoLink(strip_tags($data['location'])));
                    $event->setWhoCanView((int) $data['who_can_view']);
                    $event->setWhoCanInvite((int) $data['who_can_invite']);
                    $event->setDescription($data['desc']);
                    $event->setEndDateFlag(!empty($_POST['endDateFlag']));
                    $event->setStartTimeDisable( $data['start_time'] == 'all_day' );
                    $event->setEndTimeDisable( $data['end_time'] == 'all_day' );

                    $this->eventService->saveEvent($event);

                    $e = new OW_Event(EVENT_BOL_EventService::EVENT_AFTER_EVENT_EDIT, array('eventId' => $event->id));
                    OW::getEventManager()->trigger($e);

                    OW::getFeedback()->info($language->text('event', 'edit_form_success_message'));
                    $this->redirect(OW::getRouter()->urlForRoute('event.view', array('eventId' => $event->getId())));
                }
            }
        }

        if( !$endDateFlag )
        {
            // $form->getElement('start_time')->addAttribute('disabled', 'disabled');
            // $form->getElement('start_time')->addAttribute('style', 'display:none;');

            $form->getElement('end_date')->addAttribute('disabled', 'disabled');
            $form->getElement('end_date')->addAttribute('style', 'display:none;');

            $form->getElement('end_time')->addAttribute('disabled', 'disabled');
            $form->getElement('end_time')->addAttribute('style', 'display:none;');
        }

        $this->assign('endDateFlag', $endDateFlag);

        $this->setPageHeading($language->text('event', 'edit_page_heading'));
        $this->setPageTitle($language->text('event', 'edit_page_title'));
        OW::getNavigation()->activateMenuItem(OW_Navigation::MAIN, 'event', 'main_menu_item');
        $this->addForm($form);
        OW::getEventManager()->trigger(new OW_Event(EVENT_BOL_EventService::ON_BEFORE_EVENT_VIEW_RENDER, array('eventId' => $event->getId(),
            'pageType' => "edit")));
    }

    /**
     * Delete event controller
     *
     * @param array $params
     */
    public function delete( $params )
    {
        if(!OW::getUser()->isAuthenticated())
        {
            throw new Redirect404Exception();
        }
        if(FRMSecurityProvider::checkPluginActive('frmsecurityessentials', true)) {
            $code =$_GET['code'];
            if(!isset($code)){
                throw new Redirect404Exception();
            }
            OW::getEventManager()->trigger(new OW_Event('frmsecurityessentials.on.check.request.manager',
                array('senderId' => OW::getUser()->getId(), 'code'=>$code,'activityType'=>'delete_event')));
        }
        $event = $this->getEventForParams($params);
        if (OW::getUser()->getId() != $event->getUserId() && !OW::getUser()->isAdmin()  && !OW::getUser()->isAuthorized('event') )
        {
            throw new Redirect404Exception();
        }

        $this->eventService->deleteEvent($event->getId());
        OW::getFeedback()->info(OW::getLanguage()->text('event', 'delete_success_message'));
        $this->redirect(OW::getRouter()->urlForRoute('event.main_menu_route'));
    }

    /**
     * Get event by params(eventId)
     *
     * @param array $params
     * @return EVENT_BOL_Event
     */
    private function getEventForParams( $params )
    {
        if ( empty($params['eventId']) )
        {
            throw new Redirect404Exception();
        }

        $event = $this->eventService->findEvent($params['eventId']);

        if ( $event === null )
        {
            throw new Redirect404Exception();
        }

        return $event;
    }
    /**
     * Events list controller
     * 
     * @param array $params 
     */
    public function eventsList( $params )
    {
        OW::getDocument()->setHeading(OW::getLanguage()->text('event', 'event_index'));

        if ( empty($params['list']) )
        {
            throw new Redirect404Exception();
        }

        if ( !OW::getUser()->isAuthorized('event', 'view_event') )
        {
            $status = BOL_AuthorizationService::getInstance()->getActionStatus('event', 'view_event');
            throw new AuthorizationException($status['msg']);
        }

        $configs = $this->eventService->getConfigs();
        $page = ( empty($_GET['page']) || (int) $_GET['page'] < 0 ) ? 1 : (int) $_GET['page'];

        $language = OW::getLanguage();

        $toolbarList = array();
        $resultsEvent = OW::getEventManager()->trigger(new OW_Event(FRMEventManager::ADD_LIST_TYPE_TO_EVENT, array('list' =>trim($params['list']))));
        $eventPlusTitle=false;
        if(isset($resultsEvent->getData()['list'])) {
            $params['list'] = $resultsEvent->getData()['list'];
            $this->setPageHeading($language->text('frmeventplus',  $params['list'].'_heading'));
            $this->setPageTitle($language->text('frmeventplus',  $params['list'].'_events_page_title'));
            $eventPlusTitle=true;
        }
        switch ( trim($params['list']) )
        {
            case 'created':
                if ( !OW::getUser()->isAuthenticated() )
                {
                    throw new Redirect404Exception();
                }

                $this->setPageHeading($language->text('event', 'event_created_by_me_page_heading'));
//                $this->setPageTitle($language->text('event', 'event_created_by_me_page_title'));
                $this->setPageHeadingIconClass('ow_ic_calendar');
                $events = $this->eventService->findUserEvents(OW::getUser()->getId(), $page, null);
                $eventsCount = $this->eventService->findLatestEventsCount();
                break;

            case 'joined':
                if ( !OW::getUser()->isAuthenticated() )
                {
                    throw new Redirect404Exception();
                }
                $contentMenu = $this->getContentMenu();
                $this->addComponent('contentMenu', $contentMenu);
                $this->setPageHeading($language->text('event', 'event_joined_by_me_page_heading'));
//                $this->setPageTitle($language->text('event', 'event_joined_by_me_page_title'));
                $this->setPageHeadingIconClass('ow_ic_calendar');

                $events = $this->eventService->findUserParticipatedEvents(OW::getUser()->getId(), $page, null, true);
                $eventsCount = $this->eventService->findUserParticipatedEventsCount(OW::getUser()->getId(), true);
                break;

            case 'latest':
                $contentMenu = $this->getContentMenu();
                $contentMenu->setItemActive('latest');
                $this->addComponent('contentMenu', $contentMenu);
                $this->setPageHeading($language->text('event', 'latest_events_page_heading'));
//                $this->setPageTitle($language->text('event', 'latest_events_page_title'));
                $this->setPageHeadingIconClass('ow_ic_calendar');
                OW::getDocument()->setDescription($language->text('event', 'latest_events_page_desc'));
                $events = $this->eventService->findPublicEvents($page);
                $eventsCount = $this->eventService->findPublicEventsCount();
                break;

            case 'user-participated-events':

                if ( empty($_GET['userId']) )
                {
                    throw new Redirect404Exception();
                }

                $user = BOL_UserService::getInstance()->findUserById($_GET['userId']);

                if ( $user === null )
                {
                    throw new Redirect404Exception();
                }

                $eventParams = array(
                    'action' => 'event_view_attend_events',
                    'ownerId' => $user->getId(),
                    'viewerId' => OW::getUser()->getId()
                );

                OW::getEventManager()->getInstance()->call('privacy_check_permission', $eventParams);

                $displayName = BOL_UserService::getInstance()->getDisplayName($user->getId());

                $this->setPageHeading($language->text('event', 'user_participated_events_page_heading', array('display_name' => $displayName)));
//                $this->setPageTitle($language->text('event', 'user_participated_events_page_title', array('display_name' => $displayName)));
                OW::getDocument()->setDescription($language->text('event', 'user_participated_events_page_desc', array('display_name' => $displayName)));
                $this->setPageHeadingIconClass('ow_ic_calendar');
                $events = $this->eventService->findUserParticipatedPublicEvents($user->getId(), $page);
                $eventsCount = $this->eventService->findUserParticipatedPublicEventsCount($user->getId());
                break;

            case 'past':
                $contentMenu = $this->getContentMenu();
                $this->addComponent('contentMenu', $contentMenu);
                $this->setPageHeading($language->text('event', 'past_events_page_heading'));
//                $this->setPageTitle($language->text('event', 'past_events_page_title'));
                $this->setPageHeadingIconClass('ow_ic_calendar');
//                OW::getDocument()->setDescription($language->text('event', 'past_events_page_desc'));
                $events = $this->eventService->findPublicEvents($page, null, true);
                $eventsCount = $this->eventService->findPublicEventsCount(true);
                break;

            case 'invited':
                if ( !OW::getUser()->isAuthenticated() )
                {
                    throw new Redirect404Exception();
                }

                $this->eventService->hideInvitationByUserId(OW::getUser()->getId());

                $contentMenu = $this->getContentMenu();
                $this->addComponent('contentMenu', $contentMenu);
                $this->setPageHeading($language->text('event', 'invited_events_page_heading'));
//                $this->setPageTitle($language->text('event', 'invited_events_page_title'));
                $this->setPageHeadingIconClass('ow_ic_calendar');
                $events = $this->eventService->findUserInvitedEvents(OW::getUser()->getId(), $page);
                $eventsCount = $this->eventService->findUserInvitedEventsCount(OW::getUser()->getId());
                
                foreach( $events as $event )
                {
                    $toolbarList[$event->getId()] = array();

                    $paramsList = array( 'eventId' => $event->getId(), 'page' => $page, 'list' => trim($params['list']) );

                    $acceptUrl = OW::getRequest()->buildUrlQueryString(OW::getRouter()->urlForRoute('event.invite_accept', $paramsList), array('page' => $page));
                    $ignoreUrl = OW::getRequest()->buildUrlQueryString(OW::getRouter()->urlForRoute('event.invite_decline', $paramsList), array('page' => $page));

                    $frmSecuritymanagerEvent= OW::getEventManager()->trigger(new OW_Event('frmsecurityessentials.on.generate.request.manager',
                        array('senderId'=>OW::getUser()->getId(),'receiverId'=>$event->id,'isPermanent'=>true,'activityType'=>'join_event')));
                    if(isset($frmSecuritymanagerEvent->getData()['code'])){
                        $code = $frmSecuritymanagerEvent->getData()['code'];
                        $paramsList['joinCode']=$code;
                        $acceptUrl = OW::getRequest()->buildUrlQueryString(OW::getRouter()->urlForRoute('event.invite_accept', $paramsList), array('page' => $page));
                    }


                    $frmSecuritymanagerEvent= OW::getEventManager()->trigger(new OW_Event('frmsecurityessentials.on.generate.request.manager',
                        array('senderId'=>OW::getUser()->getId(),'receiverId'=>$event->id,'isPermanent'=>true,'activityType'=>'declineInvite_event')));
                    if(isset($frmSecuritymanagerEvent->getData()['code'])){
                        $code = $frmSecuritymanagerEvent->getData()['code'];
                        $paramsList['declineCode']=$code;
                        $ignoreUrl = OW::getRequest()->buildUrlQueryString(OW::getRouter()->urlForRoute('event.invite_decline', $paramsList), array('page' => $page));
                    }

                    $toolbarList[$event->getId()][] = array('label' => $language->text('event', 'accept_request'),'href' => $acceptUrl,'id'=>'accept_'.$event->id);
                    $toolbarList[$event->getId()][] = array('label' => $language->text('event', 'ignore_request'),'href' => $ignoreUrl,'id'=>'ignore_'.$event->id);
                    
                }

                break;

            default:
                $resultsEvent = OW::getEventManager()->trigger(new OW_Event(FRMEventManager::GET_RESULT_FOR_LIST_ITEM_EVENT, array('list' =>trim($params['list']), 'eventController' => $this,'page'=>$page)));
                if(isset($resultsEvent->getData()['events']) && isset($resultsEvent->getData()['eventsCount'])) {
                    $url = OW::getRouter()->urlForRoute('event.view_event_list', array('list' =>trim($params['list'])));
                    $this->assign('url',$url);
                    $events = $resultsEvent->getData()['events'];
                    $eventsCount = $resultsEvent->getData()['eventsCount'];
                    $page=$resultsEvent->getData()['page'];
                }else {
                    throw new Redirect404Exception();
                }
        }

        foreach( $events as $event ) {
            $event->setCreateTimeStamp(UTIL_DateTime::formatDate($event->getCreateTimeStamp()));
            $avatars = BOL_AvatarService::getInstance()->getDataForUserAvatars(array($event->userId));
        }
        $this->addComponent('paging', new BASE_CMP_PagingMobile($page, ceil($eventsCount / $configs[EVENT_BOL_EventService::CONF_EVENTS_COUNT_ON_PAGE]), 5));

        if($eventPlusTitle){
            $eventForPaging = new OW_Event('eventplus.add.filter.parameters.to.paging', array('setForPaging' => true));
            OW::getEventManager()->trigger($eventForPaging);
            if(isset($eventForPaging->getData()['pagingParams'])){
                $prefixUrl = "";
                if(isset($this->getComponent('paging')->assignedVars['url'])){
                    $prefixUrl = $this->getComponent('paging')->assignedVars['url'];
                }
                $this->getComponent('paging')->assignedVars['url']= $prefixUrl . $eventForPaging->getData()['pagingParams'];
            }

        }

        $addUrl = OW::getRouter()->urlForRoute('event.add');

        $script = '$("input.add_event_button").click(function() {
                window.location='.json_encode($addUrl).';
            });';

        if ( !OW::getUser()->isAuthorized('event', 'add_event') && !OW::getUser()->isAuthorized('event') && !OW::getUser()->isAdmin())
        {
            $status = BOL_AuthorizationService::getInstance()->getActionStatus('event', 'add_event');

            if ( $status['status'] == BOL_AuthorizationService::STATUS_PROMOTED )
            {
                $script = '$("input.add_event_button").click(function() {
                        OW.authorizationLimitedFloatbox('.json_encode($status['msg']).');
                    });';
            }
            else if ( $status['status'] == BOL_AuthorizationService::STATUS_DISABLED )
            {
                $this->assign('noButton', true);
            }
        }

        OW::getDocument()->addOnloadScript($script);



        if ( empty($events) )
        {
            $this->assign('no_events', true);
        }
        
        $this->assign('listType', trim($params['list']));
        $this->assign('page', $page);
        $this->assign('events', $this->eventService->getListingDataWithToolbar($events, $toolbarList));
        $this->assign('toolbarList', $toolbarList);
        OW::getNavigation()->activateMenuItem(OW_Navigation::MAIN, 'event', 'main_menu_item');
        if(! isset($eventPlusTitle)) {
            // meta info
            $params = array(
                "sectionKey" => "event",
                "entityKey" => "eventsList",
                "title" => "event+meta_title_events_list",
                "description" => "event+meta_desc_events_list",
                "keywords" => "event+meta_keywords_events_list",
                "vars" => array("event_list" => $language->text("event", str_replace("-", "_", trim($params["list"])) . "_events_page_title"))
            );

            OW::getEventManager()->trigger(new OW_Event("base.provide_page_meta_info", $params));
        }

    }

    public function inviteListAccept( $params )
    {
        if ( !OW::getUser()->isAuthenticated() )
        {
            throw new Redirect404Exception();
        }

        $userId = OW::getUser()->getId();
        $feedback = array('messageType' => 'error');
        $exit = false;
        $attendedStatus = 1;

        if ( !empty($attendedStatus) && !empty($params['eventId']) && $this->eventService->canUserView($params['eventId'], $userId) )
        {
            $event = $this->eventService->findEvent($params['eventId']);

            if ( $event->getEndTimeStamp() < time() )
            {
                throw new Redirect404Exception();
            }

            $eventUser = $this->eventService->findEventUser($params['eventId'], $userId);

            if ( $eventUser !== null && (int) $eventUser->getStatus() === (int) $attendedStatus )
            {
                $feedback['message'] = OW::getLanguage()->text('event', 'user_status_not_changed_error');
                //exit(json_encode($feedback));
            }

            if ( $event->getUserId() == OW::getUser()->getId() && (int) $attendedStatus == EVENT_BOL_EventService::USER_STATUS_NO )
            {
                $feedback['message'] = OW::getLanguage()->text('event', 'user_status_author_cant_leave_error');
                //exit(json_encode($feedback));
            }

            if ( !$exit )
            {
                if ( $eventUser === null )
                {
                    $eventUser = new EVENT_BOL_EventUser();
                    $eventUser->setUserId($userId);
                    $eventUser->setEventId((int) $params['eventId']);
                }

                $eventUser->setStatus((int) $attendedStatus);
                $eventUser->setTimeStamp(time());
                $this->eventService->saveEventUser($eventUser);
                $this->eventService->deleteUserEventInvites((int)$params['eventId'], OW::getUser()->getId());

                $feedback['message'] = OW::getLanguage()->text('event', 'user_status_updated');
                $feedback['messageType'] = 'info';

                if ( $eventUser->getStatus() == EVENT_BOL_EventService::USER_STATUS_YES && $event->getWhoCanView() == EVENT_BOL_EventService::CAN_VIEW_ANYBODY )
                {
                    $eventTitle = $event->getTitle();
                    $eventUrl = EVENT_BOL_EventService::getInstance()->getEventUrl($event->getId());
                    $eventEmbed = '<a href="' . $eventUrl . '">' . $eventTitle . '</a>';

                    OW::getEventManager()->trigger(new OW_Event('feed.activity', array(
                            'activityType' => 'event-join',
                            'activityId' => $eventUser->getId(),
                            'entityId' => $event->getId(),
                            'entityType' => 'event',
                            'userId' => $eventUser->getUserId(),
                            'pluginKey' => 'event',
                            'visibility'=>3//VISIBILITY_SITE + VISIBILITY_FOLLOW
                            ), array(
                            'eventId' => $event->getId(),
                            'userId' => $eventUser->getUserId(),
                            'eventUserId' => $eventUser->getId(),
                            'string' =>  OW::getLanguage()->text('event', 'feed_actiovity_attend_string' ,  array( 'user' => $eventEmbed )),
                            'feature' => array()
                        )));
                }
            }
        }
        else
        {
            $feedback['message'] = OW::getLanguage()->text('event', 'user_status_update_error');
        }

        if ( !empty($feedback['message']) )
        {
            switch( $feedback['messageType'] )
            {
                case 'info':
                    OW::getFeedback()->info($feedback['message']);
                    break;
                case 'warning':
                    OW::getFeedback()->warning($feedback['message']);
                    break;
                case 'error':
                    OW::getFeedback()->error($feedback['message']);
                    break;
            }
        }

        $paramsList = array();

        if ( !empty($params['page']) )
        {
            $paramsList['page'] = $params['page'];
        }

        if ( !empty($params['list']) )
        {
            $paramsList['list'] = $params['list'];
        }
        BOL_InvitationService::getInstance()->deleteInvitation(EVENT_CLASS_InvitationHandler::INVITATION_JOIN, $event->getId(), OW::getUser()->getId());
        $this->redirect(OW::getRouter()->urlForRoute('event.view_event_list', $paramsList));
    }

    public function inviteListDecline( $params )
    {
        if ( !empty($params['eventId']) )
        {
            $this->eventService->deleteUserEventInvites((int)$params['eventId'], OW::getUser()->getId());
            OW::getLanguage()->text('event', 'user_status_updated');
        }
        else
        {
            OW::getLanguage()->text('event', 'user_status_update_error');
        }

        if ( !empty($params['page']) )
        {
            $paramsList['page'] = $params['page'];
        }

        if ( !empty($params['list']) )
        {
            $paramsList['list'] = $params['list'];
        }
        BOL_InvitationService::getInstance()->deleteInvitation(EVENT_CLASS_InvitationHandler::INVITATION_JOIN, $params['eventId'], OW::getUser()->getId());
        $this->redirect(OW::getRouter()->urlForRoute('event.view_event_list', $paramsList));
    }

    /**
     * User's events list controller
     * 
     * @param array $params 
     */
    public function eventUserLists( $params )
    {
        if ( empty($params['eventId']) || empty($params['list']) )
        {
            throw new Redirect404Exception();
        }

        $event = $this->eventService->findEvent((int) $params['eventId']);

        if ( $event === null )
        {
            throw new Redirect404Exception();
        }

        $listArray = array_flip($this->eventService->getUserListsArray());

        if ( !array_key_exists($params['list'], $listArray) )
        {
            throw new Redirect404Exception();
        }

        if ( !OW::getUser()->isAuthorized('event', 'view_event') && $event->getUserId() != OW::getUser()->getId() && !OW::getUser()->isAuthorized('event') )
        {
            $this->assign('authErrorText', OW::getLanguage()->text('event', 'event_view_permission_error_message'));
            return;
        }

        // guest gan't view private events
        if ( (int) $event->getWhoCanView() === EVENT_BOL_EventService::CAN_VIEW_INVITATION_ONLY && !OW::getUser()->isAuthenticated() )
        {
            throw new Redirect404Exception();
        }

        $eventInvite = $this->eventService->findEventInvite($event->getId(), OW::getUser()->getId());
        $eventUser = $this->eventService->findEventUser($event->getId(), OW::getUser()->getId());

        // check if user can view event
        if ( (int) $event->getWhoCanView() === EVENT_BOL_EventService::CAN_VIEW_INVITATION_ONLY && $eventUser === null && !OW::getUser()->isAuthorized('event') )
        {
            if( $eventInvite === null ) {
                throw new Redirect404Exception();
            }else{
                $this->redirect(OW::getRouter()->urlForRoute('event.view_event_list', array('list' => 'invited')));
            }
        }

        $language = OW::getLanguage();
        $configs = $this->eventService->getConfigs();
        $page = ( empty($_GET['page']) || (int) $_GET['page'] < 0 ) ? 1 : (int) $_GET['page'];
        $status = $listArray[$params['list']];
        $eventUsers = $this->eventService->findEventUsers($event->getId(), $status, $page);
        $eventUsersCount = $this->eventService->findEventUsersCount($event->getId(), $status);

        $userIdList = array();

        /* @var $eventUser EVENT_BOL_EventUser */
        foreach ( $eventUsers as $eventUser )
        {
            $userIdList[] = $eventUser->getUserId();
        }

        $userDtoList = BOL_UserService::getInstance()->findUserListByIdList($userIdList);

        $this->addComponent('users', new EVENT_CMP_EventUsersList($userDtoList, $eventUsersCount, $configs[EVENT_BOL_EventService::CONF_EVENT_USERS_COUNT_ON_PAGE], true));

        $this->setPageHeading($language->text('event', 'user_list_page_heading_' . $status, array('eventTitle' => $event->getTitle())));

        OW::getNavigation()->activateMenuItem(OW_Navigation::MAIN, 'event', 'main_menu_item');
        $this->assign("eventId", $event->id);

        // meta info
        $params = array(
            "sectionKey" => "event",
            "entityKey" => "eventUsers",
            "title" => "event+meta_title_event_users",
            "description" => "event+meta_desc_event_users",
            "keywords" => "event+meta_keywords_event_users",
            "vars" => array("event_title" => $event->getTitle())
        );

        OW::getEventManager()->trigger(new OW_Event("base.provide_page_meta_info", $params));

    }

    public function privateEvent( $params )
    {
        $language = OW::getLanguage();

        $this->setPageTitle($language->text('event', 'private_page_title'));
        $this->setPageHeading($language->text('event', 'private_page_heading'));
        $this->setPageHeadingIconClass('ow_ic_lock');

        $eventId = $params['eventId'];
        $event = $this->eventService->findEvent((int) $eventId);

        $avatarList = BOL_AvatarService::getInstance()->getDataForUserAvatars(array($event->userId));
        $displayName = BOL_UserService::getInstance()->getDisplayName($event->userId);
        $userUrl = BOL_UserService::getInstance()->getUserUrl($event->userId);

        $this->assign('event', $event);
        $this->assign('avatar', $avatarList[$event->userId]);
        $this->assign('displayName', $displayName);
        $this->assign('userUrl', $userUrl);
        $this->assign('creator', $language->text('event', 'creator'));
    }
    
    /**
     * Responder for event attend form
     */
    public function attendFormResponder()
    {
        if ( !OW::getRequest()->isAjax() || !OW::getUser()->isAuthenticated() )
        {
            throw new Redirect404Exception();
        }

        $userId = OW::getUser()->getId();
        $respondArray = array('messageType' => 'error');
        
        if ( !empty($_POST['attend_status']) && in_array((int) $_POST['attend_status'], array(1, 2, 3)) && !empty($_POST['eventId']) && $this->eventService->canUserView($_POST['eventId'], $userId) )
        {
            $event = $this->eventService->findEvent($_POST['eventId']);
            
            if ( $event->getEndTimeStamp() < time() )
            {
                throw new Redirect404Exception();
            }

            $eventUser = $this->eventService->findEventUser($_POST['eventId'], $userId);

            /*if ( $eventUser !== null && (int) $eventUser->getStatus() === (int) $_POST['attend_status'] )
            {
                $respondArray['message'] = OW::getLanguage()->text('event', 'user_status_not_changed_error');
                exit(json_encode($respondArray));
            }*/

            if ( $event->getUserId() == OW::getUser()->getId() && (int) $_POST['attend_status'] == EVENT_BOL_EventService::USER_STATUS_NO )
            {
                $respondArray['message'] = OW::getLanguage()->text('event', 'user_status_author_cant_leave_error');
                exit(json_encode($respondArray));
            }

            if ( $eventUser === null )
            {
                $eventUser = new EVENT_BOL_EventUser();
                $eventUser->setUserId($userId);
                $eventUser->setEventId((int) $_POST['eventId']);
            }

            $eventUser->setStatus((int) $_POST['attend_status']);
            $eventUser->setTimeStamp(time());
            $this->eventService->saveEventUser($eventUser);

            $this->eventService->deleteUserEventInvites((int)$_POST['eventId'], OW::getUser()->getId());

            $e = new OW_Event(EVENT_BOL_EventService::EVENT_ON_CHANGE_USER_STATUS, array('eventId' => $event->id, 'userId' => $eventUser->userId));
            OW::getEventManager()->trigger($e);
            
            $respondArray['message'] = OW::getLanguage()->text('event', 'user_status_updated');
            $respondArray['messageType'] = 'info';
            $respondArray['currentLabel'] = OW::getLanguage()->text('event', 'user_status_label_' . $eventUser->getStatus());
            $respondArray['eventId'] = (int) $_POST['eventId'];
            $respondArray['newInvCount'] = $this->eventService->findUserInvitedEventsCount(OW::getUser()->getId());

            if ( $eventUser->getStatus() == EVENT_BOL_EventService::USER_STATUS_YES && $event->getWhoCanView() == EVENT_BOL_EventService::CAN_VIEW_ANYBODY )
            {
                $eventTitle = $event->getTitle();
                $eventUrl = EVENT_BOL_EventService::getInstance()->getEventUrl($event->getId());
                $eventEmbed = '<a href="' . $eventUrl . '">' . $eventTitle . '</a>';

                OW::getEventManager()->trigger(new OW_Event('feed.activity', array(
                        'activityType' => 'event-join',
                        'activityId' => $eventUser->getId(),
                        'entityId' => $event->getId(),
                        'entityType' => 'event',
                        'userId' => $eventUser->getUserId(),
                        'pluginKey' => 'event'
                        ), array(
                        'eventId' => $event->getId(),
                        'userId' => $eventUser->getUserId(),
                        'eventUserId' => $eventUser->getId(),
                        'string' =>  OW::getLanguage()->text('event', 'feed_actiovity_attend_string' ,  array( 'user' => $eventEmbed )),
                        'feature' => array()
                    )));
            }
        }
        else
        {
            $respondArray['message'] = OW::getLanguage()->text('event', 'user_status_update_error');
        }

        exit(json_encode($respondArray));
    }

    /**
     * Responder for event invite form
     */
    public function inviteResponder()
    {
        $respondArray = array();

        if ( empty($_POST['eventId']) || empty($_POST['userIdList']) || !OW::getUser()->isAuthenticated() )
        {
            $respondArray['messageType'] = 'error';
            $respondArray['message'] = '_ERROR_';
            echo json_encode($respondArray);
            exit;
        }

        $idList = json_decode($_POST['userIdList']);

        if ( empty($_POST['eventId']) || empty($idList) )
        {
            $respondArray['messageType'] = 'error';
            $respondArray['message'] = '_EMPTY_EVENT_ID_';
            echo json_encode($respondArray);
            exit;
        }

        $event = $this->eventService->findEvent($_POST['eventId']);


        if ( $event === null )
        {
            $respondArray['messageType'] = 'error';
            $respondArray['message'] = '_EMPTY_EVENT_';
            echo json_encode($respondArray);
            exit;
        }

        if ( (int) $event->getUserId() === OW::getUser()->getId() || (int) $event->getWhoCanInvite() === EVENT_BOL_EventService::CAN_INVITE_PARTICIPANT )
        {
            $count = 0;

            $userList = BOL_UserService::getInstance()->findUserListByIdList($idList);

            foreach ( $userList as $user )
            {
                $userId = $user->id;
                $eventInvite = $this->eventService->findEventInvite($event->getId(), $userId);

                if ( $eventInvite === null )
                {
                    $eventInvite = $this->eventService->inviteUser($event->getId(), $userId, OW::getUser()->getId());
                    $eventObj = new OW_Event('event.invite_user', array('userId' => $userId, 'inviterId' => OW::getUser()->getId(), 'eventId' => $event->getId(), 'imageId' => $event->getImage(), 'eventTitle' => $event->getTitle(), 'eventDesc' => $event->getDescription(), 'displayInvitation' => $eventInvite->displayInvitation));
                    OW::getEventManager()->trigger($eventObj);
                    $count++;
                }
            }
        }

        $respondArray['messageType'] = 'info';
        $respondArray['message'] = OW::getLanguage()->text('event', 'users_invite_success_message', array('count' => $count));

        exit(json_encode($respondArray));
    }
    
    public function approve( $params )
    {
        $entityId = $params["eventId"];
        $entityType = EVENT_CLASS_ContentProvider::ENTITY_TYPE;
        
        $backUrl = OW::getRouter()->urlForRoute("event.view", array(
            "eventId" => $entityId
        ));
        
        $event = new OW_Event("moderation.approve", array(
            "entityType" => $entityType,
            "entityId" => $entityId
        ));
        
        OW::getEventManager()->trigger($event);
        
        $data = $event->getData();
        if ( empty($data) )
        {
            $this->redirect($backUrl);
        }
        
        if ( $data["message"] )
        {
            OW::getFeedback()->info($data["message"]);
        }
        else
        {
            OW::getFeedback()->error($data["error"]);
        }
        
        $this->redirect($backUrl);
    }
}

/**
 * Event attend form
 * @package ow_plugins.event.forms
 * @since 1.0
 */
class AttendMobileForm extends Form
{

    public function __construct( $eventId, $contId )
    {
        parent::__construct('event_attend');
        $this->setAction(OW::getRouter()->urlFor('EVENT_CTRL_Base', 'attendFormResponder'));
        $this->setAjax();
        $hidden = new HiddenField('attend_status');
        $this->addElement($hidden);
        $eventIdField = new HiddenField('eventId');
        $eventIdField->setValue($eventId);
        $this->addElement($eventIdField);

        $submit = new Submit('submit');
        $this->addElement($submit);

        $this->setAjaxResetOnSuccess(false);
        $this->bindJsFunction(Form::BIND_SUCCESS, "function(data){
            var \$context = $('#" . $contId . "');

            

            if(data.messageType == 'error'){
                OW.error(data.message);
            }
            else{
                $('.current_status span.status', \$context).empty().html(data.currentLabel);
                $('.current_status span.link', \$context).css({display:'inline'});
                $('.attend_buttons .buttons', \$context).fadeOut(500);

                if ( data.eventId != 'undefuned' )
                {
                    OW.loadComponent('EVENT_CMP_EventUsers', {eventId: data.eventId},
                    {
                      onReady: function( html ){
                         $('.userList', \$context).empty().html(html);

                      }
                    });
                }

                $('.userList', \$context).empty().html(data.eventUsersCmp);
                OW.trigger('event_notifications_update', {count:data.newInvCount});
                OW.info(data.message);
            }
        }");
    }
}

class EventAddForm extends Form
{

    const EVENT_NAME = 'event.event_add_form.get_element';

    public function __construct( $name )
    {
        parent::__construct($name);

        $militaryTime = OW::getConfig()->getValue('base', 'military_time');

        $language = OW::getLanguage();

        $currentYear = date('Y', time());
        if(OW::getConfig()->getValue('frmjalali', 'dateLocale')==1){
            $currentYear=$currentYear-1;
        }
        $title = new TextField('title');
        $title->setRequired();
        $title->setLabel($language->text('event', 'add_form_title_label'));

        $event = new OW_Event(self::EVENT_NAME, array( 'name' => 'title' ), $title);
        OW::getEventManager()->trigger($event);
        $title = $event->getData();

        $this->addElement($title);

        $startDate = new DateField('start_date');
        $startDate->setMinYear($currentYear);
        $startDate->setMaxYear($currentYear + 5);
        $startDate->setRequired();

        $event = new OW_Event(self::EVENT_NAME, array( 'name' => 'start_date' ), $startDate);
        OW::getEventManager()->trigger($event);
        $startDate = $event->getData();

        $this->addElement($startDate);

        $startTime = new EventTimeField('start_time');
        $startTime->setMilitaryTime($militaryTime);

        if ( !empty($_POST['endDateFlag']) )
        {
            $startTime->setRequired();
        }

        $event = new OW_Event(self::EVENT_NAME, array( 'name' => 'start_time' ), $startTime);
        OW::getEventManager()->trigger($event);
        $startTime = $event->getData();

        $this->addElement($startTime);

        $endDate = new DateField('end_date');
        $endDate->setMinYear($currentYear);
        $endDate->setMaxYear($currentYear + 5);

        $event = new OW_Event(self::EVENT_NAME, array( 'name' => 'end_date' ), $endDate);
        OW::getEventManager()->trigger($event);
        $endDate = $event->getData();

        $this->addElement($endDate);

        $endTime = new EventTimeField('end_time');
        $endTime->setMilitaryTime($militaryTime);

        $event = new OW_Event(self::EVENT_NAME, array( 'name' => 'end_time' ), $endTime);
        OW::getEventManager()->trigger($event);
        $endTime = $event->getData();

        $this->addElement($endTime);

        $location = new TextField('location');
        $location->setRequired();
        $location->setLabel($language->text('event', 'add_form_location_label'));

        $event = new OW_Event(self::EVENT_NAME, array( 'name' => 'location' ), $location);
        OW::getEventManager()->trigger($event);
        $location = $event->getData();

        $this->addElement($location);

        $whoCanView = new RadioField('who_can_view');
        $whoCanView->setRequired();
        $whoCanView->addOptions(
            array(
                '1' => $language->text('event', 'add_form_who_can_view_option_anybody'),
                '2' => $language->text('event', 'add_form_who_can_view_option_invit_only')
            )
        );
        $whoCanView->setLabel($language->text('event', 'add_form_who_can_view_label'));

        $event = new OW_Event(self::EVENT_NAME, array( 'name' => 'who_can_view' ), $whoCanView);
        OW::getEventManager()->trigger($event);
        $whoCanView = $event->getData();

        $this->addElement($whoCanView);

        $whoCanInvite = new RadioField('who_can_invite');
        $whoCanInvite->setRequired();
        $whoCanInvite->addOptions(
            array(
                EVENT_BOL_EventService::CAN_INVITE_PARTICIPANT => $language->text('event', 'add_form_who_can_invite_option_participants'),
                EVENT_BOL_EventService::CAN_INVITE_CREATOR => $language->text('event', 'add_form_who_can_invite_option_creator')
            )
        );
        $whoCanInvite->setLabel($language->text('event', 'add_form_who_can_invite_label'));

        $event = new OW_Event(self::EVENT_NAME, array( 'name' => 'who_can_invite' ), $whoCanInvite);
        OW::getEventManager()->trigger($event);
        $whoCanInvite = $event->getData();

        $this->addElement($whoCanInvite);

        $deleteImageField = new HiddenField('deleteEventImage');
        $deleteImageField->setId('deleteEventImage');
        $deleteImageField->setValue('false');
        $this->addElement($deleteImageField);

        $submit = new Submit('submit');
        $submit->setValue($language->text('event', 'add_form_submit_label'));
        $this->addElement($submit);

        $desc = new MobileWysiwygTextarea('desc','event',array(
            BOL_TextFormatService::WS_BTN_BOLD,
            BOL_TextFormatService::WS_BTN_ITALIC,
            BOL_TextFormatService::WS_BTN_UNDERLINE,
            BOL_TextFormatService::WS_BTN_LINK,
        ));
        $desc->setLabel($language->text('event', 'add_form_desc_label'));
        $desc->setRequired();


        $event = new OW_Event(self::EVENT_NAME, array( 'name' => 'desc' ), $desc);
        OW::getEventManager()->trigger($event);
        $desc = $event->getData();

        $this->addElement($desc);

        $imageField = new FileField('image');
        $imageField->setLabel($language->text('event', 'add_form_image_label'));
        $this->addElement($imageField);

        $event = new OW_Event(self::EVENT_NAME, array( 'name' => 'image' ), $imageField);
        OW::getEventManager()->trigger($event);
        $imageField = $event->getData();

        $this->setEnctype(Form::ENCTYPE_MULTYPART_FORMDATA);
    }
}

/**
 * Form element: CheckboxField.
 *
 * @package ow_core
 * @since 1.0
 */
class EventTimeField extends FormElement
{
    private $militaryTime;

    private $allDay = false;

    /**
     * Constructor.
     *
     * @param string $name
     */
    public function __construct( $name )
    {
        parent::__construct($name);
        $this->militaryTime = false;
    }

    public function setMilitaryTime( $militaryTime )
    {
        $this->militaryTime = (bool) $militaryTime;
    }

    public function setValue( $value )
    {
        if ( $value === null )
        {
            $this->value = null;
        }

        $this->allDay = false;

        if ( $value === 'all_day' )
        {
            $this->allDay = true;
            $this->value = null;
            return;
        }

        if ( is_array($value) && isset($value['hour']) && isset($value['minute']) )
        {
            $this->value = array_map('intval', $value);
        }

        if ( is_string($value) && strstr($value, ':') )
        {
            $parts = explode(':', $value);
            $this->value['hour'] = (int) $parts[0];
            $this->value['minute'] = (int) $parts[1];
        }
    }

    public function getValue()
    {
        if ( $this->allDay === true )
        {
            return 'all_day';
        }

        return $this->value;
    }

    /**
     *
     * @return string
     */
    public function getElementJs()
    {
        $jsString = "var formElement = new OwFormElement('" . $this->getId() . "', '" . $this->getName() . "');";

        return $jsString.$this->generateValidatorAndFilterJsCode("formElement");
    }

    private function getTimeString( $hour, $minute )
    {
        if ( $this->militaryTime )
        {
            $hour = $hour < 10 ? '0' . $hour : $hour;
            return $hour . ':' . $minute;
        }
        else
        {
            if ( $hour == 12 )
            {
                $dp = 'pm';
            }
            else if ( $hour > 12 )
            {
                $hour = $hour - 12;
                $dp = 'pm';
            }
            else
            {
                $dp = 'am';
            }

            $hour = $hour < 10 ? '0' . $hour : $hour;
            return $hour . ':' . $minute . $dp;
        }
    }

    /**
     * @see FormElement::renderInput()
     *
     * @param array $params
     * @return string
     */
    public function renderInput( $params = null )
    {
        parent::renderInput($params);

        for ( $hour = 0; $hour <= 23; $hour++ )
        {
            $valuesArray[$hour . ':0'] = array('label' => $this->getTimeString($hour, '00'), 'hour' => $hour, 'minute' => 0);
            $valuesArray[$hour . ':30'] = array('label' => $this->getTimeString($hour, '30'), 'hour' => $hour, 'minute' => 30);
        }

        $optionsString = UTIL_HtmlTag::generateTag('option', array('value' => ""), true, OW::getLanguage()->text('event', 'time_field_invitation_label'));

        $allDayAttrs = array( 'value' => "all_day"  );

        if ( $this->allDay )
        {
            $allDayAttrs['selected'] = 'selected';
        }

        $optionsString = UTIL_HtmlTag::generateTag('option', $allDayAttrs, true, OW::getLanguage()->text('event', 'all_day'));

        foreach ( $valuesArray as $value => $labelArr )
        {
            $attrs = array('value' => $value);

            if ( !empty($this->value) && $this->value['hour'] === $labelArr['hour'] && $this->value['minute'] === $labelArr['minute'] )
            {
                $attrs['selected'] = 'selected';
            }

            $optionsString .= UTIL_HtmlTag::generateTag('option', $attrs, true, $labelArr['label']);
        }

        return UTIL_HtmlTag::generateTag('select', $this->attributes, true, $optionsString);
    }
}

class EVENT_CMP_EventUsersList extends BASE_CMP_Users
{

    public function getFields( $userIdList )
    {
        $fields = array();

        $qs = array();

        $qBdate = BOL_QuestionService::getInstance()->findQuestionByName('birthdate');

        if ( $qBdate->onView )
            $qs[] = 'birthdate';

        $qSex = BOL_QuestionService::getInstance()->findQuestionByName('sex');

        if ( $qSex->onView )
            $qs[] = 'sex';

        $questionList = BOL_QuestionService::getInstance()->getQuestionData($userIdList, $qs);

        foreach ( $questionList as $uid => $q )
        {

            $fields[$uid] = array();

            $age = '';

            if ( !empty($q['birthdate']) )
            {
                $date = UTIL_DateTime::parseDate($q['birthdate'], UTIL_DateTime::MYSQL_DATETIME_DATE_FORMAT);

                $age = UTIL_DateTime::getAge($date['year'], $date['month'], $date['day']);
            }

            if ( !empty($q['sex']) )
            {
                $fields[$uid][] = array(
                    'label' => '',
                    'value' => BOL_QuestionService::getInstance()->getQuestionValueLang('sex', $q['sex']) . ' ' . $age
                );
            }

            if ( !empty($q['birthdate']) )
            {
                $dinfo = date_parse($q['birthdate']);
            }
        }

        return $fields;
    }
}
