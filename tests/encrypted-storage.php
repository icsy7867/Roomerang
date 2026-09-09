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
$other=$service->room($service->create('10m','all')['token']);
$c=$service->join($other,'session-c','C');
try {$service->content($other,$c,$second['id']); throw new LogicException('room leak');} catch(RuntimeException $e) {check($e->getMessage()==='Message unavailable.','cross-room content denied');}
try {$service->post($r,$a,random_bytes(12),str_repeat('x',App\EncryptedChatService::MAX_CIPHERTEXT+1)); throw new LogicException('size limit');} catch(InvalidArgumentException) {echo "PASS server size limit\n";}
$db->exec('UPDATE rooms SET expires_at=0');
try {$service->room($r['token']); throw new LogicException('expired room');} catch(RuntimeException $e) {check(str_contains($e->getMessage(),'expired'),'expiry denied');}
check((int)$db->query('SELECT COUNT(*) FROM messages')->fetchColumn()===0,'no plaintext message rows');
echo "All encrypted storage checks passed.\n";
