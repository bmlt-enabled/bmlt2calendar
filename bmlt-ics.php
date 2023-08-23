<?php
/*
Plugin Name: BMLT 2 ICS
Description: Generate Calendars from BMLT Meetings
Version: 0.1
*/
/* Disallow direct access to the plugin file */
if (basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
    // die('Sorry, but you cannot access this page directly.');
}
require_once plugin_dir_path(__FILE__).'vendor/autoload.php';
use Ramsey\Uuid\Uuid;

require_once "ics-lines.php";

if (!class_exists("BMLT2ics")) {
    // phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
    class BMLT2ics
    // phpcs:enable PSR1.Classes.ClassDeclaration.MissingNamespace
    {
        public $optionsName = 'bmlt_tabs_options';  // get the root server from crouton
        public $options = array();
        public $formats = array();
        
        public function __construct()
        {
            add_action('init', array($this, 'addBmlt2icsFeed'));
        }
        public function addBmlt2icsFeed()
        {
            add_feed('bmlt2ics', array($this, 'doRouting'));
            $link = get_feed_link('bmlt2ics');
        }
        public function beginCalendar($cal)
        {
            $cal->addLine("BEGIN", "VCALENDAR");
            $cal->addLine("VERSION", "2.0");
            $cal->addLine("CALSCALE", "GREGORIAN");
            $cal->addLine("PRODID", "bmlt-enabled/ics");
            $cal->addLine("METHOD", "PUBLISH");
        }
        public function endCalendar($cal)
        {
            $cal->addLine("END", "VCALENDAR");
        }
        public function sendHeaders()
        {
            header('Content-Type: text/calendar; charset=utf-8');
            header('Content-Disposition: attachment; filename="cal.ics"');
        }
        public function doRouting()
        {
            $this->getOptions();
            $this->formats = $this->getFormats($this->options['root_server']);
            if (isset($_GET['meeting-id'])) {
                $this->doSingleMeeting($_GET['meeting-id']);
                return;
            }
        }
        private function doSingleMeeting($meetingId)
        {
            $this->doCustomQuery('&meeting_ids[]='.$meetingId);
            return;
        }
        private function doCustomQuery($custom_query)
        {
            $meetings = $this->getAllMeetings($this->options['root_server'], '', '', $custom_query);
            $cal = new IcsLines();
            $this->beginCalendar($cal);
            foreach ($meetings as $meeting) {
                $event = $this->createEventFromMeeting($meeting);
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
            $ret = array($meeting['location_text'],
                         $meeting['location_street'] . ', ' . $meeting['location_municipality'] . ', ' .
                         $meeting['location_province'] . ', ' . $meeting['location_postal_code_1'],
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
        private function createEventFromMeeting($meeting)
        {
            $startTime = array_map('intval', explode(':', $meeting['start_time']));
            $duration = array_map('intval', explode(':', $meeting['duration_time']));
            $dayDif = intval(date('w')) + 1 - $meeting['weekday_tinyint'] ;
            $dayDif += ($dayDif < 0) ? 7 : 0;
            if ($dayDif === 0) {
                $difHours = intval(date('H')) - $startTime[0];
                $dayDif = ($difHours > 0) ? 7 : 0;
                if ($difHours === 0) {
                    $difMins = intval(date('i')) - $startTime[1];
                    $dayDif = ($difMins > 0) ? 7 : 0;
                }
            }
            define('ICAL_FORMAT', 'Ymd\THis\Z');
            $dayDif = new DateInterval('P'.$dayDif.'D');
            $dur = new DateInterval('PT'.$duration[0].'H'.$duration[1].'M');
            $nextStartDay = (new DateTime('NOW'))->add($dayDif);
            // Some meetings may be monthly, bi-weekly, etc.  We have no standard for this in BMLT,
            // but let every decide on their own stategy...
            $nextStartDay = apply_filters('bmlt_ics_adjustWeek', $nextStartDay, $meeting);
            $nextStart = $nextStartDay->setTime($startTime[0], $startTime[1]);
            $nextEnd = DateTime::createFromInterface($nextStart)->add($dur);
            $uuid = Uuid::uuid5(Uuid::NAMESPACE_URL, $this->options['root_server'].'/meeting-id/'.$meeting['id_bigint']);
            $lastChange = intval($this->getChanges($this->options['root_server'], $meeting['id_bigint'])[0]['date_int']);
            $url = $this->getServerRequestScheme().'://'.$_SERVER['HTTP_HOST'].$this->options['meeting_details_href']."?meeting-id=".$meeting['id_bigint'];
            $event = new IcsLines();
            $event->addLine("BEGIN", "VEVENT");
            $event->addLine("UID", $uuid);
            $event->addLine("DTSTAMP", date(ICAL_FORMAT, (new DateTime('NOW'))->getTimestamp()));
            $event->addLine("DTSTART", date(ICAL_FORMAT, $nextStart->getTimestamp()));
            $event->addLine("DTEND", date(ICAL_FORMAT, $nextEnd->getTimestamp()));
            $event->addLine("LAST-MODIFIED", date(ICAL_FORMAT, $lastChange));
            $event->addLine("SUMMARY", $this->getSummary($meeting));
            $event->addMultilineValue("LOCATION", $this->formatLocation($meeting));
            $event->addMultilineValue("DESCRIPTION", $this->getDescription($meeting));
            $event->addLine("URL", $url);
            $event->addLine("GEO:", $meeting['latitude'] . ';' . $meeting['longitude']);
            $event->addLine("END", "VEVENT");
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
            return array_reduce($arr, function ($result, $format) {
                $result[$format['id']] = $format;
                return $result;
            }, array());
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
    }
}
//End Class BMLTMeetingDetails
// end if
// instantiate the class
if (class_exists("BMLT2ics")) {
    $BMLT2ics_instance = new BMLT2ics();
}
