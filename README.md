# mdns
Multicast DNS (mDNS) resolver and responder with service discovery (DNS-SD) based on ReactPHP

### Resolver
The resolver implements React\Dns\Resolver\ResolverInterface, so it can be used in connectors.

```php
use SharkyDog\mDNS;
use React\Dns\Config\Config as DnsConfig;
use React\Dns\Resolver\Factory as DnsFactory;

$domain = 'homeassistant.local';

$dnsConfig = DnsConfig::loadSystemConfigBlocking();
$dnsResolver = (new DnsFactory)->create($dnsConfig);

$resolver = new mDNS\React\Resolver(2,true,$dnsResolver);
$resolver->resolve($domain)->then(
  function($addr) {
    print "Found IP ".$addr."\n";
  },
  function(\Exception $e) {
    print "Error: ".$e->getMessage()."\n";
  }
);
```
Constructor
```php
public function __construct(int $timeout=2, bool $unicast=true, ?ResolverInterface $dnsResolver=null);
```
- Timeout is in seconds.
- If `$unicast` is `true`, queries are sent with dynamic source port, mDNS responders should send an unicast reply.
  If `false`, queries are sent with source port 5353 and resolver listens in multicast group.
- If `$dnsResolver` is supplied, only domains ending in `.local` are queried to the multicast group.

### Responder
```php
use SharkyDog\mDNS;

$mdnsd = new mDNS\SimpleResponder;
$mdnsd->addRecordIPv4('my-local-pc.local', '192.168.1.123');
$mdnsd->start();

// Stopping will close the socket and discard queued replies.
//$mdnsd->stop();
```
It's a "SimpleResponder", because it will only respond to simple queries.
Any record type can be added and the responder will answer with one record per message, matching query name to record name.
On qtype "ANY", all records matching name will be sent, again one record per message.
`$cfbit` is the "cache-flush bit" from RFC6762, `SRV` and `TXT` records created by `addService()` bellow will have the cache-flush bit set.

```php
public function addRecordIPv4(string $name, string $addr, int $ttl=120, $cfbit=true);
public function addRecordIPv6(string $name, string $addr, int $ttl=120, $cfbit=true);
public function addRecord(React\Dns\Model\Record $record, $cfbit=false);
public function delRecord(string $name, int $type, $data=null): bool;
public function addReverseIPv4(string $addr, string $name, int $ttl=120);
public function delReverseIPv4(string $addr): bool;
public function enableRecord(string $name, int $type, $data=null, bool $enable=true);
```
Type is one of `React\Dns\Model\Message::TYPE_` constants.
Single call for deleting a record will delete one record and return `true` or `false` if not found.
If there are multiple with the same name and type, to delete all run a loop until `false` is returned.

### Service discovery responder
Basic service discovery
```php
use SharkyDog\mDNS;

$mdnsd = new mDNS\SimpleResponder;
$mdnsd->addRecordIPv4('my-local-pc.local', '192.168.1.123');
$mdnsd->addService('_testsvc1._tcp', 'instance1', -1, 'my-local-pc.local', 23456, 'aa','bb','cc');

$mdnsd->start();
```
The `addService()` is the key point.
```php
public function addService(string $type, string $instance, ?int $ttl=null, ?string $target=null, int $srvport=0, string ...$txts);
public function delService(string $type, string $instance, bool $srv=true, bool $txt=true);
public function enableService(string $type, string $instance, bool $enable=true);
public function advertiseService(string $type, string $instance, ?int $ttl=null);
```
- `$type` and `$instance` form the service `instance1._testsvc1._tcp.local`
- `$ttl` (default 120) will be used for all records (`PTR`, `SRV` and `TXT`)
- If `$target` is supplied, automatic `SRV` and `TXT` records will be created
  - `SRV` will have priority=0, weight=0, port=`$srvport` and target=`$target`
  - `TXT` will be empty if `$txts` array is empty
- No automatic `SRV` and `TXT` records will be created if those already exist for the service (`instance1._testsvc1._tcp.local`)
  - Use `addRecord()` before `addService()`
- An automatic `PTR` will be added for `_services._dns-sd._udp.local` pointing to the new service type (`_testsvc1._tcp.local`)
  - Same as above, if it does not already exist
- `A` and `AAAA` records can not be auto created

`advertiseService()` when called will send once all records related to that service instance.

This service discovery still suffers from the same limitation of the `SimpleResponder` class mentioned above.
One answer per message, but additional `SRV`, `TXT`, `A` and `AAAA` records will be returned. If the reply grows too large, additional records will be sent in separate messages, but only for multicast replies. In unicast replies only the record matched with the query will be sent, additional records will be removed if they do not fit in a single message.

### RecordFactory class
New class (`SharkyDog\mDNS\RecordFactory`) to help create records and validate some parameters. IPv4 and IPv6 addresses are not yet checked thought.
```php
use SharkyDog\mDNS;

$rfy = new mDNS\RecordFactory;
$mdnsd = new mDNS\SimpleResponder;

$mdnsd->addRecord($rfy->A('my-router.local', '192.168.1.1'));
$mdnsd->addRecord($rfy->A('my-pc.local', '192.168.1.2'));
$mdnsd->addRecord($rfy->TXT('sometxt-my-pc.local', 120, 'txt1','txt2','...'));
```

### Multiple messages resolver
React resolver will use the first received message, which is proper for DNS, but in mDNS world multiple hosts can answer a query.
To return answers from multiple messages, some extensions to `SharkyDog\mDNS\React\Resolver` and executors need to be made.

A new parameter is added to `resolveAll`
```php
public function resolveAll($domain, $type, bool $multi=false);
```
When `$multi` is `true`, the timeout will be turned into time to collect messages.
So, no timeout error will be thrown, response will be returned after timeout have passed
and if no valid message was received in that time, React will throw `NOERROR / NODATA` error.

If the resolver was created with `$dnsResolver` parameter, `$multi` will be set to `false` for all domains except `.local`.

Let's see how many web servers in our network will respond in 2 seconds.
```php
use SharkyDog\mDNS;
use React\Dns\Model\Message;

$resolver = new mDNS\React\Resolver(2);
$resolver->resolveAll('_http._tcp.local', Message::TYPE_PTR, true)->then(
  function($data) {
    print_r(array_unique($data));
  },
  function(\Exception $e) {
    print "Error: ".$e->getMessage()."\n";
  }
);
```
This will return an array of PTRs (targets).
```
Array
(
    [0] => shellyplus1pm-xxxxxxxxxxxx._http._tcp.local
    [1] => shellyem-xxxxxxxxxxxx._http._tcp.local
    [2] => shellyplus2pm-xxxxxxxxxxxx._http._tcp.local
)
```
These are service instance names, like `instance1._testsvc1._tcp.local` from the Service discovery responder example above.
Only queried record types (in this case PTRs) should be returned as `additional` section is not processed.

Now, let's find all service types advertised on the local network.
```php
use SharkyDog\mDNS;
use React\Dns\Model\Message;

$resolver = new mDNS\React\Resolver(2);
$resolver->resolveAll('_services._dns-sd._udp.local', Message::TYPE_PTR, true)->then(
  function($data) {
    print_r(array_unique($data));
  },
  function(\Exception $e) {
    print "Error: ".$e->getMessage()."\n";
  }
);
```
Should return
```
Array
(
    [0] => _nut._tcp.local
    [1] => _smb._tcp.local
    [2] => _testsvc._tcp.local
    [3] => _esphomelib._tcp.local
    [4] => _http._tcp.local
    [5] => _shelly._tcp.local
    [6] => _androidtvremote2._tcp.local
    [7] => _googlecast._tcp.local
)
```
Probably many more.

### Additional records
```php
public function resolveAll($domain, $type, bool $multi=false, bool $additional=false);
```
Off by default as this can increase the size of the response significantly and it changes the structure of the response array a little.
```php
use SharkyDog\mDNS;
use React\Dns\Model\Message;

$resolver = new mDNS\React\Resolver(2);
$resolver->resolveAll('_http._tcp.local', Message::TYPE_PTR, false, true)->then(
  function($data) {
    $additional = isset($data['additional']) ? array_pop($data) : null;
    print_r(array_unique($data));
    print_r($additional);
  },
  function(\Exception $e) {
    print "Error: ".$e->getMessage()."\n";
  }
);
```
The additional records are put in `additional` element of the response array and will always be the last one.
Each element in this array is a `React\Dns\Model\Record` object.

For service discovery, most devices respond on query for `_svctype._tcp.local` and `_services._dns-sd._udp.local` with a `PTR` for an instance of that service in `answers` section and `SRV`, `TXT`, `A` and `AAAA` in `additional` section.

Any records in `answers` section of the DNS message that do not match the name in the query will be moved to `additional`.

#### Responder (`SharkyDog\mDNS\SimpleResponder`).
Replies to queries for `PTR` or `SRV` will add additional records that exist in the responder, added via `addRecordIPv4()`, `addRecordIPv6()`, `addRecord()` or `addService()`.
- Any records a `PTR` is pointing to.
- The target of a `SRV` (an `A`, `AAAA` or both).

If the response becomes too big, the additional records will be removed for unicast replies and sent in separate messages for multicast replies. Default message size is 1472 bytes, can be changed with `SharkyDog\mDNS\Socket::setPacketSize()`. Minimum is 12 bytes (dns message header), maximum is unbound.
```php
public static function setPacketSize(int $size);
```

### Message filter for the resolver
This is a callback that can filter out DNS messages before they are handled by the resolver.
The purpose of this filter is to remove unwanted records in multi mode (`$multi == true`) or to select the exact record in single mode (`$multi == false`) instead of the first received.
This also reflects on what additional records will be included as nothing is used from filtered out messages.

Only messages that have an answer matching query name and type will reach the filter.

Find a specific http server.
```php
use SharkyDog\mDNS;
use React\Dns\Model\Message;

$filter = function(Message $message, string $addr) {
  print "Message from ".$addr."\n";

  foreach($message->answers as $record) {
    if($record->type != Message::TYPE_PTR) {
      continue;
    }
    if($record->data == 'shellyem-xxxxxxxxxxxx._http._tcp.local') {
      return true;
    }
  }

  return false;
};

$resolver = new mDNS\React\Resolver(2);
$resolver->setMDnsFilter($filter);

$resolver->resolveAll('_http._tcp.local', Message::TYPE_PTR, false, true)->then(
  function($data) {
    $additional = isset($data['additional']) ? array_pop($data) : null;
    print_r(array_unique($data));
    print_r($additional);
  },
  function(\Exception $e) {
    print "Error: ".$e->getMessage()."\n";
  }
);
```
From v1.5 filter can receive the query too (`React\Dns\Query\Query`).
```php
use React\Dns\Model\Message;
use React\Dns\Query\Query;

$filter = function(Message $message, string $addr, Query $query) {
  // some code here
};
```
The filter can:
- return `false` - skip this message
- return `true` - stop processing messages and resolve the query with whatever was received so far
- return `Message` - stop and resolve with returned message, other messages received before in multi mode will be discarded
- throw exception - reject the query

#### Per query filter
The filter above will be used for all queries. From v1.5 a filter can be set only for the next query.
First the global filter will be called then if it doesn't return anything (or returns `null`) and doesn't throw exception, the per query filter will be called. Parameters are the same, return meaning too.
```php
// set filter for all queries
$resolver->setMDnsFilter($filter_all);
// set filter for next query
$resolver->setMDnsQueryFilter($filter_query1);
// $filter_all, then $filter_query1
$resolver->resolve($domain1);
// only $filter_all
$resolver->resolve($domain2);
```

### Discoverer
New class for service discovery.
```php
use SharkyDog\mDNS;
use SharkyDog\mDNS\Discoverer\Service;

$discoverer = new mDNS\SimpleDiscoverer;

// get all web servers that advertise themselves through mDNS
$discoverer->service('_http._tcp.local')->then(
  function($services) {
    foreach($services as $service) {
      $address = $service->target[0];
      print "Service ".$service->name;
      print " on ".$address->address.":".$service->port."\n";
    }
  },
  function(\Throwable $e) {
    print "Error: [".get_class($e)."] ".$e->getMessage()."\n";
  }
);
```
The `SimpleDiscoverer->service()` method resolves a given service type (`_http._tcp.local`), a service instance (`server1._http._tcp.local`) or the reserved name for all services (`_services._dns-sd._udp.local`) to an array of `SharkyDog\mDNS\Discoverer\Service` objects. It will throw an exception on any other name, like `some-host.local`.

First, the `PTR` record for the service type is resolved to service instances or other service types in case of `_services._dns-sd._udp.local`.
Then the `SRV` records for every instance, then `A` and/or `AAAA` for the target from the `SRV`, then the `TXT` records for every instance.

A service instance will be discarded if no IP address is found for it. The resolved addresses will be in `Service->target` property as an array of `SharkyDog\mDNS\Discoverer\Address` objects. IP type (IPv4 or IPv6) can be found in `Address->type` property: `React\Dns\Model\Message::TYPE_A` or `React\Dns\Model\Message::TYPE_AAAA`.

An important thing to note is when this is used with a service type, including the reserved one for all services, the resolving will stop only after the full timeout of the Resolver has passed (default 2s). Results will be returned only after that. This can be changed with the message filter (see above) and the service filter (bellow). A per query message filter can not be used here.

Used with service instance, will resolve without waiting the timeout if `SRV` and ip addresses were found.

The `service()` method has few more parameters.
```php
public function service(
  string $name,
  bool $ip4=true,
  bool $ip6=false,
  bool $txt=false,
  array &$addrr=[]
): React\Promise\PromiseInterface;
```
- `$ip4` and `$ip6` control what addresses will be resolved. If both are `false`, `$ip4` will be set to `true`.
- `$txt` control if the `TXT` record will be queried.
- `$addrr` is and array which will be filled with all additional records in the DNS messages.
  It can then be passed to another `service()` call and any records in it will be used instead of making new queries for them.
  Many devices return `SRV`, `TXT`, `A` and `AAAA` additional records in response to a `PTR` query for their service type.

#### Service filter
This filter applies to the next `service()` call, after which it will need to be set again if needed.

Returning `false` will discard the service, but the resolver will continue until timeout.
Returning `true` will stop listening and resolve the promise with the received services.
```php
use SharkyDog\mDNS;
use SharkyDog\mDNS\Discoverer\Service;

// use with custom resolver and change default timeout
$resolver = new mDNS\React\Resolver(5);
$discoverer = new mDNS\SimpleDiscoverer($resolver);

// will discard all services not on port 80
// and stop resolver on two received services
$discoverer->filter(function(Service $service) {
  static $counter = 0;

  if($service->port != 80) {
    return false;
  }

  if(++$counter == 2) {
    return true;
  }
});

// ask for all services on the network
// resolve IPv4, not IPv6
// ask for TXTs if not already in additional from PTRs
$discoverer->service('_services._dns-sd._udp.local',true,false,true)->then(
  function($services) {
    foreach($services as $service) {
      $address = $service->target[0];
      print "Service ".$service->name;
      print " on ".$address->address.":".$service->port."\n";
    }
  },
  function(\Throwable $e) {
    print "Error: [".get_class($e)."] ".$e->getMessage()."\n";
  }
);
```

### What is my local IP?
This class was made mostly for fun, but could be useful if for some reason your too many Raspberry Pis do not keep a static ip address.
A query will be sent for IPv4 address and a special domain `_my_lan_ip._test.local` (can be changed) to the mDNS group.
If there is a responder, it will reply with the source ip address.
```php
// Responder
namespace SharkyDog\mDNS;

// start
mDNS\WhatIsMyIP::startResponderIPv4();
// stop
mDNS\WhatIsMyIP::stopResponderIPv4();

// start, but only answer if the source ip is in this network
// 192.168.1.1 - 192.168.1.62
mDNS\WhatIsMyIP::startResponderIPv4('192.168.1.0/26');
```
```php
// Resolver
namespace SharkyDog\mDNS;

// $resolver is optional, use to change timeout
$resolver = new mDNS\React\Resolver(2);
// Accept a reply only if it comes from this network, also optional
// 192.168.1.1 - 192.168.1.254
$promise = mDNS\WhatIsMyIP::resolveIPv4('192.168.1.0/24',$resolver);

// Will add the found IP to this mDNS Responder
$mdnsd = new mDNS\SimpleResponder;

$promise->then(
  function($ip) use($mdnsd) {
    print "My IP is ".$ip."\n";
    $mdnsd->addRecordIPv4('my-local-pc.local', $ip);
  },
  function(\Exception $e) {
    print "Error: [".get_class($e)."] ".$e->getMessage()."\n";
  }
);
```
