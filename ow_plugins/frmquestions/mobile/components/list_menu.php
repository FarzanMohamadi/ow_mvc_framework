<?php
class FRMQUESTIONS_MCMP_ListMenu extends OW_MobileComponent
{

    public function __construct($selected)
    {
        parent::__construct();

        $this->addComponent('menu', $this->getMenu($selected));
        $this->setTemplate(OW::getPluginManager()->getPlugin('frmquestions')->getMobileCmpViewDir() . 'list_menu.html');
    }

    public function onBeforeRender()
    {
        parent::onBeforeRender();
    }

    public function getMenu($selected)
    {
        $language = OW::getLanguage();

        $menu = new BASE_MCMP_ContentMenu();

        $menuItem = new BASE_MenuItem();
        $menuItem->setKey('all');
        $menuItem->setPrefix('frmquestions');
        $menuItem->setLabel( $language->text('frmquestions', 'list_all_tab') );
        $menuItem->setOrder(1);
        if($selected == 'all')
            $menuItem->setActive(true);
        $menuItem->setUrl(OW::getRouter()->urlForRoute('frmquestions-home',array('type'=>'all')));
        $menuItem->setIconClass('ow_ic_lens');

        $menu->addElement($menuItem);

        $menuItem = new BASE_MenuItem();
        $menuItem->setKey('hottest');
        $menuItem->setPrefix('frmquestions');
        $menuItem->setLabel( $language->text('frmquestions', 'feed_order_popular') );
        $menuItem->setOrder(1);
        if($selected == 'hottest')
            $menuItem->setActive(true);
        $menuItem->setUrl(OW::getRouter()->urlForRoute('frmquestions-home',array('type'=>'hottest')));
        $menuItem->setIconClass('ow_ic_star');

        $menu->addElement($menuItem);

        if ( OW::getUser()->isAuthenticated() )
        {

            $menuItem = new BASE_MenuItem();
            $menuItem->setKey('my');
            if($selected == 'my')
                $menuItem->setActive(true);
            $menuItem->setPrefix('frmquestions');
            $menuItem->setLabel( $language->text('frmquestions', 'list_my_tab') );
            $menuItem->setOrder(3);
            $menuItem->setUrl(OW::getRouter()->urlForRoute('frmquestions-home',array('type'=>'my')));
            $menuItem->setIconClass('ow_ic_user');

            $menu->addElement($menuItem);

            if ( OW::getPluginManager()->isPluginActive('friends') )
            {
                $menuItem = new BASE_MenuItem();
                $menuItem->setKey('friends');
                if($selected == 'friends')
                    $menuItem->setActive(true);
                $menuItem->setPrefix('frmquestions');
                $menuItem->setLabel( $language->text('frmquestions', 'list_friends_tab') );
                $menuItem->setOrder(2);
                $menuItem->setUrl(OW::getRouter()->urlForRoute('frmquestions-home',array('type'=>'friend')));
                $menuItem->setIconClass('ow_ic_user');

                $menu->addElement($menuItem);
            }
        }

        return $menu;
    }
}