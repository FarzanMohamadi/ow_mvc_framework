<?php
/**
 * @package ow_plugins.blogs.controllers
 * @since 1.0
 */
class BLOGS_CTRL_Blog extends OW_ActionController
{

    public function index($params)
    {
        if ( empty($params['list']) )
        {
            $params['list'] = 'latest';
        }

        OW::getNavigation()->activateMenuItem(OW_Navigation::MAIN, 'blogs', 'main_menu_item');

        $this->setPageHeading(OW::getLanguage()->text('blogs', 'list_page_heading'));
        $this->setPageHeadingIconClass('ow_ic_write');

        if ( !OW::getUser()->isAdmin() && !OW::getUser()->isAuthorized('blogs', 'view') )
        {
            $status = BOL_AuthorizationService::getInstance()->getActionStatus('blogs', 'view');
            throw new AuthorizationException($status['msg']);
        }

        $page = (!empty($_GET['page']) && intval($_GET['page']) > 0 ) ? $_GET['page'] : 1;

        $addNew_promoted = false;
        $addNew_isAuthorized = false;
        if (OW::getUser()->isAuthenticated())
        {
            if (OW::getUser()->isAuthorized('blogs', 'add'))
            {
                $addNew_isAuthorized = true;
                $this->assign('my_published_posts_url', OW::getRouter()->urlForRoute('blog-manage-posts'));
            }
            else
            {
                $status = BOL_AuthorizationService::getInstance()->getActionStatus('blogs', 'add');
                if ($status['status'] == BOL_AuthorizationService::STATUS_PROMOTED)
                {
                    $addNew_promoted = true;
                    $addNew_isAuthorized = true;
                    $script = '$("#btn-add-new-post").click(function(){
                        OW.authorizationLimitedFloatbox('.json_encode($status['msg']).');
                        return false;
                    });';
                    OW::getDocument()->addOnloadScript($script);
                }
                else
                {
                    $addNew_isAuthorized = false;
                }
            }
        }

        $this->assign('addNew_isAuthorized', $addNew_isAuthorized);
        $this->assign('addNew_promoted', $addNew_promoted);

        $rpp = (int) OW::getConfig()->getValue('blogs', 'results_per_page');

        $first = ($page - 1) * $rpp;

        $count = $rpp;

        $case = $params['list'];
        if ( !in_array($case, array( 'latest', 'browse-by-tag', 'most-discussed', 'top-rated' )) )
        {
            throw new Redirect404Exception();
        }
        $showList = true;
        $isBrowseByTagCase = $case == 'browse-by-tag';

        $contentMenu = $this->getContentMenu();
        $contentMenu->setItemActive($case);
        $this->addComponent('menu', $contentMenu );
        $this->assign('listType', $case);

        $this->assign('isBrowseByTagCase', $isBrowseByTagCase);

        $tagSearch = new BASE_CMP_TagSearch(OW::getRouter()->urlForRoute('blogs.list', array('list'=>'browse-by-tag')));

        $this->addComponent('tagSearch', $tagSearch);

        $tagCount = null;
        if ( $isBrowseByTagCase )
        {
            $tagCount = 1000;
        }

        $tagCloud = new BASE_CMP_EntityTagCloud('blog-post', OW::getRouter()->urlForRoute('blogs.list', array('list'=>'browse-by-tag')), $tagCount);

        if ( $isBrowseByTagCase )
        {
            $tagCloud->setTemplate(OW::getPluginManager()->getPlugin('base')->getCmpViewDir() . 'big_tag_cloud.html');

            $tag = !(empty($_GET['tag'])) ? strip_tags(UTIL_HtmlTag::stripTags($_GET['tag'])) : '';
            $this->assign('tag', $tag );

            if (empty($tag))
            {
                $showList = false;
            }
        }

        $this->addComponent('tagCloud', $tagCloud);
        $this->assign('showList', $showList);

        list($list, $itemsCount) = PostService::getInstance()->getBlogList($case, $first, $count);

        $posts = array();
        $authorIdList = array();

        foreach ( $list as $item )
        {
            $dto = $item['dto'];
            $stringRenderer = OW::getEventManager()->trigger(new OW_Event(FRMEventManager::ON_AFTER_NEWSFEED_STATUS_STRING_READ,array('string' => $dto->getPost())));

            $stringRenderer = OW::getEventManager()->trigger(new OW_Event(FRMEventManager::ON_BEFORE_RENDER_STRING, array('string' => $dto->getPost())));
            if (isset($stringRenderer->getData()['string'])) {
                $dto->post = ($stringRenderer->getData()['string']);
            }

            if(isset($stringRenderer->getData()['string'])){
                $dto->setPost($stringRenderer->getData()['string']);
            }
            $dto->setPost($dto->getPost());
            $dto->setTitle( UTIL_String::truncate(UTIL_HtmlTag::stripTagsAndJs($dto->getTitle()), 250, '...' )  );

            $text = explode("<!--more-->", $dto->getPost());

            $isPreview = count($text) > 1;

            if ( !$isPreview )
            {
                $text = explode('<!--page-->', $text[0]);
                $showMore = count($text) > 1;
            }
            else
            {
                $showMore = true;
            }
            if(!$showMore) {
                $textwithouttag = UTIL_HtmlTag::stripTagsAndJs($text[0]);
                if (strlen($textwithouttag) > 500) {
                    $spacePosition = strpos($text[0], ' ', 500);
                    if (strlen($text[0]) > $spacePosition) {
                        $text[0] = UTIL_String::truncate_html($text[0], 500);
                        $showMore = true;
                    }
                }
            }


            $text = $text[0];

            $posts[] = array(
                'dto' => $dto,
                'text' => $text,
                'showMore' => $showMore,
                'url' => OW::getRouter()->urlForRoute('user-post', array('id'=>$dto->getId()))
            );

            $authorIdList[] = $dto->authorId;
            $idList[] = $dto->getId();
        }

        if ( !empty($idList) )
        {
            $avatars = BOL_AvatarService::getInstance()->getDataForUserAvatars($authorIdList, true, false);
            foreach ( $avatars as $avatar )
            {
                $userId = $avatar['userId'];
                $avatars[$userId]['url'] = BOL_UserService::getInstance()->getUserUrl($userId);
            }
            $this->assign('avatars', $avatars);

            $nlist = array();
            foreach ( $avatars as $userId => $avatar )
            {
                $nlist[$userId] = $avatar['title'];
            }
            $urls = BOL_UserService::getInstance()->getUserUrlsForList($authorIdList);
            $this->assign('toolbars', $this->getToolbar($idList, $list, $urls, $nlist));
        }

        $this->assign('list', $posts);
        $this->assign('url_new_post', OW::getRouter()->urlForRoute('post-save-new'));

        $paging = new BASE_CMP_Paging($page, ceil($itemsCount / $rpp), 5);
        $this->addComponent('paging', $paging);

        $params = array(
            "sectionKey" => "blogs",
            "entityKey" => "blogsList",
            "title" => "blogs+".str_replace("-", "_", $case)."_title",
            "description" => "blogs+meta_desc_blogs_list",
            "keywords" => "blogs+meta_keywords_blogs_list",
            "vars" => array( "blog_list" => OW::getLanguage()->text("blogs", str_replace("-", "_", $case)."_title") )
        );
        
        OW::getEventManager()->trigger(new OW_Event("base.provide_page_meta_info", $params));
        $this->setDocumentKey("user_blogs");
    }

    /**
     * Get top menu for Blog post list
     *
     * @return BASE_CMP_ContentMenu
     */
    private function getContentMenu()
    {
        $menuItems = array();

        $listNames = array(
            'latest' => array('iconClass' => 'ow_ic_latest ow_dynamic_color_icon'),
            'most-discussed' => array('iconClass' => 'ow_ic_most_discussed ow_dynamic_color_icon'),
            'top-rated' => array('iconClass' => 'ow_ic_most_popular ow_dynamic_color_icon'),
            'browse-by-tag' => array('iconClass' => 'ow_ic_tag ow_dynamic_color_icon')
        );

        $order = 0;
        foreach ( $listNames as $listKey => $listArr )
        {
            $menuItem = new BASE_MenuItem();
            $menuItem->setKey($listKey);
            $menuItem->setOrder($order);
            $menuItem->setUrl(OW::getRouter()->urlForRoute('blogs.list', array('list' => $listKey)));
            $menuItemKey = explode('-', $listKey);
            $listKey = "";
            foreach ($menuItemKey as $key)
            {
                $listKey .= strtoupper(substr($key, 0, 1)).substr($key, 1);
            }

            $menuItem->setLabel(OW::getLanguage()->text('blogs', 'menuItem'.$listKey));
            $menuItem->setIconClass($listArr['iconClass']);
            $menuItems[] = $menuItem;
            $order++;
        }

        return new BASE_CMP_ContentMenu($menuItems);
    }

    private function getToolbar( $idList, $list, $ulist, $nlist )
    {
        if ( empty($idList) )
        {
            return array();
        }

        $info = array();

        $info['comment'] = BOL_CommentService::getInstance()->findCommentCountForEntityList('blog-post', $idList);

        $info['rate'] = BOL_RateService::getInstance()->findRateInfoForEntityList('blog-post', $idList);

        $info['tag'] = BOL_TagService::getInstance()->findTagListByEntityIdList('blog-post', $idList);

        $toolbars = array();

        foreach ( $list as $item )
        {
            $id = $item['dto']->id;

            $userId = $item['dto']->authorId;

            $toolbars[$id] = array(
                array(
                    'class' => 'ow_icon_control ow_ic_user',
                    'label' => !empty($nlist[$userId]) ? $nlist[$userId] : OW::getLanguage()->text('base', 'deleted_user'),
                    'href' => !empty($ulist[$userId]) ? $ulist[$userId] : '#'
                ),
                array(
                    'class' => 'ow_ipc_date',
                    'label' => UTIL_DateTime::formatDate($item['dto']->timestamp)
                ),
            );

            if ( $info['rate'][$id]['avg_score'] > 0 )
            {
                $toolbars[$id][] = array(
                    'label' => OW::getLanguage()->text('blogs', 'rate') . ' <span class="ow_txt_value">' . ( ( $info['rate'][$id]['avg_score'] - intval($info['rate'][$id]['avg_score']) == 0 ) ? intval($info['rate'][$id]['avg_score']) : sprintf('%.2f', $info['rate'][$id]['avg_score']) ) . '</span>',
                );
            }

            if ( !empty($info['comment'][$id]) )
            {
                $toolbars[$id][] = array(
                    'label' => OW::getLanguage()->text('blogs', 'comments') . ' <span class="ow_txt_value">' . $info['comment'][$id] . '</span>',
                );
            }


            if ( empty($info['tag'][$id]) )
            {
                continue;
            }

            $value = "<span class='ow_wrap_normal'>" . OW::getLanguage()->text('blogs', 'tags') . ' ';

            foreach ( $info['tag'][$id] as $tag )
            {
                $value .='<a href="' . OW::getRouter()->urlForRoute('blogs.list', array('list'=>'browse-by-tag')) . "?tag={$tag}" . "\">{$tag}</a>, ";
            }

            $value = mb_substr($value, 0, mb_strlen($value) - 2);
            $value .= "</span>";
            $toolbars[$id][] = array(
                'label' => $value,
            );
        }

        return $toolbars;
    }

    /***
     * @throws Redirect404Exception
     */
    public function ajaxDeleteAttachment()
    {
        $result = array('result' => false);

        if ( !isset($_POST['attachmentId']) || !OW::getRequest()->isAjax())
        {
            exit(json_encode($result));
        }

        if(FRMSecurityProvider::checkPluginActive('frmsecurityessentials', true)) {
            $code =$_POST['attachmentDeleteCode'];
            if(!isset($code)){
                throw new Redirect404Exception();
            }
            OW::getEventManager()->trigger(new OW_Event('frmsecurityessentials.on.check.request.manager',
                array('senderId' => OW::getUser()->getId(), 'code'=>$code,'activityType'=>'delete_attachment')));
        }

        PostService::getInstance()->deleteAttachment($_POST['attachmentId']);
    }
}