<?php
namespace SharkyDog\mDNS;
use React\Dns\Model\Record;
use React\Dns\Model\Message;
use React\EventLoop\Loop;

class SimpleResponder {
  private $_records = [];
  private $_socket;
  private $_queue = [];
  private $_send_ms = 0;
  private $_send_timer;

  public function addRecordIPv4(string $name, string $addr, int $ttl=120) {
    $this->addRecord(new Record(
      $name, Message::TYPE_A, Message::CLASS_IN,
      min(max(0,$ttl),0x7fffffff), $addr
    ));
  }

  public function addRecordIPv6(string $name, string $addr, int $ttl=120) {
    $this->addRecord(new Record(
      $name, Message::TYPE_AAAA, Message::CLASS_IN,
      min(max(0,$ttl),0x7fffffff), $addr
    ));
  }

  public function addRecord(Record $record) {
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

  public function start() {
    if($this->_socket) {
      return;
    }

    $socket = $this->_socket = new Socket;

    $socket->on('dns-message', function($message,$addr,$socket) {
      if($message->qr !== false) {
        return;
      }

      foreach($message->questions as $query) {
        $name = strtolower($query->name);
        $tlc = substr($name,0,3);
        $qu = (bool)($query->class & 0x8000);

        if(!isset($this->_records[$tlc])) {
          continue;
        }

        if($query->type == Message::TYPE_ANY) {
          $recordss = &$this->_records[$tlc];
        } else if(!isset($this->_records[$tlc][$query->type])) {
          continue;
        } else {
          $recordss = [$query->type => &$this->_records[$tlc][$query->type]];
        }

        foreach($recordss as $type => &$records) {
          foreach($records as $record) {
            if($record->name != $name) {
              continue;
            }

            Log::debug('Responder: '.$addr.' asked for record['.$record->name.','.$record->type.','.$record->data.']');

            $this->_queue[] = (object)[
              'r' => $record,
              'a' => $qu ? $addr : null
            ];
          }
        }
      }

      $this->_send();
    });
  }

  public function stop() {
    if(!$this->_socket) {
      return;
    }

    if($this->_send_timer) {
      Loop::cancelTimer($this->_send_timer);
      $this->_send_timer = null;
    }

    $this->_queue = [];
    $this->_socket->removeAllListeners();
    $this->_socket = null;
  }

  private function _send() {
    if(!$this->_socket || $this->_send_timer || empty($this->_queue)) {
      return;
    }

    $now_ms = round(microtime(true) * 1000);
    $dif_ms = $now_ms - $this->_send_ms;
    $delay = $dif_ms > 120 ? 0 : min($dif_ms + random_int(20, 120), 120);

    $data = array_shift($this->_queue);

    $sender = function() use($data) {
      $this->_send_timer = null;

      if(!$this->_socket) {
        return;
      }

      $response = new Message;
      $response->qr = true;
      $response->answers[] = $data->r;

      Log::debug('Responder: send['.($data->a?:'QM').'] record['.$data->r->name.','.$data->r->type.','.$data->r->data.']');

      $this->_socket->send($response, $data->a);
      $this->_send_ms = round(microtime(true) * 1000);

      $this->_send();
    };

    if(!$delay) {
      $sender();
    } else {
      $this->_send_timer = Loop::addTimer($delay/1000, $sender);
    }
  }
}
