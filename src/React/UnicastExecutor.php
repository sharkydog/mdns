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
  private $_filterQuery;
  private $_collector;

  public function setFilter(?callable $filter, bool $query=false) {
    if($query) {
      $this->_filterQuery = $filter;
    } else {
      $this->_filter = $filter;
    }
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

    $collector = $this->_collector;
    $this->_collector = null;
    $filterQuery = $this->_filterQuery;
    $this->_filterQuery = null;

    $socket->on('message', function($message,$addr) use($query,$deferred,$mesgid,$collector,$filterQuery) {
      if(!DnsMessage::validReply($message,$mesgid,Message::RCODE_OK)) {
        return;
      }
      if(!($message = DnsMessage::decode($message))) {
        return;
      }

      $filter = null;

      try {
        if($this->_filter) {
          if(($filter = ($this->_filter)($message,$addr,$query)) === false) {
            return;
          } else if($filter instanceOf Message) {
            $deferred->resolve($filter);
            return;
          }
        }
        if($filter === null && $filterQuery) {
          if(($filter = $filterQuery($message,$addr,$query)) === false) {
            return;
          } else if($filter instanceOf Message) {
            $deferred->resolve($filter);
            return;
          }
        }
      } catch(\Exception $e) {
        $deferred->reject($e);
        return;
      }

      if($collector) {
        if(!$collector->id) {
          $collector->id = $message->id;
          $collector->questions[] = $query;
        }
        foreach($message->answers as $record) {
          $collector->answers[] = $record;
        }
        foreach($message->additional as $record) {
          $collector->additional[] = $record;
        }
        if($filter === true) {
          $deferred->resolve($collector);
        }
      } else {
        $deferred->resolve($message);
      }
    });

    $message = DnsMessage::encode($message);
    $socket->send($message, Socket::NS);

    return $deferred->promise()->finally(function() use($socket) {
      $socket->close();
    });
  }
}
