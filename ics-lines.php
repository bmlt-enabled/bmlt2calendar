<?php
// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
class IcsLines
// phpcs:enable PSR1.Classes.ClassDeclaration.MissingNamespace
{
    private $lines = array();
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
}
