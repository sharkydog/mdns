<?php
namespace SharkyDog\mDNS\React;
use SharkyDog\mDNS\Socket;
use React\Dns\Query\ExecutorInterface;
use React\Dns\Query\Query;
use React\Dns\Model\Message;
use React\Promise\Deferred;

class MulticastExecutor implements ExecutorInterface {
  private $_collector;

  public function setCollector(Message $collector) {
    $this->_collector = $collector;
    $this->_collector->qr = true;
  }

  public function query(Query $query) {
    $message = Message::createRequestForQuery($query);
    $socket = new Socket;
    $rrtype = $query->type;
    $rrname = strtolower($query->name);

    $deferred = new Deferred(function() use($rrtype,$rrname) {
      throw new \RuntimeException('mDNS query for '.$rrname.'['.$rrtype.'] cancelled');
    });

    $socket->on('dns-message', function($message,$addr) use($rrtype,$rrname,$deferred) {
      if($message->qr !== true) {
        return;
      }

      $found = false;

      foreach($message->answers as $record) {
        if(!$found) {
          if($record->type != $rrtype) {
            continue;
          }
          if(strtolower($record->name) != $rrname) {
            continue;
          }
        }

        $found = true;

        if($this->_collector) {
          $this->_collector->answers[] = $record;
        } else {
          $deferred->resolve($message);
          return;
        }
      }

      if($found && $this->_collector) {
        foreach($message->additional as $record) {
          $this->_collector->additional[] = $record;
        }
      }
    });

    $socket->send($message);

    return $deferred->promise()->finally(function() use($socket) {
      $socket->removeAllListeners();
      $this->_collector = null;
    });
  }
}
