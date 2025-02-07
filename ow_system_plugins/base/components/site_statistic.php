<?php
/**
 * @author Farzan Mohammadi <farzan.mohamadii@gmail.com>
 * @package ow_system_plugins.base.components
 * @since 1.7.6
 */
class BASE_CMP_SiteStatistic  extends OW_Component
{
    /**
     * Chart Id
     *
     * @var string
     */
    protected $chartId;

    /**
     * Entities
     *
     * @var array
     */
    protected $entities;

    /**
     * Entity labels
     *
     * @var array
     */
    protected $entityLabels;

    /**
     * Period
     *
     * @var array
     */
    protected $period;

    /**
     * Class constructor
     *
     * @param string $chartId
     * @param array $entityTypes
     * @param string $period
     * @param array $entityLabels
     */
    public function __construct( $chartId, array $entityTypes, array $entityLabels, $period = BOL_SiteStatisticService::PERIOD_TYPE_TODAY )
    {
        parent::__construct();

        $this->chartId  = $chartId;
        $this->entityTypes = $entityTypes;
        $this->entityLabels = $entityLabels;
        $this->period = $period;
    }

    /**
     * On before render
     *
     * @return void
     */
    public function onBeforeRender()
    {
        $service = BOL_SiteStatisticService::getInstance();

        // get statistics
        $entities = $service->getStatistics($this->entityTypes, $this->period);

        $chart_data = array();
        $categories = $this->entityCategories();

        for ($i = 0; $i < sizeof($categories); $i++){
            $chart_data[$i][0] = $categories[$i];
        }

        for ($i = 1; $i <= sizeof(array_keys($entities)); $i++) {
            $index = array_keys($entities)[$i - 1];
            for ($j = 0; $j < sizeof($entities[$index]); $j++) {
                $j_index = array_keys($entities[$index])[$j];
                $chart_data[$j][$i] = $entities[$index][$j_index];
            }
        }

        $table_titles = array_keys($entities);
        $date_column_title = ($this->period == "today" || $this->period == "yesterday") ?
            OW_Language::getInstance()->text('base', 'time') :
            OW_Language::getInstance()->text('base', 'date');
        array_push($table_titles, $date_column_title);
        $this->assign("date_column_label", $date_column_title);
        $this->assign("chart_data", $chart_data);

        OW::getDocument()->addScript( OW::getPluginManager()->getPlugin('base')->getStaticJsUrl() . 'fileSaver.min.js' );
        OW::getDocument()->addScript( OW::getPluginManager()->getPlugin('base')->getStaticJsUrl() . 'xlsx.full.min.js' );
        OW::getDocument()->addScript( OW::getPluginManager()->getPlugin('base')->getStaticJsUrl() . 'tableExport.min.js' );

        // translate and process the data entities
        $data  = array();
        $total = array();
        $index = 0;

        foreach ($entities as $entity => $values)
        {
            $list = array_values($values);

            $data[] = array_merge(array(
                'label' => $this->entityLabels[$entity],
                'data' => $list
            ), $this->getChartColor($index));

            $total[] = array(
                'label' => $this->entityLabels[$entity],
                'count' => array_sum($list)
            );

            $index++;
        }

        // assign view variables
        $this->assign('chartId', $this->chartId);
        $this->assign('categories', json_encode($this->entityCategories()));
        $this->assign('data', json_encode($data, JSON_NUMERIC_CHECK));
        $this->assign('total', $total);

        // include js and css files
        OW::getDocument()->addScript(OW::getPluginManager()->getPlugin('base')->getStaticJsUrl() . 'chart.js');
    }

    /**
     * Get entity categories
     *
     * @return array
     */
    protected function entityCategories()
    {
        switch ($this->period)
        {
            case BOL_SiteStatisticService::PERIOD_TYPE_LAST_YEAR :
                $categories =  $this->getMonths(12);
                break;

            case BOL_SiteStatisticService::PERIOD_TYPE_LAST_30_DAYS :
            case BOL_SiteStatisticService::PERIOD_TYPE_LAST_7_DAYS  :
                $categories =  $this->
                        getDays(($this->period == BOL_SiteStatisticService::PERIOD_TYPE_LAST_30_DAYS ? 30 : 7));

                break;

            case BOL_SiteStatisticService::PERIOD_TYPE_YESTERDAY :
            case BOL_SiteStatisticService::PERIOD_TYPE_TODAY :
            default :
                $categories =  $this->getHours();
        }

        return $categories;
    }

    /**
     * Get months
     *
     * @param integer $count
     * @return array
     */
    protected function getMonths($count)
    {
        $months = array();
        $language = OW::getLanguage();

        for ($i = $count-1; $i >= 0; $i--)
        {
            $tmpDateArray = explode('/', date('Y/m/d',strtotime('today -' . $i . ' month')));
            $jalaliDate = OW::getEventManager()->trigger(new OW_Event(FRMEventManager::ON_AFTER_DEFAULT_DATE_VALUE_SET, array('changeTojalali' => true, 'yearTochange' =>  (int) $tmpDateArray[0], 'monthTochange'=> (int) $tmpDateArray[1] ,'dayTochange'=> (int)$tmpDateArray[2], 'monthWordFormat' =>true)));
            if($jalaliDate->getData() && isset($jalaliDate->getData()['changedMonth'])) {
                $faMonth = $jalaliDate->getData()['changedMonth'];
                $months[] = $faMonth;
            }
            else {
                $months[] = $language->
                text('base', 'month_' . date('n', strtotime('today -' . $i . ' month')));
            }
        }
        return $months;
    }

    /**
     * Get hours
     *
     * @return array
     */
    protected function getHours()
    {
        $hours = array();
        $hours[] = '12:00 AM';
        $hour  = 1;

        for ($i = 0; $i < 23; $i++)
        {
            $suffix = $i < 11 ? 'AM' : 'PM';

            if ($i == 12)
            {
                $hour = 1;
            }

            $hours[] = $hour . ':00 ' . $suffix;
            $hour++;
        }

        return $hours;
    }

    /**
     * Get days
     *
     * @param integer $count
     * @return array
     */
    protected function getDays($count)
    {
        $days = array();

        for ($i = $count - 1; $i > 0; $i--)
        {
            $days[] = UTIL_DateTime::formatDate(strtotime('today -' . $i . ' days'), true);
        }

        $days[] = UTIL_DateTime::formatDate(strtotime('today'), true);

        return $days;
    }

    /**
     * Get chart color
     *
     * @param integer $num
     * @return array
     */
    protected function getChartColor($num)
    {
        $hash = md5('chart' . $num);

        $r = hexdec(substr($hash, 0, 2));
        $g = hexdec(substr($hash, 2, 2));
        $b = hexdec(substr($hash, 4, 2));

        return array(
            'fillColor' => 'rgba(' . $r . ',' . $g . ',' . $b . ',0.2)',
            'strokeColor' => 'rgba(' . $r . ',' . $g . ',' .$b . ',1)',
            'pointColor' => 'rgba(' . $r . ',' .$g .',' . $b . ',1)',
            'pointStrokeColor' => '#fff',
            'pointHighlightFill' => '#fff',
            'pointHighlightStroke' => 'rgba(' .$r . ',' . $g .','. $b . ',1)'
        );
    }
}