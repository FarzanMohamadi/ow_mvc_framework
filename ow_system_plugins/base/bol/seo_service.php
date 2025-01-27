<?php
/**
 * Seo service.
 *
 * @author Farzan Mohammadi <farzan.mohamadii@gmail.com>
 * @package ow_system_plugins.base.bol
 * @method static BOL_SeoService getInstance()
 * @since 1.8.4
 */
class BOL_SeoService
{
    use OW_Singleton;

    /**
     * Sitemap item update weekly
     */
    const SITEMAP_ITEM_UPDATE_WEEKLY = 'weekly';

    /**
     * Sitemap item update daily
     */
    const SITEMAP_ITEM_UPDATE_DAILY = 'daily';

    /**
     * Sitemap file name
     */
    const SITEMAP_FILE_NAME = 'sitemap%s.xml';

    /**
     * Sitemap dir name
     */
    const SITEMAP_DIR_NAME = 'sitemap';

    /**
     * Sitemap update daily
     */
    const SITEMAP_UPDATE_DAILY = 'daily';

    /**
     * Sitemap update weekly
     */
    const SITEMAP_UPDATE_WEEKLY = 'weekly';

    /**
     * Sitemap update monthly
     */
    const SITEMAP_UPDATE_MONTHLY = 'monthly';

    /**
     * Meta title max length
     */
    const META_TITLE_MAX_LENGTH = 70;

    /**
     * Meta description max length
     */
    const META_DESC_MAX_LENGTH = 150;

    const EVENT_BUILD_SITE_MAP_INCOMPLETE = 'base.build_site_map_incomplete';
    const EVENT_GENERATE_SITE_MAP_INCOMPLETE = 'base.generate_site_map_incomplete';

    /**
     * Sitemap
     *
     * @var BOL_SitemapDao
     */
    protected $sitemapDao;

    /**
     * Constructor.
     */
    private function __construct()
    {
        $this->sitemapDao = BOL_SitemapDao::getInstance();
    }

    /**
     * Get sitemap url
     *
     * @param integer $part
     * @return string
     */
    public function getSitemapUrl($part = null)
    {
        $url =  OW::getRouter()->urlForRoute('base.sitemap');

        return $part
            ? $url . '?part=' . $part
            : $url;
    }

    /**
     * Get sitemap path
     *
     * @param integer $part
     * @return string
     */
    public function getSitemapPath($part = null)
    {
        $sitemapBuild = (int) OW::getConfig()->getValue('base', 'seo_sitemap_last_build');
        $sitemapPath = $this->getBaseSitemapPath() . $sitemapBuild . DS;

        return $sitemapPath . sprintf(self::SITEMAP_FILE_NAME, $part);
    }

    /**
     * Get base sitemap path
     *
     * @return string
     */
    protected function getBaseSitemapPath()
    {
        $path = OW::getPluginManager()->getPlugin('base')->getUserFilesDir() . self::SITEMAP_DIR_NAME . DS;

        if ( !OW::getStorage()->fileExists($path) )
        {
            OW::getStorage()->mkdir($path);
//            OW::getStorage()->chmod($path, 0777, true);
        }

        return $path;
    }

    /**
     * Escape url
     *
     * @param string $url
     * @return string
     */
    protected function escapeSitemapUrl($url)
    {
        return htmlspecialchars($url, ENT_QUOTES | ENT_XML1);
    }

    /**
     * Generate sitemap
     *
     * @return void
     */
    public function generateSitemap()
    {
        $inProgress = (int) OW::getConfig()->getValue('base', 'seo_sitemap_in_progress');
        $inProgressTime = (int) OW::getConfig()->getValue('base', 'seo_sitemap_in_progress_time');

        // is it possible to start sitemap generating?
        if ( $inProgress && time() - $inProgressTime < 3600 ) {
            return;
        }

        OW::getConfig()->saveConfig('base', 'seo_sitemap_in_progress', 1, null, false);
        OW::getConfig()->saveConfig('base', 'seo_sitemap_in_progress_time', time(), null, false);

        $isAllEntitiesFetched = true;

        // don't collect urls while sitemap is building
        if ( !(int) OW::getConfig()->getValue('base', 'seo_sitemap_build_in_progress') )
        {
            OW::getConfig()->saveConfig('base', 'seo_sitemap_build_finished', 0, null, false);

            // get sitemap entities
            $entities = $this->getSitemapEntities();
            $maxCount = (int) OW::getConfig()->getValue('base', 'seo_sitemap_entitites_max_count');
            $limit = (int) OW::getConfig()->getValue('base', 'seo_sitemap_entitites_limit');

            if ( $entities )
            {
                // fetch urls
                foreach ( $entities as $entityType => $entityData )
                {
                    // skip all disabled entities
                    if ( !$entityData['enabled'] )
                    {
                        continue;
                    }

                    // get sitemap items
                    foreach ( $entityData['items'] as $item )
                    {
                        // skip already fetched items
                        if ( $item['data_fetched'] )
                        {
                            continue;
                        }

                        // correct the limit value
                        if ( $item['urls_count'] + $limit > $maxCount )
                        {
                            $limit = $maxCount - $item['urls_count'];
                        }

                        // get urls
                        $event = new OW_Event('base.sitemap.get_urls', array(
                            'entity' => $item['name'],
                            'limit' => $limit,
                            'offset' => $item['urls_count']
                        ));

                        OW::getEventManager()->trigger($event);

                        if(empty($event->getData())){
                            continue;
                        }
                        $newUrlsCount = count($event->getData());
                        $totalUrlsCount = (int) $item['urls_count'] + $newUrlsCount;
                        $isAllEntitiesFetched = false;

                        !$newUrlsCount || $newUrlsCount != $limit || $totalUrlsCount >= $maxCount
                            ? $this->updateSitemapEntityItem($entityType, $item['name'], true, $totalUrlsCount)
                            : $this->updateSitemapEntityItem($entityType, $item['name'], false, $totalUrlsCount);

                        // add new urls
                        if ( $newUrlsCount )
                        {
                            // process received urls
                            foreach ( $event->getData() as $url )
                            {
                                if (isset($url) && $this->isSitemapUrlUnique($url) )
                                {
                                    $this->addSiteMapUrl($url, $entityType);
                                }
                            }
                        }

                        // we process at a time only one entity item
                        break 2;
                    }
                }
            }
        }

        // build sitemap
        if ( $isAllEntitiesFetched )
        {
            $this->buildSitemap();
        } else {
            OW::getEventManager()->trigger(new OW_Event(self::EVENT_GENERATE_SITE_MAP_INCOMPLETE));
        }

        OW::getConfig()->saveConfig('base', 'seo_sitemap_in_progress', 0, null, false);
    }

    /**
     * Build sitemap
     *
     * @return void
     */
    protected function buildSitemap()
    {
        OW::getConfig()->saveConfig('base', 'seo_sitemap_build_in_progress', 1, null, false);

        $urls = $this->sitemapDao->findUrlList( (int) OW::getConfig()->getValue('base', 'seo_sitemap_max_urls_in_file') );
        $newSitemapBuild = (int) OW::getConfig()->getValue('base', 'seo_sitemap_last_build') + 1;
        $entities = $this->getSitemapEntities();
        $sitemapIndex = (int) OW::getConfig()->getValue('base', 'seo_sitemap_index');
        $newSitemapPath = $this->getBaseSitemapPath() . $newSitemapBuild . DS;

        if ( !OW::getStorage()->fileExists($newSitemapPath) )
        {
            OW::getStorage()->mkdir($newSitemapPath);
//            OW::getStorage()->chmod($newSitemapPath, 0777, true);
        }

        // generate list of sitemaps
        if ( $urls )
        {
            $urlsIds = array();

            // generate parts of sitemap
            $processedUrls   = [];
            $defaultLanguage = BOL_LanguageService::getInstance()->findDefault();
            $activeLanguages = BOL_LanguageService::getInstance()->findActiveList();
            $activeLanguagesCount = count($activeLanguages);

            // process urls
            foreach( $urls as $urlData )
            {
                $urlsIds[] = $urlData['id'];

                if ( $activeLanguagesCount > 1 )
                {
                    // process active languages
                    foreach( $activeLanguages as $language )
                    {
                        $mainUrl = null;

                        // get main url
                        if ( $language->id == $defaultLanguage->id )
                        {
                            $mainUrl = $urlData['url']; // don't include a lang param for default language
                        }
                        else {
                            $mainUrl = strstr($urlData['url'], '?')
                                ? $urlData['url'] . '&language_id=' . $language->id
                                : $urlData['url'] . '?language_id=' . $language->id;
                        }

                        // process alternate languages
                        $alternateLanguages = array();
                        foreach( $activeLanguages as $altLanguage )
                        {
                            if ( $altLanguage->id == $defaultLanguage->id )
                            {
                                $alternateLanguages[] = array(
                                    'url' => $this->escapeSitemapUrl($urlData['url']),
                                    'code' => $altLanguage->tag
                                );
                            }
                            else
                            {
                                $alternateLanguages[] = array(
                                    'url' => strstr($urlData['url'], '?')
                                        ? $this->escapeSitemapUrl($urlData['url'] . '&language_id=' . $altLanguage->id)
                                        : $this->escapeSitemapUrl($urlData['url'] . '?language_id=' . $altLanguage->id),
                                    'code' => $altLanguage->tag
                                );
                            }
                        }

                        $processedUrls[] = array(
                            'url' => $this->escapeSitemapUrl($mainUrl),
                            'changefreq' => $entities[$urlData['entityType']]['changefreq'],
                            'priority' => $entities[$urlData['entityType']]['priority'],
                            'alternateLanguages' => $alternateLanguages
                        );
                    }
                }
                else
                {
                    $processedUrls[] = array(
                        'url' => $this->escapeSitemapUrl($urlData['url']),
                        'changefreq' => $entities[$urlData['entityType']]['changefreq'],
                        'priority' => $entities[$urlData['entityType']]['priority'],
                        'alternateLanguages' => array()
                    );
                }
            }

            // delete processed urls
            $urlsIds = array_chunk($urlsIds, 500);
            foreach( $urlsIds as $urlList )
            {
                $this->sitemapDao->deleteByIdList($urlList);
            }

            // render data
            $view = new OW_View();
            $view->setTemplate(OW::getPluginManager()->getPlugin('base')->getViewDir() . 'sitemap_part.xml');
            $view->assign('urls', $processedUrls);

            // save data in a file
            OW::getStorage()->fileSetContent($newSitemapPath . sprintf(self::SITEMAP_FILE_NAME, $sitemapIndex + 1), $view->render());

            OW::getConfig()->saveConfig('base', 'seo_sitemap_index', $sitemapIndex + 1, null, false);

            OW::getEventManager()->trigger(new OW_Event(self::EVENT_BUILD_SITE_MAP_INCOMPLETE));
            return;
        }

        // generate a final sitemap index file
        if ( $sitemapIndex )
        {
            $sitemapParts = array();
            $lastModDate = date('c', time());

            for ( $i = 1; $i <= $sitemapIndex; $i++ )
            {
                $sitemapParts[] = array(
                    'url' => $this->escapeSitemapUrl($this->getSitemapUrl($i)),
                    'lastmod' => $lastModDate
                );
            }

            // render data
            $view = new OW_View();
            $view->setTemplate(OW::getPluginManager()->getPlugin('base')->getViewDir() . 'sitemap.xml');
            $view->assign('urls', $sitemapParts);

            // save data in a file
            OW::getStorage()->fileSetContent($newSitemapPath . sprintf(self::SITEMAP_FILE_NAME, ''), $view->render());

            // update configs
            OW::getConfig()->saveConfig('base', 'seo_sitemap_index', 0, null, false);
            OW::getConfig()->saveConfig('base', 'seo_sitemap_last_start', time(), null, false);
            OW::getConfig()->saveConfig('base', 'seo_sitemap_last_build', $newSitemapBuild, null, false);

            // truncate table
            $this->sitemapDao->truncate();
        }

        // clear all entities
        foreach ( $entities as $entityType => $entityData )
        {
            foreach ( $entityData['items'] as $item )
            {
                $this->updateSitemapEntityItem($entityType, $item['name'], false, 0);
            }
        }

        // remove a previous build
        $previousBuildPath = $this->getBaseSitemapPath() . ($newSitemapBuild - 1) . '/';

        if ( OW::getStorage()->fileExists($previousBuildPath) )
        {
            UTIL_File::removeDir($previousBuildPath);
        }

        OW::getConfig()->saveConfig('base', 'seo_sitemap_build_in_progress', 0, null, false);
        OW::getConfig()->saveConfig('base', 'seo_sitemap_build_finished', 1, null, false);
    }

    /**
     * Is sitemap ready for the next build
     *
     * @return boolean
     */
    public function isSitemapReadyForNextBuild()
    {
        $lastStart  = (int) OW::getConfig()->getValue('base', 'seo_sitemap_last_start');
        $scheduleUpdate = OW::getConfig()->getValue('base', 'seo_sitemap_schedule_update');

        if ( !$lastStart )
        {
            return true;
        }

        $secondsInDay = 86400;

        switch($scheduleUpdate)
        {
            case self::SITEMAP_UPDATE_MONTHLY :
                $delaySeconds = $secondsInDay * 30;
                break;

            case self::SITEMAP_UPDATE_WEEKLY :
                $delaySeconds = $secondsInDay * 6;
                break;

            case self::SITEMAP_UPDATE_DAILY:
            default:
                $delaySeconds = $secondsInDay;
        }

        return time() - $lastStart >= $delaySeconds;
    }

    /**
     * Get sitemap entities
     *
     * @return array
     */
    public function getSitemapEntities()
    {
        $path = OW::getPluginManager()->getPlugin('base')->getPluginFilesDir() . 'seo_sitemap.json';
        $content = file_get_contents($path);
        return json_decode($content, true);
    }

    /***
     * @param array $entities
     */
    public function setSitemapEntities($entities)
    {
        $path = OW::getPluginManager()->getPlugin('base')->getPluginFilesDir() . 'seo_sitemap.json';
        file_put_contents($path, json_encode($entities));
    }

    /**
     * Add sitemap entity
     *
     * @param string $langPrefix
     * @param string $label
     * @param string $entityType
     * @param string $description
     * @param array $items
     * @param float $priority
     * @param string $changeFreq
     * @return void
     */
    public function addSitemapEntity($langPrefix, $label, $entityType, array $items, $description = null, $priority = 0.5, $changeFreq = self::SITEMAP_ITEM_UPDATE_WEEKLY)
    {
        $entities = $this->getSitemapEntities();

        if ( !array_key_exists($entityType, $entities) )
        {
            // process items
            $processedItems = array();
            foreach ($items as $item) {
                $processedItems[] = array(
                    'name' => $item,
                    'data_fetched' => false,
                    'urls_count' => 0,
                );
            }

            $entities[$entityType] = array(
                'lang_prefix' => $langPrefix,
                'label' => $label,
                'description' => $description,
                'items' => $processedItems,
                'enabled' => true,
                'priority' => $priority,
                'changefreq' => $changeFreq
            );

            $this->setSitemapEntities($entities);
        }
    }

    /**
     * Enable sitemap entity
     *
     * @param string $entityType
     * @return void
     */
    public function enableSitemapEntity($entityType)
    {
        $this->setSitemapEntityStatus($entityType);
    }

    /**
     * Disable sitemap entity
     *
     * @param string $entityType
     * @return void
     */
    public function disableSitemapEntity($entityType)
    {
        $this->setSitemapEntityStatus($entityType, false);
    }

    /**
     * Remove sitemap entity
     *
     * @param string $entityType
     * @return void
     */
    public function removeSitemapEntity($entityType)
    {
        $entities = $this->getSitemapEntities();

        if ( array_key_exists($entityType, $entities) )
        {
            unset($entities[$entityType]);
            $this->setSitemapEntities($entities);
            // delete already collected data
            $this->deleteSitemapUrls($entityType);
        }
    }

    protected $metaData;

    /**
     * @return array
     */
    public function getMetaData()
    {
        if( $this->metaData === null )
        {
            $this->metaData = json_decode(OW::getConfig()->getValue("base", "seo_meta_info"), true);
        }

        return $this->metaData;
    }

    /**
     * @param array $data
     */
    public function setMetaData( array $data )
    {
        $this->metaData = $data;
        OW::getConfig()->saveConfig("base", "seo_meta_info", json_encode($data), null, false);
    }

    /**
     * @param $sectionKey
     * @param string $entityKey
     * @return bool
     */
    public function isMetaDisabledForEntity( $sectionKey, $entityKey )
    {
        return  isset($this->getMetaData()["disabledEntities"][$sectionKey]) && in_array($entityKey, $this->getMetaData()["disabledEntities"][$sectionKey]);
    }

    /**
     * @param BOL_User $userDto
     * @return array
     */
    public function getUserMetaInfo( BOL_User $userDto )
    {
        $result = array("user_name" => $userDto->getUsername());
        $data = BOL_QuestionService::getInstance()->getQuestionData(array($userDto->getId()), array("sex", "birthdate", "googlemap_location"))[$userDto->getId()];

        if( !empty($data["sex"]) )
        {
            $result["user_gender"] = BOL_QuestionService::getInstance()->getQuestionValueLang("sex", $data["sex"]);
        }

        if( !empty($data["birthdate"]) )
        {
            $date = UTIL_DateTime::parseDate($data["birthdate"], UTIL_DateTime::MYSQL_DATETIME_DATE_FORMAT);
            $result["user_age"] = UTIL_DateTime::getAge($date['year'], $date['month'], $date['day']);
        }

        if( !empty($data["googlemap_location"]["address"]) )
        {
            $result["user_location"] = trim($data["googlemap_location"]["address"]);
        }

        $result['user_display_name'] = BOL_UserService::getInstance()->getDisplayName($userDto->id);

        return $result;
    }

    /**
     * @param $path
     * @param $name
     */
    public function saveSocialLogo( $path, $name )
    {
        OW::getStorage()->copyFile($path, OW::getPluginManager()->getPlugin("base")->getUserFilesDir().$name);
        OW::getConfig()->saveConfig("base", "seo_social_meta_logo_name", $name, null, false);
    }

    /**
     * @return string
     */
    public function getSocialLogoUrl()
    {
        $fileName = OW::getConfig()->getValue("base", "seo_social_meta_logo_name");

        if( !$fileName )
        {
            return null;
        }

        return OW::getStorage()->getFileUrl(OW::getPluginManager()->getPlugin("base")->getUserFilesDir().$fileName);
    }

    /**
     * Delete sitemap urls
     *
     * @param string $entityType
     * @return void
     */
    protected function deleteSitemapUrls($entityType)
    {
        $example = new OW_Example();
        $example->andFieldEqual('entityType', $entityType);

        $this->sitemapDao->deleteByExample($example);
    }

    /**
     * Set sitemap entity status
     *
     * @param string $entityType
     * @param boolean $enabled
     * @return void
     */
    protected function setSitemapEntityStatus($entityType, $enabled = true)
    {
        $entities = $this->getSitemapEntities();

        if ( array_key_exists($entityType, $entities) )
        {
            $entities[$entityType]['enabled'] = $enabled;

            $this->setSitemapEntities($entities);

            if ( !$enabled )
            {
                // clear entity items
                foreach ( $entities[$entityType]['items'] as $item )
                {
                    $this->updateSitemapEntityItem($entityType, $item['name'], false, 0);
                }

                // delete already collected urls
                $this->deleteSitemapUrls($entityType);
            }
        }
    }

    /**
     * Is sitemap url unique
     *
     * @param $url
     * @return bool
     */
    protected function isSitemapUrlUnique($url)
    {
        $example = new OW_Example();
        $example->andFieldEqual('url', $url);

        return !$this->sitemapDao->countByExample($example);
    }

    /**
     * Add sitemap url
     *
     * @param string $url
     * @param string $entityType
     * @return void
     */
    protected function addSiteMapUrl($url, $entityType)
    {
        $sitemapDto = new BOL_Sitemap();
        $sitemapDto->url = $url;
        $sitemapDto->entityType = $entityType;


        $this->sitemapDao->save($sitemapDto);
    }

    /**
     * Update sitemap entity item
     *
     * @param string $entityType
     * @param string $itemName
     * @param boolean $isDataFetched
     * @param integer $urlsCount
     * @return void
     */
    protected function updateSitemapEntityItem($entityType, $itemName, $isDataFetched, $urlsCount = 0)
    {
        $entities = $this->getSitemapEntities();

        if ( array_key_exists($entityType, $entities) )
        {
            foreach ( $entities[$entityType]['items'] as &$item )
            {

                if ( $itemName == $item['name'] )
                {
                    $item['data_fetched'] = $isDataFetched;
                    $item['urls_count'] = $urlsCount;

                    break;
                }
            }

            $this->setSitemapEntities($entities);
        }
    }
}