<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
class BMLT2CalendarIcsLines
// phpcs:enable PSR1.Classes.ClassDeclaration.MissingNamespace
{
    private $lines = array();
    public static $days = array("", 'SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA');
    public function addLine($name, $value)
    {
        if (is_null($value)) {
            return;
        }
        $value = trim($value);
        if (strlen($value) === 0) {
            return;
        }
        $value = str_replace(',', '\,', $value);
        $this->lines[] = $name.':'.$value;
    }
    public function addLines(Bmlt2CalendarIcsLines $other)
    {
        array_push($this->lines, ...$other->lines);
    }
    public function addMultilineValue($name, array $parts)
    {
        if (is_null($parts) || count($parts) === 0) {
            return;
        }
        $this->addLine($name, implode('\n', $parts));
    }
    public function toString()
    {
        return implode("\r\n", $this->lines);
    }
    public function rrule($day, $until)
    {
        $ret = "FREQ=WEEKLY;BYDAY='.IcsLines::$this->days[$day].';INTERVAL=1;'";
        $ret .= 'UNTIL='.date($until);
        return $ret;
    }
}
