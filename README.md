# mdns
mDNS resolver and responder based on ReactPHP

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

```php
public function addRecordIPv4(string $name, string $addr, int $ttl=120);
public function addRecordIPv6(string $name, string $addr, int $ttl=120);
public function addRecord(React\Dns\Model\Record $record);
```

### Service discovery responder (from v1.1)
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

This service discovery still suffers from the same limitation of the `SimpleResponder` class mentioned above.
One record per message (help appreciated), which means a discoverer will have to make separate queries to follow PTRs.

### RecordFactory class (from v1.1)
New class (`SharkyDog\mDNS\RecordFactory`) to help create records and validate some parameters. IPv4 and IPv6 addresses are not yet checked thought.
```php
use SharkyDog\mDNS;

$rfy = new mDNS\RecordFactory;
$mdnsd = new mDNS\SimpleResponder;

$mdnsd->addRecord($rfy->A('my-router.local', '192.168.1.1'));
$mdnsd->addRecord($rfy->A('my-pc.local', '192.168.1.2'));
$mdnsd->addRecord($rfy->TXT('sometxt-my-pc.local', 120, 'txt1','txt2','...'));
```
