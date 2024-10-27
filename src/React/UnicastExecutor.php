<?php
namespace SharkyDog\mDNS\React;
use SharkyDog\mDNS\Socket;
use React\Dns\Query\ExecutorInterface;
use React\Dns\Query\Query;
use React\Dns\Model\Message;
use React\Promise\Deferred;
use Clue\React\Multicast\Factory as MulticastFactory;
use React\Dns\Protocol\Parser as DnsParser;
use React\Dns\Protocol\BinaryDumper as DnsEncoder;

class UnicastExecutor implements ExecutorInterface {
  private $_collector;

  public function setCollector(Message $collector) {
    $this->_collector = $collector;
    $this->_collector->qr = true;
  }

  public function query(Query $query) {
    $query->class |= 0x8000;
    $message = Message::createRequestForQuery($query);
    $socket = (new MulticastFactory)->createSender();
    $parser = new DnsParser;
    $mesgid = $message->id;
    $rrtype = $query->type;
    $rrname = $query->name;

    $deferred = new Deferred(function() use($rrtype,$rrname) {
      throw new \RuntimeException('mDNS query for '.$rrname.'['.$rrtype.'] cancelled');
    });

    $socket->on('message', function($message) use($parser,$deferred,$mesgid) {
      $message = $parser->parseMessage($message);

      if($message->qr !== true) {
        return;
      }
      if($message->id !== $mesgid) {
        return;
      }

      if($this->_collector) {
        if(!$this->_collector->id) {
          $this->_collector->id = $message->id;
        }
        foreach($message->answers as $record) {
          $this->_collector->answers[] = $record;
        }
      } else {
        $deferred->resolve($message);
      }
    });

    $message = (new DnsEncoder)->toBinary($message);
    $socket->send($message, Socket::NS);

    return $deferred->promise()->finally(function() use($socket) {
      $socket->close();
      $this->_collector = null;
    });
  }
}
