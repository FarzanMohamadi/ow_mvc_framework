<?php
/**
 * 
 * All rights reserved.
 */

/**
 *
 *
 * @author Farzan Mohammadi <farzan.mohamadii@gmail.com>
 * @package ow_plugins.frmmobilesupport.bol
 * @since 1.0
 */
class FRMMOBILESUPPORT_BOL_WebServiceForum
{
    private static $classInstance;

    public static function getInstance()
    {
        if ( self::$classInstance === null )
        {
            self::$classInstance = new self();
        }

        return self::$classInstance;
    }

    private function __construct()
    {
    }

    public function preparePost($post, $attachments = null, $postDto = null) {
        $forumService = FORUM_BOL_ForumService::getInstance();

        if ($post instanceof FORUM_BOL_Post) {
            $topicDto = $this->checkTopicAccess($post->topicId);
            $isClosingPost = ($topicDto->conclusionPostId == $post->id) ? true : false;
            $post = array(
                'id' => $post->id,
                'topicId' => $post->topicId,
                'text' => $post->text,
                'createStampRaw' => $post->createStamp,
                'isClosingPost' => $isClosingPost,
                'userId' => $post->userId,
            );
        }

        if ($attachments == null) {
            $attachments = FORUM_BOL_PostAttachmentService::getInstance()->findAttachmentsByPostIdList(array($post['id']));
        }

        $canConclude = $forumService->canCurrentUserConcludeTopic($post['topicId']);
        $files = array();
        if (isset($attachments[$post['id']])) {
            $filesList = $attachments[$post['id']];
            foreach ($filesList as $file) {
                $files[] = array(
                    "id" => $file['id'],
                    "name" => $file['fileName'],
                    "size" => $file['fileSize'],
                    "url" => $file['downloadUrl']
                );
            }
        }
        if ($postDto == null) {
        $postDto = $forumService->findPostById($post['id']);
        }
        $removable = true;
        if ($postDto == null || ($postDto->userId != OW::getUser()->getId() && !OW::getUser()->isAdmin())) {
            $removable = false;
        }
        $stripedString = FRMMOBILESUPPORT_BOL_WebServiceGeneral::getInstance()->stripString($post['text'], true, false, true);
        $stripedString = FRMMOBILESUPPORT_BOL_WebServiceGeneral::getInstance()->setMentionsOnText($stripedString);

        return array(
            "id" => (int) $post['id'],
            "time" => $post['createStampRaw'],
            "files" => $files,
            "removable" => $removable,
            "editable" => $removable,
            "text" => $stripedString,
            "user" => FRMMOBILESUPPORT_BOL_WebServiceUser::getInstance()->getUserInformationById($post['userId']),
            "flagAble" => true,
            "entityType" => "forum-post",
            "entityId" => (int)$post['id'],
            "isConclusionPost" => $post['isClosingPost'],
            "canConclude" => $canConclude,
            'topicId' => (int) $post['topicId'],
            'commentsCount' => empty($post['commentsCount'])?0:$post['commentsCount']
        );
    }

    public function preparedTopic($topic, $fetchPosts = true){
        $groupId = null;
        $forumService = FORUM_BOL_ForumService::getInstance();
        if($topic instanceof  FORUM_BOL_Topic){
            $isTopicConcluded = $topic->conclusionPostId ? true : false;
            $topicDto = array(
                "id" => (int) $topic->getId(),
                "title" => $topic->title,
                "viewCount" => $topic->viewCount,
                "postCount" => $forumService->findTopicPostCount($topic->getId()) + 1,
                "userId" => (int) $topic->userId,
                "locked" => $topic->locked,
                "groupId" => $topic->groupId,
                "flagAble" => true,
                "entityType" => "forum-topic",
                "entityId" => (int) $topic->getId(),
            );
            $groupId = $topic->groupId;
            $topic = $topicDto;
        } else{
            $isTopicConcluded = $topic['conclusionPostId'] ? true : false;
        }

        if($topic == null || sizeof($topic) == 0){
            return array();
        }
        $conclusionPost = null;
        $firstPost = null;
        $posts = array();
        if ($fetchPosts) {
            $fixedPosts = $forumService->getTopicPostList($topic['id'], $this->getCurrentPage(), false, true);
            $firstPostId = isset(array_keys($fixedPosts)[0]) ? array_keys($fixedPosts)[0] : null;
            $conclusionPostId = isset(array_keys($fixedPosts)[1]) ? array_keys($fixedPosts)[1] : null;
            $topicHasConclusionPost = $conclusionPostId ? true : false;

            $postList = $forumService->getTopicPostList($topic['id'], $this->getCurrentPage());
            $postList[] = $fixedPosts[$firstPostId];
            if ($topicHasConclusionPost) {
                $postList[] = $fixedPosts[$conclusionPostId];
            }
            $postIds = array();
            foreach ($postList as $post){
                $postIds[] = $post['id'];
            }
            if($groupId == null && isset($topic['groupId'])){
                $groupId = $topic['groupId'];
            }
            $attachments = FORUM_BOL_PostAttachmentService::getInstance()->findAttachmentsByPostIdList($postIds);
            $postsDto = FORUM_BOL_ForumService::getInstance()->findPostListByIds(array_column($postList, 'id'));
            foreach ($postList as $post) {
                /** @var FORUM_BOL_Post $postDto */
                $key = array_search($post['id'], array_column($postsDto, 'id'));
                $postDto = $postsDto[$key];
                $posts[] = $this->preparePost($post, $attachments, $postDto);
            }

            $conclusionPost = null;
            if ($topicHasConclusionPost) {
                $conclusionPost = array_pop($posts);
            }
            $firstPost = array_pop($posts);
            $firstPost['canConclude'] = false;
        }

        $isOwner = ( $topic['userId'] == OW::getUser()->getId() ) ? true : false;
        $lockable = $isOwner || OW::getUser()->isAdmin();
        $removable = $isOwner || OW::getUser()->isAdmin();

        $groupInfo = null;

        if ($groupId != null) {
            $groupDto = $forumService->getGroupInfo($groupId);
            if ($groupDto) {
                $forumSection = $forumService->findSectionById($groupDto->sectionId);
                if ($forumSection && $forumSection->entity == 'groups') {
                    $groupInfo['id'] = (int)$groupDto->entityId;
                    $groupInfo['title'] = $groupDto->name;
                }
            }
        }


        $isTopicNew = false;
        if (isset($topic['new'])) {
            $isTopicNew = $topic['new'];
        } else if ( OW::getUser()->isAuthenticated()) {
            $readTopicDao = FORUM_BOL_ReadTopicDao::getInstance();
            $readTopicIds = $readTopicDao->findUserReadTopicIds(array($topic['id']), OW::getUser()->getId());
            $isTopicNew = (OW::getUser()->getId() && !in_array($topic['id'], $readTopicIds));
        }

        $forumTopicsInfo = array(
            "id" => (int) $topic['id'],
            "title" => $topic['title'],
            "view_count" => $topic['viewCount'],
            "post_count" => $topic['postCount'] - 1,
            "user" => FRMMOBILESUPPORT_BOL_WebServiceUser::getInstance()->getUserInformationById($topic['userId']),
            "locked" => (int) $topic['locked'],
            "sectionId" => $groupId,
            "lockable" => $lockable,
            "new" => $isTopicNew,
            "removable" => $removable,
            "editable" => $removable,
            "groupInfo" => $groupInfo
        );

        if ($fetchPosts) {
            $forumTopicsInfo["posts"] = $posts;
            $forumTopicsInfo["conclusionPost"] = $conclusionPost;
            $forumTopicsInfo["firstPost"] = $firstPost;
        }

        $forumTopicsInfo['isTopicConcluded'] = $isTopicConcluded;
        return $forumTopicsInfo;
    }

    public function lockTopic(){
        if(!FRMSecurityProvider::checkPluginActive('forum', true) || !OW::getUser()->isAuthenticated()){
            return array('valid' => false, 'message' => 'authorization_error');
        }
        if(!isset($_GET['id']) || empty($_GET['id'])){
            return array('valid' => false, 'message' => 'authorization_error');
        }
        $guestAccess = FRMMOBILESUPPORT_BOL_WebServiceGeneral::getInstance()->checkGuestAccess();
        if(!$guestAccess){
            return array('valid' => false, 'message' => 'authorization_error');
        }
        $topicDto = $this->checkTopicAccess($_GET['id']);
        if($topicDto == null){
            return array('valid' => false, 'message' => 'authorization_error');
        }
        $isOwner = ( $topicDto->userId == OW::getUser()->getId() ) ? true : false;
        $isOwner = $isOwner || OW::getUser()->isAdmin();

        if(!$isOwner){
            return array('valid' => false, 'message' => 'authorization_error');
        }
        $topicDto->locked = 1;
        FORUM_BOL_ForumService::getInstance()->saveOrUpdateTopic($topicDto);
        return $this->preparedTopic($topicDto);
    }

    public function editTopic(){
        if(!FRMSecurityProvider::checkPluginActive('forum', true) || !OW::getUser()->isAuthenticated()){
            return array('valid' => false, 'message' => 'authorization_error');
        }
        if(!isset($_GET['id']) || empty($_GET['id']) || !isset($_POST['text']) || empty($_POST['text'])){
            return array('valid' => false, 'message' => 'authorization_error');
        }
        $guestAccess = FRMMOBILESUPPORT_BOL_WebServiceGeneral::getInstance()->checkGuestAccess();
        if(!$guestAccess){
            return array('valid' => false, 'message' => 'authorization_error');
        }

        $text = trim($_POST['text']);
        $text = UTIL_HtmlTag::stripTagsAndJs($text);

        $topicDto = $this->checkTopicAccess($_GET['id']);
        if($topicDto == null || empty($text)){
            return array('valid' => false, 'message' => 'authorization_error');
        }
        $isOwner = ( $topicDto->userId == OW::getUser()->getId() ) ? true : false;
        $isOwner = $isOwner || OW::getUser()->isAdmin();

        if(!$isOwner){
            return array('valid' => false, 'message' => 'authorization_error');
        }

        $topicDto->title = UTIL_HtmlTag::stripTagsAndJs($text);
        FORUM_BOL_ForumService::getInstance()->saveOrUpdateTopic($topicDto);
        return $this->preparedTopic($topicDto);
    }

    public function editTopicPost(){
        if(!FRMSecurityProvider::checkPluginActive('forum', true) || !OW::getUser()->isAuthenticated()){
            return array('valid' => false, 'message' => 'authorization_error');
        }
        if(!isset($_GET['id']) || empty($_GET['id']) || !isset($_POST['text']) || empty($_POST['text'])){
            return array('valid' => false, 'message' => 'authorization_error');
        }
        $guestAccess = FRMMOBILESUPPORT_BOL_WebServiceGeneral::getInstance()->checkGuestAccess();
        if(!$guestAccess){
            return array('valid' => false, 'message' => 'authorization_error');
        }

        $text = trim($_POST['text']);
        $text = UTIL_HtmlTag::stripTagsAndJs($text);

        $postId = $_GET['id'];

        $forumService = FORUM_BOL_ForumService::getInstance();
        $postDto = $forumService->findPostById($postId);
        if($postDto == null || ($postDto->userId != OW::getUser()->getId() && !OW::getUser()->isAdmin())){
            return array('valid' => false, 'message' => 'authorization_error');
        }
        $topicDto = $this->checkTopicAccess($postDto->topicId);
        if($topicDto == null){
            return array('valid' => false, 'message' => 'authorization_error');
        }

        $isOwner = ( $topicDto->userId == OW::getUser()->getId() ) ? true : false;
        $isOwner = $isOwner || OW::getUser()->isAdmin();

        if(!$isOwner){
            return array('valid' => false, 'message' => 'authorization_error');
        }

        $postDto->text = $text;
        FORUM_BOL_ForumService::getInstance()->saveOrUpdatePost($postDto);
        return $this->preparePost($postDto);
    }

    public function unlockTopic(){
        if(!FRMSecurityProvider::checkPluginActive('forum', true)){
            return array('valid' => false, 'message' => 'authorization_error');
        }
        if(!isset($_GET['id']) || empty($_GET['id'])){
            return array('valid' => false, 'message' => 'authorization_error');
        }
        $guestAccess = FRMMOBILESUPPORT_BOL_WebServiceGeneral::getInstance()->checkGuestAccess();
        if(!$guestAccess){
            return array('valid' => false, 'message' => 'authorization_error');
        }
        $topicDto = $this->checkTopicAccess($_GET['id']);
        if($topicDto == null){
            return array('valid' => false, 'message' => 'authorization_error');
        }
        $isOwner = ( $topicDto->userId == OW::getUser()->getId() ) ? true : false;
        $isOwner = $isOwner || OW::getUser()->isAdmin();

        if(!$isOwner){
            return array('valid' => false, 'message' => 'authorization_error');
        }
        $topicDto->locked = 0;
        FORUM_BOL_ForumService::getInstance()->saveOrUpdateTopic($topicDto);
        return $this->preparedTopic($topicDto);
    }

    public function deleteTopic(){
        if(!FRMSecurityProvider::checkPluginActive('forum', true)){
            return array('valid' => false, 'message' => 'authorization_error');
        }
        if(!isset($_GET['id']) || empty($_GET['id'])){
            return array('valid' => false, 'message' => 'authorization_error');
        }
        $guestAccess = FRMMOBILESUPPORT_BOL_WebServiceGeneral::getInstance()->checkGuestAccess();
        if(!$guestAccess){
            return array('valid' => false, 'message' => 'authorization_error');
        }
        $topicDto = $this->checkTopicAccess($_GET['id']);
        if($topicDto == null){
            return array('valid' => false, 'message' => 'authorization_error');
        }
        $isOwner = ( $topicDto->userId == OW::getUser()->getId() ) ? true : false;
        $isOwner = $isOwner || OW::getUser()->isAdmin();

        if(!$isOwner){
            return array('valid' => false, 'message' => 'authorization_error');
        }
        FORUM_BOL_ForumService::getInstance()->deleteTopic($topicDto->id);
        return array('valid' => true, 'message' => 'topic_deleted');
    }

    public function preparedForum($forum){
        if($forum == null || sizeof($forum) == 0){
            return array();
        }

        $forumItems = $forum['groups'];
        foreach ($forumItems as $forumItem){
            $items[] = $this->preparedForumItem($forumItem, false);
        }

        $forumInfo = array(
            "id" => (int) $forum['sectionId'],
            "name" => $forum['sectionName'],
            "url" => $forum['sectionUrl'],
            "items" => $items
        );

        return $forumInfo;
    }

    public function userCanView($groupId){
        $forumService = FORUM_BOL_ForumService::getInstance();
        $groupInfo = $forumService->getGroupInfo($groupId);
        if ( !$groupInfo )
        {
            return false;
        }

        $forumSection = $forumService->findSectionById($groupInfo->sectionId);
        if ( !$forumSection )
        {
            return false;
        }

        $userId = OW::getUser()->getId();
        $isHidden = $forumSection->isHidden;

        if ( $isHidden )
        {
            $isModerator = OW::getUser()->isAuthorized($forumSection->entity);

            $event = new OW_Event('forum.can_view', array(
                'entity' => $forumSection->entity,
                'entityId' => $groupInfo->entityId
            ), true);
            OW::getEventManager()->trigger($event);

            $canView = $event->getData();
        }
        else
        {
            $isModerator = OW::getUser()->isAuthorized('forum');
            $canView = OW::getUser()->isAuthorized('forum', 'view');
        }

        if ( !$canView )
        {
            return false;
        }

        if ( $groupInfo->isPrivate )
        {
            if ( !$userId )
            {
                return false;
            }
            else if ( !$isModerator )
            {
                if ( !$forumService->isPrivateGroupAvailable($userId, json_decode($groupInfo->roles)) )
                {
                    return false;
                }
            }
        }

        return true;
    }

    public function preparedForumItem($forumItem, $fetchTopics = true){
        if($forumItem == null || sizeof($forumItem) == 0){
            return array();
        }

        if(!$this->userCanView($forumItem['id'])){
            return array();
        }

        $forumService = FORUM_BOL_ForumService::getInstance();
        $topics = $forumService->getGroupTopicList($forumItem['id'], $this->getCurrentPage());
        $topicsData = array();
        if ($fetchTopics) {
            foreach ($topics as $topic){
                $topicsData[] = $this->preparedTopic($topic, false);
            }
        }

        $forumItemsInfo = array(
            "id" => (int) $forumItem['id'],
            "name" => $forumItem['name'],
            "description" => $forumItem['description'],
            "topic_count" => $forumItem['topicCount'],
        );

        if ($fetchTopics) {
            $forumItemsInfo["topics"] = $topicsData;
        }

        return $forumItemsInfo;
    }

    public function getForums(){
        if(!FRMSecurityProvider::checkPluginActive('forum', true)){
            return array();
        }

        $guestAccess = FRMMOBILESUPPORT_BOL_WebServiceGeneral::getInstance()->checkGuestAccess();
        if(!$guestAccess){
            return array('valid' => false, 'message' => 'authorization_error');
        }

        $isModerator = OW::getUser()->isAuthorized('forum');
        $viewPermissions = OW::getUser()->isAuthorized('forum', 'view');

        if ( !$viewPermissions && !$isModerator )
        {
            return array();
        }
        $forumInfo = array();
        $forumService = FORUM_BOL_ForumService::getInstance();
        $userId = OW::getUser()->getId();
        $forums = $forumService->getSectionGroupList($userId);

        foreach ($forums as $forum){
            $forumInfo[] = $this->preparedForum($forum);
        }

        return $forumInfo;
    }

    public function getTopics(){
        if(!FRMSecurityProvider::checkPluginActive('forum', true)){
            return array('valid' => false, 'message' => 'authorization_error');
        }
        if(!isset($_GET['id']) || empty($_GET['id'])){
            return array('valid' => false, 'message' => 'authorization_error');
        }
        $guestAccess = FRMMOBILESUPPORT_BOL_WebServiceGeneral::getInstance()->checkGuestAccess();
        if(!$guestAccess){
            return array('valid' => false, 'message' => 'authorization_error');
        }
        $forumItemId = $_GET['id'];
        if(!$this->userCanView($forumItemId)){
            return array('valid' => false, 'message' => 'authorization_error');
        }
        $forumService = FORUM_BOL_ForumService::getInstance();
        $groupInfo = $forumService->getGroupInfo($forumItemId);
        if ( !$groupInfo )
        {
            return array('valid' => false, 'message' => 'authorization_error');
        }

        $forumSection = $forumService->findSectionById($groupInfo->sectionId);
        if ( !$forumSection )
        {
            return array('valid' => false, 'message' => 'authorization_error');
        }

        $groupData = array(
            "id" => (int) $groupInfo->id,
            "name" => $groupInfo->name,
            "description" => $groupInfo->description,
            "topicCount" => $forumService->getGroupTopicCount($groupInfo->id)
        );

        $items = $this->preparedForumItem($groupData);

        $forumInfo = array(
            "sectionId" => (int) $forumSection->getId(),
            "name" => $forumSection->name,
            "topics" => $items
        );

        return $forumInfo;
    }

    public function getCurrentPage(){
        $first = 0;
        if(isset($_GET['first'])){
            $first = (int) $_GET['first'];
        }

        $page = FRMMOBILESUPPORT_BOL_WebServiceGeneral::getInstance()->getPageNumber($first);
        return $page;
    }

    public function checkTopicAccess($id){
        if(!FRMSecurityProvider::checkPluginActive('forum', true)){
            return null;
        }
        if($id == null){
            return null;
        }

        $forumService = FORUM_BOL_ForumService::getInstance();
        $topicDto = $forumService->findTopicById($id);
        if ( !$topicDto ){
            return null;
        }

        if(!$this->userCanView($topicDto->groupId)){
            return null;
        }

        return $topicDto;
    }

    public function getTopic(){
        if(!FRMSecurityProvider::checkPluginActive('forum', true) || !OW::getUser()->isAuthenticated()){
            return array('valid' => false, 'message' => 'authorization_error');
        }
        if(!isset($_GET['id']) || empty($_GET['id'])){
            return array('valid' => false, 'message' => 'authorization_error');
        }
        $guestAccess = FRMMOBILESUPPORT_BOL_WebServiceGeneral::getInstance()->checkGuestAccess();
        if(!$guestAccess){
            return array('valid' => false, 'message' => 'authorization_error');
        }
        $topicDto = $this->checkTopicAccess($_GET['id']);
        if($topicDto == null){
            return array('valid' => false, 'message' => 'authorization_error', 'id' => $_GET['id']);
        }
        if (OW::getUser()->isAuthenticated()) {
            FORUM_BOL_ForumService::getInstance()->setTopicRead($topicDto->id, OW::getUser()->getId());
        }
        return $this->preparedTopic($topicDto);
    }

    public function addPost(){
        if(!FRMSecurityProvider::checkPluginActive('forum', true)){
            return array('valid' => false, 'message' => 'authorization_error');
        }
        if(!isset($_GET['id']) || empty($_GET['id']) || !OW::getUser()->isAuthenticated()){
            return array('valid' => false, 'message' => 'authorization_error');
        }

        $forumService = FORUM_BOL_ForumService::getInstance();
        $topicDto = $this->checkTopicAccess($_GET['id']);
        if($topicDto == null){
            return array('valid' => false, 'message' => 'authorization_error');
        }

        $text = null;
        if(isset($_POST['text']) && !empty($_POST['text'])){
            $text = $_POST['text'];
        }

        $text = FRMMOBILESUPPORT_BOL_WebServiceGeneral::getInstance()->stripString($text);

        if($text == null){
            return array('valid' => false, 'message' => 'authorization_error');
        }

        $fileIndex = 0;
        $virusDetectedFiles = array();
        $bundle = FRMSecurityProvider::generateUniqueId();

        if (isset($_FILES)) {
            if (isset($_FILES['file'])) {
                $isFileClean = FRMMOBILESUPPORT_BOL_WebServiceGeneral::getInstance()->isFileClean($_FILES['file']['tmp_name']);
                if ($isFileClean) {
                    try{
                        BOL_AttachmentService::getInstance()->processUploadedFile('forum', $_FILES['file'], $bundle);
                    }
                    catch ( Exception $e ){
                    }
                } else {
                    $virusDetectedFiles[] = $_FILES['file']['name'];
                }
            }
            while (isset($_FILES['file' . $fileIndex])) {
                $isFileClean = FRMMOBILESUPPORT_BOL_WebServiceGeneral::getInstance()->isFileClean($_FILES['file' . $fileIndex]['tmp_name']);
                if ($isFileClean) {
                    try{
                        BOL_AttachmentService::getInstance()->processUploadedFile('forum', $_FILES['file' . $fileIndex], $bundle);
                    }
                    catch ( Exception $e ){
                    }
                } else {
                    $virusDetectedFiles[] = $_FILES['file' . $fileIndex]['name'];
                }
                $fileIndex++;
            }
        }


        $data = array(
            "text" => $text,
            "attachmentUid" => $bundle
        );

        $forumService->addPost($topicDto, $data);
        return array('valid' => true, 'topic' => $this->preparedTopic($topicDto), 'virus_files' => $virusDetectedFiles);
    }

    public function addTopic(){
        if(!FRMSecurityProvider::checkPluginActive('forum', true) || !OW::getUser()->isAuthenticated()){
            return array('valid' => false, 'message' => 'authorization_error');
        }

        $forumService = FORUM_BOL_ForumService::getInstance();
        $topicDto = null;
        $title = null;
        $entity = null;
        $text = null;
        $groupId = null;
        $text = FRMMOBILESUPPORT_BOL_WebServiceGeneral::getInstance()->stripString($text);
        $title = FRMMOBILESUPPORT_BOL_WebServiceGeneral::getInstance()->stripString($title, true, true);

        if(isset($_POST['title']) && !empty($_POST['title'])){
            $title = $_POST['title'];
        }
        if(isset($_POST['text']) && !empty($_POST['text'])){
            $text = $_POST['text'];
        }
        if(isset($_POST['groupId']) && !empty($_POST['groupId'])){
            $groupId = $_POST['groupId'];
        }
        if(isset($_POST['entity']) && !empty($_POST['entity'])){
            $entity = $_POST['entity'];
        }
        if($text == null || empty($text) || $title == null || empty($title) || !OW::getUser()->isAuthenticated()){
            return array('valid' => false, 'message' => 'authorization_error');
        }

        if($entity != null  && $entity == 'groups') {
            if (!FRMMOBILESUPPORT_BOL_WebServiceGroup::getInstance()->canAddTopicToGroup($groupId)){
                return array('valid' => false, 'message' => 'authorization_error');
            }
            $forumGroup = $forumService->findGroupByEntityId($entity, $groupId);
            $groupId = $forumGroup->id;
        }else{
            $forumGroup = $forumService->getGroupInfo($groupId);
        }
        $isHidden = false;
        if ( $forumGroup )
        {
            $forumSection = $forumService->findSectionById($forumGroup->sectionId);
            $isHidden = $forumSection->isHidden;
        }else{
            return array('valid' => false, 'message' => 'authorization_error');
        }

        if(!$this->userCanView($groupId)) {
            return array('valid' => false, 'message' => 'authorization_error');
        }

        $data = array(
            'title' => $title,
            'text' => $text,
            'group' => $groupId,
            'attachmentUid' => FRMSecurityProvider::generateUniqueId(),
            'subscribe' => true
        );
        if ( isset($forumSection) ) {
            $topicDto = $forumService->addTopic($forumGroup, $isHidden, OW::getUser()->getId(), $data, $forumSection);
        }else{
            return array('valid' => false, 'message' => 'authorization_error');
        }

        if($topicDto == null){
            return array('valid' => false, 'message' => 'authorization_error');
        }

        return array('valid' => true, 'topic' => $this->preparedTopic($topicDto));
    }

    public function deletePost(){
        if(!FRMSecurityProvider::checkPluginActive('forum', true)){
            return array('valid' => false, 'message' => 'authorization_error');
        }
        if(!isset($_GET['id']) || empty($_GET['id']) || !OW::getUser()->isAuthenticated()){
            return array('valid' => false, 'message' => 'authorization_error');
        }

        $postId = $_GET['id'];

        $forumService = FORUM_BOL_ForumService::getInstance();
        $postDto = $forumService->findPostById($postId);
        if($postDto == null || ($postDto->userId != OW::getUser()->getId() && !OW::getUser()->isAdmin())){
            return array('valid' => false, 'message' => 'authorization_error');
        }
        $topicDto = $this->checkTopicAccess($postDto->topicId);
        $topicId = $postDto->topicId;
        if($topicDto == null){
            return array('valid' => false, 'message' => 'authorization_error');
        }

        $prevPostDto = $forumService->findPreviousPost($topicDto->id, $postId);

        $removedTopic = false;
        if ( $prevPostDto )
        {
            $topicDto->lastPostId = $prevPostDto->id;
            if ($topicDto->conclusionPostId == $postId)
                $topicDto->conclusionPostId = null;
            $forumService->deletePost($postId);
            $forumService->saveOrUpdateTopic($topicDto);
        } else {
            $removedTopic = true;
            $forumService->deleteTopic($topicDto->id);
        }

        $topicInfo = null;
        if (!$removedTopic) {
            $topicInfo = $this->preparedTopic($topicDto);
        }

        return array('valid' => true, 'topic' => $topicInfo, 'topicId' => (int) $topicId);
    }

    public function concludeTopic() {
        if(!FRMSecurityProvider::checkPluginActive('forum', true)){
            return array('valid' => false, 'message' => 'authorization_error');
        }
        if(!isset($_GET['topicId']) || empty($_GET['topicId'])){
            return array('valid' => false, 'message' => 'authorization_error');
        }
        if(!isset($_GET['postId']) || empty($_GET['postId'])){
            return array('valid' => false, 'message' => 'authorization_error');
        }
        $guestAccess = FRMMOBILESUPPORT_BOL_WebServiceGeneral::getInstance()->checkGuestAccess();
        if(!$guestAccess){
            return array('valid' => false, 'message' => 'authorization_error');
        }
        $topicDto = $this->checkTopicAccess($_GET['topicId']);
        if($topicDto == null){
            return array('valid' => false, 'message' => 'authorization_error');
        }
        $forumService = FORUM_BOL_ForumService::getInstance();
        $canConclude = $forumService->canCurrentUserConcludeTopic($topicDto->id);
        if(!$canConclude){
            return array('valid' => false, 'message' => 'authorization_error');
        }

       $postId = $_GET['postId'];
       $topicId = $topicDto->id;

        $isConcluded = $forumService->concludeTopic($topicId, $postId);
        $conclusionMessage = 'topic_concluded';
        if (!$isConcluded)
            $conclusionMessage = 'topic_unconcluded';
        return array('valid' => true, 'message' => $conclusionMessage, 'topic_id' => $topicId, 'post_id' => $postId);
    }
}