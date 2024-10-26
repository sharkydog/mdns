<?php
namespace SharkyDog\mDNS;
use React\Dns\Model\Record;
use React\Dns\Model\Message;

class RecordFactory {
  public function A(string $name, string $addr, int $ttl=120): Record {
    return $this->_rec($name, Message::TYPE_A, $ttl, $addr);
  }

  public function AAAA(string $name, string $addr, int $ttl=120): Record {
    return $this->_rec($name, Message::TYPE_AAAA, $ttl, $addr);
  }

  public function PTR(string $name, string $target, int $ttl=120): Record {
    return $this->_rec($name, Message::TYPE_PTR, $ttl, strtolower($target));
  }

  public function SRV(string $name, int $priority, int $weight, int $port, string $target, int $ttl=120): Record {
    return $this->_rec($name, Message::TYPE_SRV, $ttl, [
      'priority' => $this->_uint16($priority),
      'weight' => $this->_uint16($weight),
      'port' => $this->_uint16($port),
      'target' => strtolower($target)
    ]);
  }

  public function TXT(string $name, ?int $ttl=null, string ...$txts): Record {
    return $this->_rec($name, Message::TYPE_TXT, $this->DefaultTTL($ttl), $txts);
  }

  public function DefaultTTL(?int $ttl): int {
    return $ttl === null || $ttl < 0 ? 120 : $ttl;
  }

  private function _rec($name, $type, $ttl, $data) {
    return new Record($name, $type, Message::CLASS_IN, $this->_uint31($ttl), $data);
  }

  private function _uint16($int) {
    return min(max(0,$int),0xffff);
  }

  private function _uint31($int) {
    return min(max(0,$int),0x7fffffff);
  }
}
