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

### Respnder
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
