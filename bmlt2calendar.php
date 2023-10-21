<?php
/**
Plugin Name: BMLT2Calendar
Plugin URI: https://wordpress.org/plugins/bmlt2calendar/
Description: Convert data from a BMLT Meeting database to a calendar format
Author: otrok7, bmlt-enabled
Author URI: https://bmlt.app
Version: 1.0.0
*/
/* Disallow direct access to the plugin file */
if (basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
    // die('Sorry, but you cannot access this page directly.');
}
require_once plugin_dir_path(__FILE__).'vendor/autoload.php';
use Ramsey\Uuid\Uuid;

require_once "ics-lines.php";

if (!class_exists("BMLT2calendar")) {
    // phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
    class BMLT2calendar
    {
        private $icalFormat = 'Ymd\THis\Z';
        private $optionsName = 'bmlt_tabs_options';  // get the root server from crouton
        private $options = array();
        private $formats = array();
        
        public function __construct()
        {
            add_action('init', array($this, 'addBmlt2icsFeed'));
            //There are a few alternative places to put this in, for now, "calendar_displayed" seems best.
            //add_shortcode('bmlt_to_fullcalendar', array($this, 'bmltToFullCalendar'));
            add_filter('wpfc_calendar_displayed', array($this, 'bmltToFullCalendar'));
            //add_filter('wpfc_fullcalendar_args', array($this, 'bmltToFullCalendar'));
        }
        public function addBmlt2icsFeed()
        {
            add_feed('bmlt2ics', array($this, 'doIcsRouting'));
            add_feed('bmlt2Json', array($this, 'doJsonRouting'));
        }
        private function beginCalendar($cal)
        {
            $cal->addLine("BEGIN", "VCALENDAR");
            $cal->addLine("VERSION", "2.0");
            $cal->addLine("CALSCALE", "GREGORIAN");
            $cal->addLine("PRODID", "bmlt-enabled/ics");
            $cal->addLine("METHOD", "PUBLISH");
        }
        private function endCalendar($cal)
        {
            $cal->addLine("END", "VCALENDAR");
        }
        private function sendJsonHeaders()
        {
            header('Content-Type: application/json; charset=utf-8');
        }
        private function sendHeaders()
        {
            header('Content-Type: text/calendar; charset=utf-8');
            //header('Content-Disposition: attachment; filename="cal.ics"');
        }
        public function doIcsRouting()
        {
            $this->getOptions();
            $this->formats = $this->getFormats($this->options['root_server']);
            if (isset($_GET['meeting-id'])) {
                $this->doSingleMeeting($_GET['meeting-id']);
                return;
            }
            $expand = false;
            if (isset($_GET['expand'])) {
                $expand = $_GET['expand'];
            }
            $startTime = new DateTime('NOW');
            if (isset($_GET['startTime'])) {
                $startTime = DateTime::createFromFormat($this->icalFormat, $_GET['startTime']);
            }
            $endTime = (clone $startTime)->modify('+1 week');
            if (isset($_GET['endTime'])) {
                $endTime = DateTime::createFromFormat($this->icalFormat, $_GET['endTime']);
            }
            $custom_query = '';
            if (isset($_REQUEST['custom_query'])) {
                // there's probably a better way to do this, but the problem is, the
                // '&'s should be left in the url, but the spaces replaced....
                $custom_query = str_replace(' ', '%20', $_REQUEST['custom_query']);
            }
            $is_data = explode(',', esc_html($this->options['service_body_1']));
            $custom_query .= '&services='.$is_data[1];
            $this->doCustomQuery($custom_query, $startTime, $endTime, $expand);
        }
        public function doJsonRouting()
        {
            $this->getOptions();
            $this->formats = $this->getFormats($this->options['root_server']);
            $start = $_REQUEST['start'];
            $end = $_REQUEST['end'];
            $custom_query = '';
            $special = '';
            if (isset($_REQUEST['custom_query'])) {
                // there's probably a better way to do this, but the problem is, the
                // '&'s should be left in the url, but the spaces replaced....
                $custom_query = str_replace(' ', '%20', $_REQUEST['custom_query']);
            }
            if (isset($_REQUEST['special'])) {
                $special = $_REQUEST['special'];
            }
            $this->doJson($custom_query, new DateTime($start), new DateTime($end), $special);
        }
        private function doJson($custom_query, $start, $end, $special)
        {
            $is_data = explode(',', esc_html($this->options['service_body_1']));
            $service = '&services='.$is_data[1].'&recursive=1';
            $meetings = $this->getAllMeetings($this->options['root_server'], $service, '', $custom_query);
            $events = array();
            foreach ($meetings as $meeting) {
                array_push($events, ...$this->createJsonEventFromMeeting($meeting, $start, $end, $special));
            }
            $this->sendJsonHeaders();
            echo json_encode($events);
        }
        private function createJsonEventFromMeeting($meeting, $start, $end, $special)
        {
            $startSafe = clone $start;
            $startTime = $this->getTimeForFirstMeeting($meeting, $startSafe, $special);
            if (is_null($startTime)) {
                return [];
            }
            $endTime = $this->getEndTime($startTime, $meeting);
            $title = $this->getSummary($meeting);
            $url = $this->getURL($meeting);
            $loc = $this->formatLocation($meeting);
            array_push($loc, ...$this->getDescription($meeting));
            $ret = array();
            while ($startTime < $end) {
                $ret[] = array(
                    'start' => $startTime->format(DateTime::ATOM),
                    'end' => $endTime->format(DateTime::ATOM),
                    'title' => $title,
                    'url' => $url,
                    'description' => implode('<br/>', $loc)
                );
                $startTime->modify('+1 week');
                $startTime = apply_filters('bmlt_ics_adjustWeek', $startTime, $meeting, $special);
                // should never happen, but for robustnesses sake.
                if (is_null($startTime)) {
                    break;
                }
                $endTime = $this->getEndTime($startTime, $meeting);
            }
            return $ret;
        }
        private function doSingleMeeting($meetingId)
        {
            $this->doCustomQuery('&meeting_ids[]='.$meetingId, new DateTime('NOW'), (new DateTime('NOW'))->modify('+1 week'), false);
            return;
        }
        private function doCustomQuery($custom_query, $startTime, $endTime, $expand)
        {
            $meetings = $this->getAllMeetings($this->options['root_server'], '', '', $custom_query);
            $cal = new IcsLines();
            $this->beginCalendar($cal);
            foreach ($meetings as $meeting) {
                $event = $this->createEventFromMeeting($meeting, $startTime, $endTime, $expand);
                $cal->addLines($event);
            }
            $this->endCalendar($cal);

            $this->sendHeaders();
            echo $cal->toString();
        }
        private function formatLocation($meeting)
        {
            if ($meeting['venue_type'] == "2") {
                $ret = array(
                    $meeting['virtual_meeting_link'],
                    $meeting['virtual_meeting_additional_info']
                );
                return apply_filters('bmlt_ics_virtual', $ret, $meeting);
            }
            $state = empty($meeting['location_province']) ? ' ' : ', '.$meeting['location_province'].', ';
            $ret = array($meeting['location_text'],
                         $meeting['location_street'] . ', ' . $meeting['location_municipality'].$state.$meeting['location_postal_code_1'],
                         $meeting['location_info']);
            return apply_filters('bmlt_ics_location', $ret, $meeting);
        }
        private function getOptions()
        {
            // Don't forget to set up the default options
            if (!$theOptions = get_option($this->optionsName)) {
                $theOptions = array(
                 'root_server' => '',
                 'service_body_1' => ''
                );
            }
            $this->options = $theOptions;
            $this->options['root_server'] = sanitize_url(untrailingslashit(preg_replace('/^(.*)\/(.*php)$/', '$1', $this->options['root_server'])));
        }
        private function getServerRequestScheme()
        {
            if ((! empty($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] == 'https')
                || (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on')
                || (! empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == '443')
            ) {
                return 'https';
            } else {
                return 'http';
            }
        }
        private function getSummary($meeting)
        {
            return apply_filters('bmlt_ics_summary', $meeting['meeting_name'], $meeting);
        }
        private function getDescription($meeting) : array
        {
            $ret = array();
            if (!empty($meeting['comments'])) {
                $ret[] = $meeting['comments'];
            }
            $format_ids = explode(',', $meeting['format_shared_id_list']);
            $formats = array_reduce($format_ids, function ($result, $id) {
                if (isset($this->formats[$id])) {
                    $result[] = $this->formats[$id];
                }
                return $result;
            }, array());
            if (count($formats) > 0) {
                if (count($ret) > 0) {
                    $ret[] = '';
                }
                $ret[] = 'Meeting formats:';
                foreach ($formats as $format) {
                    $ret[] = '- '.$format['description_string'];
                }
            }
            return apply_filters('bmlt_ics_description', $ret, $meeting);
        }
        private function getTimeForFirstMeeting($meeting, $timePeriodStart, $special = '')
        {
            $startTime = array_map('intval', explode(':', $meeting['start_time']));
            $dayDif = intval($meeting['weekday_tinyint']) - (intval($timePeriodStart->format('w')) + 1) ;
            $dayDif += ($dayDif < 0) ? 7 : 0;
            if ($dayDif === 0) {
                $difHours = intval($timePeriodStart->format('H')) - $startTime[0];
                $dayDif = ($difHours > 0) ? 7 : 0;
                if ($difHours === 0) {
                    $difMins = intval($timePeriodStart->format('i')) - $startTime[1];
                    $dayDif = ($difMins > 0) ? 7 : 0;
                }
            }
            $dayDif = new DateInterval('P'.$dayDif.'D');
            $nextStartDay = $timePeriodStart->add($dayDif);
            // Some meetings may be monthly, bi-weekly, etc.  We have no standard for this in BMLT,
            // but let every decide on their own stategy...
            $nextStartDay = apply_filters('bmlt_ics_adjustWeek', $nextStartDay, $meeting, $special);
            if (is_null($nextStartDay)) {
                return null;
            }
            return $nextStartDay->setTime($startTime[0], $startTime[1]);
        }
        private function getEndTime($startTime, $meeting)
        {
            $duration = array_map('intval', explode(':', $meeting['duration_time']));
            $dur = new DateInterval('PT'.$duration[0].'H'.$duration[1].'M');
            return (clone $startTime)->add($dur);
        }
        private function getUID($meeting)
        {
            return Uuid::uuid5(Uuid::NAMESPACE_URL, $this->options['root_server'].'/meeting-id/'.$meeting['id_bigint']);
        }
        private function getURL($meeting)
        {
            if (!isset($this->options['meeting_details_href'])) {
                return "";
            }
            $path = $this->options['meeting_details_href'];
            if ($meeting['venue_type'] === '2' && !empty($this->options['virtual_meeting_details_href'])) {
                $path = $this->options['virtual_meeting_details_href'];
            }
            return $this->getServerRequestScheme().'://'.$_SERVER['HTTP_HOST'].$path."?meeting-id=".$meeting['id_bigint'];
        }
        private function createEventFromMeeting($meeting, DateTime $timePeriodStart, DateTime $timePeriodEnd, $expand)
        {
            $nextStart = $this->getTimeForFirstMeeting($meeting, clone $timePeriodStart);
            $timezoneOffset = intval(wp_timezone()->getOffset($nextStart));
            $uuid = $this->getUID($meeting);
            $lastChange = intval($this->getChanges($this->options['root_server'], $meeting['id_bigint'])[0]['date_int']);
            $url = $this->getURL($meeting);
            $event = new IcsLines();
            while ($nextStart < $timePeriodEnd) {
                $event->addLine('tyoff', $timezoneOffset);
                $nextEnd = $this->getEndTime($nextStart, $meeting);
                $event->addLine("BEGIN", "VEVENT");
                $event->addLine("UID", $uuid);
                $event->addLine("DTSTAMP", date($this->icalFormat, (new DateTime('NOW'))->getTimestamp()));
                $event->addLine("DTSTART", date($this->icalFormat, $nextStart->getTimestamp()-$timezoneOffset));
                $event->addLine("DTEND", date($this->icalFormat, $nextEnd->getTimestamp()-$timezoneOffset));
                $event->addLine("LAST-MODIFIED", date($this->icalFormat, $lastChange));
                $event->addLine("SUMMARY", $this->getSummary($meeting));
                $event->addMultilineValue("LOCATION", $this->formatLocation($meeting));
                $event->addMultilineValue("DESCRIPTION", $this->getDescription($meeting));
                if (strlen($url) > 0) {
                    $event->addLine("URL", $url);
                }
                $event->addLine("GEO", $meeting['latitude'] . ';' . $meeting['longitude']);
                $event->addLine("END", "VEVENT");
                if (!$expand) {
                    break;
                }
                $nextStart->modify('+1 week');
            }
            return $event;
        }
        private function getAllMeetings($root_server, $services, $format_id, $query_string)
        {
            if (isset($query_string) && $query_string != '') {
                $query_string = "&".html_entity_decode($query_string);
                $query_string = str_replace("()", "[]", $query_string);
            } else {
                $query_string = '';
            }
            if (isset($format_id) && $format_id != '') {
                $ids = explode(',', $format_id);
                $format_id = '';
                foreach ($ids as $id) {
                    $format_id .= "&formats[]=$id";
                }
            } else {
                $format_id = '';
            }
            return $this->makeCall("$root_server/client_interface/json/?switcher=GetSearchResults$format_id$services$query_string&sort_keys=weekday_tinyint,start_time,duration_time");
        }
        private function getChanges($root_server, $meeting_id)
        {
            return $this->makeCall("$root_server/client_interface/json/?switcher=GetChanges&meeting_id=$meeting_id");
        }
        private function getFormats($root_server)
        {
            $arr = $this->makeCall("$root_server/client_interface/json/?switcher=GetFormats");
            return apply_filters(
                'bmlt_ics_load_formats',
                array_reduce($arr, function ($result, $format) {
                    $result[$format['id']] = $format;
                    return $result;
                },
                array())
            );
        }
        private function makeCall($url)
        {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            //curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)");
            curl_setopt($ch, CURLOPT_USERAGENT, "cURL Mozilla/5.0 (Windows NT 5.1; rv:21.0) Gecko/20130401 Firefox/21.0");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
            curl_setopt($ch, CURLOPT_ENCODING, 'gzip,deflate');
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $results  = curl_exec($ch);
            // echo curl_error($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $c_error  = curl_error($ch);
            $c_errno  = curl_errno($ch);
            curl_close($ch);
            if ($httpcode != 200 && $httpcode != 302 && $httpcode != 304) {
                echo "<p style='color: #FF0000;'>Problem Connecting to BMLT Root Server: ( $httpcode )</p>";
                return [];
            }
            return json_decode($results, true);
        }
        public function bmltToFullCalendar($args)
        {
            $ret = '<script type="text/javascript">';
            $ret .= "jQuery(document).on('wpfc_fullcalendar_args', function(event,args) {";
            $ret = apply_filters('bmlt_ics_configureCalendar', $ret, $args);
            if (isset($args['bmlt_meetings_only'])) {
                $ret .= "args.eventSources.shift();";
            }
            if (isset($args['bmlt_meetings_only']) || isset($args['bmlt_add_meetings'])) {
                $ret .= "args.eventSources.push({";
                $ret .= "url:'".get_feed_link("bmlt2Json");
                $first = true;
                if (isset($args['bmlt_special_query_option'])) {
                    $ret .= $first ? '?' : '&';
                    $first = false;
                    $special = $args['bmlt_special_query_option'];
                    $ret .= 'special='.$special.'';
                }
                if (isset($args['bmlt_custom_query'])) {
                    $ret .= $first ? '?' : '&';
                    $first = false;
                    $special = urlencode(html_entity_decode($args['bmlt_custom_query']));
                    $ret .= 'custom_query='.$special.'';
                }
                $color = "#294372";
                if (isset($args['bmlt_color'])) {
                    $color = $args['bmlt_color'];
                }
                $textColor = "white";
                if (isset($args['bmlt_textColor'])) {
                    $textColor = $args['bmlt_textColor'];
                }
                $ret .= "', color: '$color', textColor: '$textColor'}),";

                $ret .= 'args.eventRender = function(eventObj,el) {';
                $ret .= "if (eventObj.hasOwnProperty('description')) {";
                
                $ret .= 'tippy(el[0], {content: eventObj.description, theme : WPFC.tippy_theme,
					placement : WPFC.tippy_placement, allowHTML: true} );';
                $ret .= "}"; // close if
                $ret .= "  };"; // close event render function
            }
            $ret .= '});</script>';
            echo $ret;
        }
    }
}
//End Class BMLT2calendar
// end if
// instantiate the class
if (class_exists("BMLT2calendar")) {
    $BMLT2calendar_instance = new BMLT2calendar();
}
