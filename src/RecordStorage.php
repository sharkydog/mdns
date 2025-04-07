<?php
namespace SharkyDog\mDNS;
use React\Dns\Model\Record;
use React\Dns\Model\Message;

class RecordStorage {
  protected $_records = [];

  public function addRecord(Record $record, bool $cfbit=false) {
    if($cfbit) $record->class |= 0x8000;
    $record->name = strtolower($record->name);
    $tlc = substr($record->name,0,3);

    if(!isset($this->_records[$tlc])) {
      $this->_records[$tlc] = [];
    }
    if(!isset($this->_records[$tlc][$record->type])) {
      $this->_records[$tlc][$record->type] = [];
    }

    $this->_records[$tlc][$record->type][] = $record;
  }

  public function delRecord(string $name, int $type, $data=null): bool {
    $name = strtolower($name);
    $tlc = substr($name,0,3);
    $found = false;

    if(!isset($this->_records[$tlc])) {
      return false;
    }
    if(!isset($this->_records[$tlc][$type])) {
      return false;
    }

    foreach($this->_records[$tlc][$type] as $key => $rr) {
      if($rr->name != $name) continue;
      if($data!==null && $rr->data !== $data) continue;
      unset($this->_records[$tlc][$type][$key]);
      $this->_records[$tlc][$type] = array_values($this->_records[$tlc][$type]);
      $found = true;
      break;
    }

    if(!$found) {
      return false;
    }

    if(empty($this->_records[$tlc][$type])) {
      unset($this->_records[$tlc][$type]);
    }
    if(empty($this->_records[$tlc])) {
      unset($this->_records[$tlc]);
    }

    return true;
  }

  public function enableRecord(string $name, int $type, $data=null, bool $enable=true) {
    foreach($this->findRecords($name,$type,$data,!$enable) as $record) {
      $record->class ^= 0x4000;
    }
  }

  public function findRecords(string $name, int $type, $data=null, ?bool $active=true): array {
    $name = strtolower($name);
    $tlc = substr($name,0,3);
    $rrs = [];

    if($type == Message::TYPE_ANY) {
      if(!isset($this->_records[$tlc])) {
        return $rrs;
      }
      $recordss = &$this->_records[$tlc];
    } else if(!isset($this->_records[$tlc][$type])) {
      return $rrs;
    } else {
      $recordss = [$type => &$this->_records[$tlc][$type]];
    }

    foreach($recordss as $rrtype => &$records) {
      foreach($records as $record) {
        if($active!==null && !($record->class & 0x4000) != $active) continue;
        if($record->name != $name) continue;
        if($data!==null && $record->data !== $data) continue;
        $rrs[] = $record;
      }
    }

    return $rrs;
  }
}
