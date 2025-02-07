<?php
/**
 * @author Farzan Mohammadi <farzan.mohamadii@gmail.com>
 * @package ow_plugins.frmhashtag
 * @since 1.0
 */
class FRMHASHTAG_CTRL_Load extends OW_ActionController
{

    public function __construct()
    {
    }

    /***
     * @param $params
     * @throws Redirect404Exception
     */
    public function index($params){
        $service = FRMHASHTAG_BOL_Service::getInstance();
        $selectedTab = empty($params['tab'])?'':$params['tab'];
        $tag = empty($params['tag'])? false:trim(htmlspecialchars(UTIL_HtmlTag::stripJs(urldecode($params['tag']))));
        $tag = preg_replace("/[#@! &^%$';+()]/", '', $tag);
        if($tag == '')
            $tag = false;
        $this->assign('tag',$tag);
        $contentMenu = new BASE_CMP_ContentMenu(null);
        $isEmptyList = false;

        //search form
        $this->addForm($service->getSearchForm($selectedTab));

        if(!$tag) {
            //no tag specified
            $page_title = OW::getLanguage()->text('frmhashtag', 'list_page_title_default');
            $top_tags_tmp = $service->findTags("",50);
            $top_tags = array();
            if(count($top_tags_tmp)>0) {
                $count_max = $top_tags_tmp[0]['count'];
                foreach ($top_tags_tmp as $item) {
                    $size = 13 + intval(intval($item['count']) * 16 / $count_max);
                    $top_tags[] = array(
                        'label' => $item['tag'],
                        'size' => $size,
                        'lineHeight' => $size + 4,
                        'url' => OW::getRouter()->urlForRoute('frmhashtag.tag', array('tag' => $item['tag']))
                    );
                }
            }
            $this->assign('top_tags',$top_tags);
            $isEmptyList = (count($top_tags)==0);
            $this->assign('no_newsfeed',false);
        }else if(!FRMSecurityProvider::checkPluginActive('newsfeed', true)) {
            $page_title = OW::getLanguage()->text('frmhashtag', 'list_page_title_default');
            $this->assign('no_newsfeed',true);
        }else{
            $page_title = OW::getLanguage()->text('frmhashtag', 'list_page_title', array("tag"=>$tag));

            //menu
            $contentMenuArray = $service->getContentMenu($tag, $selectedTab);
            if($selectedTab != $contentMenuArray['default']){
                $this->redirect(OW::getRouter()->urlForRoute('frmhashtag.tag.tab', array('tag' => $tag , 'tab' => $contentMenuArray['default'])));
            }

            $allCounts = $contentMenuArray['allCounts'];
            $selectedTab = $contentMenuArray['default'];
            $selectedPage = (!empty($_GET['page']) && intval($_GET['page']) > 0 ) ? $_GET['page'] : 1;

            $this->assign('no_newsfeed',false);
            $this->assign('selected_tab',$selectedTab);

            if ($selectedTab == "newsfeed" && OW::getUser()->isAuthorized('frmhashtag', 'view_newsfeed')) {
                $entityIds = $service->findEntitiesByTag($tag, "user-status");
                if (count($entityIds) > 0) {
                    $this->addComponent('newsfeedComponent', new FRMHASHTAG_CMP_Newsfeed($entityIds, $allCounts['newsfeed']));
                }
                $isEmptyList = (count($entityIds) == 0);
            } else if ($selectedTab == "news" && OW::getPluginManager()->isPluginActive('frmnews')) {
                $entityIds = $service->findEntitiesByTag($tag, "news-entry");
                if (count($entityIds) > 0) {
                    $this->addComponent('newsComponent', new FRMHASHTAG_CMP_News($entityIds, $selectedPage));
                }
                $isEmptyList = (count($entityIds) == 0);
            }
            else if ($selectedTab == "blogs" && OW::getPluginManager()->isPluginActive('blogs')) {
                $entityIds = $service->findEntitiesByTag($tag, "blog-post");
                if (count($entityIds) > 0) {
                    $this->addComponent('blogsComponent', new FRMHASHTAG_CMP_Blogs($entityIds, $selectedPage));
                }
                $isEmptyList = (count($entityIds) == 0);
            }
            else if ($selectedTab == "groups" && OW::getPluginManager()->isPluginActive('groups')) {
                $entityIds = $service->findGroupEntitiesByTag($tag);
                if (count($entityIds) > 0) {
                    $this->addComponent('groupsComponent', new FRMHASHTAG_CMP_Groups($entityIds, $allCounts['groups'], $selectedPage));
                }
                $isEmptyList = (count($entityIds) == 0);
            } else if ($selectedTab == "event" && OW::getPluginManager()->isPluginActive('event')) {
                $entityIds = $service->findEntitiesByTag($tag, "event");
                if (count($entityIds) > 0) {
                    $this->addComponent('eventComponent', new FRMHASHTAG_CMP_Event($entityIds, $allCounts['event'], $selectedPage));
                }
                $isEmptyList = (count($entityIds) == 0);
            } else if ($selectedTab == "video" && OW::getPluginManager()->isPluginActive('video')) {
                $entityIds = $service->findEntitiesByTag($tag, "video_comments");
                if (count($entityIds) > 0) {
                    $this->addComponent('videoComponent', new FRMHASHTAG_CMP_Video($entityIds, $allCounts['video'],$selectedPage));
                }
                $isEmptyList = (count($entityIds) == 0);
            } else if ($selectedTab == "photo" && OW::getPluginManager()->isPluginActive('photo')) {
                $this->addComponent('photoComponent', new FRMHASHTAG_CMP_Photo($tag,  $allCounts['photo']));
            } else if ($selectedTab == "forum" && OW::getPluginManager()->isPluginActive('forum')) {
                $entityIds = $service->findEntitiesByTag($tag, "forum-post");
                if (count($entityIds) > 0) {
                    $this->addComponent('forumComponent', new FRMHASHTAG_CMP_Forum($entityIds, $allCounts['forum'], $selectedPage));
                }
                $isEmptyList = (count($entityIds) == 0);
            } else {
                $notFound = true;
                foreach ($allCounts as $key=>$count){
                    if($count > 0){
                        $notFound = false;
                        break;
                    }
                }
                if ($notFound) {
                    throw new Redirect404Exception();
                }else{
                    $this->assign('selected_tab','no_access');
                    $this->addComponent('no_access', new FRMHASHTAG_CMP_NoAccess($allCounts));
                }
            }
        }

        if(isset($selectedTab)) {
            $contentMenuArray = $service->getContentMenu($tag, $selectedTab);
            $contentMenu = $contentMenuArray['menu'];
            $contentMenu = new BASE_CMP_ContentMenu($contentMenu);
            $selectedTab = $contentMenuArray['default'];
            $contentMenu->setItemActive($selectedTab);
        }

        $this->addComponent('menu', $contentMenu);
        $this->assign('isEmpty',$isEmptyList);
        OW::getNavigation()->activateMenuItem(OW_Navigation::MAIN, 'frmhashtag', 'main_menu_item');

        $this->setPageHeading($page_title);
        $this->setPageTitle($page_title);
        $this->setPageHeadingIconClass('ow_ic_write');
        $this->setDocumentKey("hashtag_index");
    }

    /***
     * @param $params
     * @throws AuthenticateException
     */
    public function loadTags($params){
        if (!OW::getUser()->isAuthenticated()) {
            throw new AuthenticateException();
        }

        $tag = false;
        if(isset($params['tag']))
            $tag = trim(htmlspecialchars(urldecode($params['tag'])));

        try {
            //sample
            $data[] = array('tag'=>'moradnejad', 'count'=>'4');

            //actual
            $max_count = OW::getConfig()->getValue('frmhashtag','max_count');
            $data = FRMHASHTAG_BOL_Service::getInstance()->findTags($tag,$max_count);

            exit(json_encode($data));
        }catch(Exception $e){
            exit(json_encode(array('status'=>'error','error_msg'=>OW::getLanguage()->text('base','comment_add_post_error'))));
        }
    }

    public function ajaxResponder($params)
    {
        if(!OW::getRequest()->isAjax()){
            throw new Redirect404Exception();
        }
        $photoService = PHOTO_BOL_PhotoService::getInstance();
        if (isset($_POST['ajaxFunc']) && $_POST['ajaxFunc'] == 'ajaxDeletePhoto') {
            $photoId = (int)$_POST['entityId'];
            $ownerId = $photoService->findPhotoOwner($photoId);
            $ownerMode = $ownerId !== null && $ownerId == OW::getUser()->getId();

            if($ownerId == null || $ownerMode) {
                $photoService->deletePhoto($photoId);
                exit( json_encode(array(
                    'result' => true,
                    'msg' => OW::getLanguage()->text('admin', 'theme_graphics_delete_success_message'),
                    'imageId' => $photoId
                )));
            }
            return;
        }

        $offset = 1;
        if(isset($_POST['offset'])){
            $offset = $_POST['offset'];
        }
        $tag = '';
        if(isset($params['tag'])){
            $tag = $params['tag'];
            $tag = urldecode($tag);
        }
        FRMHASHTAG_BOL_Service::getInstance()->getPhotoList($offset, $tag);
    }
}

