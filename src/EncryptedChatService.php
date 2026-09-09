<?php
declare(strict_types=1);
namespace App;

use PDO;

final class EncryptedChatService
{
    // 8 MiB content + 4-byte header length + 4096-byte encrypted metadata + GCM tag.
    public const MAX_CIPHERTEXT = 8388608 + 4 + 4096 + 16;
    public function __construct(private PDO $db) {}

    public function create(string $duration, string $history): array
    {
        $this->db->beginTransaction();
        try {
            $room = (new ChatService($this->db))->create($duration, $history);
            $this->db->prepare('INSERT INTO encrypted_rooms(room_id) SELECT id FROM rooms WHERE token=?')->execute([$room['token']]);
            $this->db->commit();
            return $room;
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }
    public function room(string $token): array
    {
        $q=$this->db->prepare('SELECT r.* FROM rooms r JOIN encrypted_rooms e ON e.room_id=r.id WHERE token=? AND expires_at>?');
        $q->execute([$token,time()]);
        return $q->fetch() ?: throw new \RuntimeException('This chat does not exist or has expired.');
    }
    public function member(array $room, string $session): ?array
    {
        $q=$this->db->prepare('SELECT * FROM encrypted_members WHERE room_id=? AND session_hash=?');
        $q->execute([$room['id'],$session]);
        return $q->fetch() ?: null;
    }
    public function join(array $room, string $session, string $name): array
    {
        $name=trim($name);
        if ($name==='' || mb_strlen($name)>40) throw new \InvalidArgumentException('Name must be between 1 and 40 characters.');
        // A single write statement records the exact message boundary, even within one second.
        $q=$this->db->prepare('INSERT OR IGNORE INTO encrypted_members(room_id,session_hash,display_name,after_id) VALUES (?,?,?,(SELECT COALESCE(MAX(id),0) FROM encrypted_messages WHERE room_id=?))');
        $q->execute([$room['id'],$session,$name,$room['id']]);
        return $this->member($room,$session);
    }
    public function messages(array $room, array $member, int $after=0): array
    {
        $boundary=$room['history_mode']==='since_join' ? (int)$member['after_id'] : 0;
        $q=$this->db->prepare('SELECT m.id,m.created_at,u.display_name,m.session_hash FROM encrypted_messages m JOIN encrypted_members u ON u.room_id=m.room_id AND u.session_hash=m.session_hash WHERE m.room_id=? AND m.id>? ORDER BY m.id LIMIT 100');
        $q->execute([$room['id'],max($after,$boundary)]);
        return array_map(static function($m) use ($member) {
            $m['is_mine']=$m['session_hash']===$member['session_hash']; unset($m['session_hash']); return $m;
        },$q->fetchAll());
    }
    public function post(array $room,array $member,string $nonce,string $ciphertext): array
    {
        if(strlen($nonce)!==12 || strlen($ciphertext)<20 || strlen($ciphertext)>self::MAX_CIPHERTEXT) throw new \InvalidArgumentException('Invalid encrypted payload or file exceeds 8 MB.');
        $q=$this->db->prepare('INSERT INTO encrypted_messages(room_id,session_hash,nonce,ciphertext,created_at) VALUES (?,?,?,?,?)');
        $q->bindValue(1,$room['id'],PDO::PARAM_INT); $q->bindValue(2,$member['session_hash']);
        $q->bindValue(3,$nonce,PDO::PARAM_LOB); $q->bindValue(4,$ciphertext,PDO::PARAM_LOB); $q->bindValue(5,time(),PDO::PARAM_INT); $q->execute();
        return ['id'=>(int)$this->db->lastInsertId(),'created_at'=>time(),'display_name'=>$member['display_name'],'is_mine'=>true];
    }
    public function content(array $room,array $member,int $id): string
    {
        $boundary=$room['history_mode']==='since_join' ? (int)$member['after_id'] : 0;
        $q=$this->db->prepare('SELECT nonce,ciphertext FROM encrypted_messages WHERE room_id=? AND id=? AND id>?');
        $q->execute([$room['id'],$id,$boundary]); $m=$q->fetch();
        if(!$m) throw new \RuntimeException('Message unavailable.');
        return $m['nonce'].$m['ciphertext'];
    }
}
