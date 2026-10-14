<?php
declare(strict_types=1);
/**
 * LeagueCut isolated WebSocket process:
 * PHP bin/realtime-server.php
 * Bind 127.0.0.1:18765 ONLY; TLS and /ws Upgrade are handled by the site's Nginx.
 */
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/realtime.php';
require dirname(__DIR__).'/vendor/autoload.php';

final class LeagueCutHub implements \Ratchet\MessageComponentInterface {
    private \SplObjectStorage $clients;
    private int $cursor=0;
    private int $lastCheck=0;
    private string $origin;
    public function __construct(private \React\EventLoop\LoopInterface $loop) {
        $this->clients=new \SplObjectStorage();
        $this->origin=rtrim(lc_env('APP_URL','https://leaguecut.com'),'/');
        $this->cursor=(int)(lc_one('SELECT COALESCE(MAX(id),0) AS id FROM realtime_events')['id']??0);
        $loop->addPeriodicTimer(1.5,fn()=> $this->tick());
    }
    public function onOpen(\Ratchet\ConnectionInterface $conn): void {
        $req=$conn->httpRequest??null;
        $origin=$req && method_exists($req,'getHeaderLine')?(string)$req->getHeaderLine('Origin'):'';
        // Browser clients only; never allow cross-origin subscriptions.
        if($origin!==$this->origin || $this->clients->count()>=600){$conn->close();return;}
        $this->clients->attach($conn,['user'=>null,'opened'=>time()]);
    }
    public function onMessage(\Ratchet\ConnectionInterface $from,$message): void {
        if(!$this->clients->contains($from)){$from->close();return;}
        $info=$this->clients[$from];
        if($info['user']!==null)return; // read-only socket: ignore client commands after auth
        if(strlen((string)$message)>1400){$from->close();return;}
        $data=json_decode((string)$message,true);
        if(!is_array($data)||($data['type']??null)!=='auth'||!is_string($data['ticket']??null)){
            $from->close();return;
        }
        $verified=lc_realtime_authorize($data['ticket']);
        if(!$verified){$from->close();return;}
        $user=lc_one('SELECT id,role,status FROM users WHERE id=?',[(int)$verified['id']]);
        if(!$user || $user['role']!==$verified['role'] || $user['status']!=='approved'){$from->close();return;}
        $recentId=(int)(lc_one('SELECT COALESCE(MAX(id),0) AS last_id FROM realtime_events')['last_id']??0);
        $this->clients[$from]=['user'=>['id'=>(int)$user['id'],'role'=>$user['role']],'opened'=>time(),'min_id'=>$recentId];
        $from->send(json_encode(['type'=>'ready','cursor'=>$this->cursor],JSON_THROW_ON_ERROR));
    }
    public function onClose(\Ratchet\ConnectionInterface $conn): void {
        if($this->clients->contains($conn))$this->clients->detach($conn);
    }
    public function onError(\Ratchet\ConnectionInterface $conn,\Exception $e): void {
        error_log('LeagueCut websocket client disconnected');
        $conn->close();
    }
    private function tick(): void {
        $now=time();
        foreach($this->clients as $conn) {
            $info=$this->clients[$conn];
            if($info['user']===null&&$info['opened']<$now-10)$conn->close();
        }
        try{
            $batch=lc_all('SELECT id,scope,user_id,event_type,entity_type,entity_id FROM realtime_events WHERE id>? ORDER BY id ASC LIMIT 250',[$this->cursor]);
            foreach($batch as $row) {
                $this->cursor=(int)$row['id'];
                $message=json_encode(['type'=>'event','id'=>$this->cursor,'event'=>$row['event_type'],
                    'entity'=>$row['entity_type'],'entity_id'=>$row['entity_id']===null?null:(int)$row['entity_id']],JSON_THROW_ON_ERROR);
                foreach($this->clients as $conn){
                    $info=$this->clients[$conn];
                    $u=$info['user'];
                    if(!$u || (int)$row['id'] <= (int)($info['min_id']??0))continue;
                    $allowed=$row['scope']==='admin'?$u['role']==='admin':
                        ($u['role']==='clipador' && $u['id']===(int)$row['user_id']);
                    if($allowed)$conn->send($message);
                }
            }
            if($now-$this->lastCheck>=45){
                $this->lastCheck=$now;
                foreach($this->clients as $conn) {
                    $info=$this->clients[$conn];$u=$info['user'];
                    if(!$u)continue;
                    $current=lc_one('SELECT role,status FROM users WHERE id=?',[$u['id']]);
                    if(!$current||$current['status']!=='approved'||$current['role']!==$u['role'])$conn->close();
                    else $conn->send('{"type":"heartbeat"}');
                }
            }
        }catch(\Throwable $e){
            error_log('LeagueCut realtime worker: event polling failed');
            // Continue on transient DB errors without taking the website down.
        }
    }
}
try {
    if(lc_env('DB_NAME')!=='leaguecut')throw new RuntimeException('O worker deve usar apenas o banco leaguecut.');
    lc_realtime_key();
    $loop=\React\EventLoop\Loop::get();
    $socket=new \React\Socket\SocketServer('127.0.0.1:18765',[], $loop);
    $hub=new LeagueCutHub($loop);
    $http=new \Ratchet\Http\HttpServer(new \Ratchet\WebSocket\WsServer($hub));
    $server=new \Ratchet\Server\IoServer($http,$socket,$loop);
    echo "LEAGUECUT_WS_LISTENING=127.0.0.1:18765\n";
    $server->run();
}catch(\Throwable $e){
    fwrite(STDERR,"WebSocket indisponível: ".$e->getMessage()."\n");
    exit(1);
}
