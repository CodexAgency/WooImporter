<?php 
namespace CodexAgency\WooImporter;


class CSV  {

    protected $delimiter = ';';
    protected $newLine = '\n';
    protected $switchFirstLine = true;

    public function setDelimiter(string $char) : CSV {
        $this->delimiter = $char;
        return $this;
    }

    public function setNewLine(string $char) : CSV {
        $this->newLine = $char;
        return $this;
    }

    public function setSwitchFirstLine(bool $switch) : CSV {
        $this->switchFirstLine = $switch;
        return $this;
    }

    /**
     * @param string $csv
     * @return array
     */
    public function convertStringCsvToArray(string $csv) : array {
        
        return [];
    }



}
