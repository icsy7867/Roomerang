<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
function check(bool $condition,string $label): void { if(!$condition) throw new RuntimeException($label); echo "PASS $label\n"; }
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec(file_get_contents(dirname(__DIR__).'/database/schema.sql'));
$service=new App\EncryptedChatService($db);
$r=$service->room($service->create('10m','since_join')['token']);
$a=$service->join($r,'session-a','Same Name');
$first=$service->post($r,$a,random_bytes(12),random_bytes(32));
$b=$service->join($r,'session-b','Same Name');
check($service->messages($r,$b)===[],'history boundary excludes earlier ciphertext');
try {$service->content($r,$b,$first['id']); throw new LogicException('history leak');} catch(RuntimeException $e) {check($e->getMessage()==='Message unavailable.','direct content history check');}
$second=$service->post($r,$a,random_bytes(12),random_bytes(32));
$visible=$service->messages($r,$b);
check(count($visible)===1 && !$visible[0]['is_mine'],'session ownership despite identical names');
check(strlen($service->content($r,$b,$second['id']))===44,'binary nonce and ciphertext preserved');
$share=$service->share($r,$b,$second['id']);
check(preg_match('/^[A-Za-z0-9_-]{43}$/',$share['token'])===1,'shared message token is opaque');
$shared=$service->shared($share['token']);
check($shared['id']===$second['id'] && strlen($service->sharedContent($shared))===44,'shared message exposes only its encrypted bytes');
try {$service->share($r,$b,$first['id']); throw new LogicException('history share leak');} catch(RuntimeException $e) {check($e->getMessage()==='Message unavailable.','history boundary prevents sharing earlier ciphertext');}
$other=$service->room($service->create('10m','all')['token']);
$c=$service->join($other,'session-c','C');
try {$service->content($other,$c,$second['id']); throw new LogicException('room leak');} catch(RuntimeException $e) {check($e->getMessage()==='Message unavailable.','cross-room content denied');}
try {$service->post($r,$a,random_bytes(12),str_repeat('x',App\EncryptedChatService::MAX_CIPHERTEXT+1)); throw new LogicException('size limit');} catch(InvalidArgumentException) {echo "PASS server size limit\n";}
$ids = [];
for ($i = 0; $i < 60; $i++) $ids[] = $service->post($other,$c,random_bytes(12),random_bytes(32))['id'];
$latest = $service->messages($other,$c,0,PHP_INT_MAX);
check(array_column($latest,'id') === array_slice($ids,-25),'latest page returns newest 25 in chronological order');
$older = $service->messages($other,$c,0,(int)$latest[0]['id']);
check(array_column($older,'id') === array_slice($ids,10,25),'older page has no duplicates or gaps');
check(array_column($service->messages($other,$c,0,(int)$older[0]['id']),'id') === array_slice($ids,0,10),'oldest partial page');
check($service->messages($r,$b,0,(int)$second['id']) === [],'backward pagination respects join boundary');
check(array_column($service->messages($other,$c,(int)$ids[57]),'id') === array_slice($ids,58),'live cursor returns only newer messages');
$db->exec('UPDATE rooms SET expires_at=0');
try {$service->room($r['token']); throw new LogicException('expired room');} catch(RuntimeException $e) {check(str_contains($e->getMessage(),'expired'),'expiry denied');}
try {$service->shared($share['token']); throw new LogicException('expired share');} catch(RuntimeException $e) {check(str_contains($e->getMessage(),'expired'),'shared message expires with parent room');}
check((int)$db->query('SELECT COUNT(*) FROM messages')->fetchColumn()===0,'no plaintext message rows');
echo "All encrypted storage checks passed.\n";
