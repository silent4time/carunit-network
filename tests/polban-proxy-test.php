<?php
define('ABSPATH','/'); define('MINUTE_IN_SECONDS',60); define('HOUR_IN_SECONDS',3600); define('DAY_IN_SECONDS',86400);
$F=array(); $OPT=array('cn_polban_url'=>'https://pb.test','cn_polban_key'=>'K');
function add_filter($t,$c){global $F;$F[$t]=$c;} function apply_filters($t,$v,...$a){global $F;return isset($F[$t])?$F[$t]($v,...$a):$v;}
function add_action(){} function is_admin(){return false;} function get_option($k,$d=''){global $OPT;return $OPT[$k]??$d;}
function wp_json_encode($x){return json_encode($x);} function wp_parse_url($u,$c=-1){return parse_url($u,$c);} function get_transient(){return false;} function set_transient(){} function delete_transient(){}
class WP_Http_Cookie{public $name,$value;function __construct($a){$this->name=$a['name'];$this->value=$a['value'];}}
$base=__DIR__.'/../includes/';
require $base.'class-cn-http.php'; require $base.'class-cn-crypto.php'; require $base.'class-cn-util.php'; require $base.'drivers/class-cn-driver.php';
$LAST=null;
add_filter('cn_http_mock',function($v,$m,$url,$args,$o){global $LAST,$NEXT;$LAST=array($url,$args);return $NEXT;});
class T extends CN_Driver_Xui{ function h($m,$p,$o){return $this->http($m,$p,$o);} }
$d=new T(array('id'=>1,'type'=>'xui','url'=>'https://panel.test:2053','settings'=>array('use_polban'=>1),'secrets'=>array()));
$fail=0; function ok($c,$m){global $fail; echo ($c?'PASS ':'FAIL ').$m."\n"; if(!$c)$fail++;}
// 1 bearer + custom header + cookies
$NEXT=array('code'=>200,'body'=>json_encode(array('ok'=>true,'code'=>200,'error'=>'','headers'=>array('Set-Cookie'=>'3x-ui=abc123; Path=/; HttpOnly'),'body'=>'{"success":true}')));
$r=$d->h('POST','/login',array('bearer'=>'TT','headers'=>array('X-API-KEY'=>'xk'),'cookies'=>array('a'=>'1',new WP_Http_Cookie(array('name'=>'b','value'=>'2')))));
$p=json_decode($LAST[1]['body'],true);
ok($LAST[0]==='https://pb.test/v1/proxy','url'); ok($LAST[1]['headers']['X-Polban-Key']==='K','key');
ok($p['url']==='https://panel.test:2053/login','target'); ok($p['headers']['Authorization']==='Bearer TT','bearer');
ok($p['headers']['X-API-KEY']==='xk','custom header'); ok($p['headers']['Cookie']==='a=1; b=2','cookie out');
ok($p['headers']['Accept']==='application/json','accept');
ok($r['ok'] && $r['json']['success']===true,'unwrap'); ok($r['cookies']===array('3x-ui'=>'abc123'),'set-cookie parsed');
// 2 basic + form
$r=$d->h('POST','/x',array('auth'=>array('u','p'),'form'=>array('a'=>'b c')));
$p=json_decode($LAST[1]['body'],true);
ok($p['headers']['Authorization']==='Basic '.base64_encode('u:p'),'basic'); ok($p['body']==='a=b+c','form body');
// 3 upstream error 502
$NEXT=array('code'=>502,'body'=>json_encode(array('ok'=>false,'code'=>0,'error'=>'Connection timed out after 25001 milliseconds','headers'=>array(),'body'=>'')));
$r=$d->h('GET','/y',array()); ok(!$r['ok'] && strpos(CN_Http::error_text($r),'پل‌بان به پنل «panel.test» نرسید')===0 && strpos(CN_Http::error_text($r),'25001')!==false,'polban->panel timeout: host + raw error: '.CN_Http::error_text($r));
// 4 302 with cookie (IBSng)
$NEXT=array('code'=>200,'body'=>json_encode(array('ok'=>true,'code'=>302,'error'=>'','headers'=>array('Location'=>'/IBSng/admin/admin_index.php','Set-Cookie'=>'IBS_SESSID=zz; path=/'),'body'=>'')));
$r=$d->h('POST','/IBSng/admin/',array()); ok($r['cookies']===array('IBS_SESSID'=>'zz') && $r['headers']['location']==='/IBSng/admin/admin_index.php','302 cookie+location');
// 5 no polban
$d2=new T(array('id'=>2,'type'=>'xui','url'=>'https://p2','settings'=>array(),'secrets'=>array()));
$NEXT=array('code'=>200,'body'=>'{}'); $d2->h('GET','/z',array()); ok($LAST[0]==='https://p2/z','direct path untouched');
// 6 v1.2.4 error texts
$NEXT=array('code'=>502,'body'=>json_encode(array('ok'=>false,'code'=>403,'error'=>'upstream not in allowlist','headers'=>array(),'body'=>'')));
$r=$d->h('GET','/y',array()); $t=CN_Http::error_text($r); ok(strpos($t,'allowlist')!==false && strpos($t,'panel.test')!==false,'allowlist msg: '.$t);
$NEXT=array('code'=>0,'body'=>'','error'=>'cURL error 28: Operation timed out after 75000 milliseconds');
$r=$d->h('GET','/y',array()); $t=CN_Http::error_text($r); ok(strpos($t,'سرور سایت به پل‌بان «pb.test» نرسید')===0 && strpos($t,'cURL error 28')!==false,'site->polban failure: '.$t);
$NEXT=array('code'=>401,'body'=>json_encode(array('ok'=>false,'error'=>'unauthorized')));
$r=$d->h('GET','/y',array()); $t=CN_Http::error_text($r); ok(strpos($t,'کلید API')!==false && strpos($t,'pb.test')!==false,'polban key rejected: '.$t);
$NEXT=array('code'=>0,'body'=>'','error'=>'cURL error 7: Failed to connect to p2 port 443');
$r=$d2->h('GET','/z',array()); $t=CN_Http::error_text($r); ok(strpos($t,'سرور سایت به آدرس پنل «p2» نرسید')===0 && strpos($t,'cURL error 7')!==false,'direct: host + raw: '.$t);
$NEXT=array('code'=>404,'body'=>'{"detail":"User not found"}');
$r=$d2->h('GET','/z',array()); ok(CN_Http::error_text($r)==='User not found','404 text unchanged');
// 7 timeouts: direct default 20, polban 75 (http() default 20 does not cap it)
$NEXT=array('code'=>200,'body'=>'{}');
$d2->h('GET','/z',array()); ok($LAST[1]['timeout']===20,'direct default 20s');
$d->h('GET','/z',array()); ok($LAST[1]['timeout']===75,'polban default 75s');
exit($fail?1:0);
