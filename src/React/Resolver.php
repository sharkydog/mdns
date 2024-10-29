<?php
namespace SharkyDog\mDNS\React;
use React\Dns\Resolver\ResolverInterface;
use React\Dns\Resolver\Resolver as ReactResolver;
use React\Dns\Query\TimeoutExecutor;
use React\Dns\Query\TimeoutException;
use React\Dns\Query\CoopExecutor;
use React\Dns\Model\Message;

class Resolver implements ResolverInterface {
  private $_resolver;
  private $_resolver_dns;
  private $_executor_mdns;
  private $_storage = [];

  public function __construct(int $timeout=2, bool $unicast=true, ?ResolverInterface $dnsResolver=null) {
    $executor = $unicast ? new UnicastExecutor : new MulticastExecutor;
    $this->_executor_mdns = $executor;

    $executor = new CallbackExecutor(function($query) use($executor) {
      if($collector = ($this->_getStorageQuery($query)->collector ?? null)) {
        $executor->setCollector($collector);
      }
      return $executor->query($query);
    });

    $executor = new TimeoutExecutor($executor, $timeout);

    $executor = new CallbackExecutor(function($query) use($executor) {
      $store = $this->_getStorageQuery($query);
      $store->collector = $collector = ($store->multi??null) ? new Message : null;

      $promise = $executor->query($query);

      if($collector) {
        $promise = $promise->catch(function(TimeoutException $e) use($collector) {
          return $collector;
        });
      }

      $promise = $promise->then(function($message) use($query, $store) {
        $qname = strtolower($query->name);

        foreach($message->answers as $k => $record) {
          if(strtolower($record->name) == $qname && $record->type == $query->type) {
            continue;
          }

          unset($message->answers[$k]);
          array_unshift($message->additional, $record);
        }

        $message->answers = array_values($message->answers);
        $store->reply = $message;

        return $message;
      });

      return $promise;
    });

    $executor = new CoopExecutor($executor);

    $this->_resolver = new ReactResolver($executor);
    $this->_resolver_dns = $dnsResolver;
  }

  public function setMDnsFilter(?callable $filter) {
    $this->_executor_mdns->setFilter($filter);
  }

  public function setDnsResolver(?ResolverInterface $dnsResolver) {
    $this->_resolver_dns = $dnsResolver;
  }

  public function resolve($domain) {
    return $this->_getResolver($domain)->resolve($domain)->finally(function() use($domain) {
      $this->_delStorage($domain, Message::TYPE_A);
    });
  }

  public function resolveAll($domain, $type, bool $multi=false, bool $additional=false) {
    $resolver = $this->_getResolver($domain);

    if($resolver !== $this->_resolver) {
      return $resolver->resolveAll($domain, $type);
    }

    $store = $this->_getStorage($domain, $type);
    $store->multi = $multi;
    $store->addrr = $additional;

    $promise = $resolver->resolveAll($domain, $type);

    $promise = $promise->then(function($data) use($store) {
      if($store->addrr) {
        $data['additional'] = $store->reply->additional;
      }
      return $data;
    });

    $promise = $promise->finally(function() use($domain, $type) {
      $this->_delStorage($domain, $type);
    });

    return $promise;
  }

  private function _getResolver($domain) {
    if(!$this->_resolver_dns || preg_match('/\.local$/i',$domain)) {
      return $this->_resolver;
    } else {
      return $this->_resolver_dns;
    }
  }

  private function _getStorageQuery($query) {
    return $this->_getStorage($query->name, $query->type);
  }

  private function _getStorage($name, $type) {
    $key = $name.'|'.$type;

    if(!isset($this->_storage[$key])) {
      $this->_storage[$key] = new \stdClass;
    }

    return $this->_storage[$key];
  }

  private function _delStorage($name, $type) {
    $key = $name.'|'.$type;
    unset($this->_storage[$key]);
  }
}
