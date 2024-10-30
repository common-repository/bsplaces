<?php
/*
vim: set expandtab sw=4 ts=4 sts=4 foldmethod=indent:
Plugin Name: BSPlaces
Description: WP extension for attaching gps location and track (gpx) information to posts and pages. Default visualization engine is "api.mapy.cz".
Version: 1.6
Author: Michal Nezerka 
Author URI: http://blue.pavoucek.cz
Text Domain: bsplaces
Domain Path: /languages
*/

// Require additional code 
require_once('bsmetabox.php');

class BSPlacesLocationWgs84
{
    var $lat = 0;
    var $lon = 0;
    var $titles = array();

    public function __construct($lat, $lon, $title = '', $url = '')  
    {
        $this->lat = $lat;
        $this->lon = $lon;
        $this->addTitle($title, $url);
    }

    function addTitle($title, $url)
    {
        if (strlen($title) > 0)
            $this->titles[] = array('title' => $title, 'url' => $url);
    }
}

class BSPlacesLocations
{
    // threshold in meters
    var $threshold = 300;

    // locations storage
    var $locs = array();

    public function isEmpty()
    {
        return count($this->locs) == 0;
    }

    /**
     * Adds new location
     */
    public function addLocation($newLoc)
    {
        // check if there is some location near newly added
        $add = true;

        foreach ($this->locs as $key => $loc)
        {
            $dist = $this->distGps($loc->lat, $loc->lon, $newLoc->lat, $newLoc->lon);
            if ($dist < $this->threshold)
            {
                $add = false;
                foreach ($newLoc->titles as $title)
                    $this->locs[$key]->addTitle($title['title'], $title['url']);
            }
        }

        if ($add)
            $this->locs[] = $newLoc;
    }

    /**
     * Calculates the great-circle distance between two points, with
     * the Haversine formula.
     * @param float $latitudeFrom Latitude of start point in [deg decimal]
     * @param float $longitudeFrom Longitude of start point in [deg decimal]
     * @param float $latitudeTo Latitude of target point in [deg decimal]
     * @param float $longitudeTo Longitude of target point in [deg decimal]
     * @param float $earthRadius Mean earth radius in [m]
     * @return float Distance between points in [m] (same as earthRadius)
     */
    public function distGps ( $latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo, $earthRadius = 6372795.477598)
    {
        // convert from degrees to radians
        $latFrom = deg2rad($latitudeFrom);
        $lonFrom = deg2rad($longitudeFrom);
        $latTo = deg2rad($latitudeTo);
        $lonTo = deg2rad($longitudeTo);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) +
            cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));

        return $angle * $earthRadius;
    }

    /**
     * formats javascript array of all locations
     */
    public function toJavaScriptStr()
    {
        $locsForJs = array();
        foreach ($this->locs as $loc)
        {
            $jsTitles = array();
            foreach ($loc->titles as $title)
            {
                if (strlen($title['url']) > 0)
                    $jsTitles[] = '<a href="' . $title['url'] . '">' . $title['title'] . '</a>';
                else
                    $jsTitles[] = $title['title'];
            }
            $locsForJs[] = sprintf("[%f, %f, '%s']",
                $loc->lon,
                $loc->lat,
                implode('<br>', $jsTitles));
        }
        $result .= "[" . implode(", ", $locsForJs) . "]";

        return $result;

    }
}

class BSPlaces
{  
    protected $pluginPath;
    protected $pluginUrl;
   
    public function __construct()  
    {  
 
        $this->pluginPath = plugin_dir_path(__FILE__);
        $this->pluginUrl = plugin_dir_url(__FILE__);

        add_action('init', array($this, 'onInit'));
        add_action('plugins_loaded', array($this, 'onPluginsLoaded'));
        add_action('wp_head', array($this, 'onWpHead'));

        add_filter('upload_mimes', array($this, 'onUploadMimeTypes'));
        add_filter('the_content', array($this, 'onContent'));

        add_shortcode('places', array($this, 'onShortcodePlaces'));

        // enable mapy.cz API
        if (!is_admin())
        {
            wp_enqueue_script('api-mapy-cz', 'http://api4.mapy.cz/loader.js');
            wp_enqueue_script('bsplaces-js', plugins_url('/js/bsplaces.js', __FILE__), array('jquery', 'api-mapy-cz'));
        }
    }

    public function onInit()
    {
        // places metabox ----------------------------------------------- 
        $metaBox = array(
            'id' => 'places-meta-box',
            'title' => __('Location information', 'bsplaces'),
            'context' => 'normal',
            'priority' => 'high',
            'post_types' => array('post', 'page'),
            'fields' => array(
                array(
                    'name' => __('Location', 'bsplaces'),
                    'id' => 'bsplaces_location',
                    'type' => 'textarea')
            ));

        new BSMetaBox($metaBox);
    }


    // set text domain for i18n
    public function onPluginsLoaded()
    {
        load_plugin_textdomain('bsplaces', false, 'bsplaces/languages');
    }

    public function onWpHead()
    {
        echo "<script>Loader.load()</script>\n";
        echo "<style>#mapa img, #map-places img { max-width: none; }</style>\n";
    }

    // add necessary mime types to enable upload of gpx files
    public function onUploadMimeTypes($mimeTypes = array())
    {
        if (!isset($mimeTypes['gpx']))
            $mimeTypes['gpx'] = 'application/gpx';
        
        return $mimeTypes;
    }

    public function onContent($content)
    {
        global $post;

        // modify content only of selected post types
        if (! in_array($post->post_type, array('page', 'post')))
            return $content;

        $custom = get_post_custom();

        // get list of gpx files attached to current post
        $atts = &get_children('post_type=attachment&post_parent=' . $post->ID);
        $gpxList = array();
        foreach ($atts as $a)
            if ($a->post_mime_type === 'application/gpx')
                $gpxList[] = $a->guid;


        if (isset($custom['bsplaces_location']) or count($gpxList) > 0)
        {
            $locsText = trim($custom['bsplaces_location'][0]);

            $locs = new BSPlacesLocations();
            $this->multilineToWgs84($locsText, $locs);

            if (!$locs->isEmpty() or count($gpxList) > 0)
            {
                $content .= '<p>' . __('Location information', 'bsplaces') . ':</p>';
                $content .= '<div id="mapa" style="width:100%; height:400px;"></div>' . "\n";
                $content .= '  <script type="text/javascript">' . "\n";
                $content .= '    var allPoints = [];' . "\n";
                $content .= '    var mapa = new SMap(JAK.gel("mapa"));' . "\n";
                $content .= '    var layerT = mapa.addDefaultLayer(SMap.DEF_SMART_TURIST);' . "\n";
                $content .= '    layerT.enable();' . "\n";
                $content .= '    layerT.setTrail(true);' . "\n";
                $content .= '    mapa.addDefaultControls();' . "\n";
                $content .= '    var sync = new SMap.Control.Sync();' . "\n";
                $content .= '    mapa.addControl(sync);' . "\n";


                // add all markers
                if (count($locs) > 0)
                {
                    $locsJsArray = $locs->toJavaScriptStr();
                    $content .= "    var markers = " . $locsJsArray . ";\n";
                    $content .= '    addMarkers(mapa, markers, allPoints, false)' . "\n";
                }

                // add gpx files
                if (count($gpxList) > 0)
                {
                    $content .= "    var gpxFiles = ['" . implode("', '", $gpxList) . "'];\n";
                    $content .= '    addGpx(mapa, gpxFiles, allPoints)' . "\n";
                }
                else
                {
                    $content .= '    var mapCenterZoom = mapa.computeCenterZoom(allPoints, true);' . "\n";
                    $content .= '    if (mapCenterZoom[1] > 13) mapCenterZoom[1] = 13;';
                    $content .= '    mapa.setCenterZoom(mapCenterZoom[0], mapCenterZoom[1]);' . "\n";
                }
 
                $content .= '</script>';
            }
        }

       return $content;
    }

    // parse string of format "latitude, longitude [, description]"
    public function stringToWgs84($str, $mainTitle = NULL, $url = NULL)
    {
        $result = NULL;
        $signMultiplier = array('S' => -1, 'N' => 1, 'W' => -1, 'E' => 1);

        $parts = split(',', $str);

        $parts[0] = trim($parts[0]);
        $parts[1] = trim($parts[1]);
        $parts[2] = trim($parts[2]);

        if (count($parts) < 2)
            return $result;

        // parse decimal coordinates
        if (!preg_match('/([0-9]{1,3}\.[0-9]{1,})([NWSE\-])/i', $parts[0], $latitudeMatches))
            return $result;
        if (!preg_match('/([0-9]{1,3}\.[0-9]{1,})([NWSE\-])/i', $parts[1], $longitudeMatches))
            return $result;

        $latitude = (float)$latitudeMatches[1] * $signMultiplier[$latitudeMatches[2]];
        $longitude = (float)$longitudeMatches[1] * $signMultiplier[$longitudeMatches[2]];
        // main title
        $mainTitle = is_null($mainTitle) ? '' : $mainTitle;
        // subtitle
        $subTitle = isset($parts[2]) ? $parts[2] : '';

        $result = new BSPlacesLocationWgs84($latitude, $longitude, implode(' - ', array($mainTitle, $subTitle)), $url);

        return $result;
    }

    public function multilineToWgs84($str, &$locs, $postTitle = NULL, $postId = NULL, $postUrl = NULL)
    {
        $locsText = trim($str);
        $locsRaw = explode("\n", str_replace("\r\n","\n", $locsText));

        foreach ($locsRaw as $locRaw)
        {
            $loc = $this->stringToWgs84($locRaw, $postTitle, $postUrl);
            if (!is_null($loc))
                $locs->addLocation($loc);
        }
    }

    public function getLocations(&$locs)
    {
        global $wpdb;

        $result = array();
        $sql = "SELECT p.post_title, m.post_id, p.guid, m.meta_value as 'location', DATE_FORMAT(p.post_date, '%e/%c/%Y') AS 'post_date_str' FROM wp_postmeta m INNER JOIN wp_posts p ON m.post_id = p.id AND meta_key='bsplaces_location' ORDER BY p.post_date DESC";
        $values = $wpdb->get_results($sql, ARRAY_A);
        foreach ($values as $rec)
            $this->multilineToWgs84($rec['location'], $locs, $rec['post_title'] . ' (' . $rec['post_date_str'] . ')', $rec['post_id'], $rec['guid']);
    }

    public function onShortcodePlaces($atts)
    {
        // convert short code attributes to array with default values
        $atts = shortcode_atts(array(
            'filter_category' => false,
            'id' => 'map-places'
        ), $atts, 'places');

        $result = '';

        $locs = new BSPlacesLocations();

        $mapId = $atts['id'];

        // get all points
        $this->getLocations($locs);

        // get all gpx files
        $gpxList = array();

        //$result .= '<div id="' . $mapId . '" style="width:100%; height:400px;"></div>' . "\n";

        if ($atts['filter_category'] === 'yes')
        {
            $cats = get_categories();
            $result .= '<div class="bsplaces-search">';
            $result .= '  <form id="bsplaces-filter" method="post">';
            $result .= '    <select name="bsplaces-filter-category">';
            $result .= '      <option value="all">' . __('All categories') . '</option>';
            foreach ($cats as $cat)
            {
                $result .= '      <option value="' . $cat->cat_ID . '">' . $cat->cat_name . '</option>';
            }
            $result .= '    </select>';
            $result .= '    <input type="submit" value="' . __('Filter') . '"/>';
            $result .= '  </form>';
            $result .= '</div>';
        }

        $result .= '<div id="' . $mapId . '"></div>' . "\n";
        $result .= '  <script type="text/javascript">' . "\n";
        $result .= '    var allPoints = [];' . "\n";
        $result .= '    var map_places = new SMap(JAK.gel("' . $mapId . '"));' . "\n";
        $result .= '    var layerT = map_places.addDefaultLayer(SMap.DEF_SMART_TURIST);' . "\n";
        $result .= '    layerT.enable();' . "\n";
        $result .= '    layerT.setTrail(true);' . "\n";
        $result .= '    map_places.addDefaultControls();' . "\n";
        $result .= '    var sync = new SMap.Control.Sync();' . "\n";
        $result .= '    map_places.addControl(sync);' . "\n";


        // add all markers
        if (!$locs->isEmpty())
        {
            $locsJsArray = $locs->toJavaScriptStr();

            $result .= "    var markers = " . $locsJsArray . ";\n";
            $result .= '    addMarkers(map_places, markers, allPoints, true)' . "\n";
        }

        // add gpx files
        if (count($gpxList) > 0)
        {
            $result .= "    var gpxFiles = ['" . implode("', '", $gpxList) . "'];\n";
            $result .= '    addGpx(map_places, gpxFiles, allPoints)' . "\n";
        }
        else
        {
            $result .= '    var mapCenterZoom = map_places.computeCenterZoom(allPoints, true);' . "\n";
            $result .= '    if (mapCenterZoom[1] > 13) mapCenterZoom[1] = 13;';
            $result .= '    map_places.setCenterZoom(mapCenterZoom[0], mapCenterZoom[1]);' . "\n";
        }

        $result .= '</script>';

        // generate search form
        if ($atts['search_category'] === 'yes')
        {
            $result .= '<form id="places-search" method="post">';
            $result .= 'form';
            $result .= '<input type="hidden" name="bsplaces_action" value="search" />';
            $result .= '</form>';
        } // search form

        return $result; 
    }
}

// create plugin instance
$bsPlaces = new BSPlaces();  

?>
