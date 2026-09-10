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
    public function messages(array $room, array $member, int $after=0): array
    {
        $this->assertMember($member);
        $boundary=$room['history_mode']==='since_join' ? (int)$member['after_id'] : 0;
        $q=$this->db->prepare('SELECT m.id,m.created_at,u.display_name,m.session_hash FROM encrypted_messages m JOIN encrypted_members u ON u.room_id=m.room_id AND u.session_hash=m.session_hash WHERE m.room_id=? AND m.id>? ORDER BY m.id LIMIT 100');
        $q->execute([$room['id'],max($after,$boundary)]);
        return array_map(static function($m) use ($member) {
            $m['is_mine']=$m['session_hash']===$member['session_hash']; unset($m['session_hash']); return $m;
        },$q->fetchAll());
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
    public function content(array $room,array $member,int $id): string
    {
        $this->assertMember($member);
        $boundary=$room['history_mode']==='since_join' ? (int)$member['after_id'] : 0;
        $q=$this->db->prepare('SELECT nonce,ciphertext FROM encrypted_messages WHERE room_id=? AND id=? AND id>?');
        $q->execute([$room['id'],$id,$boundary]); $m=$q->fetch();
        if(!$m) throw new \RuntimeException('Message unavailable.');
        return $m['nonce'].$m['ciphertext'];
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
