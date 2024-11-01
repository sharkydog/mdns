<?php
namespace SharkyDog\mDNS;
use SharkyDog\mDNS\Discoverer\Address;
use React\Dns\Resolver\ResolverInterface;
use React\Dns\Model\Message;
use React\Dns\Model\Record;
use React\Dns\RecordNotFoundException;
use React\Promise;
use React\Promise\Exception\CompositeException;
use React\EventLoop\Loop;

class SimpleDiscoverer {
  private $_resolver;
  private $_filter;

  public function __construct(
    ?React\Resolver $mdnsResolver=null,
    ?ResolverInterface $dnsResolver=null
  ) {
    if(!$mdnsResolver) {
      $mdnsResolver = new React\Resolver;
      $mdnsResolver->setDnsResolver($dnsResolver);
    }
    $this->_resolver = $mdnsResolver;
  }

  public function filter(?callable $filter) {
    $this->_filter = $filter;
  }

  public function address(
    string $name,
    bool $ip4=true,
    bool $ip6=false,
    array &$addrr=[]
  ): Promise\PromiseInterface {
    $ip4 = $ip4 || !$ip6;
    $promises = [];

    if($ip4) {
      $ip4 = $this->_find_records($addrr, $name, Message::TYPE_A);
      if(empty($ip4)) {
        Log::debug('Discoverer: Resolve A for '.$name);
        $promise = $this->_resolver->resolveAll($name, Message::TYPE_A);
        $promise = $promise->then(function($results) {
          return [
            'type' => Message::TYPE_A,
            'addr' => $results
          ];
        });
      } else {
        $promise = Promise\resolve([
          'type' => Message::TYPE_A,
          'addr' => array_map(fn($rr) => $rr->data, $ip4)
        ]);
      }
      $promises[] = $promise;
    }

    if($ip6) {
      $ip6 = $this->_find_records($addrr, $name, Message::TYPE_AAAA);
      if(empty($ip6)) {
        Log::debug('Discoverer: Resolve AAAA for '.$name);
        $promise = $this->_resolver->resolveAll($name, Message::TYPE_AAAA);
        $promise = $promise->then(function($results) {
          return [
            'type' => Message::TYPE_AAAA,
            'addr' => $results
          ];
        });
      } else {
        $promise = Promise\resolve([
          'type' => Message::TYPE_AAAA,
          'addr' => array_map(fn($rr) => $rr->data, $ip6)
        ]);
      }
      $promises[] = $promise;
    }

    $promise = $this->_promiseSome(...$promises);

    $promise = $promise->then(function($results) use($name, &$addrr) {
      $ret = [];

      foreach($results as $result) {
        foreach($result['addr'] as $data) {
          $addr = new Discoverer\Address;
          $addr->name = $name;
          $addr->address = $data;
          $addr->type = $result['type'];

          $ret[] = $addr;

          $this->_add_records($addrr, [new Record(
            $addr->name, $addr->type,
            Message::CLASS_IN, 120,
            $addr->address
          )]);
        }
      }

      return $ret;
    });

    $promise = $promise->catch(function(CompositeException $e) use($name) {
      foreach($e->getThrowables() as $ex) Log::debug('Dicoverer: '.$ex->getMessage());
      throw new RecordNotFoundException('No address found for '.$name);
    });

    return $promise;
  }

  public function service(
    string $name,
    bool $ip4=true,
    bool $ip6=false,
    bool $txt=false,
    array &$addrr=[]
  ): Promise\PromiseInterface {
    $filter = $this->_filter ? (object)['f'=>$this->_filter,'r'=>null] : null;
    $this->_filter = null;
    return $this->_service($name, $ip4, $ip6, $txt, $filter, $addrr);
  }

  private function _promiseSome(...$promises) {
    $exceptions = [];

    $catcher = function(\Exception $e) use(&$exceptions) {
      $exceptions[] = $e;
      return null;
    };

    $promises = array_map(fn($pr) => $pr->catch($catcher), $promises);

    return Promise\all($promises)->then(function($values) use(&$exceptions) {
      $values = array_filter($values, fn($val) => $val!==null);

      if(empty($values)) {
        throw new CompositeException($exceptions);
      }

      return $values;
    });
  }

  private function _add_record(array &$store, $record) {
    $key = strtolower($record->name).'|'.$record->type;

    if(in_array($record->type, [Message::TYPE_PTR,Message::TYPE_A,Message::TYPE_AAAA])) {
      $key .= '|'.strtolower($record->data);
    } else if($record->type == Message::TYPE_SRV) {
      $key .= '|'.implode('|',$record->data);
    } else if($record->type == Message::TYPE_TXT) {
    } else {
      return false;
    }

    if(isset($store[$key])) {
      return false;
    }

    $store[$key] = $record;
    return true;
  }

  private function _add_records(array &$store, $records) {
    foreach($records as $record) {
      $this->_add_record($store, $record);
    }
  }

  private function _find_records(array &$store, $name, $type) {
    if(empty($store)) {
      return [];
    }

    $name = strtolower($name);
    $found = [];

    foreach($store as $record) {
      if($record->type != $type) {
        continue;
      }
      if(strtolower($record->name) != $name) {
        continue;
      }
      $found[] = $record;
    }

    return $found;
  }

  private function _service($name, $ip4, $ip6, $txt, $filter, array &$addrr=[]) {
    if(!preg_match('/\s*(?:(.+)\.)?(_[^\.]+\.(?:_tcp|_udp)\.local)$/i', $name, $m)) {
      return Promise\reject(new \Exception($name.' is not a service instance or service type'));
    }

    list(,$svcinst,$svctype) = $m;

    if($svctype == '_dns-sd._udp.local' && $svcinst == '_services') {
      $svctype = '_services._dns-sd._udp.local';
      $svcinst = '';
    }

    if($svcinst) {
      return $this->_service_instance($svcinst.'.'.$svctype, $ip4, $ip6, $txt, $filter, $addrr);
    } else {
      return $this->_service_type($svctype, $ip4, $ip6, $txt, $filter, $addrr);
    }
  }

  private function _service_type($name, $ip4, $ip6, $txt, $filter, array &$addrr=[]) {
    $prs = [];
    $ptrs = $this->_find_records($addrr, $name, Message::TYPE_PTR);

    if(empty($ptrs)) {
      $prsvct = null;

      $this->_resolver->setMDnsQueryFilter(
        function($message,$addr,$query)
        use($name,$ip4,$ip6,$txt,&$prsvct,$filter,&$addrr,&$prs) {
          if($filter && $filter->r) {
            return true;
          }

          $this->_add_records($addrr, $message->additional);

          foreach($message->answers as $record) {
            if($record->type != Message::TYPE_PTR) {
              $this->_add_record($addrr, $record);
              continue;
            }
            if($this->_add_record($addrr, $record)) {
              $pr = $this->_service($record->data,$ip4,$ip6,$txt,$filter,$addrr);
              $pr = $pr->then(function($val) use(&$prsvct,$filter) {
                if($filter && $filter->r) {
                  Loop::futureTick(fn() => $prsvct->cancel());
                }
                return $val;
              });
              $prs[] = $pr;
            }
          }

          return false;
        }
      );

      Log::debug('Discoverer: Resolve PTR for '.$name);
      $promise = $this->_resolver->resolveAll($name, Message::TYPE_PTR);
      $prsvct = $promise;
      $promise = $promise->catch(fn(\Exception $e) => null);
    } else {
      if(!$filter || $filter->r === null) {
        foreach($ptrs as $ptr) {
          $prs[] = $this->_service($ptr->data,$ip4,$ip6,$txt,$filter,$addrr);
        }
      }
      $promise = Promise\resolve(null);
    }

    $promise = $promise->then(function() use($filter,&$prs) {
      if(empty($prs)) throw new \Exception;
      if($filter && $filter->r) {
        foreach($prs as $pr) $pr->cancel();
      }

      return $this->_promiseSome(...$prs);
    });

    $promise = $promise->catch(function(\Exception $e) use($name) {
      throw new RecordNotFoundException('No services found for '.$name);
    });

    $promise = $promise->then(function($results) {
      return array_merge(...$results);
    });

    return $promise;
  }

  private function _service_instance($name, $ip4, $ip6, $txt, $filter, array &$addrr=[]) {
    $srvs = $this->_find_records($addrr, $name, Message::TYPE_SRV);

    if(empty($srvs)) {
      Log::debug('Discoverer: Resolve SRV for '.$name);
      $promise = $this->_resolver->resolveAll($name, Message::TYPE_SRV, false, true);

      $promise = $promise->then(function($results) use($name,&$addrr) {
        $this->_add_records($addrr, array_pop($results));

        foreach($results as $result) {
          $rr = new Record(
            $name, Message::TYPE_SRV,
            Message::CLASS_IN, 120,
            $result
          );
          $this->_add_records($addrr, [$rr]);
        }

        return $results;
      });
    } else {
      $promise = Promise\resolve(array_map(fn($srv)=>$srv->data, $srvs));
    }

    $targets = [];

    $promise = $promise->then(function($results) use($name, &$targets, $ip4, $ip6, $txt) {
      $ip4 = $ip4 || !$ip6;
      $ret = [];

      $addr_resolver = function($svc, $name, $type) use(&$targets) {
        if(!isset($targets[$name])) {
          $targets[$name] = [Message::TYPE_A=>[],Message::TYPE_AAAA=>[]];
        }
        $targets[$name][$type][] = $svc;
      };

      foreach($results as &$result) {
        $svc = new Discoverer\Service;
        $svc->name = $name;
        $svc->priority = $result['priority'];
        $svc->weight = $result['weight'];
        $svc->port = $result['port'];

        $ret[] = $svc;

        if($ip4) {
          $addr_resolver($svc, $result['target'], Message::TYPE_A);
        }
        if($ip6) {
          $addr_resolver($svc, $result['target'], Message::TYPE_AAAA);
        }
      }

      return $ret;
    });

    $promise = $promise->then(function($results) use($filter,&$addrr,&$targets) {
      if(empty($targets)) {
        return $results;
      }

      $promises = [];

      foreach($targets as $domain => $ip46) {
        $promise = $this->address(
          $domain,
          !empty($ip46[Message::TYPE_A]),
          !empty($ip46[Message::TYPE_AAAA]),
          $addrr
        );

        $promise = $promise->then(function($results) use($ip46) {
          foreach($results as $result) {
            foreach($ip46[$result->type] as $svc) {
              $svc->target[] = $result;
            }
          }
        });

        $promises[] = $promise;
      }

      $promise = $this->_promiseSome(...$promises);
      $promise = $promise->catch(fn(\Exception $e) => null);

      $promise = $promise->then(function() use($results,$filter) {
        $results = array_filter($results, fn($svc) => !empty($svc->target));

        if($filter) {
          if($filter->r) {
            return [];
          }

          $ret = [];

          foreach($results as $result) {
            try {
              if(($r = ($filter->f)($result)) === false) {
                continue;
              }

              $ret[] = $result;

              if($r === true) {
                $filter->r = true;
                break;
              }
            } catch(\Exception $e) {
              $filter->r = $e;
              return [];
            }
          }

          $results = $ret;
        }

        return $results;
      });

      return $promise;
    });

    $promise = $promise->then(function($results) use($name) {
      if(empty($results)) {
        throw new RecordNotFoundException('No service found for '.$name);
      }
      return $results;
    });

    if(!$txt) {
      return $promise;
    }

    $txtrr = [];

    $promise = $promise->then(function($results) use($name, &$addrr, &$txtrr) {
      $txtrr = $this->_find_records($addrr, $name, Message::TYPE_TXT);
      if(!empty($txtrr)) {
        return $results;
      }

      Log::debug('Discoverer: Resolve TXT for '.$name);
      $promise = $this->_resolver->resolveAll($name, Message::TYPE_TXT, false, false);
      $promise = $promise->catch(fn(\Exception $e) => []);

      $promise = $promise->then(function($txts) use($results, $name, &$addrr, &$txtrr) {
        foreach($txts as $txt) {
          $rr = new Record(
            $name, Message::TYPE_TXT,
            Message::CLASS_IN, 120,
            $txt
          );
          $txtrr[] = $rr;
          $this->_add_records($addrr, [$rr]);
        }
        return $results;
      });

      return $promise;
    });

    $promise = $promise->then(function($results) use(&$txtrr) {
      $txts = [];

      foreach($txtrr as $rr) {
        foreach($rr->data as $txt) {
          if(!preg_match('/^([^\=]+)(.*)$/', $txt, $m)) {
            continue;
          }
          $txts[$m[1]] = $m[2] ? substr($m[2],1) : true;
        }
      }

      foreach($results as $result) {
        $result->data = $txts;
      }

      return $results;
    });

    return $promise;
  }
}
