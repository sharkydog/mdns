<?php
namespace SharkyDog\mDNS;
use Evenement\EventEmitter;
use React\Dns\Model\Message;
use React\Dns\Protocol\Parser as DnsParser;
use React\Dns\Protocol\BinaryDumper as DnsEncoder;
use Clue\React\Multicast\Factory as MulticastFactory;

final class Socket extends EventEmitter {
  const NS = '224.0.0.251:5353';

  private static $_packet_size = 1472;
  private static $_emitter;
  private static $_decoder;
  private static $_encoder;
  private static $_socket;
  private static $_ending = false;
  private static $_queue = [];

  private $_listeners = [];

  public static function setPacketSize(int $size) {
    self::$_packet_size = max(12,$size);
  }

  public static function dnsDecoder(): DnsParser {
    if(!self::$_decoder) {
      self::$_decoder = new DnsParser;
    }
    return self::$_decoder;
  }

  public static function dnsEncoder(): DnsEncoder {
    if(!self::$_encoder) {
      self::$_encoder = new DnsEncoder;
    }
    return self::$_encoder;
  }

  public function __construct() {
    if(!self::$_emitter) {
      self::$_emitter = new EventEmitter;
    }
  }

  public function __destruct() {
    $this->_clearListeners();
  }

  public function __clone() {
    $this->_listeners = [];
  }

  public function on($event, callable $listener) {
    $this->_addListener('on', $event, $listener);
  }
  public function once($event, callable $listener) {
    $this->_addListener('once', $event, $listener);
  }

  public function removeListener($event, callable $listener) {
    parent::removeListener($event, $listener);
    if(count(parent::listeners($event))) return;
    $this->_clearListeners($event);
  }

  public function removeAllListeners($event = null) {
    parent::removeAllListeners($event);
    $this->_clearListeners($event);
  }

  public function emit($event, array $arguments = []) {
  }

  public function send(Message $message, ?string $addr=null): ?bool {
    $message = self::dnsEncoder()->toBinary($message);

    if(strlen($message) > self::$_packet_size) {
      return false;
    }

    if(self::$_ending) {
      self::$_queue[] = $message;
      return null;
    }

    $socket = $this->_getSocket();
    $socket->send($message, $addr ?: self::NS);
    $this->_end();

    return true;
  }

  private function _getSocket() {
    if(self::$_socket) {
      return self::$_socket;
    }

    $socket = self::$_socket = (new MulticastFactory)->createReceiver(self::NS);

    $socket->on('close', function() {
      self::$_emitter->emit('close', [$this]);
      self::$_socket = null;
      self::$_ending = false;

      if($msgListeners = $this->_countMsgListeners()) {
        $this->_getSocket();
      }

      if(!empty(self::$_queue)) {
        $socket = $this->_getSocket();
        while(($message = array_shift(self::$_queue)) !== null) {
          $socket->send($message, self::NS);
          if(!$msgListeners) $this->_end();
        }
      }
    });

    $socket->on('message', function($data, $addr, $socket) {
      self::$_emitter->emit('raw-message', [$data, $addr, $this]);

      if(!count(self::$_emitter->listeners('dns-message'))) {
        return;
      }

      try {
        $message = self::dnsDecoder()->parseMessage($data);
      } catch(\Exception $e) {
        return;
      }

      self::$_emitter->emit('dns-message', [$message, $addr, $this]);
    });

    return $socket;
  }

  private function _addListener($fn, $event, $listener) {
    parent::$fn($event, $listener);

    if(!isset($this->_listeners[$event])) {
      $this->_listeners[$event] = function(...$args) use($event) {
        parent::emit($event, $args);
        if(count(parent::listeners($event))) return;
        $this->_clearListeners($event);
      };
      self::$_emitter->on($event, $this->_listeners[$event]);
    }

    if($event == 'raw-message' || $event == 'dns-message') {
      $this->_getSocket();
    }
  }

  private function _clearListeners($event=null) {
    $listeners = $event ? [$event=>$this->_listeners[$event]??null] : $this->_listeners;

    foreach($listeners as $event => $listener) {
      if(!$listener) continue;
      self::$_emitter->removeListener($event, $listener);
      unset($this->_listeners[$event]);
    }

    if(!$event || $event == 'raw-message' || $event == 'dns-message') {
      $this->_end();
    }
  }

  private function _countMsgListeners() {
    $listeners  = count(self::$_emitter->listeners('raw-message'));
    $listeners += count(self::$_emitter->listeners('dns-message'));
    return $listeners;
  }

  private function _end() {
    if(!self::$_socket || self::$_ending) {
      return;
    }

    if($this->_countMsgListeners()) {
      return;
    }

    self::$_ending = true;
    self::$_socket->end();
  }
}
