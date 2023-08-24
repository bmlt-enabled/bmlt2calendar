<?php
// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
class IcsLines
// phpcs:enable PSR1.Classes.ClassDeclaration.MissingNamespace
{
    private $lines = array();
    public static $days = array('SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA');
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
    public function addLines(IcsLines $other)
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
    public function rrule($frequency, $weekOfMonth, $day, DateTime $until)
    {
        $ret = "FREQ=$frequency;";
        $setpos = 0;
        if ($weekOfMonth === 'L') {
            $setpos = -1;
        } elseif (is_numeric($weekOfMonth)) {
            $setpos = intval($weekOfMonth);
        }
        if ($setpos != 0) {
            $ret .= "BYSETPOS=$setpos;";
        }
        $ret .= 'BYDAY='.IcsLines::$days[$day].';INTERVAL=1;';
        $ret .= 'UNTIL='.date(ICAL_FORMAT, $until->getTimestamp());
        return $ret;
    }
}
