<?php
declare(strict_types=1);
namespace App;

use PDO;

final class AccessDeniedException extends \RuntimeException {}

final class EncryptedChatService
{
    // 8 MiB content + 4-byte header length + 4096-byte encrypted metadata + GCM tag.
    public const MAX_CIPHERTEXT = 8388608 + 4 + 4096 + 16;
    public function __construct(private PDO $db) {}

    public function create(string $duration, string $history): array
    {
        SystemState::assertWritable($this->db);
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
        $q=$this->db->prepare('SELECT r.*,e.master_session_hash,e.read_only FROM rooms r JOIN encrypted_rooms e ON e.room_id=r.id WHERE r.token=? AND r.expires_at>?');
        $q->execute([$token,time()]);
        return $q->fetch() ?: throw new \RuntimeException('This chat does not exist or has expired.');
    }
    public function member(array $room, string $session): ?array
    {
        $q=$this->db->prepare('SELECT * FROM encrypted_members WHERE room_id=? AND session_hash=?');
        $q->execute([$room['id'],$session]);
        return $q->fetch() ?: null;
    }
    private function assertMember(array $member): void
    {
        if ((int)($member['blocked'] ?? 0) === 1) throw new AccessDeniedException('This session is blocked from the chat.');
    }
    public function touch(array $room, array $member): array
    {
        $this->assertMember($member);
        if (SystemState::maintenanceEnabled($this->db)) return $member;
        $now=time();
        $q=$this->db->prepare('UPDATE encrypted_members SET last_seen_at=? WHERE room_id=? AND session_hash=? AND blocked=0');
        $q->execute([$now,$room['id'],$member['session_hash']]);
        $member['last_seen_at']=$now;
        return $member;
    }
    public function isMaster(array $room, array $member): bool
    {
        return hash_equals((string)($room['master_session_hash'] ?? ''), (string)$member['session_hash']);
    }
    public function roomView(array $room): array
    {
        return ['expires_at'=>(int)$room['expires_at'],'history_mode'=>$room['history_mode'],'read_only'=>(int)$room['read_only']===1];
    }
    public function memberView(array $room, array $member): array
    {
        return ['display_name'=>$member['display_name'],'is_master'=>$this->isMaster($room,$member)];
    }
    private function requireMaster(array $room, array $member): void
    {
        $this->assertMember($member);
        if (!$this->isMaster($room,$member)) throw new AccessDeniedException('Only the room Master can do that.');
    }
    public function join(array $room, string $session, string $name): array
    {
        $name=trim($name);
        if ($name==='' || mb_strlen($name)>40) throw new \InvalidArgumentException('Name must be between 1 and 40 characters.');
        $existing=$this->member($room,$session);
        if ($existing) {
            $this->assertMember($existing);
            if (!SystemState::maintenanceEnabled($this->db)) $this->touch($room,$existing);
            return $this->member($room,$session);
        }
        SystemState::assertWritable($this->db);
        // A single write statement records the exact message boundary, even within one second.
        $now=time();
        $q=$this->db->prepare('INSERT OR IGNORE INTO encrypted_members(room_id,session_hash,display_name,after_id,last_seen_at) VALUES (?,?,?,(SELECT COALESCE(MAX(id),0) FROM encrypted_messages WHERE room_id=?),?)');
        $q->execute([$room['id'],$session,$name,$room['id'],$now]);
        $q=$this->db->prepare('UPDATE encrypted_rooms SET master_session_hash=? WHERE room_id=? AND master_session_hash IS NULL');
        $q->execute([$session,$room['id']]);
        return $this->member($room,$session);
    }
    public function messages(array $room, array $member, int $after=0, ?int $before=null): array
    {
        $this->assertMember($member);
        $boundary=$room['history_mode']==='since_join' ? (int)$member['after_id'] : 0;
        $q=$this->db->prepare('SELECT m.id,m.created_at,u.display_name,m.session_hash FROM encrypted_messages m JOIN encrypted_members u ON u.room_id=m.room_id AND u.session_hash=m.session_hash WHERE m.room_id=? AND m.id>?' . ($before !== null ? ' AND m.id<? ORDER BY m.id DESC LIMIT 25' : ' ORDER BY m.id LIMIT 100'));
        $parameters = [$room['id'],max($after,$boundary)];
        if ($before !== null) $parameters[] = $before;
        $q->execute($parameters);
        $rows = $q->fetchAll();
        if ($before !== null) $rows = array_reverse($rows);
        return array_map(static function($m) use ($member) {
            $m['is_mine']=$m['session_hash']===$member['session_hash']; unset($m['session_hash']); return $m;
        },$rows);
    }
    public function post(array $room,array $member,string $nonce,string $ciphertext): array
    {
        $this->requireCanPost($room,$member);
        if(strlen($nonce)!==12 || strlen($ciphertext)<20 || strlen($ciphertext)>self::MAX_CIPHERTEXT) throw new \InvalidArgumentException('Invalid encrypted payload or file exceeds 8 MB.');
        $q=$this->db->prepare('INSERT INTO encrypted_messages(room_id,session_hash,nonce,ciphertext,created_at) VALUES (?,?,?,?,?)');
        $q->bindValue(1,$room['id'],PDO::PARAM_INT); $q->bindValue(2,$member['session_hash']);
        $q->bindValue(3,$nonce,PDO::PARAM_LOB); $q->bindValue(4,$ciphertext,PDO::PARAM_LOB); $q->bindValue(5,time(),PDO::PARAM_INT); $q->execute();
        return ['id'=>(int)$this->db->lastInsertId(),'created_at'=>time(),'display_name'=>$member['display_name'],'is_mine'=>true];
    }

    /** @param array{path:string,bytes:int,sha256:string,nonce:string} $staged */
    public function postAttachment(array $room, array $member, array $staged): array
    {
        $this->requireCanPost($room, $member);
        $ciphertextBytes = $staged['bytes'] - 12;
        if (strlen($staged['nonce']) !== 12 || $ciphertextBytes < 20 || $ciphertextBytes > self::MAX_CIPHERTEXT) {
            EncryptedAttachmentStore::discard($staged['path']);
            throw new \InvalidArgumentException('Invalid encrypted payload or file exceeds 8 MB.');
        }

        $key = EncryptedAttachmentStore::newKey();
        $now = time();
        $promoted = false;
        $this->db->beginTransaction();
        try {
            $message = $this->db->prepare('INSERT INTO encrypted_messages(room_id,session_hash,nonce,ciphertext,created_at) VALUES (?,?,?,zeroblob(0),?)');
            $message->execute([$room['id'], $member['session_hash'], $staged['nonce'], $now]);
            $messageId = (int)$this->db->lastInsertId();
            $file = $this->db->prepare('INSERT INTO encrypted_message_files(message_id,storage_key,wire_bytes,wire_sha256,created_at) VALUES (?,?,?,?,?)');
            $file->execute([$messageId, $key, $staged['bytes'], $staged['sha256'], $now]);
            EncryptedAttachmentStore::promote($staged['path'], $key);
            $promoted = true;
            $this->db->commit();
            return ['id'=>$messageId,'created_at'=>$now,'display_name'=>$member['display_name'],'is_mine'=>true];
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if ($promoted) EncryptedAttachmentStore::remove($key);
            else EncryptedAttachmentStore::discard($staged['path']);
            throw $error;
        }
    }

    /** @return array{bytes?:string,storage_key?:string,wire_bytes?:int} */
    public function contentSource(array $room, array $member, int $id): array
    {
        $this->assertMember($member);
        $boundary=$room['history_mode']==='since_join' ? (int)$member['after_id'] : 0;
        $q=$this->db->prepare('SELECT m.nonce,m.ciphertext,f.storage_key,f.wire_bytes FROM encrypted_messages m LEFT JOIN encrypted_message_files f ON f.message_id=m.id WHERE m.room_id=? AND m.id=? AND m.id>?');
        $q->execute([$room['id'],$id,$boundary]); $m=$q->fetch();
        if(!$m) throw new \RuntimeException('Message unavailable.');
        if ($m['storage_key'] !== null) return ['storage_key'=>(string)$m['storage_key'],'wire_bytes'=>(int)$m['wire_bytes']];
        return ['bytes'=>$m['nonce'].$m['ciphertext']];
    }

    public function content(array $room,array $member,int $id): string
    {
        $source = $this->contentSource($room, $member, $id);
        return isset($source['bytes']) ? $source['bytes'] : EncryptedAttachmentStore::read($source['storage_key'], $source['wire_bytes']);
    }
    public function share(array $room, array $member, int $id): array
    {
        $this->assertMember($member);
        SystemState::assertWritable($this->db);
        $boundary=$room['history_mode']==='since_join' ? (int)$member['after_id'] : 0;
        $q=$this->db->prepare('SELECT m.id,u.display_name FROM encrypted_messages m JOIN encrypted_members u ON u.room_id=m.room_id AND u.session_hash=m.session_hash WHERE m.room_id=? AND m.id=? AND m.id>?');
        $q->execute([$room['id'],$id,$boundary]);
        $message=$q->fetch();
        if (!$message) throw new \RuntimeException('Message unavailable.');
        $q=$this->db->prepare('SELECT token FROM encrypted_message_shares WHERE message_id=?');
        $q->execute([$id]); $existing=$q->fetchColumn();
        if ($existing) return ['token'=>$existing];
        $token=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
        $q=$this->db->prepare('INSERT INTO encrypted_message_shares(token,message_id,display_name,created_at) VALUES (?,?,?,?)');
        $q->execute([$token,$id,$message['display_name'],time()]);
        return ['token'=>$token];
    }
    public function shared(string $token): array
    {
        $q=$this->db->prepare('SELECT s.token,r.token AS room_token,m.id,m.created_at,s.display_name,m.nonce,m.ciphertext,f.storage_key,f.wire_bytes FROM encrypted_message_shares s JOIN encrypted_messages m ON m.id=s.message_id JOIN rooms r ON r.id=m.room_id LEFT JOIN encrypted_message_files f ON f.message_id=m.id WHERE s.token=? AND r.expires_at>?');
        $q->execute([$token,time()]);
        return $q->fetch() ?: throw new \RuntimeException('This shared message does not exist or has expired.');
    }
    public function sharedView(array $shared): array
    {
        return ['id'=>(int)$shared['id'],'created_at'=>(int)$shared['created_at'],'display_name'=>$shared['display_name'],'room_token'=>$shared['room_token']];
    }
    public function sharedContent(array $shared): string
    {
        $source = $this->sharedContentSource($shared);
        return isset($source['bytes']) ? $source['bytes'] : EncryptedAttachmentStore::read($source['storage_key'], $source['wire_bytes']);
    }

    /** @return array{bytes?:string,storage_key?:string,wire_bytes?:int} */
    public function sharedContentSource(array $shared): array
    {
        if ($shared['storage_key'] !== null) return ['storage_key'=>(string)$shared['storage_key'],'wire_bytes'=>(int)$shared['wire_bytes']];
        return ['bytes'=>$shared['nonce'].$shared['ciphertext']];
    }
    public function requireCanPost(array $room, array $member): void
    {
        $this->assertMember($member);
        SystemState::assertWritable($this->db);
        if ((int)$room['read_only'] === 1 && !$this->isMaster($room,$member)) {
            throw new AccessDeniedException('This chat is read-only. Only the Master can post.');
        }
    }
    public function setReadOnly(array $room, array $member, bool $enabled): array
    {
        $this->requireMaster($room,$member);
        SystemState::assertWritable($this->db);
        $q=$this->db->prepare('UPDATE encrypted_rooms SET read_only=? WHERE room_id=?');
        $q->execute([$enabled ? 1 : 0,$room['id']]);
        $room['read_only']=$enabled ? 1 : 0;
        return $room;
    }
    public function activeMembers(array $room, array $member): array
    {
        $this->requireMaster($room,$member);
        $cutoff=time()-300;
        $q=$this->db->prepare('SELECT display_name,session_hash,last_seen_at FROM encrypted_members WHERE room_id=? AND blocked=0 AND last_seen_at>=? ORDER BY last_seen_at DESC');
        $q->execute([$room['id'],$cutoff]);
        return array_map(function(array $row) use ($room) {
            return ['display_name'=>$row['display_name'],'session_id'=>substr(hash('sha256',$row['session_hash']),0,12),'last_seen_at'=>(int)$row['last_seen_at'],'is_master'=>hash_equals((string)$room['master_session_hash'],$row['session_hash'])];
        },$q->fetchAll());
    }
    private function moderate(array $room, array $member, string $target, bool $blocked): void
    {
        $this->requireMaster($room,$member);
        SystemState::assertWritable($this->db);
        $q=$this->db->prepare('SELECT session_hash FROM encrypted_members WHERE room_id=?');
        $q->execute([$room['id']]);
        $targetHash=null;
        foreach ($q->fetchAll() as $row) {
            if (hash_equals(substr(hash('sha256',$row['session_hash']),0,12),$target)) { $targetHash=$row['session_hash']; break; }
        }
        if ($targetHash===null || hash_equals($targetHash,$member['session_hash'])) throw new AccessDeniedException('That session cannot be moderated.');
        if ($blocked) {
            $q=$this->db->prepare('UPDATE encrypted_members SET blocked=1 WHERE room_id=? AND session_hash=?');
            $q->execute([$room['id'],$targetHash]);
        } else {
            $q=$this->db->prepare('DELETE FROM encrypted_members WHERE room_id=? AND session_hash=?');
            $q->execute([$room['id'],$targetHash]);
        }
    }
    public function kick(array $room,array $member,string $target): void { $this->moderate($room,$member,$target,false); }
    public function block(array $room,array $member,string $target): void { $this->moderate($room,$member,$target,true); }
}
