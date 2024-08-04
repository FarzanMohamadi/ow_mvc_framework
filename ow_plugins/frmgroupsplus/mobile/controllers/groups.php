<?php
/**
 * frmgroupsplus
 *
 * @author Farzan Mohammadi <farzan.mohamadii@gmail.com>
 * @package ow_plugins.frmgroupsplus.controllers
 * @since 1.0
 */
class FRMGROUPSPLUS_MCTRL_Groups extends OW_MobileActionController
{
    /**
     *
     * @var FRMGROUPSPLUS_BOL_Service
     */
    private $service;

    public function __construct()
    {
        $this->service = FRMGROUPSPLUS_BOL_Service::getInstance();

    }

    public function deleteUserAsManager( $params )
    {
        if ( empty($params['groupId']) || empty($params['userId']) )
        {
            throw new Redirect404Exception();
        }

        if ( !OW::getUser()->isAuthenticated() )
        {
            throw new AuthenticateException();
        }

        $groupDto = GROUPS_BOL_Service::getInstance()->findGroupById($params['groupId']);

        if ( $groupDto === null )
        {
            throw new Redirect404Exception();
        }
        if ( !GROUPS_BOL_Service::getInstance()->isCurrentUserCanEdit($groupDto))
        {
            throw new Redirect404Exception();
        }
        $groupId = (int) $groupDto->id;
        $userIds = array($params['userId']);
        $this->service->deleteUserManager($groupId, $userIds);

        OW::getFeedback()->info(OW::getLanguage()->text('frmgroupsplus', 'delete_user_as_manager_success_message'));

        $redirectUri = urldecode($_GET['redirectUri']);
        $this->redirect(OW_URL_HOME . $redirectUri);
    }

    public function addUserAsManager( $params )
    {
        if ( empty($params['groupId']) || empty($params['userId']) )
        {
            throw new Redirect404Exception();
        }

        if ( !OW::getUser()->isAuthenticated() )
        {
            throw new AuthenticateException();
        }

        $groupDto = GROUPS_BOL_Service::getInstance()->findGroupById($params['groupId']);

        if ( $groupDto === null )
        {
            throw new Redirect404Exception();
        }

        if ( !GROUPS_BOL_Service::getInstance()->isCurrentUserCanEdit($groupDto))
        {
            throw new Redirect404Exception();
        }

        $groupId = (int) $groupDto->id;
        $userId = $params['userId'];

        $this->service->addUserAsManager($groupId, $userId);

        OW::getFeedback()->info(OW::getLanguage()->text('frmgroupsplus', 'add_user_as_manager_success_message'));

        $redirectUri = urldecode($_GET['redirectUri']);
        $this->redirect(OW_URL_HOME . $redirectUri);
    }
    public function fileList( $params )
    {

        $searchTitle='';

        if (OW::getRequest()->isPost()) {
            $searchTitle = $_POST['searchTitle'];
        }

        if(isset($_GET['searchTitle'])){
            $searchTitle = $_GET['searchTitle'];
        }

        $groupId = (int) $params['groupId'];
        $groupDto = GROUPS_BOL_Service::getInstance()->findGroupById($groupId);

        if ( $groupDto === null )
        {
            throw new Redirect404Exception();
        }
        $language = OW::getLanguage();

        if ( !GROUPS_BOL_Service::getInstance()->isCurrentUserCanView($groupDto) )
        {
            throw new Redirect404Exception();
        }
        if ( $groupDto->whoCanView == GROUPS_BOL_Service::WCV_INVITE && !OW::getUser()->isAuthorized('groups') )
        {
            if ( !OW::getUser()->isAuthenticated() )
            {
                throw new Redirect404Exception();
            }

            $invite = GROUPS_BOL_Service::getInstance()->findInvite($groupDto->id, OW::getUser()->getId());
            $user = GROUPS_BOL_Service::getInstance()->findUser($groupDto->id, OW::getUser()->getId());

            if ( $groupDto->whoCanView == GROUPS_BOL_Service::WCV_INVITE && $user === null )
            {
                if( $invite === null ) {
                    throw new Redirect404Exception();
                }else{
                    $this->redirect(OW::getRouter()->urlForRoute('groups-invite-list'));
                }
            }
        }

        $page = (!empty($_GET['page']) && intval($_GET['page']) > 0 ) ? $_GET['page'] : 1;
        $perPage = 20;
        $first = ($page - 1) * $perPage;
        $count = $perPage;

        $dtoList = $this->service->findFileList($groupId, $first, $count, $searchTitle);
        $listCount = $this->service->findFileListCount($groupId,$searchTitle);
        $paging = new BASE_CMP_PagingMobile($page, ceil($listCount / $perPage), 2);
        $this->addComponent('paging',$paging);
        $fileList = array();
        $attachmentIds = array();
        $deleteUrls = array();
        foreach ( $dtoList as $item ) {
            $sentenceCorrected = false;
            if (mb_strlen($item->getOrigFileName()) > 100) {
                $sentence = $item->getOrigFileName();
                $event = OW::getEventManager()->trigger(new OW_Event(FRMEventManager::PARTIAL_HALF_SPACE_CODE_DISPLAY_CORRECTION, array('sentence' => $sentence, 'trimLength' => 100)));
                if (isset($event->getData()['correctedSentence'])) {
                    $sentence = $event->getData()['correctedSentence'];
                    $sentenceCorrected = true;
                }
                $event = OW::getEventManager()->trigger(new OW_Event(FRMEventManager::PARTIAL_SPACE_CODE_DISPLAY_CORRECTION, array('sentence' => $sentence, 'trimLength' => 100)));
                if (isset($event->getData()['correctedSentence'])) {
                    $sentence = $event->getData()['correctedSentence'];
                    $sentenceCorrected = true;
                }
            }
            if ($sentenceCorrected) {
                $fileName = $sentence . '...';
            } else {
                $fileName = UTIL_String::truncate($item->getOrigFileName(), 100, '...');
            }
            $canEdit=false;
            if ( GROUPS_BOL_Service::getInstance()->isCurrentUserCanEdit($groupDto) )
            {
                $this->assign("canEdit", true);
                $canEdit = true;
            }
            $code = '';
            $frmSecuritymanagerEvent= OW::getEventManager()->trigger(new OW_Event('frmsecurityessentials.on.generate.request.manager',
                array('senderId'=>OW::getUser()->getId(),'receiverId'=>$item->id,'isPermanent'=>true,'activityType'=>'groups_deleteFile')));
            if(isset($frmSecuritymanagerEvent->getData()['code'])){
                $code = $frmSecuritymanagerEvent->getData()['code'];
            }
            $toolbar = array(
                array(
                    'label' => UTIL_DateTime::formatSimpleDate($item->addStamp)
                )
            );

            $fileNameArr = explode('.', $item->fileName);
            $fileNameExt = end($fileNameArr);
            $itemInformation = array();
            $itemInformation['url'] = $this->getAttachmentUrl($item->fileName);
            $itemInformation['imageSrc'] = FRMGROUPSPLUS_BOL_Service::getInstance()->getProperIcon(strtolower($fileNameExt));
            $itemInformation['title'] = $fileName;
            $itemInformation['imageTitle'] = $fileName;
            $itemInformation['fileName'] = $item->getOrigFileName();
            $itemInformation['time'] = $item->addStamp;
            $itemInformation['content'] = $language->text('frmgroupsplus', 'by') . ' :' . '<a href="'.OW::getRouter()->urlForRoute('base_user_profile', array('username' => BOL_UserService::getInstance()->getUserName($item->getUserId()))).'">' .BOL_UserService::getInstance()->getDisplayName($item->getUserId()) . '</a>';
            $itemInformation['id'] = $item->id;

            if ($item->userId == OW::getUser()->getId() || $canEdit) {
                $deleteUrl =  OW::getRequest()->buildUrlQueryString(OW::getRouter()->urlForRoute('frmgroupsplus.deleteFile',
                    array('attachmentId' => $item->id, 'groupId' => $groupId)),array('code' =>$code));
                $toolbar[] = array(
                    'label' => '<span>'.$language->text('frmgroupsplus', 'delete_item').'</span>',
                    'href' => $deleteUrl,
                    'click' => "return confirm_redirect('" . OW::getLanguage()->text('base', 'are_you_sure') . "', '$deleteUrl');",
                    'class' => 'delete_file'
                );
            }
            $itemInformation['toolbar'] = $toolbar;
            $fileList[] = $itemInformation;
        }

        $showAdd=true;
        $isChannel=false;
        if(OW::getUser()->isAuthenticated()){
            $isUserInGroup = GROUPS_BOL_Service::getInstance()->findUser($groupId, OW::getUser()->getId());
            if(!$isUserInGroup)
                $showAdd=false;
            $channelEvent = OW::getEventManager()->trigger(new OW_Event('frmgroupsplus.on.channel.add.widget',
                array('groupId'=>$groupId)));
            $isChannelParticipant = $channelEvent->getData()['channelParticipant'];
            if( $isUserInGroup && isset($isChannelParticipant) && $isChannelParticipant ){
                $isChannel=true;
            }
        }

        $isAuthorizedUpload=true;
        $groupSettingEvent = OW::getEventManager()->trigger(new OW_Event('can.upload.in.file.widget',
            array('groupId'=>$groupId)));
        if(isset($groupSettingEvent->getData()['accessUploadFile'])) {
            $isAuthorizedUpload = $groupSettingEvent->getData()['accessUploadFile'];
        }
        $groupDto= GROUPS_BOL_Service::getInstance()->findGroupById($groupId);
        $isModerator=GROUPS_BOL_Service::getInstance()->isCurrentUserCanEdit($groupDto);
        if(!$isModerator) {
            if (!$isAuthorizedUpload) {
                $showAdd = false;
            }
            else if ($isAuthorizedUpload && $isChannel) {
                $showAdd = false;
            }
        }
        if(!OW::getUser()->isAuthenticated())
            $showAdd = false;
        $this->assign("showAdd", $showAdd);
        $this->assign("fileList", $fileList);
        $this->assign("attachmentIds", $attachmentIds);
        $this->assign('deleteUrls', $deleteUrls);
        $plugin = OW::getPluginManager()->getPlugin('frmgroupsplus');
        OW::getDocument()->addScript($plugin->getStaticJsUrl() . 'frmgroupsplus.js');
        OW::getDocument()->addStyleSheet($plugin->getStaticCssUrl() . 'frmgroupsplus.css');
        $this->assign('deleteIconUrl', $plugin->getStaticUrl().'images/trash.svg');
        $this->assign('addIconUrl', $plugin->getStaticUrl().'images/add.svg');
        $this->assign("groupId", $groupId);
        $this->assign('backUrl',OW::getRouter()->urlForRoute('groups-view' , array('groupId'=>$groupId)));
        OW::getDocument()->addStyleDeclaration('.owm_group_list.files_list span.ow_nowrap.delete_file a {background-image: url('.$plugin->getStaticUrl().'images/trash.svg'.');}');
        $params = array(
            "sectionKey" => "frmgroupsplus",
            "entityKey" => "groupFiles",
            "title" => "frmgroupsplus+meta_title_group_files",
            "description" => "frmgroupsplus+meta_desc_group_files",
            "keywords" => "frmgroupsplus+meta_keywords_group_files",
            "vars" => array( "group_title" => $groupDto->title )
        );
        $this->assign('search_url',OW::getRouter()->urlForRoute('frmgroupsplus.file-list',array('groupId'=>$groupId)));
        $this->assign('searchTitle',$searchTitle);

        OW::getEventManager()->trigger(new OW_Event("base.provide_page_meta_info", $params));
        OW::getEventManager()->trigger(new OW_Event(FRMEventManager::ON_BEFORE_GROUP_VIEW_RENDER, array(
            'groupId' => $groupId, 'pageType' => "fileList")));
    }

    public function getAttachmentUrl($name)
    {
        return OW::getStorage()->getFileUrl($this->getAttachmentDir($name));
    }

    public function getAttachmentDir($name)
    {
        return OW::getPluginManager()->getPlugin('base')->getUserFilesDir() . 'attachments' . DS .$name ;
    }

    public function addFile($params)
    {
        if (!OW::getUser()->isAuthenticated()) {
            throw new AuthenticateException();
        }
        $groupId = (int) $params['groupId'];

        if ( $groupId<=0  )
        {
            throw new Redirect404Exception();
        }
        $isChannel=false;
        if(OW::getUser()->isAuthenticated()){
            $isUserInGroup = GROUPS_BOL_Service::getInstance()->findUser($groupId, OW::getUser()->getId());
            $channelEvent = OW::getEventManager()->trigger(new OW_Event('frmgroupsplus.on.channel.add.widget',
                array('groupId'=>$groupId)));
            $isChannelParticipant = $channelEvent->getData()['channelParticipant'];
            if( $isUserInGroup && isset($isChannelParticipant) && $isChannelParticipant ){
                $isChannel=true;
            }
        }

        $isAuthorizedUpload=true;
        $groupSettingEvent = OW::getEventManager()->trigger(new OW_Event('can.upload.in.file.widget',
            array('groupId'=>$groupId)));
        if(isset($groupSettingEvent->getData()['accessUploadFile'])) {
            $isAuthorizedUpload = $groupSettingEvent->getData()['accessUploadFile'];
        }
        $groupDto= GROUPS_BOL_Service::getInstance()->findGroupById($groupId);
        $isModerator=GROUPS_BOL_Service::getInstance()->isCurrentUserCanEdit($groupDto);
        if(!$isModerator) {
            if (!$isAuthorizedUpload) {
                throw new Redirect404Exception();
            }
            else if ($isAuthorizedUpload && $isChannel) {
                throw new Redirect404Exception();
            }
        }
        $form = $this->service->getUploadFileForm($groupId);
        if (OW::getRequest()->isPost() && $form->isValid($_POST)) {
            if (!empty($_FILES)) {
                $resultArr = FRMGROUPSPLUS_BOL_Service::getInstance()->manageAddFile($groupId, $_FILES['fileUpload']);
                if(!isset($resultArr) || !$resultArr['result']){
                    exit(array('valid' => false, 'message' => 'authorization_error'));
                }
                OW::getEventManager()->call('frmfilemanager.after_file_upload',
                    array('entityType'=>'groups', 'entityId'=>$groupId, 'dto'=>$resultArr['dtoArr']['dto'], 'file' => $_FILES['fileUpload']));
            }else{
                OW::getFeedback()->error(OW::getLanguage()->text('frmgroupsplus', 'file_empty'));
            }

            exit();
        }
    }

    public function deleteFile($params){
        if (!OW::getUser()->isAuthenticated()) {
            throw new AuthenticateException();
        }
        $groupId = $params['groupId'];
        $attachmentId = $params['attachmentId'];
        if ( !isset($groupId)  || !isset($attachmentId))
        {
            throw new Redirect404Exception();
        }
        if(FRMSecurityProvider::checkPluginActive('frmsecurityessentials', true)) {
            $code =$_GET['code'];
            if(!isset($code)){
                throw new Redirect404Exception();
            }
            OW::getEventManager()->trigger(new OW_Event('frmsecurityessentials.on.check.request.manager',
                array('senderId' => OW::getUser()->getId(), 'code'=>$code,'activityType'=>'groups_deleteFile')));
        }
        $groupDto = GROUPS_BOL_Service::getInstance()->findGroupById($groupId);
          if(!$groupDto) {
              throw new Redirect404Exception();
          }
        $canEdit=false;
        if (GROUPS_BOL_Service::getInstance()->isCurrentUserCanEdit($groupDto) )
        {
            $canEdit = true;
        }

        $attachment = BOL_AttachmentDao::getInstance()->findById($attachmentId);
        if ($attachment->userId != OW::getUser()->getId() && !$canEdit) {
            throw new Redirect404Exception();
        }
        $isUserInGroup = GROUPS_BOL_Service::getInstance()->findUser($groupId, OW::getUser()->getId());
        if(!$isUserInGroup){
            throw new Redirect404Exception();
        }
        try {
            $this->service->deleteFileForGroup($groupId, $attachmentId);
        }
        catch (Exception $e){

        }

        $this->redirect(OW::getRouter()->urlForRoute('frmgroupsplus.file-list' , array('groupId'=>$groupId)));
    }

    public function revoke()
    {
        if ( !OW::getRequest()->isAjax() )
        {
            throw new Redirect404Exception();
        }
        FRMGROUPSPLUS_BOL_Service::getInstance()->revoke();
    }

    /**
     * @param $params
     * @throws Redirect404Exception
     */
    public function approve( $params ){
        if (!OW::getUser()->isAuthenticated() || !isset($params['groupId'])) {
            throw new Redirect404Exception();
        }

        if (!OW::getUser()->isAuthorized('frmgroupsplus', 'groups')) {
            throw new Redirect404Exception();
        }

        $pluginIisSecurity = BOL_PluginDao::getInstance()->findPluginByKey('frmsecurityessentials');
        if($pluginIisSecurity->isActive()) {
            $code =$_GET['code'];
            if(!isset($code)){
                throw new Redirect404Exception();
            }
            OW::getEventManager()->trigger(new OW_Event('frmsecurityessentials.on.check.request.manager',
                array('senderId' => OW::getUser()->getId(), 'code'=>$code,'activityType'=>'approve_group')));
        }

        $language = OW::getLanguage();
        $approveResult = FRMGROUPSPLUS_BOL_Service::getInstance()->approveGroupById($params['groupId']);
        if( $approveResult === true ){
            OW::getFeedback()->info($language->text('frmgroupsplus', 'group_has_been_approved'));
        }else{
            OW::getFeedback()->error($language->text('frmgroupsplus', 'group_not_approved'));
        }

        $this->redirect( OW::getRouter()->urlForRoute('groups-view', array('groupId' => $params['groupId'])));
    }
}