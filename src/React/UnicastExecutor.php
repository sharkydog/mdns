<?php
namespace SharkyDog\mDNS\React;
use SharkyDog\mDNS\Socket;
use SharkyDog\mDNS\DnsMessage;
use React\Dns\Query\ExecutorInterface;
use React\Dns\Query\Query;
use React\Dns\Model\Message;
use React\Promise\Deferred;
use Clue\React\Multicast\Factory as MulticastFactory;

class UnicastExecutor implements ExecutorInterface {
  private $_filter;
  private $_collector;

  public function setFilter(?callable $filter) {
    $this->_filter = $filter;
  }

  public function setCollector(Message $collector) {
    $this->_collector = $collector;
    $this->_collector->qr = true;
  }

  public function query(Query $query) {
    $query->class |= 0x8000;
    $message = Message::createRequestForQuery($query);
    $socket = (new MulticastFactory)->createSender();
    $mesgid = $message->id;
    $rrtype = $query->type;
    $rrname = $query->name;

    $deferred = new Deferred(function() use($rrtype,$rrname) {
      throw new \RuntimeException('mDNS query for '.$rrname.'['.$rrtype.'] cancelled');
    });

    $socket->on('message', function($message,$addr) use($deferred,$mesgid) {
      if(!DnsMessage::validReply($message,$mesgid,Message::RCODE_OK)) {
        return;
      }
      if(!($message = DnsMessage::decode($message))) {
        return;
      }

      if($this->_filter && ($this->_filter)($message,$addr) === false) {
        return;
      }

      if($this->_collector) {
        if(!$this->_collector->id) {
          $this->_collector->id = $message->id;
        }
        foreach($message->answers as $record) {
          $this->_collector->answers[] = $record;
        }
        foreach($message->additional as $record) {
          $this->_collector->additional[] = $record;
        }
      } else {
        $deferred->resolve($message);
      }
    });

    $message = DnsMessage::encode($message);
    $socket->send($message, Socket::NS);

    return $deferred->promise()->finally(function() use($socket) {
      $socket->close();
      $this->_collector = null;
    });
  }
}
